<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 资源类型
 * 对应 resources.resource_type: 1影视 2音乐 3软件 4文档 5图片 6压缩包 7其他
 */
class ResourceType
{
    public const VIDEO    = 1;
    public const MUSIC    = 2;
    public const SOFTWARE = 3;
    public const DOCUMENT = 4;
    public const IMAGE    = 5;
    public const ARCHIVE  = 6;
    public const OTHER    = 7;

    /**
     * 类型与文案映射
     */
    private const MAP = [
        self::VIDEO    => '影视',
        self::MUSIC    => '音乐',
        self::SOFTWARE => '软件',
        self::DOCUMENT => '文档',
        self::IMAGE    => '图片',
        self::ARCHIVE  => '压缩包',
        self::OTHER    => '其他',
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

    /**
     * 校验类型是否合法
     */
    public static function isValid(int $type): bool
    {
        return array_key_exists($type, self::MAP);
    }
}
