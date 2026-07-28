<?php
declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/**
 * 订单验证器
 */
class OrderValidate extends Validate
{
    protected $rule = [
        'order_id' => 'require|integer|gt:0',
        'trade_no' => 'require|max:64',
    ];

    protected $message = [
        'order_id.require' => '订单ID不能为空',
        'order_id.integer' => '订单ID必须为整数',
        'order_id.gt'      => '订单ID必须大于0',
        'trade_no.require' => '交易号不能为空',
        'trade_no.max'     => '交易号不能超过64个字符',
    ];

    protected $scene = [
        'manualComplete' => ['order_id', 'trade_no'],
    ];
}
