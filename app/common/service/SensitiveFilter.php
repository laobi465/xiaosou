<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\SensitiveWord;
use Pansou\Sensitive\DfaFilter;
use think\facade\Cache;
use think\cache\driver\Redis as RedisCacheDriver;

/**
 * 敏感词过滤服务
 *
 * 底层使用 Pansou\Sensitive\DfaFilter(DFA 算法)实现。
 * 敏感词来源: sensitive_words 表 status=1 的词条。
 *
 * 缓存策略:
 *   - 进程内静态缓存 DfaFilter 实例(TTL 10 分钟),避免每次查库/重建 DFA 树
 *   - Redis 可用时缓存敏感词列表(跨进程共享),减少 DB 压力
 *   - Redis 不可用时直接查 DB,不阻塞业务
 *   - CLI 长驻进程场景下静态缓存 10 分钟自动刷新
 *   - 后台修改敏感词后调用 clearCache() 主动刷新
 */
class SensitiveFilter
{
    /**
     * DFA 过滤器实例(进程内静态缓存)
     *
     * @var DfaFilter|null
     */
    protected static ?DfaFilter $filter = null;

    /**
     * 上次加载时间戳
     *
     * @var int|null
     */
    protected static ?int $loadedAt = null;

    /**
     * 静态缓存 TTL(秒),10 分钟
     */
    protected const CACHE_TTL = 600;

    /**
     * Redis 中敏感词列表缓存 key
     */
    protected const REDIS_WORDS_KEY = 'sensitive:words:list';

    /**
     * Redis 中敏感词列表缓存 TTL(秒),与静态缓存对齐
     */
    protected const REDIS_WORDS_TTL = 600;

    /**
     * 检查文本是否命中敏感词
     *
     * @param string $text 待检查文本
     * @return array{hit:bool,words:array<int,string>} ['hit'=>是否命中,'words'=>命中的敏感词列表(去重)]
     */
    public function check(string $text): array
    {
        try {
            $filter = self::getFilter();
            if ($filter === null) {
                return ['hit' => false, 'words' => []];
            }
            return $filter->check($text);
        } catch (\Throwable $e) {
            trace('sensitive_filter_check_error: ' . $e->getMessage(), 'error');
            return ['hit' => false, 'words' => []];
        }
    }

    /**
     * 替换文本中的敏感词为 ***
     *
     * @param string $text 待处理文本
     * @return string 替换后的文本(失败时返回原文)
     */
    public function replace(string $text): string
    {
        try {
            $filter = self::getFilter();
            if ($filter === null) {
                return $text;
            }
            return $filter->replace($text, '***');
        } catch (\Throwable $e) {
            trace('sensitive_filter_replace_error: ' . $e->getMessage(), 'error');
            return $text;
        }
    }

    /**
     * 获取 DFA 过滤器实例(带静态缓存)
     *
     * 若静态缓存为空或已过期,则重新加载敏感词并构建 DFA 树。
     * Redis 不可用时直接查 DB,不阻塞业务。
     *
     * @return DfaFilter|null
     */
    protected static function getFilter(): ?DfaFilter
    {
        // 静态缓存有效则直接复用
        if (self::$filter !== null && self::$loadedAt !== null && (time() - self::$loadedAt) <= self::CACHE_TTL) {
            return self::$filter;
        }

        // 加载敏感词列表并构建 DFA 树
        $words  = self::loadWords();
        $filter = new DfaFilter();
        $filter->load($words);

        self::$filter   = $filter;
        self::$loadedAt = time();
        return $filter;
    }

    /**
     * 加载敏感词列表
     *
     * 优先从 Redis 读取(跨进程共享),Redis 不可用时直接查 DB。
     * 查 DB 成功后回写 Redis 缓存。
     *
     * @return array<int,string>
     */
    protected static function loadWords(): array
    {
        // 1. 尝试 Redis 缓存
        $redis = self::redis();
        if ($redis !== null) {
            try {
                $cached = $redis->get(self::REDIS_WORDS_KEY);
                if (is_string($cached) && $cached !== '') {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        return array_values(array_filter($decoded, 'is_string'));
                    }
                }
            } catch (\Throwable $e) {
                trace('sensitive_filter_redis_read_error: ' . $e->getMessage(), 'error');
            }
        }

        // 2. 回退查 DB
        try {
            $words = SensitiveWord::where('status', 1)->column('word');
            $words = array_map('strval', $words);
        } catch (\Throwable $e) {
            trace('sensitive_filter_db_load_error: ' . $e->getMessage(), 'error');
            $words = [];
        }

        // 3. 写回 Redis 缓存(仅在 Redis 可用时)
        if ($redis !== null && !empty($words)) {
            try {
                $redis->setex(
                    self::REDIS_WORDS_KEY,
                    self::REDIS_WORDS_TTL,
                    json_encode($words, JSON_UNESCAPED_UNICODE)
                );
            } catch (\Throwable $e) {
                trace('sensitive_filter_redis_write_error: ' . $e->getMessage(), 'error');
            }
        }

        return $words;
    }

    /**
     * 获取底层 Redis 实例
     *
     * 复用 think Cache 的 redis 驱动 handler,避免新建连接。
     *
     * @return \Redis|null
     */
    protected static function redis(): ?\Redis
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
            trace('sensitive_filter_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }

    /**
     * 清除进程内静态缓存
     *
     * 后台修改敏感词后调用此方法主动刷新缓存。
     * 同时清除 Redis 中的敏感词列表缓存,确保跨进程立即生效。
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$filter   = null;
        self::$loadedAt = null;

        $redis = self::redis();
        if ($redis !== null) {
            try {
                $redis->del(self::REDIS_WORDS_KEY);
            } catch (\Throwable $e) {
                trace('sensitive_filter_redis_clear_error: ' . $e->getMessage(), 'error');
            }
        }
    }
}
