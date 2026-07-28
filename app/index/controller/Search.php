<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\enum\AdSlotCode;
use app\common\service\AdService;
use app\common\service\SearchService;
use Pansou\Search\MysqlFulltextDriver;
use Pansou\Search\SearchQuery;
use Pansou\Search\SearchResult;

/**
 * 搜索
 */
class Search extends BaseController
{
    /**
     * 搜索页
     * 解析参数 → 构造 SearchQuery → SearchService::search → 渲染(含 SEARCH_TOP 广告位)
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

        // 构造 SearchQuery
        $query = new SearchQuery([
            'keyword'      => $q,
            'resourceType' => $type > 0 ? $type : null,
            'panSources'   => $sources !== '' ? array_values(array_filter(explode(',', $sources))) : [],
            'cursor'       => $cursor,
            'limit'        => (int) config('pan.page_size', 15),
        ]);

        // 解析 size 范围(min-max, 单位 MB → 字节)
        if ($size !== '') {
            if (str_contains($size, '-')) {
                [$min, $max] = explode('-', $size, 2);
                $query->minSize = $min !== '' ? (int) $min * 1048576 : null;
                $query->maxSize = $max !== '' ? (int) $max * 1048576 : null;
            } else {
                $query->minSize = (int) $size * 1048576;
            }
        }

        // 解析 time 范围(start,end)
        if ($time !== '' && str_contains($time, ',')) {
            [$start, $end] = explode(',', $time, 2);
            $query->startTime = $start !== '' ? trim($start) . ' 00:00:00' : null;
            $query->endTime   = $end !== '' ? trim($end) . ' 23:59:59' : null;
        }

        // 执行搜索
        $result = new SearchResult([], 0, 0);
        try {
            $searchService = new SearchService(new MysqlFulltextDriver());
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
            $searchService = new SearchService(new MysqlFulltextDriver());
            $list = $searchService->hotKeywords(10);
        } catch (\Throwable $e) {
            trace('search_hot_error: ' . $e->getMessage(), 'error');
        }
        return $this->success(['list' => $list], 'success');
    }
}
