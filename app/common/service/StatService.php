<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\Resource;
use app\common\model\User;
use app\common\model\Order;
use app\common\model\SearchLog;
use app\common\model\CrawlTask;
use app\common\model\CrawlLog;
use app\common\model\Submission;
use think\facade\Cache;
use think\cache\driver\Redis as RedisCacheDriver;

/**
 * 统计服务
 *
 * 提供后台仪表盘聚合数据。
 */
class StatService
{
    /** 仪表盘缓存 key */
    protected const DASHBOARD_CACHE_KEY = 'stat:dashboard';

    /** 仪表盘缓存 TTL(秒,5 分钟) */
    protected const DASHBOARD_CACHE_TTL = 300;

    /**
     * 后台仪表盘数据
     *
     * @return array 仪表盘统计指标
     */
    public function dashboard(): array
    {
        // 1. 缓存命中检查
        try {
            $cached = Cache::get(self::DASHBOARD_CACHE_KEY);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            trace('stat_dashboard_cache_read_error: ' . $e->getMessage(), 'error');
        }

        // 2. 聚合各维度指标(每个指标独立 try-catch,单个失败不影响其他)
        $todayStart = date('Y-m-d 00:00:00');
        $todayEnd   = date('Y-m-d 23:59:59');

        $data = [
            'resource'   => $this->statResource($todayStart, $todayEnd),
            'user'       => $this->statUser($todayStart, $todayEnd),
            'search'     => $this->statSearch($todayStart, $todayEnd),
            'order'      => $this->statOrder($todayStart, $todayEnd),
            'crawl'      => $this->statCrawl($todayStart, $todayEnd),
            'submission' => $this->statSubmission(),
            'ad'         => $this->statAd(),
        ];

        // 3. 写缓存(失败降级,不影响返回)
        try {
            Cache::set(self::DASHBOARD_CACHE_KEY, $data, self::DASHBOARD_CACHE_TTL);
        } catch (\Throwable $e) {
            trace('stat_dashboard_cache_write_error: ' . $e->getMessage(), 'error');
        }

        return $data;
    }

    /**
     * 资源指标: total / today_new / pending_review(status=2)
     */
    protected function statResource(string $todayStart, string $todayEnd): array
    {
        $result = ['total' => 0, 'today_new' => 0, 'pending_review' => 0];

        try {
            $result['total'] = (int) Resource::count();
        } catch (\Throwable $e) {
            trace('stat_resource_total_error: ' . $e->getMessage(), 'error');
        }

        try {
            $result['today_new'] = (int) Resource::whereBetweenTime('create_time', $todayStart, $todayEnd)->count();
        } catch (\Throwable $e) {
            trace('stat_resource_today_new_error: ' . $e->getMessage(), 'error');
        }

        try {
            $result['pending_review'] = (int) Resource::where('status', 2)->count();
        } catch (\Throwable $e) {
            trace('stat_resource_pending_error: ' . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * 用户指标: total / today_new / active(今日有搜索日志的独立用户数)
     */
    protected function statUser(string $todayStart, string $todayEnd): array
    {
        $result = ['total' => 0, 'today_new' => 0, 'active' => 0];

        try {
            $result['total'] = (int) User::count();
        } catch (\Throwable $e) {
            trace('stat_user_total_error: ' . $e->getMessage(), 'error');
        }

        try {
            $result['today_new'] = (int) User::whereBetweenTime('create_time', $todayStart, $todayEnd)->count();
        } catch (\Throwable $e) {
            trace('stat_user_today_new_error: ' . $e->getMessage(), 'error');
        }

        try {
            $result['active'] = (int) SearchLog::whereNotNull('user_id')
                ->whereBetweenTime('create_time', $todayStart, $todayEnd)
                ->distinct(true)
                ->field('user_id')
                ->count('user_id');
        } catch (\Throwable $e) {
            trace('stat_user_active_error: ' . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * 搜索指标: today_count / hot_keywords(TOP10, 从 Redis ZSET 取)
     */
    protected function statSearch(string $todayStart, string $todayEnd): array
    {
        $result = ['today_count' => 0, 'hot_keywords' => []];

        try {
            $result['today_count'] = (int) SearchLog::whereBetweenTime('create_time', $todayStart, $todayEnd)->count();
        } catch (\Throwable $e) {
            trace('stat_search_today_count_error: ' . $e->getMessage(), 'error');
        }

        try {
            // 通过容器解析 SearchService(SearchDriverInterface 已在 AppService 中绑定)
            $searchService = app(SearchService::class);
            $result['hot_keywords'] = $searchService->hotKeywords(10);
        } catch (\Throwable $e) {
            trace('stat_search_hot_keywords_error: ' . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * 订单指标: today_count / today_revenue(sum amount where status=1 and today)
     */
    protected function statOrder(string $todayStart, string $todayEnd): array
    {
        $result = ['today_count' => 0, 'today_revenue' => 0.0];

        try {
            $query = Order::where('status', 1)->whereBetweenTime('create_time', $todayStart, $todayEnd);
            $result['today_count']  = (int) $query->count();
            $result['today_revenue'] = (float) (clone $query)->sum('amount');
        } catch (\Throwable $e) {
            trace('stat_order_error: ' . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * 采集指标: enabled_count / today_success / today_fail
     */
    protected function statCrawl(string $todayStart, string $todayEnd): array
    {
        $result = ['enabled_count' => 0, 'today_success' => 0, 'today_fail' => 0];

        try {
            $result['enabled_count'] = (int) CrawlTask::where('enabled', 1)->count();
        } catch (\Throwable $e) {
            trace('stat_crawl_enabled_error: ' . $e->getMessage(), 'error');
        }

        try {
            $baseQuery = CrawlLog::whereBetweenTime('create_time', $todayStart, $todayEnd);
            $result['today_success'] = (int) (clone $baseQuery)->where('status', 1)->count();
            $result['today_fail']    = (int) (clone $baseQuery)->where('status', 0)->count();
        } catch (\Throwable $e) {
            trace('stat_crawl_today_error: ' . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * 提交指标: pending_count(status=0 待审)
     */
    protected function statSubmission(): array
    {
        $result = ['pending_count' => 0];

        try {
            $result['pending_count'] = (int) Submission::where('status', 0)->count();
        } catch (\Throwable $e) {
            trace('stat_submission_pending_error: ' . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * 广告指标: today_impressions / today_clicks (从 Redis Hash 取所有字段求和)
     *
     * Key 格式: ad:imp:{date} / ad:click:{date}
     * 取值方式: HVALS 后 array_sum
     */
    protected function statAd(): array
    {
        $result = ['today_impressions' => 0, 'today_clicks' => 0];

        $redis = $this->redis();
        if ($redis === null) {
            return $result;
        }

        $date = date('Y-m-d');

        try {
            $imp = $redis->hVals('ad:imp:' . $date);
            if (is_array($imp)) {
                $result['today_impressions'] = (int) array_sum(array_map('intval', $imp));
            }
        } catch (\Throwable $e) {
            trace('stat_ad_impressions_error: ' . $e->getMessage(), 'error');
        }

        try {
            $click = $redis->hVals('ad:click:' . $date);
            if (is_array($click)) {
                $result['today_clicks'] = (int) array_sum(array_map('intval', $click));
            }
        } catch (\Throwable $e) {
            trace('stat_ad_clicks_error: ' . $e->getMessage(), 'error');
        }

        return $result;
    }

    /**
     * 获取底层 Redis 实例
     *
     * 参考 RateLimiter::redis() 实现。
     */
    protected function redis(): ?\Redis
    {
        try {
            $store = Cache::store('redis');
            if ($store instanceof RedisCacheDriver) {
                $handler = $store->handler();
                if ($handler instanceof \Redis) {
                    return $handler;
                }
            }
            // 兜底:部分版本通过 handler() 返回 \Redis
            if (method_exists($store, 'handler')) {
                $handler = $store->handler();
                return $handler instanceof \Redis ? $handler : null;
            }
        } catch (\Throwable $e) {
            trace('stat_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }
}
