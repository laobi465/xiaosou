<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\enum\AdSlotCode;
use app\common\enum\CreditType;
use app\common\exception\CreditNotEnoughException;
use app\common\model\Resource as ResourceModel;
use app\common\model\ResourceLink;
use app\common\model\ResourceReport;
use app\common\service\AdService;
use app\common\service\CreditService;
use think\facade\Session;

/**
 * 资源详情
 */
class Resource extends BaseController
{
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

        // view_count 自增(try-catch 降级)
        try {
            ResourceModel::where('id', $id)->inc('view_count')->update();
        } catch (\Throwable $e) {
            trace('resource_view_count_incr_error: ' . $e->getMessage(), 'error');
        }

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
     * 登录校验 → 积分扣减 → 返回完整链接 + 提取码 → 自增 link_view_count → 写 ResourceReport
     */
    public function viewLink(int $id)
    {
        // 登录校验(此路由未挂 UserAuth 中间件, 从 Session 读取)
        $userId = Session::get('user_id');
        if (!$userId) {
            return $this->error('请先登录', 1002);
        }
        $userId = (int) $userId;

        // 查找链接
        $link = ResourceLink::where('id', $id)->where('status', 1)->find();
        if (!$link) {
            return $this->error('链接不存在或已失效');
        }

        // 积分扣减
        $viewCost = (int) config('pan.credit.view_link');
        try {
            app(CreditService::class)->consume(
                $userId,
                $viewCost,
                CreditType::CONSUME,
                $id,
                '查看资源链接'
            );
        } catch (CreditNotEnoughException $e) {
            return $this->error('积分不足', 3001);
        } catch (\Throwable $e) {
            trace('view_link_credit_error: ' . $e->getMessage(), 'error');
            return $this->error('积分扣减失败,请重试');
        }

        // 自增 link_view_count
        try {
            ResourceModel::where('id', (int) $link->resource_id)->inc('link_view_count')->update();
        } catch (\Throwable $e) {
            trace('link_view_count_incr_error: ' . $e->getMessage(), 'error');
        }

        // 写 ResourceReport 记录查看(可选, 失败忽略)
        try {
            ResourceReport::create([
                'resource_id' => (int) $link->resource_id,
                'link_id'     => $id,
                'user_id'     => $userId,
                'type'        => 'view',
                'reason'      => '查看链接',
                'status'      => 0,
            ]);
        } catch (\Throwable $e) {
            trace('view_link_report_error: ' . $e->getMessage(), 'error');
        }

        return $this->success([
            'share_url'    => (string) $link->share_url,
            'extract_code' => (string) $link->extract_code,
        ], 'success');
    }

    /**
     * 资源失效举报
     * POST: reason → ResourceReport::create
     */
    public function report(int $id)
    {
        $reason = (string) $this->request->post('reason', '');
        if ($reason === '') {
            return $this->error('举报原因不能为空');
        }

        $userId = Session::get('user_id');
        $uid    = $userId ? (int) $userId : 0;

        try {
            ResourceReport::create([
                'resource_id' => $id,
                'user_id'     => $uid,
                'reason'      => $reason,
                'status'      => 0,
            ]);
        } catch (\Throwable $e) {
            trace('resource_report_error: ' . $e->getMessage(), 'error');
            return $this->error('举报提交失败,请稍后重试');
        }

        return $this->success([], '举报已提交,感谢您的反馈');
    }
}
