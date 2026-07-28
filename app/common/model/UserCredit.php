<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 用户积分余额
 * 表: user_credits (仅 update_time)
 */
class UserCredit extends Model
{
    protected $name = 'user_credits';

    protected $autoWriteTimestamp = 'datetime';

    // 该表无 create_time 字段
    protected $createTime = false;
    protected $updateTime = 'update_time';

    protected $type = [
        'balance'        => 'int',
        'total_recharge' => 'int',
        'total_consume'  => 'int',
        'total_reward'   => 'int',
        'version'        => 'int',
    ];

    /**
     * 反向关联: 所属用户
     */
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
