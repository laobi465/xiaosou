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

    /**
     * 字段白名单: 防止误写入不存在的字段(如 type)
     */
    protected $field = [
        'resource_id', 'link_id', 'user_id', 'handler_id',
        'reason', 'status', 'create_time', 'update_time',
    ];

    protected $type = [
        'resource_id' => 'int',
        'user_id'     => 'int',
        'status'      => 'int',
        // link_id / handler_id 可空(举报未指定具体链接 / 未处理时无 handler), 不强制 int cast 避免 null → 0
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
     * 查询范围: 待处理(status=0)
     * 注意: 举报状态语义为 0待处理 1已确认失效 2已忽略,
     *       与全项目 status=1 正常态约定不同, 故不提供 scopeNormal。
     */
    public function scopePending($query)
    {
        return $query->where('status', 0);
    }
}
