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
     * 隐藏敏感字段: 提取码、分享链接(序列化时不再暴露)
     * 需要输出时通过 visible() 临时开放: $link->visible(['extract_code','share_url'])
     */
    protected $hidden = ['extract_code', 'share_url'];

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
     * 查询范围: 有效链接(语义化,推荐使用)
     */
    public function scopeValid($query)
    {
        return $query->where('status', 1);
    }

    /**
     * 查询范围: 有效链接(兼容旧调用,语义不精确,推荐使用 scopeValid)
     * @deprecated 请使用 scopeValid
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 1);
    }
}
