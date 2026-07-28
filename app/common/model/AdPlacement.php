<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 广告投放
 * 表: ad_placements
 * 状态: 1上线 0下线
 */
class AdPlacement extends Model
{
    protected $name = 'ad_placements';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'slot_id'     => 'int',
        'weight'      => 'int',
        'status'      => 'int',
        'impressions' => 'int',
        'clicks'      => 'int',
    ];

    /**
     * 反向关联: 所属广告位
     */
    public function slot(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(AdSlot::class, 'slot_id');
    }

    /**
     * 一对多: 广告统计(按天)
     */
    public function stats(): \think\model\relation\HasMany
    {
        return $this->hasMany(AdStat::class, 'placement_id');
    }

    /**
     * 查询范围: 上线投放(语义化,推荐使用)
     */
    public function scopeOnline($query)
    {
        return $query->where('status', 1);
    }

    /**
     * 查询范围: 上线投放(兼容旧调用,语义不精确,推荐使用 scopeOnline)
     * @deprecated 请使用 scopeOnline
     */
    public function scopeNormal($query)
    {
        return $query->where('status', 1);
    }
}
