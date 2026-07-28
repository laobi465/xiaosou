<?php
declare(strict_types=1);

namespace app\index\middleware;

use Closure;
use think\cache\driver\Redis as RedisCacheDriver;
use think\facade\Cache;
use think\Request;
use think\Response;

/**
 * 访问日志记录(轻量化,异步落库由后续队列接管)
 *
 * 写入两路 Redis 数据:
 *   1. List 队列 visitor:logs:list    —— 最近的访问明细(LTRIM 截断,供消费者异步落库)
 *   2. Hash 统计 visitor:stats:{date} —— 按状态码累计 PV
 *   3. HyperLogLog visitor:uv:{date}  —— 按 IP 去重的 UV 估算
 *
 * 所有 Redis 操作 try-catch 降级,失败仅 trace 日志,不阻断主流程。
 */
class VisitorLog
{
    /**
     * 访问明细 List 的 key
     */
    protected const LIST_KEY = 'visitor:logs:list';

    /**
     * 按日统计 Hash 的 key 前缀
     */
    protected const STATS_KEY_PREFIX = 'visitor:stats:';

    /**
     * 按日 UV HyperLogLog 的 key 前缀
     */
    protected const UV_KEY_PREFIX = 'visitor:uv:';

    /**
     * List 容量上限(保留最近 N 条明细)
     */
    protected const LIST_MAX_SIZE = 10000;

    /**
     * 统计/UV key 的 TTL(秒,7 天)
     */
    protected const STATS_TTL = 86400 * 7;

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        /** @var Response $response */
        $response = $next($request);
        $duration = (int) ((microtime(true) - $start) * 1000);

        // 仅记录非静态资源请求
        if (str_starts_with($request->pathinfo(), 'static/')) {
            return $response;
        }

        $method     = $request->method();
        $url        = $request->url(true);
        $path       = $request->pathinfo();
        $ip         = $request->ip();
        $ua         = (string) $request->header('user-agent', '');
        $referer    = (string) $request->header('referer', '');
        $userId     = $request->userId ?? null;
        $statusCode = $response->getCode();

        // 保留原有 trace 日志(兼容性)
        trace(sprintf(
            '[VISIT] %s %s | %d | %dms | ip:%s',
            $method,
            $url,
            $statusCode,
            $duration,
            $ip
        ), 'info');

        // 写入 Redis(异步落库 + 统计),失败仅 trace
        $this->record([
            'method'       => $method,
            'url'          => $url,
            'path'         => $path,
            'ip'           => $ip,
            'ua'           => $ua,
            'referer'      => $referer,
            'user_id'      => $userId,
            'status_code'  => $statusCode,
            'duration_ms'  => $duration,
            'time'         => date('Y-m-d H:i:s'),
        ]);

        return $response;
    }

    /**
     * 写入访问明细 List + 按日统计 Hash + UV HyperLogLog
     *
     * @param array<string,mixed> $log
     */
    protected function record(array $log): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }

        $date = date('Y-m-d');
        $payload = json_encode($log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            // 1. 访问明细入队(LPUSH 最新在前 + LTRIM 截断,避免内存爆炸)
            if ($payload !== false) {
                $redis->lPush(self::LIST_KEY, $payload);
                $redis->lTrim(self::LIST_KEY, 0, self::LIST_MAX_SIZE - 1);
            }
        } catch (\Throwable $e) {
            trace('visitor_log_list_error: ' . $e->getMessage(), 'error');
        }

        try {
            // 2. 按状态码累计 PV
            $statsKey = self::STATS_KEY_PREFIX . $date;
            $redis->hIncrBy($statsKey, (string) $log['status_code'], 1);
            $redis->hIncrBy($statsKey, 'pv', 1);
            $redis->expire($statsKey, self::STATS_TTL);
        } catch (\Throwable $e) {
            trace('visitor_log_stats_error: ' . $e->getMessage(), 'error');
        }

        try {
            // 3. UV 去重(HyperLogLog,内存远低于 Set)
            $uvKey = self::UV_KEY_PREFIX . $date;
            $redis->pfAdd($uvKey, [$log['ip']]);
            $redis->expire($uvKey, self::STATS_TTL);
        } catch (\Throwable $e) {
            trace('visitor_log_uv_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 获取底层 Redis 实例(参考 RateLimiter::redis() 实现)
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
            trace('visitor_log_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }
}
