<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 积分流水
 * 表: credit_logs (仅 create_time)
 * 类型: 1充值 2消耗 3签到 4注册赠送 5提交奖励 6管理员调整 7退款
 */
class CreditLog extends Model
{
    protected $name = 'credit_logs';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    // 该表无 update_time 字段
    protected $updateTime = false;

    protected $type = [
        'type'          => 'int',
        'amount'        => 'int',
        'balance_after' => 'int',
        // related_id / admin_id 可空(签到/注册赠送等无关联订单或管理员), 不强制 int cast 避免 null → 0
    ];

    /**
     * 反向关联: 所属用户
     */
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
