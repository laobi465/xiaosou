<?php
declare(strict_types=1);

namespace app\common\enum;

/**
 * 广告位代码
 * 对应 ad_slots.code 枚举值
 */
class AdSlotCode
{
    /** 首页 Banner */
    public const HOME_BANNER   = 'home_banner';
    /** 搜索结果置顶 */
    public const SEARCH_TOP    = 'search_top';
    /** 详情页弹窗 */
    public const DETAIL_POPUP  = 'detail_popup';
    /** 底部浮动 */
    public const BOTTOM_FLOAT  = 'bottom_float';

    /**
     * 代码与文案映射
     */
    private const MAP = [
        self::HOME_BANNER  => '首页 Banner',
        self::SEARCH_TOP   => '搜索结果置顶',
        self::DETAIL_POPUP => '详情页弹窗',
        self::BOTTOM_FLOAT => '底部浮动',
    ];

    /**
     * 获取广告位文案
     */
    public static function label(string $code): string
    {
        return self::MAP[$code] ?? '未知';
    }

    /**
     * 获取全部广告位映射
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
