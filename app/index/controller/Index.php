<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\enum\AdSlotCode;
use app\common\model\Resource;
use app\common\service\AdService;
use app\common\service\SearchService;
use Pansou\Search\MysqlFulltextDriver;

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
        $searchService = new SearchService(new MysqlFulltextDriver());
        $adService     = app(AdService::class);

        // 热搜词 TOP10
        $hotKeywords = [];
        try {
            $hotKeywords = $searchService->hotKeywords(10);
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
                ->limit(12)
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
