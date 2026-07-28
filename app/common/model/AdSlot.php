<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 广告位
 * 表: ad_slots
 * code: home_banner/search_top/detail_popup/bottom_float
 * enabled: 1启用 0禁用
 */
class AdSlot extends Model
{
    protected $name = 'ad_slots';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'max_count' => 'int',
        'enabled'   => 'int',
    ];

    /**
     * 一对多: 广告投放
     */
    public function placements(): \think\model\relation\HasMany
    {
        return $this->hasMany(AdPlacement::class, 'slot_id');
    }

    /**
     * 查询范围: 已启用
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', 1);
    }
}
