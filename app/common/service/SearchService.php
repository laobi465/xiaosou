<?php
declare(strict_types=1);

namespace app\common\service;

use Pansou\Search\SearchQuery;
use Pansou\Search\SearchResult;
use Pansou\Search\SearchDriverInterface;

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

    public function __construct(SearchDriverInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * 执行搜索
     *
     * @param SearchQuery $q 查询参数(关键词/筛选/分页游标)
     * @return SearchResult 搜索结果集
     */
    public function search(SearchQuery $q): SearchResult
    {
        // TODO: 1. 缓存命中检查 'search:' . md5(serialize($q)), TTL 300
        // TODO: 2. 调用 $this->driver->search($q)
        // TODO: 3. 写入 search_logs
        // TODO: 4. 异步更新 hot_keywords (Redis ZINCRBY)
        // TODO: 5. 写入缓存并返回
        return $this->driver->search($q);
    }
}
