<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 资源失效举报
 * 表: resource_reports (仅 create_time)
 * 状态: 0待处理 1已确认失效 2已忽略
 */
class ResourceReport extends Model
{
    protected $name = 'resource_reports';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'resource_id' => 'int',
        'link_id'     => 'int',
        'user_id'     => 'int',
        'status'      => 'int',
        'handler_id'  => 'int',
    ];

    /**
     * 反向关联: 被举报资源
     */
    public function resource(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }

    /**
     * 反向关联: 具体失效链接
     */
    public function link(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(ResourceLink::class, 'link_id');
    }

    /**
     * 反向关联: 举报人
     */
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 查询范围: 待处理(默认未处理态)
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 0);
    }
}
