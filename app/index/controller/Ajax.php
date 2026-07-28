<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\model\Resource as ResourceModel;
use app\common\model\ResourceReport;
use app\common\service\AdService;

/**
 * 公共异步接口
 *
 * reportResource 路由层挂载 UserAuth + RateLimit 中间件, 必须登录且限流。
 * adImpression 路由层挂载 RateLimit 中间件。
 */
class Ajax extends BaseController
{
    /**
     * 资源失效举报(Ajax)
     * POST: reason → 校验资源存在 → ResourceReport::create → JSON
     *
     * 路由层已挂 UserAuth 中间件, 控制器内通过 $this->userId() 获取登录态。
     */
    public function reportResource(int $id)
    {
        $reason = (string) $this->request->post('reason', '');
        if ($reason === '') {
            return $this->error('举报原因不能为空');
        }

        // 路由层已挂 UserAuth, 统一用 $this->userId()
        $userId = $this->userId();
        if ($userId === null) {
            return $this->error('请先登录', 1002);
        }

        // 校验资源存在性(防止刷不存在的 resource_id)
        $resource = ResourceModel::find($id);
        if (!$resource) {
            return $this->error('资源不存在');
        }

        try {
            ResourceReport::create([
                'resource_id' => $id,
                'user_id'     => $userId,
                'reason'      => $reason,
                'status'      => 0,
            ]);
        } catch (\Throwable $e) {
            return $this->errorWithLog('举报提交失败,请稍后重试', $e, 'ajax_report_error');
        }

        return $this->success([], '举报已提交,感谢您的反馈');
    }

    /**
     * 广告曝光上报(Ajax)
     *
     * 路由层已挂 RateLimit 中间件(每分钟30次)防刷。
     */
    public function adImpression(int $id)
    {
        try {
            app(AdService::class)->impression($id);
        } catch (\Throwable $e) {
            trace('ajax_ad_impression_error: ' . $e->getMessage(), 'error');
        }
        return $this->success([], 'success');
    }
}
