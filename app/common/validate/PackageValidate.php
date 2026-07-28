<?php
declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/**
 * 积分套餐验证器
 */
class PackageValidate extends Validate
{
    protected $rule = [
        'name'           => 'require|max:50',
        'price'          => 'require|float|egt:0',
        'credits'        => 'require|integer|gt:0',
        'bonus'          => 'integer|egt:0',
        'is_recommended' => 'in:0,1',
        'status'         => 'in:0,1',
        'sort'           => 'integer',
    ];

    protected $message = [
        'name.require'           => '套餐名称不能为空',
        'name.max'               => '套餐名称不能超过50个字符',
        'price.require'          => '套餐价格不能为空',
        'price.float'            => '套餐价格必须为数字',
        'price.egt'              => '套餐价格不能小于0',
        'credits.require'        => '积分数量不能为空',
        'credits.integer'        => '积分数量必须为整数',
        'credits.gt'             => '积分数量必须大于0',
        'bonus.integer'          => '赠送积分必须为整数',
        'bonus.egt'              => '赠送积分不能小于0',
        'is_recommended.in'      => '是否推荐标识非法',
        'status.in'              => '状态值非法',
        'sort.integer'           => '排序必须为整数',
    ];

    protected $scene = [
        'add'  => ['name', 'price', 'credits', 'bonus', 'is_recommended', 'status', 'sort'],
        'edit' => ['name', 'price', 'credits', 'bonus', 'is_recommended', 'status', 'sort'],
    ];
}
