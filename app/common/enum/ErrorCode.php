<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 错误码常量
 * 统一响应格式: code=0 成功, 非0=错误码
 */
class ErrorCode
{
    public const SUCCESS                = 0;
    public const PARAM_ERROR            = 1001;
    public const UNAUTHORIZED           = 1002;
    public const FORBIDDEN              = 1003;
    public const VERIFY_CODE_ERROR      = 2001;
    public const VERIFY_CODE_TOO_FREQUENT = 2002;
    public const CREDIT_NOT_ENOUGH      = 3001;
    public const ORDER_EXPIRED          = 3002;
    public const RESOURCE_NOT_FOUND     = 4001;
    public const RESOURCE_INVALID       = 4002;
    public const SYSTEM_BUSY            = 5000;

    /**
     * 错误码与文案映射
     */
    private const MAP = [
        self::SUCCESS                  => 'success',
        self::PARAM_ERROR              => '参数错误',
        self::UNAUTHORIZED             => '未登录',
        self::FORBIDDEN                => '权限不足',
        self::VERIFY_CODE_ERROR        => '验证码错误或已过期',
        self::VERIFY_CODE_TOO_FREQUENT => '验证码发送频繁',
        self::CREDIT_NOT_ENOUGH        => '积分不足',
        self::ORDER_EXPIRED            => '订单已过期',
        self::RESOURCE_NOT_FOUND       => '资源不存在',
        self::RESOURCE_INVALID         => '资源已失效',
        self::SYSTEM_BUSY              => '系统繁忙',
    ];

    /**
     * 根据错误码获取 [code, message]
     *
     * @return array{0:int,1:string} [code, message]
     */
    public static function get(int $code): array
    {
        return [$code, self::MAP[$code] ?? '未知错误'];
    }

    /**
     * 根据错误码获取文案
     */
    public static function message(int $code): string
    {
        return self::MAP[$code] ?? '未知错误';
    }
}
