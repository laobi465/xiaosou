<?php
declare(strict_types=1);

namespace app\common\service;

use think\App;
use think\facade\Cache;
use think\cache\driver\Redis as RedisCacheDriver;

/**
 * 限流服务 - Redis 滑动窗口
 *
 * 实现原理:
 *   使用 Redis 有序集合(ZSET),score 为请求时间戳(微秒)。
 *   每次请求先移除窗口外的过期成员,再统计当前成员数,
 *   未超限则写入当前请求时间戳并刷新 TTL。
 *
 * 用法:
 *   $limiter->allow('rate:search:1.2.3.4', 60, 60);  // 60次/60秒
 *   $limiter->count('rate:search:1.2.3.4');
 */
class RateLimiter
{
    protected App $app;

    /**
     * ZSET 成员前缀,避免与业务 key 冲突
     */
    protected const ZSET_PREFIX = 'rate:zset:';

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 判断是否允许访问
     *
     * 使用 Lua 脚本将 zRemRangeByScore + zCard + zAdd 三步合并为原子操作,
     * 避免高并发下三步非原子导致限流失效。
     *
     * @param string $key    限流维度 key(需调用方拼接好,如 ip/user_id)
     * @param int    $limit  窗口内最大允许次数
     * @param int    $window 窗口大小(秒)
     * @return bool true=允许 false=已超限
     */
    public function allow(string $key, int $limit, int $window): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            // Redis 不可用: 根据 fail_closed 配置决定(fail-open 放行 / fail-closed 拒绝)
            if ($this->isFailClosed()) {
                return false;
            }
            return true;
        }

        $zsetKey  = self::ZSET_PREFIX . $key;
        $now      = (int) (microtime(true) * 1000); // 毫秒精度
        $minScore = $now - $window * 1000;
        // 成员用 uniqid('', true) 避免高并发下成员覆盖
        $member   = uniqid('', true);

        // Lua 脚本: 原子化执行 zRemRangeByScore + zCard + zAdd
        $script = <<<'LUA'
local key = KEYS[1]
local now = tonumber(ARGV[1])
local min_score = ARGV[2]
local limit = tonumber(ARGV[3])
local window = tonumber(ARGV[4])
local member = ARGV[5]

redis.call('ZREMRANGEBYSCORE', key, '-inf', min_score)
local count = redis.call('ZCARD', key)
if count < limit then
    redis.call('ZADD', key, now, member)
    redis.call('EXPIRE', key, window * 2)
    return 1
end
return 0
LUA;

        try {
            $result = $redis->eval($script, [$zsetKey, $now, (string) $minScore, $limit, $window, $member], 1);
            return (int) $result === 1;
        } catch (\Throwable $e) {
            trace('rate_limiter_error: ' . $e->getMessage(), 'error');
            // Redis 操作异常: 根据 fail_closed 配置决定
            if ($this->isFailClosed()) {
                return false;
            }
            return true;
        }
    }

    /**
     * 获取当前窗口内已用次数
     */
    public function count(string $key): int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return 0;
        }
        try {
            return (int) $redis->zCard(self::ZSET_PREFIX . $key);
        } catch (\Throwable $e) {
            trace('rate_limiter_count_error: ' . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * 登录失败锁定 - 检查是否已被锁定
     *
     * @param string $key        锁定 key(调用方拼接好维度,如 admin_login_fail:{ip})
     * @param int    $threshold  锁定阈值(失败达到该次数即锁定)
     * @return array{locked:bool, remain:int} locked=已锁定, remain=剩余可用尝试次数
     */
    public function checkLoginLock(string $key, int $threshold): array
    {
        $redis = $this->redis();
        if ($redis === null) {
            // Redis 不可用:降级放行,不阻塞登录
            return ['locked' => false, 'remain' => $threshold];
        }
        try {
            $count = (int) $redis->get($key);
            if ($count >= $threshold) {
                return ['locked' => true, 'remain' => 0];
            }
            return ['locked' => false, 'remain' => $threshold - $count];
        } catch (\Throwable $e) {
            trace('login_lock_check_error: ' . $e->getMessage(), 'error');
            return ['locked' => false, 'remain' => $threshold];
        }
    }

    /**
     * 登录失败锁定 - 记录一次失败
     *
     * 每次失败都会刷新 TTL,确保锁定窗口从最后一次失败开始倒计时。
     *
     * @param string $key     锁定 key
     * @param int    $lockTtl 锁定时长(秒)
     * @return int 当前失败次数(Redis 不可用时返回 0)
     */
    public function recordLoginFail(string $key, int $lockTtl): int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return 0;
        }
        try {
            $count = (int) $redis->incr($key);
            // 每次失败都刷新 TTL,锁定窗口自最后一次失败起算
            $redis->expire($key, $lockTtl);
            return $count;
        } catch (\Throwable $e) {
            trace('login_fail_record_error: ' . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * 登录失败锁定 - 清除计数(登录成功时调用)
     */
    public function clearLoginFail(string $key): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        try {
            $redis->del($key);
        } catch (\Throwable $e) {
            trace('login_fail_clear_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Redis 是否可用
     * 供中间件实现 fail-closed/fail-open 策略判断
     */
    public function isAvailable(): bool
    {
        return $this->redis() !== null;
    }

    /**
     * 是否启用 fail-closed 策略
     * 配置: pan.rate_limit.fail_closed
     * - false(默认): Redis 不可用时放行(fail-open)
     * - true: Redis 不可用时拒绝(fail-closed)
     */
    protected function isFailClosed(): bool
    {
        return (bool) $this->app->config->get('pan.rate_limit.fail_closed', false);
    }

    /**
     * 获取底层 Redis 实例
     */
    protected function redis(): ?\Redis
    {
        try {
            $store = Cache::store('redis');
            if ($store instanceof RedisCacheDriver) {
                $handler = $store->handler();
                if ($handler instanceof \Redis) {
                    return $handler;
                }
            }
            // 兜底:部分版本通过 handler() 返回 \Redis
            if (method_exists($store, 'handler')) {
                $handler = $store->handler();
                return $handler instanceof \Redis ? $handler : null;
            }
        } catch (\Throwable $e) {
            trace('rate_limiter_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }
}
