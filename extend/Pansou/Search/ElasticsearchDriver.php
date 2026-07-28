<?php
declare(strict_types=1);

namespace Pansou\Search;

/**
 * Elasticsearch 搜索驱动
 *
 * 参见架构设计文档 3.1 节。
 *
 * 实现:
 *   - 使用 ES multi_match / match_phrase 查询 title + intro
 *   - 聚合网盘来源分布
 *   - 游标分页(search_after)
 */
class ElasticsearchDriver implements SearchDriverInterface
{
    /**
     * ES 客户端配置
     */
    protected array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function search(SearchQuery $query): SearchResult
    {
        // TODO: 完整实现
        //   1. 构造 ES query DSL(multi_match + filter)
        //   2. 调用 ES search API
        //   3. 解析 hits + aggregations
        //   4. 计算 search_after 游标
        return new SearchResult();
    }

    /**
     * 校验 ES 连接
     */
    public function ping(): bool
    {
        // TODO: 实现 ES 健康检查
        return false;
    }
}
