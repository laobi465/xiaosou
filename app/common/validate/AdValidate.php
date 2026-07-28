<?php
declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/**
 * 广告验证器
 */
class AdValidate extends Validate
{
    protected $rule = [
        'slot_id'   => 'require|integer|gt:0',
        'title'     => 'require|max:100',
        'image_url' => 'require|url|max:255',
        'link_url'  => 'require|url|max:500',
        'start_at'  => 'require|date',
        'end_at'    => 'require|date',
        'weight'    => 'integer|egt:0',
    ];

    protected $message = [
        'slot_id.require'   => '广告位ID不能为空',
        'slot_id.integer'   => '广告位ID必须为整数',
        'slot_id.gt'        => '广告位ID必须大于0',
        'title.require'     => '广告标题不能为空',
        'title.max'         => '广告标题不能超过100个字符',
        'image_url.require' => '广告图片不能为空',
        'image_url.url'     => '广告图片地址格式不正确',
        'image_url.max'     => '广告图片地址不能超过255个字符',
        'link_url.require'  => '广告链接不能为空',
        'link_url.url'      => '广告链接格式不正确',
        'link_url.max'      => '广告链接不能超过500个字符',
        'start_at.require'  => '开始时间不能为空',
        'start_at.date'     => '开始时间格式不正确',
        'end_at.require'    => '结束时间不能为空',
        'end_at.date'       => '结束时间格式不正确',
        'weight.integer'    => '权重必须为整数',
        'weight.egt'        => '权重不能小于0',
    ];

    protected $scene = [
        'create' => ['slot_id', 'title', 'image_url', 'link_url', 'start_at', 'end_at', 'weight'],
        'edit'   => ['slot_id', 'title', 'image_url', 'link_url', 'start_at', 'end_at', 'weight'],
    ];
}
