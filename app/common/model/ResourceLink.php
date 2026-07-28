<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 资源链接(一个资源可多源)
 * 表: resource_links (仅 create_time)
 * 状态: 1有效 0失效
 */
class ResourceLink extends Model
{
    protected $name = 'resource_links';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'resource_id'   => 'int',
        'pan_source_id' => 'int',
        'status'        => 'int',
    ];

    /**
     * 反向关联: 所属资源
     */
    public function resource(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(Resource::class, 'resource_id');
    }

    /**
     * 反向关联: 所属网盘源
     */
    public function panSource(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(PanSource::class, 'pan_source_id');
    }

    /**
     * 查询范围: 有效链接
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 1);
    }
}
