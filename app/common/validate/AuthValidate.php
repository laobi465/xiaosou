<?php
declare(strict_types=1);

namespace app\common\validate;

use think\Validate;

/**
 * 鉴权验证器
 * 验证码发送 / 登录 / 注册
 */
class AuthValidate extends Validate
{
    protected $rule = [
        'email' => 'require|email',
        'type'  => 'require|in:1,2,3',
        'code'  => 'require|length:6',
    ];

    protected $message = [
        'email.require' => '邮箱不能为空',
        'email.email'   => '邮箱格式不正确',
        'type.require'  => '验证码类型不能为空',
        'type.in'       => '验证码类型非法',
        'code.require'  => '验证码不能为空',
        'code.length'   => '验证码长度必须为6位',
    ];

    protected $scene = [
        'sendCode'   => ['email', 'type'],
        'doLogin'    => ['email', 'code'],
        'doRegister' => ['email', 'code'],
    ];
}
