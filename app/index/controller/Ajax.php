<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\model\ResourceReport;
use app\common\service\AdService;
use think\facade\Session;

/**
 * 公共异步接口
 */
class Ajax extends BaseController
{
    /**
     * 资源失效举报(Ajax)
     * POST: reason → ResourceReport::create → JSON
     */
    public function reportResource(int $id)
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
            trace('ajax_report_error: ' . $e->getMessage(), 'error');
            return $this->error('举报提交失败,请稍后重试');
        }

        return $this->success([], '举报已提交,感谢您的反馈');
    }

    /**
     * 广告曝光上报(Ajax)
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
