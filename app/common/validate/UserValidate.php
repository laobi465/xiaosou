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
        'user_id'  => 'require|integer|gt:0',
        'amount'   => 'require|integer|gt:0',
        'type'     => 'require|in:1,2',
        'remark'   => 'max:255',
        // nickname / avatar 在 profile 场景为可选字段, 仅在提供时校验
        'nickname' => 'max:20',
        'avatar'   => 'avatarScheme|max:255',
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
        'nickname.max'    => '昵称不能超过20个字符',
        'avatar.avatarScheme' => '头像必须为 http/https 协议的 URL',
        'avatar.max'      => '头像地址不能超过255个字符',
    ];

    protected $scene = [
        'adjustCredit' => ['user_id', 'amount', 'type', 'remark'],
        'profile'      => ['nickname', 'avatar'],
    ];

    /**
     * 自定义验证规则: 校验头像 URL 协议白名单(http/https)
     * 防止 javascript:、data: 等协议触发 XSS
     * 空值跳过(可选字段)
     *
     * @param mixed      $value 字段值
     * @param mixed      $rule  验证规则
     * @param array      $data  数据
     * @return bool true=通过
     */
    protected function avatarScheme($value, $rule, array $data = []): bool
    {
        if (!is_string($value) || $value === '') {
            // 空值跳过(profile 场景 avatar 可选)
            return true;
        }
        $parsed = parse_url($value);
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        return in_array($scheme, ['http', 'https'], true);
    }
}
