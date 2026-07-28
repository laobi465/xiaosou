<?php
declare(strict_types=1);

namespace app\common\service;

/**
 * 验证码服务
 *
 * 参见架构设计文档 3.4 节。
 *
 * 关键设计:
 *   - 限流: 同邮箱 60s/次,同 IP 10min/5 次(RateLimiter)
 *   - 6 位数字验证码,Redis 存 key=verify:email:{type}:{email}, ttl=300
 *   - 尝试次数 key=verify:try:{type}:{email}, ttl=300, value=5
 *   - 入库 email_verifies 审计
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
        // TODO: 1. 限流: 邮箱维度 60s/次 + IP 维度 10min/5 次
        // TODO: 2. 生成 6 位数字验证码
        // TODO: 3. 存 Redis: verify:email:{type}:{email} ttl=300
        // TODO: 4. 存尝试次数: verify:try:{type}:{email} ttl=300 value=5
        // TODO: 5. 入库 email_verifies
        // TODO: 6. 投递邮件队列(MailService)
        return ['sent' => false, 'reason' => 'not_implemented'];
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
        // TODO: 1. Redis 取 code 比对
        // TODO: 2. 错误: 尝试次数-1,归零删除 key
        // TODO: 3. 正确: 删除 key,标记 email_verifies.used=1
        return false;
    }
}
