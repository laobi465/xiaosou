<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 用户登录方式
 * 对应 user_login_logs.login_type: 1验证码 2密码
 */
class UserLoginType
{
    public const VERIFY_CODE = 1; // 验证码登录
    public const PASSWORD    = 2; // 密码登录

    /**
     * 类型与文案映射
     */
    private const MAP = [
        self::VERIFY_CODE => '验证码',
        self::PASSWORD    => '密码',
    ];

    /**
     * 获取类型文案
     */
    public static function label(int $type): string
    {
        return self::MAP[$type] ?? '未知';
    }

    /**
     * 获取全部类型映射
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
