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
        'intro'         => 'max:2000',
        'status'        => 'integer|in:0,1,2,3',
        'share_url'     => 'max:500|url|regex:^https?://',
        'pan_source_id' => 'integer|gt:0',
        'extract_code'  => 'max:20',
    ];

    protected $message = [
        'title.require'         => '资源标题不能为空',
        'title.max'             => '资源标题不能超过255个字符',
        'resource_type.require' => '资源类型不能为空',
        'resource_type.integer' => '资源类型必须为整数',
        'resource_type.in'      => '资源类型非法',
        'intro.max'             => '资源简介不能超过2000个字符',
        'status.integer'        => '状态必须为整数',
        'status.in'             => '状态取值非法',
        'share_url.max'         => '分享链接不能超过500个字符',
        'share_url.url'         => '分享链接格式不正确',
        'share_url.regex'       => '分享链接仅支持 http/https 协议',
        'pan_source_id.integer' => '网盘来源必须为整数',
        'pan_source_id.gt'      => '网盘来源非法',
        'extract_code.max'      => '提取码不能超过20个字符',
    ];

    protected $scene = [
        'add'  => ['title', 'resource_type', 'intro', 'status'],
        'edit' => ['title', 'resource_type', 'intro', 'status'],
        'link' => ['share_url', 'pan_source_id', 'extract_code'],
    ];
}
