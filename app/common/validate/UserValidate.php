<?php
declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/**
 * 用户验证器
 */
class UserValidate extends Validate
{
    protected $rule = [
        'user_id' => 'require|integer|gt:0',
        'amount'  => 'require|integer|gt:0',
        'type'    => 'require|in:1,2',
        'remark'  => 'max:255',
    ];

    protected $message = [
        'user_id.require' => '用户ID不能为空',
        'user_id.integer' => '用户ID必须为整数',
        'user_id.gt'      => '用户ID必须大于0',
        'amount.require'  => '调整积分数量不能为空',
        'amount.integer'  => '调整积分数量必须为整数',
        'amount.gt'       => '调整积分数量必须大于0',
        'type.require'    => '调整类型不能为空',
        'type.in'         => '调整类型非法',
        'remark.max'      => '备注不能超过255个字符',
    ];

    protected $scene = [
        'adjustCredit' => ['user_id', 'amount', 'type', 'remark'],
    ];
}
