<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\enum\AdSlotCode;
use app\common\enum\CreditType;
use app\common\exception\CreditNotEnoughException;
use app\common\model\CreditLog;
use app\common\model\Resource as ResourceModel;
use app\common\model\ResourceLink;
use app\common\service\AdService;
use app\common\service\CreditService;
use think\facade\Cache;
use think\facade\Db;
use think\cache\driver\Redis as RedisCacheDriver;

/**
 * 资源详情
 *
 * viewLink 路由层挂载 UserAuth + RateLimit 中间件, 控制器内通过 $this->userId() 获取登录态。
 */
class Resource extends BaseController
{
    /**
     * 详情页浏览去重 Redis key 前缀
     */
    protected const VIEW_DEDUP_PREFIX = 'resource:view:dedup:';

    /**
     * viewLink 幂等令牌 Redis key 前缀
     */
    protected const VIEW_TOKEN_PREFIX = 'resource:view:token:';

    /**
     * 详情页浏览去重窗口(秒, 5 分钟)
     */
    protected const VIEW_DEDUP_TTL = 300;

    /**
     * viewLink 同链接 24 小时内不重复扣费(秒)
     */
    protected const VIEW_LINK_DEDUP_TTL = 86400;

    /**
     * 资源详情页
     * 查 Resource + ResourceLink(隐藏完整链接) + 相关推荐 + 广告位 DETAIL_POPUP
     */
    public function detail(int $id)
    {
        $resource = ResourceModel::find($id);
        if (!$resource || (int) $resource->status !== 1) {
            $this->fail('资源不存在');
        }

        // view_count 自增(IP/用户 5 分钟内去重, 防刷)
        $this->incrViewCountDedup($id);

        // 资源链接(隐藏完整链接, 仅展示网盘来源)
        $linkSources = [];
        try {
            $links = ResourceLink::where('resource_id', $id)
                ->where('status', 1)
                ->with(['panSource'])
                ->select();
            foreach ($links as $link) {
                $linkSources[] = [
                    'id'           => (int) $link->id,
                    'pan_source'   => $link->panSource ? (string) $link->panSource->name : '未知',
                    'pan_source_id'=> (int) $link->pan_source_id,
                ];
            }
        } catch (\Throwable $e) {
            trace('resource_links_error: ' . $e->getMessage(), 'error');
        }

        // 相关推荐(同类型最新5条)
        $related = [];
        try {
            $related = ResourceModel::normal()
                ->where('resource_type', (int) $resource->resource_type)
                ->where('id', '<>', $id)
                ->order('create_time', 'desc')
                ->limit(5)
                ->select();
        } catch (\Throwable $e) {
            trace('resource_related_error: ' . $e->getMessage(), 'error');
        }

        // 详情页弹窗广告
        $ads = [];
        try {
            $ads = app(AdService::class)->getPlacements(AdSlotCode::DETAIL_POPUP);
        } catch (\Throwable $e) {
            trace('resource_ads_error: ' . $e->getMessage(), 'error');
        }

        return view('resource/detail', [
            'resource'    => $resource,
            'linkSources' => $linkSources,
            'related'     => $related,
            'ads'         => $ads,
        ]);
    }

    /**
     * 查看链接(Ajax)
     * 登录校验(路由层 UserAuth) → 幂等令牌 + 24h 去重 → 事务扣积分+自增 → 返回完整链接
     *
     * 同一用户对同一链接 24 小时内不重复扣费, 复用首次返回结果。
     */
    public function viewLink(int $id)
    {
        // 路由层已挂 UserAuth, 此处统一用 $this->userId()
        $userId = $this->userId();
        if ($userId === null) {
            return $this->error('请先登录', 1002);
        }

        // 查找链接
        $link = ResourceLink::where('id', $id)->where('status', 1)->find();
        if (!$link) {
            return $this->error('链接不存在或已失效');
        }

        // 24 小时去重: 复用 CreditLog 记录(user_id + related_id=link_id + type=CONSUME)
        // 24 小时内已扣费则直接返回链接, 不重复扣费
        $recentLog = null;
        try {
            $since = date('Y-m-d H:i:s', time() - self::VIEW_LINK_DEDUP_TTL);
            $recentLog = CreditLog::where('user_id', $userId)
                ->where('type', CreditType::CONSUME)
                ->where('related_id', $id)
                ->where('create_time', '>=', $since)
                ->find();
        } catch (\Throwable $e) {
            trace('view_link_dedup_check_error: ' . $e->getMessage(), 'error');
        }
        if ($recentLog) {
            return $this->success([
                'share_url'    => (string) $link->share_url,
                'extract_code' => (string) $link->extract_code,
            ], 'success');
        }

        // 幂等令牌(Redis SETNX): 防止并发/重复提交导致重复扣费
        $token = (string) $this->request->post('token', '');
        $tokenKey = self::VIEW_TOKEN_PREFIX . $userId . ':' . $id . ':' . ($token !== '' ? $token : 'default');
        $redis = $this->redis();
        if ($redis !== null) {
            try {
                // SETNX + TTL, 抢占成功返回 true, 已存在返回 false
                $acquired = $redis->set($tokenKey, '1', ['nx', 'ex' => self::VIEW_LINK_DEDUP_TTL]);
                if (!$acquired) {
                    // 同一令牌正在处理或已处理, 返回链接(不扣费)
                    return $this->success([
                        'share_url'    => (string) $link->share_url,
                        'extract_code' => (string) $link->extract_code,
                    ], 'success');
                }
            } catch (\Throwable $e) {
                trace('view_link_token_error: ' . $e->getMessage(), 'error');
            }
        }

        // 事务包裹: 扣积分 + 自增 link_view_count(任一失败回滚, 避免扣费后失败不可回滚)
        $viewCost = (int) config('pan.credit.view_link');
        try {
            Db::transaction(function () use ($userId, $viewCost, $id, $link) {
                // 扣减积分(CreditService::consume 内部已写 CreditLog, related_id=link_id)
                app(CreditService::class)->consume(
                    $userId,
                    $viewCost,
                    CreditType::CONSUME,
                    $id,
                    '查看资源链接'
                );

                // 自增 link_view_count(同事务, 失败则整体回滚)
                ResourceModel::where('id', (int) $link->resource_id)->inc('link_view_count')->update();
            });
        } catch (CreditNotEnoughException $e) {
            // 积分不足, 释放幂等令牌允许用户补积分后重试
            $this->releaseToken($tokenKey);
            return $this->error('积分不足', 3001);
        } catch (\Throwable $e) {
            $this->releaseToken($tokenKey);
            return $this->errorWithLog('积分扣减失败,请重试', $e, 'view_link_consume_error');
        }

        return $this->success([
            'share_url'    => (string) $link->share_url,
            'extract_code' => (string) $link->extract_code,
        ], 'success');
    }

    /**
     * 详情页 view_count 自增(IP/用户 5 分钟内去重)
     */
    protected function incrViewCountDedup(int $resourceId): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            // Redis 不可用时直接自增(降级)
            try {
                ResourceModel::where('id', $resourceId)->inc('view_count')->update();
            } catch (\Throwable $e) {
                trace('resource_view_count_incr_error: ' . $e->getMessage(), 'error');
            }
            return;
        }

        // 去重 key: 登录用户用 userId, 游客用 IP
        $userId = $this->userId();
        $identity = $userId !== null ? 'u' . $userId : 'i' . $this->request->ip();
        $key = self::VIEW_DEDUP_PREFIX . $resourceId . ':' . $identity;

        try {
            // SETNX 抢占, 成功才计数
            $acquired = $redis->set($key, '1', ['nx', 'ex' => self::VIEW_DEDUP_TTL]);
            if ($acquired) {
                ResourceModel::where('id', $resourceId)->inc('view_count')->update();
            }
        } catch (\Throwable $e) {
            trace('resource_view_count_dedup_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 释放幂等令牌(异常时允许重试)
     */
    protected function releaseToken(string $tokenKey): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        try {
            $redis->del($tokenKey);
        } catch (\Throwable $e) {
            trace('view_link_token_release_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 获取底层 Redis 实例(复用 think Cache redis 驱动)
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
            if (method_exists($store, 'handler')) {
                $handler = $store->handler();
                return $handler instanceof \Redis ? $handler : null;
            }
        } catch (\Throwable $e) {
            trace('resource_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }
}
