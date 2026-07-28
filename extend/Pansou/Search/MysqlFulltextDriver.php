<?php
declare(strict_types=1);

namespace Pansou\Search;

use think\facade\Db;

/**
 * MySQL FULLTEXT 全文检索驱动
 *
 * 参见架构设计文档 3.1 节。
 *
 * 实现:
 *   - 使用 MATCH(title, intro) AGAINST(? IN BOOLEAN MODE)
 *   - 配合 ngram 解析器分词中文(resources 表 ft_title_intro 索引)
 *   - 游标分页(id > cursor)避免大偏移量
 */
class MysqlFulltextDriver implements SearchDriverInterface
{
    /** 查询的表名 */
    protected string $table = 'resources';

    /**
     * {@inheritdoc}
     */
    public function search(SearchQuery $query): SearchResult
    {
        $start = microtime(true);
        $keyword = trim($query->keyword);

        $builder = Db::table($this->table)
            ->where('status', 1)
            ->where('delete_time', null);

        // 1. 全文检索条件(关键词非空时)
        if ($keyword !== '') {
            $booleanKeyword = $this->buildBooleanQuery($keyword);
            $builder->whereRaw(
                'MATCH(title, intro) AGAINST(:keyword IN BOOLEAN MODE)',
                ['keyword' => $booleanKeyword]
            );
        }

        // 2. 筛选项
        if ($query->resourceType !== null) {
            $builder->where('resource_type', $query->resourceType);
        }
        if (!empty($query->panSources)) {
            $builder->whereIn('source_code', $query->panSources);
        }
        if ($query->minSize !== null) {
            $builder->where('file_size', '>=', $query->minSize);
        }
        if ($query->maxSize !== null) {
            $builder->where('file_size', '<=', $query->maxSize);
        }
        if ($query->startTime !== null) {
            $builder->where('create_time', '>=', $query->startTime);
        }
        if ($query->endTime !== null) {
            $builder->where('create_time', '<=', $query->endTime);
        }

        // 3. 游标分页(id > cursor)
        if ($query->cursor > 0) {
            $builder->where('id', '>', $query->cursor);
        }

        // 4. 总数(估算,使用 SQL_CALC_FOUND_ROWS 或单独 count)
        $total = (clone $builder)->count('id');

        // 5. 取列表(id 升序保证游标稳定)
        $rows = $builder
            ->order('id', 'asc')
            ->limit($query->limit)
            ->select()
            ->toArray();

        // 6. 下一页游标
        $nextCursor = 0;
        if (count($rows) >= $query->limit) {
            $last = end($rows);
            $nextCursor = $last['id'] ?? 0;
        }

        $took = round((microtime(true) - $start) * 1000, 2);

        $result = new SearchResult($rows, $total, $nextCursor);
        $result->took = $took;
        return $result;
    }

    /**
     * 构造 BOOLEAN MODE 查询字符串
     * 中文按字符拆分用 + 前缀要求全部包含(适合 ngram 索引)
     */
    protected function buildBooleanQuery(string $keyword): string
    {
        $keyword = preg_replace('/[+\-><()~*:""@&|\/\\\\]+/', ' ', $keyword) ?? $keyword;
        $keyword = trim($keyword);
        if ($keyword === '') {
            return '';
        }
        $chars = preg_split('//u', $keyword, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        foreach ($chars as $ch) {
            if (trim($ch) !== '') {
                $parts[] = '+' . $ch;
            }
        }
        return implode(' ', $parts);
    }
}
