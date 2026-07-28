<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\EmailVerify;
use think\facade\Cache;
use think\cache\driver\Redis as RedisCacheDriver;

/**
 * 验证码服务
 *
 * 参见架构设计文档 3.4 节。
 *
 * 关键设计:
 *   - 限流: 同邮箱 60s/次,同 IP 10min/5 次(RateLimiter)
 *   - 6 位数字验证码,Redis 存 key=verify:email:{type}:{email}, ttl=300
 *   - 尝试次数 key=verify:try:{type}:{email}, ttl=300, value=5
 *   - 入库 email_verifies 审计(code 字段哈希存储)
 */
class VerifyCodeService
{
    /**
     * 发送验证码
     *
     * @param string $email 收件邮箱
     * @param int    $type  类型: 1注册 2登录 3重置密码
     * @return array{sent:bool,reason?:string}
     */
    public function send(string $email, int $type): array
    {
        try {
            $limiter = app(RateLimiter::class);

            // 限流: 邮箱维度 60s/N 次
            $emailLimit = (int) config('pan.rate_limit.verify_send_per_email', 1);
            $emailKey   = 'verify:send:email:' . $type . ':' . $email;
            if (!$limiter->allow($emailKey, $emailLimit, 60)) {
                return ['sent' => false, 'reason' => '发送过于频繁,请稍后再试'];
            }

            // 限流: IP 维度 10min/N 次
            $ip      = request() ?-> ip() ?? '';
            $ipLimit = (int) config('pan.rate_limit.verify_send_per_ip_10m', 5);
            $ipKey   = 'verify:send:ip:' . $ip;
            if (!$limiter->allow($ipKey, $ipLimit, 600)) {
                return ['sent' => false, 'reason' => '发送过于频繁,请稍后再试'];
            }

            // 邮箱格式校验
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return ['sent' => false, 'reason' => '邮箱格式不正确'];
            }

            // 生成数字验证码
            $length = (int) config('pan.verify_code.length', 6);
            $code   = $this->generateCode($length);

            // Redis 写入 code 与尝试次数
            $ttl     = (int) config('pan.verify_code.ttl', 300);
            $maxTry  = (int) config('pan.verify_code.max_try', 5);
            $redis   = $this->redis();
            if ($redis === null) {
                return ['sent' => false, 'reason' => '发送失败,请稍后重试'];
            }

            $codeKey = 'verify:email:' . $type . ':' . $email;
            $tryKey  = 'verify:try:' . $type . ':' . $email;

            $redis->setex($codeKey, $ttl, $code);
            $redis->setex($tryKey, $ttl, (string) $maxTry);

            // 入库审计(code 哈希存储)
            EmailVerify::create([
                'email'     => $email,
                'code'      => password_hash($code, PASSWORD_DEFAULT),
                'type'      => $type,
                'ip'        => $ip,
                'expire_at' => date('Y-m-d H:i:s', time() + $ttl),
                'used'      => 0,
            ]);

            // 投递邮件队列
            app(MailService::class)->sendVerifyCode($email, $code, $type);

            return ['sent' => true];
        } catch (\Throwable $e) {
            trace('verify_code_send_error: ' . $e->getMessage(), 'error');
            return ['sent' => false, 'reason' => '发送失败,请稍后重试'];
        }
    }

    /**
     * 校验验证码
     *
     * @param string $email 邮箱
     * @param string $code  验证码
     * @param int    $type  类型
     * @return bool true=校验通过 false=失败
     */
    public function verify(string $email, string $code, int $type): bool
    {
        try {
            $redis = $this->redis();
            if ($redis === null) {
                return false;
            }

            $codeKey = 'verify:email:' . $type . ':' . $email;
            $tryKey  = 'verify:try:' . $type . ':' . $email;

            $cached = $redis->get($codeKey);
            if ($cached === false || $cached === null) {
                return false;
            }

            // 恒等比较,防 timing attack
            if (!hash_equals((string) $cached, (string) $code)) {
                // 错误: 尝试次数 -1
                $remaining = (int) $redis->decr($tryKey);
                if ($remaining <= 0) {
                    $redis->del($codeKey, $tryKey);
                }
                return false;
            }

            // 正确: 删除两个 key
            $redis->del($codeKey, $tryKey);

            // 标记最近一条该 email+type 未使用记录 used=1
            EmailVerify::where('email', $email)
                ->where('type', $type)
                ->where('used', 0)
                ->order('id', 'desc')
                ->limit(1)
                ->update(['used' => 1]);

            return true;
        } catch (\Throwable $e) {
            trace('verify_code_check_error: ' . $e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * 生成指定位数的数字验证码
     *
     * @param int $length 验证码长度
     * @return string
     */
    private function generateCode(int $length): string
    {
        $min = 10 ** ($length - 1);
        $max = (10 ** $length) - 1;
        return (string) random_int($min, $max);
    }

    /**
     * 获取底层 Redis 实例
     */
    private function redis(): ?\Redis
    {
        try {
            $store = Cache::store('redis');
            if ($store instanceof RedisCacheDriver) {
                $handler = $store->handler();
                if ($handler instanceof \Redis) {
                    return $handler;
                }
            }
            if (method_exists($store, 'handler')) {
                $handler = $store->handler();
                return $handler instanceof \Redis ? $handler : null;
            }
        } catch (\Throwable $e) {
            trace('verify_code_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }
}
