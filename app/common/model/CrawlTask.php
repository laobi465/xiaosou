<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 采集任务
 * 表: crawl_tasks
 * enabled: 1启用 0禁用
 */
class CrawlTask extends Model
{
    protected $name = 'crawl_tasks';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'pan_source_id' => 'int',
        'frequency'     => 'int',
        'enabled'       => 'int',
    ];

    /**
     * 反向关联: 所属网盘源
     */
    public function panSource(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(PanSource::class, 'pan_source_id');
    }

    /**
     * 一对多: 采集日志
     */
    public function logs(): \think\model\relation\HasMany
    {
        return $this->hasMany(CrawlLog::class, 'task_id');
    }

    /**
     * 查询范围: 已启用
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', 1);
    }
}
