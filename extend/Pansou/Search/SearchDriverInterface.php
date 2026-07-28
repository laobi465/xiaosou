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

/**
 * 搜索查询参数
 *
 * 简单 DTO,封装关键词/筛选/分页游标。
 */
class SearchQuery
{
    /** 关键词 */
    public string $keyword = '';
    /** 资源类型 1-7,null 表示不限 */
    public ?int $resourceType = null;
    /** 网盘来源 code 列表 */
    public array $panSources = [];
    /** 最小文件大小(字节) */
    public ?int $minSize = null;
    /** 最大文件大小(字节) */
    public ?int $maxSize = null;
    /** 起始时间(Y-m-d H:i:s) */
    public ?string $startTime = null;
    /** 截止时间(Y-m-d H:i:s) */
    public ?string $endTime = null;
    /** 游标(上一页最后一条记录的 id,0 表示首页) */
    public int $cursor = 0;
    /** 每页数量 */
    public int $limit = 20;

    public function __construct(array $data = [])
    {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) {
                $this->{$k} = $v;
            }
        }
    }
}

/**
 * 搜索结果
 *
 * 简单 DTO,封装结果列表与下一页游标。
 */
class SearchResult
{
    /** 结果列表 */
    public array $list = [];
    /** 总数(估算) */
    public int $total = 0;
    /** 下一页游标(0 表示无更多) */
    public int $nextCursor = 0;
    /** 是否命中缓存 */
    public bool $fromCache = false;
    /** 耗时(毫秒) */
    public float $took = 0;

    public function __construct(array $list = [], int $total = 0, int $nextCursor = 0)
    {
        $this->list       = $list;
        $this->total      = $total;
        $this->nextCursor = $nextCursor;
    }
}
