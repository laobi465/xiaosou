<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 资源状态
 * 对应 resources.status: 1正常 0失效 2待审 3驳回
 */
class ResourceStatus
{
    public const NORMAL   = 1;
    public const INVALID  = 0;
    public const PENDING  = 2;
    public const REJECTED = 3;

    /**
     * 状态与文案映射
     */
    private const MAP = [
        self::NORMAL   => '正常',
        self::INVALID  => '失效',
        self::PENDING  => '待审',
        self::REJECTED => '驳回',
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
