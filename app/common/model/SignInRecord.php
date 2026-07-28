<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 签到记录
 * 表: sign_in_records (仅 create_time)
 */
class SignInRecord extends Model
{
    protected $name = 'sign_in_records';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;

    protected $type = [
        'continuous_days' => 'int',
        'credit_amount'    => 'int',
    ];

    /**
     * 反向关联: 所属用户
     */
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
