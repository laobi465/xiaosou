<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 支付日志事件
 * 对应 payment_logs.event: create/notify/sync/refund/close
 */
class PaymentLogEvent
{
    public const CREATE = 'create'; // 创建订单
    public const NOTIFY = 'notify'; // 异步回调
    public const SYNC   = 'sync';   // 主动同步
    public const REFUND = 'refund'; // 退款
    public const CLOSE  = 'close';  // 关闭订单

    /**
     * 事件与文案映射
     */
    private const MAP = [
        self::CREATE => '创建',
        self::NOTIFY => '回调',
        self::SYNC   => '同步',
        self::REFUND => '退款',
        self::CLOSE  => '关闭',
    ];

    /**
     * 获取事件文案
     */
    public static function label(string $event): string
    {
        return self::MAP[$event] ?? $event;
    }

    /**
     * 获取全部事件映射
     */
    public static function all(): array
    {
        return self::MAP;
    }

    /**
     * 校验事件是否合法
     */
    public static function isValid(string $event): bool
    {
        return array_key_exists($event, self::MAP);
    }
}
