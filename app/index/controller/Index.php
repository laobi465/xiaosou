<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\enum\AdSlotCode;
use app\common\model\Resource;
use app\common\service\AdService;
use app\common\service\SearchService;

/**
 * 首页
 */
class Index extends BaseController
{
    /**
     * 首页: 热搜词 + Banner 广告 + 最新资源
     */
    public function index()
    {
        // 通过容器解析 SearchService(SearchDriverInterface 已在 AppService 中绑定)
        $searchService = app(SearchService::class);
        $adService     = app(AdService::class);

        // 首页展示数量配置化
        $hotKeywordsLimit = (int) config('pan.index.hot_keywords', 10);
        $latestLimit      = (int) config('pan.index.latest_limit', 12);

        // 热搜词
        $hotKeywords = [];
        try {
            $hotKeywords = $searchService->hotKeywords($hotKeywordsLimit);
        } catch (\Throwable $e) {
            trace('index_hot_keywords_error: ' . $e->getMessage(), 'error');
        }

        // 首页 Banner 广告
        $banners = [];
        try {
            $banners = $adService->getPlacements(AdSlotCode::HOME_BANNER);
        } catch (\Throwable $e) {
            trace('index_banners_error: ' . $e->getMessage(), 'error');
        }

        // 最新资源列表
        $resources = [];
        try {
            $resources = Resource::normal()
                ->order('create_time', 'desc')
                ->limit($latestLimit)
                ->select();
        } catch (\Throwable $e) {
            trace('index_resources_error: ' . $e->getMessage(), 'error');
        }

        return view('index/index', [
            'hotKeywords' => $hotKeywords,
            'banners'     => $banners,
            'resources'   => $resources,
        ]);
    }
}
