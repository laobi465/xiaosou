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
     *
     * 注意: ES 驱动尚未实现。原实现静默返回空结果会导致搜索无结果被误判为"无匹配",
     *      故改为显式抛异常, 避免静默故障。请使用 MysqlFulltextDriver 或补全 ES DSL。
     *
     * @throws \RuntimeException 驱动未实现
     */
    public function search(SearchQuery $query): SearchResult
    {
        throw new \RuntimeException('ElasticsearchDriver 未实现');
    }

    /**
     * 校验 ES 连接
     *
     * @throws \RuntimeException 驱动未实现
     */
    public function ping(): bool
    {
        throw new \RuntimeException('ElasticsearchDriver 未实现');
    }
}
