<?php
declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/**
 * 资源验证器
 */
class ResourceValidate extends Validate
{
    protected $rule = [
        'title'         => 'require|max:255',
        'resource_type' => 'require|integer|in:1,2,3,4,5,6,7',
    ];

    protected $message = [
        'title.require'         => '资源标题不能为空',
        'title.max'             => '资源标题不能超过255个字符',
        'resource_type.require' => '资源类型不能为空',
        'resource_type.integer' => '资源类型必须为整数',
        'resource_type.in'      => '资源类型非法',
    ];

    protected $scene = [
        'add'  => ['title', 'resource_type'],
        'edit' => ['title', 'resource_type'],
    ];
}
