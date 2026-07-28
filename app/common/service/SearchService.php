<?php
declare(strict_types=1);

namespace app\common\service;

use Pansou\Search\SearchQuery;
use Pansou\Search\SearchResult;
use Pansou\Search\SearchDriverInterface;
use app\common\model\SearchLog;
use app\common\model\HotKeyword;
use think\facade\Cache;
use think\facade\Db;
use think\cache\driver\Redis as RedisCacheDriver;

/**
 * 搜索服务
 *
 * 参见架构设计文档 3.1 节。
 *
 * 关键设计:
 *   - 命中 Redis 缓存(5分钟)直接返回
 *   - 调用 driver(MySQL FULLTEXT / Elasticsearch)查询
 *   - 写入 search_logs,异步更新 hot_keywords(ZINCRBY)
 *   - 游标分页避免大偏移量
 */
class SearchService
{
    protected SearchDriverInterface $driver;

    /** 搜索结果缓存 TTL(秒) */
    protected const CACHE_TTL = 300;

    /** 热搜词 ZSET key 前缀 */
    protected const HOT_KEY_PREFIX = 'hot:keywords:';

    /** 热搜词 ZSET 过期时间(秒,2 天) */
    protected const HOT_KEY_TTL = 86400 * 2;

    public function __construct(SearchDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * 执行搜索
     *
     * @param SearchQuery $q       查询参数(关键词/筛选/分页游标)
     * @param int|null    $userId  用户ID(游客为 null)
     * @param string|null $ip      客户端 IP(未传则从 request 获取)
     * @return SearchResult 搜索结果集
     */
    public function search(SearchQuery $q, ?int $userId = null, ?string $ip = null): SearchResult
    {
        $cacheKey = 'search:' . md5(serialize($q));

        // 1. 缓存命中检查
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && array_key_exists('list', $cached)) {
                $result = new SearchResult(
                    $cached['list'],
                    (int) ($cached['total'] ?? 0),
                    (int) ($cached['nextCursor'] ?? 0)
                );
                $result->took      = (float) ($cached['took'] ?? 0);
                $result->fromCache = true;
                return $result;
            }
        } catch (\Throwable $e) {
            trace('search_cache_read_error: ' . $e->getMessage(), 'error');
        }

        // 2. 调用 driver 查询并记录耗时
        $start  = microtime(true);
        $result = $this->driver->search($q);
        $took   = round((microtime(true) - $start) * 1000, 2);
        $result->took = $took;

        // 3. 异步写入 search_logs(失败降级,不影响主流程)
        $this->writeSearchLog($q, $result, $userId, $ip);

        // 4. 异步更新热词 Redis ZINCRBY(失败降级)
        $this->incrHotKeyword($q->keyword);

        // 5. 写入缓存并返回
        try {
            Cache::set($cacheKey, [
                'list'       => $result->list,
                'total'      => $result->total,
                'nextCursor' => $result->nextCursor,
                'took'       => $result->took,
            ], self::CACHE_TTL);
        } catch (\Throwable $e) {
            trace('search_cache_write_error: ' . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * 热搜词列表
     *
     * @param int $limit 返回条数
     * @return array<int,array{keyword:string,count:int}>
     */
    public function hotKeywords(int $limit = 10): array
    {
        $cacheKey = 'search:hot:' . date('Y-m-d') . ':' . $limit;

        // 1. 缓存命中检查
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            trace('hot_keywords_cache_read_error: ' . $e->getMessage(), 'error');
        }

        // 2. Redis ZREVRANGE 取热词
        $list  = [];
        $redis = $this->redis();
        if ($redis !== null && $limit > 0) {
            try {
                $key   = self::HOT_KEY_PREFIX . date('Y-m-d');
                $items = $redis->zRevRange($key, 0, $limit - 1, true);
                if (is_array($items)) {
                    foreach ($items as $keyword => $score) {
                        $list[] = [
                            'keyword' => (string) $keyword,
                            'count'   => (int) $score,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                trace('hot_keywords_read_error: ' . $e->getMessage(), 'error');
            }
        }

        // 3. 写缓存(Redis 不可用时此处也会失败,降级忽略)
        try {
            Cache::set($cacheKey, $list, self::CACHE_TTL);
        } catch (\Throwable $e) {
            trace('hot_keywords_cache_write_error: ' . $e->getMessage(), 'error');
        }

        return $list;
    }

    /**
     * 归档前日热词到 hot_keywords 表
     *
     * 供 ad:agg 命令调用,将 Redis ZSET 数据落库后清理 key。
     */
    public function archiveHotKeywords(): void
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $key       = self::HOT_KEY_PREFIX . $yesterday;

        $redis = $this->redis();
        if ($redis === null) {
            return;
        }

        try {
            $items = $redis->zRevRange($key, 0, -1, true);
            if (!is_array($items) || empty($items)) {
                return;
            }

            $table = (new HotKeyword())->getTable();
            $now   = date('Y-m-d H:i:s');

            foreach ($items as $keyword => $score) {
                $keyword = (string) $keyword;
                if ($keyword === '') {
                    continue;
                }
                try {
                    Db::execute(
                        'INSERT INTO `' . $table . '` (keyword, stat_date, search_cnt, create_time) '
                        . 'VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE search_cnt = VALUES(search_cnt)',
                        [$keyword, $yesterday, (int) $score, $now]
                    );
                } catch (\Throwable $e) {
                    trace('hot_keywords_archive_row_error: ' . $e->getMessage(), 'error');
                }
            }

            try {
                $redis->del($key);
            } catch (\Throwable $e) {
                trace('hot_keywords_archive_del_error: ' . $e->getMessage(), 'error');
            }
        } catch (\Throwable $e) {
            trace('archive_hot_keywords_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 异步写入搜索日志(失败降级,不阻塞主流程)
     */
    protected function writeSearchLog(SearchQuery $q, SearchResult $result, ?int $userId, ?string $ip): void
    {
        try {
            if ($userId === null) {
                $userId = $this->resolveUserId();
            }
            if ($ip === null) {
                $ip = $this->resolveIp();
            }

            $filters = [
                'resourceType' => $q->resourceType,
                'panSources'   => $q->panSources,
                'minSize'      => $q->minSize,
                'maxSize'      => $q->maxSize,
                'startTime'    => $q->startTime,
                'endTime'      => $q->endTime,
                'cursor'       => $q->cursor,
                'limit'        => $q->limit,
            ];

            SearchLog::create([
                'keyword'      => $q->keyword,
                'user_id'      => $userId,
                'ip'           => $ip,
                'result_count' => $result->total,
                'duration_ms'  => (int) $result->took,
                'filters'      => json_encode($filters, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            trace('search_log_write_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Redis ZINCRBY 热词计数(失败降级)
     */
    protected function incrHotKeyword(string $keyword): void
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return;
        }
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        try {
            $key = self::HOT_KEY_PREFIX . date('Y-m-d');
            $redis->zIncrBy($key, 1.0, $keyword);
            $redis->expire($key, self::HOT_KEY_TTL);
        } catch (\Throwable $e) {
            trace('hot_keywords_incr_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 解析当前用户 ID(不直接依赖 session,仅从 request 读取中间件注入值)
     */
    protected function resolveUserId(): ?int
    {
        try {
            $req = request();
            $uid = $req->userId ?? null;
            if (is_numeric($uid) && (int) $uid > 0) {
                return (int) $uid;
            }
        } catch (\Throwable $e) {
            // 忽略,返回 null
        }
        return null;
    }

    /**
     * 解析客户端 IP
     */
    protected function resolveIp(): ?string
    {
        try {
            $ip = request()->ip();
            return is_string($ip) && $ip !== '' ? $ip : null;
        } catch (\Throwable $e) {
            return null;
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
            trace('search_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }
}
