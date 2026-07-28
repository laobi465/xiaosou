<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 订单状态
 * 对应 orders.status: 0待支付 1已支付 2已退款 3已关闭
 */
class OrderStatus
{
    public const PENDING = 0;
    public const PAID    = 1;
    public const REFUND  = 2;
    public const CLOSED  = 3;

    /**
     * 状态与文案映射
     */
    private const MAP = [
        self::PENDING => '待支付',
        self::PAID    => '已支付',
        self::REFUND  => '已退款',
        self::CLOSED  => '已关闭',
    ];

    /**
     * 获取状态文案
     */
    public static function label(int $status): string
    {
        return self::MAP[$status] ?? '未知';
    }

    /**
     * 获取全部状态映射
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
