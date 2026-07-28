<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 搜索日志(按月分表预留)
 * 表: search_logs (仅 create_time)
 */
class SearchLog extends Model
{
    protected $name = 'search_logs';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        // user_id 可空(游客搜索), 不强制 int cast 避免 null → 0
        'result_count' => 'int',
        'duration_ms'  => 'int',
    ];

    /**
     * 反向关联: 搜索用户(游客为空)
     */
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
