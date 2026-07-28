<?php
declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/**
 * 系统配置验证器
 */
class ConfigValidate extends Validate
{
    protected $rule = [
        'group'   => 'require|in:smtp,payment,site,credit,security',
        'configs' => 'require|array',
    ];

    protected $message = [
        'group.require'   => '配置分组不能为空',
        'group.in'        => '配置分组非法',
        'configs.require' => '配置数据不能为空',
        'configs.array'   => '配置数据必须为数组',
    ];

    protected $scene = [
        'save' => ['group', 'configs'],
    ];
}
