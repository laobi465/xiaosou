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
              ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

        return view('submission/index', [
            'list'    => $list,
            'status'  => $status,
            'keyword' => $keyword,
        ]);
    }

    /**
     * 审核通过 → 资源正式入库 + 关联 resource_id + 奖励积分 + 通知
     *
     * 并发安全:
     *   - 审核前校验 url_hash 是否已存在,避免重复入库资源链接
     *   - 事务内通过 where('status', PENDING)->update 原子流转,检查 affected rows
     *   - 仅 affected=1 时才发放奖励积分,避免并发审核重复发奖
     *   - 通知 content 对用户输入的 title 做 htmlspecialchars 转义,防 XSS
     */
    public function approve(int $id)
    {
        $submission = SubmissionModel::find($id);
        if (!$submission) {
            return $this->error('提交记录不存在');
        }

        // 事务外快速校验
        if ((int) $submission->status !== SubmissionStatus::PENDING) {
            return $this->error('该提交已被处理');
        }

        // 审核前校验 url_hash 是否已存在,避免重复入库
        $shareUrl = (string) $submission->share_url;
        $urlHash  = HashHelper::urlHash($shareUrl);
        if (ResourceLink::where('url_hash', $urlHash)->find()) {
            return $this->error('该资源链接已存在,请勿重复审核');
        }

        $reward  = (int) config('pan.credit.submit_reward');
        $adminId = $this->adminId();
        $now     = date('Y-m-d H:i:s');

        // 预提取字段,避免在闭包内持有模型实例导致重复 save
        $title        = (string) $submission->title;
        $intro        = (string) ($submission->intro ?? '');
        $resourceType = (int) $submission->resource_type;
        $userId       = (int) $submission->user_id;
        $panSourceId  = (int) $submission->pan_source_id;
        $extractCode  = (string) ($submission->extract_code ?? '');

        try {
            $approved = false;
            Db::transaction(function () use (
                $id, $title, $intro, $resourceType, $userId, $panSourceId,
                $shareUrl, $extractCode, $urlHash, $reward, $adminId, $now, &$approved
            ) {
                // 1. 创建资源(source_type=2 用户提交, status=1 正常)
                $resource = ResourceModel::create([
                    'title'           => $title,
                    'cover'           => '',
                    'intro'           => $intro,
                    'resource_type'   => $resourceType,
                    'file_size'       => 0,
                    'file_format'     => '',
                    'source_type'     => 2,
                    'submitter_id'    => $userId,
                    'status'          => 1,
                    'view_count'      => 0,
                    'link_view_count' => 0,
                ]);

                // 2. 创建资源链接(url_hash 唯一索引兜底防并发重复)
                ResourceLink::create([
                    'resource_id'   => (int) $resource->id,
                    'pan_source_id' => $panSourceId,
                    'share_url'     => $shareUrl,
                    'extract_code'  => $extractCode,
                    'url_hash'      => $urlHash,
                    'status'        => 1,
                ]);

                // 3. 原子流转: 仅 PENDING → APPROVED,affected=0 表示已被其他请求处理
                $affected = SubmissionModel::where('id', $id)
                    ->where('status', SubmissionStatus::PENDING)
                    ->update([
                        'status'      => SubmissionStatus::APPROVED,
                        'resource_id' => (int) $resource->id,
                        'reviewer_id' => $adminId,
                        'reviewed_at' => $now,
                    ]);

                if ($affected !== 1) {
                    // 并发场景下状态已变更,抛异常回滚已创建的 resource/link
                    throw new \RuntimeException('submission_status_changed');
                }

                // 4. 奖励提交者积分
                if ($reward > 0) {
                    app(CreditService::class)->recharge(
                        $userId,
                        $reward,
                        CreditType::SUBMIT_REWARD,
                        (int) $resource->id,
                        '提交审核通过奖励',
                        $adminId
                    );
                }

                // 5. 站内通知(content 对 title 做 htmlspecialchars 转义防 XSS)
                Notification::create([
                    'user_id' => $userId,
                    'type'    => 2,
                    'title'   => '提交审核通过',
                    'content' => '您提交的资源「' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '」已审核通过,奖励' . $reward . '积分',
                    'is_read' => 0,
                ]);

                $approved = true;
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'submission_status_changed') {
                return $this->error('该提交已被处理');
            }
            return $this->errorWithLog('审核通过失败,请稍后重试', $e, 'submission_approve_error');
        } catch (\Throwable $e) {
            return $this->errorWithLog('审核通过失败,请稍后重试', $e, 'submission_approve_error');
        }

        if (!$approved) {
            return $this->error('该提交已被处理');
        }

        $this->logAction('submission', 'approve', $id, [
            'title'  => $title,
            'reward' => $reward,
        ]);
        return $this->success([], '审核通过');
    }

    /**
     * 驳回 → 记录 reject_reason + 站内通知
     *
     * 并发安全: 用 Db::transaction 包裹 save + Notification::create,
     * 通知 content 对用户输入的 title 做 htmlspecialchars 转义防 XSS。
     */
    public function reject(int $id)
    {
        $submission = SubmissionModel::find($id);
        if (!$submission) {
            return $this->error('提交记录不存在');
        }

        // 事务外快速校验
        if ((int) $submission->status !== SubmissionStatus::PENDING) {
            return $this->error('该提交已被处理');
        }

        $reason = (string) $this->request->post('reason', '');
        if ($reason === '') {
            return $this->error('驳回原因不能为空');
        }

        $adminId = $this->adminId();
        $now     = date('Y-m-d H:i:s');
        $userId  = (int) $submission->user_id;
        $title   = (string) $submission->title;

        try {
            Db::transaction(function () use ($submission, $reason, $adminId, $now, $userId, $title) {
                // 原子流转: 仅 PENDING → REJECTED,affected=0 表示已被其他请求处理
                $affected = SubmissionModel::where('id', $submission->id)
                    ->where('status', SubmissionStatus::PENDING)
                    ->update([
                        'status'        => SubmissionStatus::REJECTED,
                        'reject_reason' => $reason,
                        'reviewer_id'   => $adminId,
                        'reviewed_at'   => $now,
                    ]);

                if ($affected !== 1) {
                    throw new \RuntimeException('submission_status_changed');
                }

                // 站内通知(content 对 title 做 htmlspecialchars 转义防 XSS)
                Notification::create([
                    'user_id' => $userId,
                    'type'    => 2,
                    'title'   => '提交审核未通过',
                    'content' => '您提交的资源「' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '」未通过审核,原因: ' . $reason,
                    'is_read' => 0,
                ]);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'submission_status_changed') {
                return $this->error('该提交已被处理');
            }
            return $this->errorWithLog('驳回失败,请稍后重试', $e, 'submission_reject_error');
        } catch (\Throwable $e) {
            return $this->errorWithLog('驳回失败,请稍后重试', $e, 'submission_reject_error');
        }

        $this->logAction('submission', 'reject', $id, [
            'title'  => $title,
            'reason' => $reason,
        ]);
        return $this->success([], '已驳回');
    }
}
