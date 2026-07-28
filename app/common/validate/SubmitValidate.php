<?php
declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/**
 * 资源提交验证器
 */
class SubmitValidate extends Validate
{
    protected $rule = [
        'title'          => 'require|max:255',
        'share_url'      => 'require|url|max:500',
        'pan_source_id'  => 'require|integer|gt:0',
        'resource_type'  => 'require|integer|in:1,2,3,4,5,6,7',
        'extract_code'   => 'max:20',
        'intro'          => 'max:2000',
    ];

    protected $message = [
        'title.require'         => '资源标题不能为空',
        'title.max'             => '资源标题不能超过255个字符',
        'share_url.require'     => '分享链接不能为空',
        'share_url.url'         => '分享链接格式不正确',
        'share_url.max'         => '分享链接不能超过500个字符',
        'pan_source_id.require' => '网盘来源不能为空',
        'pan_source_id.integer' => '网盘来源ID必须为整数',
        'pan_source_id.gt'      => '网盘来源ID必须大于0',
        'resource_type.require' => '资源类型不能为空',
        'resource_type.integer'=> '资源类型必须为整数',
        'resource_type.in'      => '资源类型非法',
        'extract_code.max'      => '提取码不能超过20个字符',
        'intro.max'             => '资源简介不能超过2000个字符',
    ];

    protected $scene = [
        'create' => ['title', 'share_url', 'pan_source_id', 'resource_type', 'extract_code', 'intro'],
    ];
}
