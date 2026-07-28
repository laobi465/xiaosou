<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 管理员操作日志(按月分表预留)
 * 表: admin_logs (仅 create_time)
 * module: resource/user/order/ad/config; action: create/update/delete
 */
class AdminLog extends Model
{
    protected $name = 'admin_logs';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'admin_id' => 'int',
        // target_id 可空(部分操作无目标), 不强制 int cast 避免 null → 0
    ];

    /**
     * 反向关联: 操作管理员
     */
    public function admin(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_id');
    }
}
