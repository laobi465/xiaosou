<?php
declare(strict_types=1);

namespace Pansou\Search;

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
