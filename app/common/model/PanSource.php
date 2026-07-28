<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 网盘源配置
 * 表: pan_sources
 * is_mainstream: 1主流 0小众; enabled: 1启用 0禁用
 */
class PanSource extends Model
{
    protected $name = 'pan_sources';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'is_mainstream' => 'int',
        'enabled'       => 'int',
        'sort'          => 'int',
    ];

    /**
     * 一对多: 资源链接
     */
    public function links(): \think\model\relation\HasMany
    {
        return $this->hasMany(ResourceLink::class, 'pan_source_id');
    }

    /**
     * 一对多: 采集任务
     */
    public function crawlTasks(): \think\model\relation\HasMany
    {
        return $this->hasMany(CrawlTask::class, 'pan_source_id');
    }

    /**
     * 查询范围: 已启用
     */
    public function scopeEnabled($query)
    {
        return $query->where('enabled', 1);
    }
}
