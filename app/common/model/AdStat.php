<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 广告统计(按天聚合)
 * 表: ad_stats (仅 create_time)
 */
class AdStat extends Model
{
    protected $name = 'ad_stats';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'placement_id' => 'int',
        'impressions'  => 'int',
        'clicks'        => 'int',
    ];

    /**
     * 反向关联: 所属广告投放
     */
    public function placement(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(AdPlacement::class, 'placement_id');
    }
}
