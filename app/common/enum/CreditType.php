<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 积分流水类型
 * 对应 credit_logs.type: 1充值 2消耗 3签到 4注册赠送 5提交奖励 6管理员调整 7退款
 */
class CreditType
{
    public const RECHARGE      = 1;
    public const CONSUME       = 2;
    public const SIGN_IN       = 3;
    public const REGISTER_GIFT = 4;
    public const SUBMIT_REWARD = 5;
    public const ADMIN_ADJUST  = 6;
    public const REFUND        = 7;

    /**
     * 类型与文案映射
     */
    private const MAP = [
        self::RECHARGE      => '充值',
        self::CONSUME       => '消耗',
        self::SIGN_IN       => '签到',
        self::REGISTER_GIFT => '注册赠送',
        self::SUBMIT_REWARD => '提交奖励',
        self::ADMIN_ADJUST  => '管理员调整',
        self::REFUND        => '退款',
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
