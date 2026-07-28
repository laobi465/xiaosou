<?php
declare(strict_types=1);

namespace app\common\model;

use app\common\enum\SubmissionStatus;
use think\Model;

/**
 * 用户提交记录
 * 表: submissions
 * 状态: 0待审 1通过 2驳回
 */
class Submission extends Model
{
    protected $name = 'submissions';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'user_id'       => 'int',
        'pan_source_id' => 'int',
        'resource_type' => 'int',
        'status'        => 'int',
        // reviewer_id / resource_id 可空(待审核无审核人 / 未通过无资源), 不强制 int cast 避免 null → 0
    ];

    /**
     * 反向关联: 提交用户
     */
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 反向关联: 网盘源
     */
    public function panSource(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(PanSource::class, 'pan_source_id');
    }

    /**
     * 反向关联: 审核通过后生成的资源
     */
    public function resource(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }

    /**
     * 查询范围: 已通过(语义化,推荐使用)
     */
    public function scopeApproved($query)
    {
        return $query->where('status', SubmissionStatus::APPROVED);
    }

    /**
     * 查询范围: 待审核
     */
    public function scopePending($query)
    {
        return $query->where('status', SubmissionStatus::PENDING);
    }

    /**
     * 查询范围: 已通过(兼容旧调用,语义不精确,推荐使用 scopeApproved)
     * @deprecated 请使用 scopeApproved
     */
    public function scopeNormal($query)
    {
        return $query->where('status', SubmissionStatus::APPROVED);
    }
}
