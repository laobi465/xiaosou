<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\enum\CreditType;
use app\common\enum\SubmissionStatus;
use app\common\model\Notification;
use app\common\model\Resource as ResourceModel;
use app\common\model\ResourceLink;
use app\common\model\Submission as SubmissionModel;
use app\common\service\CreditService;
use Pansou\Helper\HashHelper;
use think\facade\Db;

/**
 * 用户提交审核
 */
class Submission extends BaseAdminController
{
    /**
     * 待审列表(status=0)
     */
    public function index()
    {
        $status  = $this->request->get('status', 0);
        $keyword = (string) $this->request->get('keyword', '');

        $list = SubmissionModel::with(['user', 'panSource'])
            ->when($status !== '' && $status !== null, function ($query) use ($status) {
                $query->where('status', (int) $status);
            })->when($keyword !== '', function ($query) use ($keyword) {
                $query->whereLike('title', '%' . $keyword . '%');
            })->order('id', 'desc')
              ->paginate(15, false, ['query' => $this->request->param()]);

        return view('submission/index', [
            'list'    => $list,
            'status'  => $status,
            'keyword' => $keyword,
        ]);
    }

    /**
     * 审核通过 → 资源正式入库 + 关联 resource_id + 奖励积分 + 通知
     */
    public function approve(int $id)
    {
        $submission = SubmissionModel::find($id);
        if (!$submission) {
            return $this->error('提交记录不存在');
        }

        if ((int) $submission->status !== SubmissionStatus::PENDING) {
            return $this->error('该提交已被处理');
        }

        $reward = (int) config('pan.credit.submit_reward');
        $adminId = $this->adminId();

        try {
            Db::transaction(function () use ($submission, $reward, $adminId) {
                // 1. 创建资源(source_type=2 用户提交, status=1 正常)
                $resource = ResourceModel::create([
                    'title'         => (string) $submission->title,
                    'cover'         => '',
                    'intro'         => (string) ($submission->intro ?? ''),
                    'resource_type' => (int) $submission->resource_type,
                    'file_size'     => 0,
                    'file_format'   => '',
                    'source_type'   => 2,
                    'submitter_id'  => (int) $submission->user_id,
                    'status'        => 1,
                    'view_count'    => 0,
                    'link_view_count' => 0,
                ]);

                // 2. 创建资源链接
                ResourceLink::create([
                    'resource_id'   => (int) $resource->id,
                    'pan_source_id' => (int) $submission->pan_source_id,
                    'share_url'     => (string) $submission->share_url,
                    'extract_code'  => (string) ($submission->extract_code ?? ''),
                    'url_hash'      => HashHelper::urlHash((string) $submission->share_url),
                    'status'        => 1,
                ]);

                // 3. 关联 submission.resource_id + 更新审核状态
                $submission->resource_id  = (int) $resource->id;
                $submission->status       = SubmissionStatus::APPROVED;
                $submission->reviewer_id  = $adminId;
                $submission->reviewed_at  = date('Y-m-d H:i:s');
                $submission->save();

                // 4. 奖励提交者积分
                if ($reward > 0) {
                    app(CreditService::class)->recharge(
                        (int) $submission->user_id,
                        $reward,
                        CreditType::SUBMIT_REWARD,
                        (int) $resource->id,
                        '提交审核通过奖励',
                        $adminId
                    );
                }

                // 5. 站内通知
                Notification::create([
                    'user_id' => (int) $submission->user_id,
                    'type'    => 2,
                    'title'   => '提交审核通过',
                    'content' => '您提交的资源「' . $submission->title . '」已审核通过,奖励' . $reward . '积分',
                    'is_read' => 0,
                ]);
            });
        } catch (\Throwable $e) {
            return $this->error('审核通过失败: ' . $e->getMessage());
        }

        $this->logAction('submission', 'approve', $id, [
            'title'      => $submission->title,
            'reward'     => $reward,
        ]);
        return $this->success([], '审核通过');
    }

    /**
     * 驳回 → 记录 reject_reason + 站内通知
     */
    public function reject(int $id)
    {
        $submission = SubmissionModel::find($id);
        if (!$submission) {
            return $this->error('提交记录不存在');
        }

        if ((int) $submission->status !== SubmissionStatus::PENDING) {
            return $this->error('该提交已被处理');
        }

        $reason = (string) $this->request->post('reason', '');
        if ($reason === '') {
            return $this->error('驳回原因不能为空');
        }

        try {
            $submission->status         = SubmissionStatus::REJECTED;
            $submission->reject_reason  = $reason;
            $submission->reviewer_id    = $this->adminId();
            $submission->reviewed_at    = date('Y-m-d H:i:s');
            $submission->save();

            Notification::create([
                'user_id' => (int) $submission->user_id,
                'type'    => 2,
                'title'   => '提交审核未通过',
                'content' => '您提交的资源「' . $submission->title . '」未通过审核,原因: ' . $reason,
                'is_read' => 0,
            ]);
        } catch (\Throwable $e) {
            return $this->error('驳回失败: ' . $e->getMessage());
        }

        $this->logAction('submission', 'reject', $id, [
            'title'  => $submission->title,
            'reason' => $reason,
        ]);
        return $this->success([], '已驳回');
    }
}
