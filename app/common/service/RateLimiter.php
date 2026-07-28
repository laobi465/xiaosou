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
     * @param string $key    限流维度 key(需调用方拼接好,如 ip/user_id)
     * @param int    $limit  窗口内最大允许次数
     * @param int    $window 窗口大小(秒)
     * @return bool true=允许 false=已超限
     */
    public function allow(string $key, int $limit, int $window): bool
    {
        $redis = $this->redis();
        if ($redis === null) {
            // Redis 不可用时降级放行,不阻塞业务
            return true;
        }

        $zsetKey = self::ZSET_PREFIX . $key;
        $now     = (int) (microtime(true) * 1000); // 毫秒精度,避免同秒成员覆盖
        $minScore = $now - $window * 1000;

        try {
            // 1. 移除窗口外的过期成员
            $redis->zRemRangeByScore($zsetKey, '-inf', (string) $minScore);
            // 2. 统计当前窗口内请求数
            $count = (int) $redis->zCard($zsetKey);
            if ($count >= $limit) {
                return false;
            }
            // 3. 写入当前请求(成员用唯一标识防覆盖)
            $redis->zAdd($zsetKey, $now, $now . ':' . random_int(0, 999999));
            // 4. 刷新过期时间(窗口的 2 倍,确保过期数据被清理)
            $redis->expire($zsetKey, $window * 2);
            return true;
        } catch (\Throwable $e) {
            trace('rate_limiter_error: ' . $e->getMessage(), 'error');
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
