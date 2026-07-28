<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 采集日志(按月分表预留)
 * 表: crawl_logs (仅 create_time)
 * 状态: 1成功 0失败
 */
class CrawlLog extends Model
{
    protected $name = 'crawl_logs';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'task_id'       => 'int',
        'pan_source_id' => 'int',
        'status'        => 'int',
        'found_count'   => 'int',
        'new_count'     => 'int',
        'duration_ms'   => 'int',
    ];

    /**
     * 反向关联: 所属采集任务
     */
    public function task(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(CrawlTask::class, 'task_id');
    }

    /**
     * 反向关联: 所属网盘源
     */
    public function panSource(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(PanSource::class, 'pan_source_id');
    }
}
