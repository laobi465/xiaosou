<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\enum\AdSlotCode;
use app\common\service\AdService;
use app\common\service\SearchService;
use app\common\model\PanSource;
use Pansou\Search\SearchQuery;
use Pansou\Search\SearchResult;

/**
 * 搜索
 */
class Search extends BaseController
{
    /**
     * 文件大小上限(字节, 100GB), 用于 size 参数范围校验
     */
    protected const MAX_SIZE_BYTES = 104857600;

    /**
     * 搜索页
     * 解析参数 → 构造 SearchQuery → SearchService::search → 渲染(含 SEARCH_TOP 广告位)
     *
     * 参数校验:
     *   - time: strtotime 校验日期格式, 非法则忽略
     *   - size: is_numeric 校验 + 范围限制(0-100GB)
     *   - cursor: 限制为正整数
     *   - sources: 校验白名单(从 PanSource 表 enabled=1 的 code 列表)
     */
    public function index()
    {
        // 解析参数
        $q       = trim((string) $this->request->get('q', ''));
        $type    = (int) $this->request->get('type', 0);
        $sources = (string) $this->request->get('sources', '');
        $size    = (string) $this->request->get('size', '');
        $time    = (string) $this->request->get('time', '');
        $cursor  = (int) $this->request->get('cursor', 0);

        // cursor 限制为非负整数
        $cursor = $cursor > 0 ? $cursor : 0;

        // 构造 SearchQuery
        $query = new SearchQuery([
            'keyword'      => $q,
            'resourceType' => $type > 0 ? $type : null,
            'panSources'   => $this->filterSources($sources),
            'cursor'       => $cursor,
            'limit'        => (int) config('pan.page_size', 15),
        ]);

        // 解析 size 范围(min-max, 单位 MB → 字节), 校验数值合法性
        if ($size !== '') {
            $this->applySizeFilter($query, $size);
        }

        // 解析 time 范围(start,end), 用 strtotime 校验日期格式
        if ($time !== '' && str_contains($time, ',')) {
            $this->applyTimeFilter($query, $time);
        }

        // 执行搜索(通过容器解析 SearchService, SearchDriverInterface 已在 AppService 中绑定)
        $result = new SearchResult([], 0, 0);
        try {
            $searchService = app(SearchService::class);
            $result = $searchService->search($query, $this->userId(), $this->request->ip());
        } catch (\Throwable $e) {
            trace('search_error: ' . $e->getMessage(), 'error');
        }

        // 搜索结果置顶广告
        $ads = [];
        try {
            $ads = app(AdService::class)->getPlacements(AdSlotCode::SEARCH_TOP);
        } catch (\Throwable $e) {
            trace('search_ads_error: ' . $e->getMessage(), 'error');
        }

        return view('search/index', [
            'q'      => $q,
            'type'   => $type,
            'sources'=> $sources,
            'size'   => $size,
            'time'   => $time,
            'result' => $result,
            'ads'    => $ads,
            'cursor' => $cursor,
        ]);
    }

    /**
     * 热搜词(Ajax)
     */
    public function hot()
    {
        $list = [];
        try {
            $searchService = app(SearchService::class);
            $list = $searchService->hotKeywords(10);
        } catch (\Throwable $e) {
            trace('search_hot_error: ' . $e->getMessage(), 'error');
        }
        return $this->success(['list' => $list], 'success');
    }

    /**
     * 过滤 sources 参数: 仅保留 PanSource 表 enabled=1 的 code 白名单
     *
     * @param string $sources 逗号分隔的 code 列表
     * @return array<int,string> 过滤后的 code 列表
     */
    protected function filterSources(string $sources): array
    {
        if ($sources === '') {
            return [];
        }
        $input = array_values(array_filter(explode(',', $sources)));
        if (empty($input)) {
            return [];
        }

        // 查询启用的网盘源 code 白名单(异常降级为空, 等价于不筛选)
        $allowCodes = [];
        try {
            $allowCodes = PanSource::where('enabled', 1)->column('code');
            $allowCodes = array_map('strval', $allowCodes);
        } catch (\Throwable $e) {
            trace('search_sources_whitelist_error: ' . $e->getMessage(), 'error');
            return [];
        }

        if (empty($allowCodes)) {
            return [];
        }

        // 仅保留在白名单内的 code
        return array_values(array_filter($input, function ($code) use ($allowCodes) {
            return in_array((string) $code, $allowCodes, true);
        }));
    }

    /**
     * 解析并应用 size 范围过滤(校验 is_numeric + 范围 0-100GB)
     *
     * @param SearchQuery $query 查询参数
     * @param string      $size  原始 size 参数(min-max 或 单值, 单位 MB)
     */
    protected function applySizeFilter(SearchQuery $query, string $size): void
    {
        if (str_contains($size, '-')) {
            [$min, $max] = explode('-', $size, 2);

            // 校验 min: 非空且为合法数字, 范围 0-100GB(单位 MB)
            if ($min !== '' && is_numeric($min)) {
                $minVal = (float) $min;
                if ($minVal >= 0 && $minVal * 1048576 <= self::MAX_SIZE_BYTES) {
                    $query->minSize = (int) ($minVal * 1048576);
                }
            }
            // 校验 max: 非空且为合法数字, 范围 0-100GB(单位 MB)
            if ($max !== '' && is_numeric($max)) {
                $maxVal = (float) $max;
                if ($maxVal >= 0 && $maxVal * 1048576 <= self::MAX_SIZE_BYTES) {
                    $query->maxSize = (int) ($maxVal * 1048576);
                }
            }
        } else {
            // 单值: 必须为合法数字, 范围 0-100GB(单位 MB)
            if (is_numeric($size)) {
                $val = (float) $size;
                if ($val >= 0 && $val * 1048576 <= self::MAX_SIZE_BYTES) {
                    $query->minSize = (int) ($val * 1048576);
                }
            }
        }
    }

    /**
     * 解析并应用 time 范围过滤(用 strtotime 校验日期格式, 非法则忽略)
     *
     * @param SearchQuery $query 查询参数
     * @param string      $time  原始 time 参数(start,end, 格式 Y-m-d)
     */
    protected function applyTimeFilter(SearchQuery $query, string $time): void
    {
        [$start, $end] = explode(',', $time, 2);
        $start = trim($start);
        $end   = trim($end);

        // strtotime 校验日期格式, 返回 false 表示非法
        if ($start !== '') {
            $ts = strtotime($start);
            if ($ts !== false) {
                $query->startTime = date('Y-m-d', $ts) . ' 00:00:00';
            }
        }
        if ($end !== '') {
            $ts = strtotime($end);
            if ($ts !== false) {
                $query->endTime = date('Y-m-d', $ts) . ' 23:59:59';
            }
        }
    }
}
