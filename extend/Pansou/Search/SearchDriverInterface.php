<?php
declare(strict_types=1);

namespace Pansou\Search;

/**
 * 搜索驱动接口
 *
 * 参见架构设计文档 3.1 节。
 * 实现方: MysqlFulltextDriver / ElasticsearchDriver
 */
interface SearchDriverInterface
{
    /**
     * 执行搜索
     *
     * @param SearchQuery $query 查询参数
     * @return SearchResult 搜索结果
     */
    public function search(SearchQuery $query): SearchResult;
}
