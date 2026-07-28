<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 提交状态
 * 对应 submissions.status: 0待审 1通过 2驳回
 */
class SubmissionStatus
{
    public const PENDING  = 0;
    public const APPROVED = 1;
    public const REJECTED = 2;

    /**
     * 状态与文案映射
     */
    private const MAP = [
        self::PENDING  => '待审',
        self::APPROVED => '通过',
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
