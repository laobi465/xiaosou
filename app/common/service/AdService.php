<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\enum\AdSlotCode;

/**
 * 广告服务
 *
 * 参见架构设计文档 3.6 节。
 *
 * 关键设计:
 *   - 按 slot_code 查询 status=1 且在投放时段内的广告
 *   - 按 weight 加权随机抽取 N 个,缓存 1 分钟
 *   - 展示/点击异步累加到 Redis Hash(ad:imp:{date} / ad:click:{date})
 *   - think ad:agg 每天 0 点归档到 ad_stats
 */
class AdService
{
    /**
     * 获取广告投放列表
     *
     * @param string $slotCode 广告位代码 @see AdSlotCode
     * @return array 广告投放数组
     */
    public function getPlacements(string $slotCode): array
    {
        // TODO: 1. slot_id = AdSlot::where('code', $slotCode)->id
        // TODO: 2. 查询 status=1 且在投放时段内的广告
        // TODO: 3. 按 weight 加权随机抽取 N 个
        // TODO: 4. 缓存 1 分钟(避免每次查询)
        return [];
    }

    /**
     * 记录广告展示
     *
     * @param int $placementId 广告投放ID
     * @return void
     */
    public function impression(int $placementId): void
    {
        // TODO: 异步 Redis HINCRBY ad:imp:{date} {placementId} 1
    }

    /**
     * 记录广告点击
     *
     * @param int $placementId 广告投放ID
     * @return void
     */
    public function click(int $placementId): void
    {
        // TODO: 异步 Redis HINCRBY ad:click:{date} {placementId} 1
        // TODO: 跳转 link_url
    }

    /**
     * 每日归档:将 Redis 数据归档到 ad_stats 表
     *
     * @return void
     */
    public function aggregateDaily(): void
    {
        // TODO: think ad:agg 每天 0 点执行
        // TODO: 读取 ad:imp:{yesterday} / ad:click:{yesterday} 写入 ad_stats
    }
}
