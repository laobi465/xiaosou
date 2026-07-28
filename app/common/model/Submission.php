<?php
declare(strict_types=1);

namespace app\common\model;

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
        'reviewer_id'   => 'int',
        'resource_id'   => 'int',
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
     * 查询范围: 已通过
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 1);
    }
}
