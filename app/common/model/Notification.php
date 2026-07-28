<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 站内通知
 * 表: notifications (仅 create_time)
 * 类型: 1系统 2提交审核 3积分变动 4订单; is_read: 1已读 0未读
 */
class Notification extends Model
{
    protected $name = 'notifications';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'user_id' => 'int',
        'type'    => 'int',
        'is_read' => 'int',
    ];

    /**
     * 反向关联: 接收用户
     */
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
