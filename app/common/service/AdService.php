<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\enum\AdSlotCode;
use app\common\model\AdSlot;
use app\common\model\AdPlacement;
use app\common\model\AdStat;
use think\facade\Cache;
use think\facade\Db;
use think\cache\driver\Redis as RedisCacheDriver;

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
    /** 投放列表缓存 key 前缀 */
    protected const SLOT_CACHE_PREFIX = 'ad:slot:';

    /** 投放列表缓存 TTL(秒) */
    protected const SLOT_CACHE_TTL = 60;

    /** 展示计数 Redis Hash key 前缀 */
    protected const IMP_KEY_PREFIX = 'ad:imp:';

    /** 点击计数 Redis Hash key 前缀 */
    protected const CLICK_KEY_PREFIX = 'ad:click:';

    /** 计数 Hash TTL(秒,2 天,确保跨夜归档前不过期) */
    protected const HASH_TTL = 86400 * 2;

    /**
     * 获取广告投放列表
     *
     * @param string $slotCode 广告位代码 @see AdSlotCode
     * @return array 广告投放数组
     */
    public function getPlacements(string $slotCode): array
    {
        $cacheKey = self::SLOT_CACHE_PREFIX . $slotCode;

        // 1. 缓存命中检查
        try {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable $e) {
            trace('ad_slot_cache_read_error: ' . $e->getMessage(), 'error');
        }

        try {
            // 2. 查询广告位(enabled=1)
            $slot = AdSlot::where('code', $slotCode)
                ->where('enabled', 1)
                ->find();
            if (!$slot) {
                return [];
            }

            $slotId    = (int) $slot->id;
            $maxCount  = (int) $slot->max_count;
            $now       = date('Y-m-d H:i:s');

            // 3. 查询 status=1 且在投放时段内的广告
            $placements = AdPlacement::where('slot_id', $slotId)
                ->where('status', 1)
                ->where('start_at', '<=', $now)
                ->where('end_at', '>=', $now)
                ->field('id, title, image_url, link_url, weight')
                ->select()
                ->toArray();

            if (empty($placements) || $maxCount <= 0) {
                $result = [];
            } else {
                // 4. 按 weight 加权随机抽取(不放回抽样)
                $picked = $this->weightedSample($placements, $maxCount);

                // 5. 整理输出结构
                $result = [];
                foreach ($picked as $item) {
                    $result[] = [
                        'id'        => (int) $item['id'],
                        'title'     => (string) $item['title'],
                        'image_url' => (string) $item['image_url'],
                        'link_url'  => (string) $item['link_url'],
                    ];
                }
            }

            // 6. 写缓存(失败降级,不影响返回)
            try {
                Cache::set($cacheKey, $result, self::SLOT_CACHE_TTL);
            } catch (\Throwable $e) {
                trace('ad_slot_cache_write_error: ' . $e->getMessage(), 'error');
            }

            return $result;
        } catch (\Throwable $e) {
            trace('ad_get_placements_error: ' . $e->getMessage(), 'error');
            return [];
        }
    }

    /**
     * 记录广告展示
     *
     * @param int $placementId 广告投放ID
     * @return void
     */
    public function impression(int $placementId): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        try {
            $key = self::IMP_KEY_PREFIX . date('Y-m-d');
            $redis->hIncrBy($key, (string) $placementId, 1);
            $redis->expire($key, self::HASH_TTL);
        } catch (\Throwable $e) {
            trace('ad_impression_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 记录广告点击并返回跳转地址
     *
     * @param int $placementId 广告投放ID
     * @return array{link_url: string}
     */
    public function click(int $placementId): array
    {
        try {
            // 查询投放获取 link_url
            $placement = AdPlacement::where('id', $placementId)->find();
            if (!$placement) {
                return ['link_url' => ''];
            }

            $linkUrl = (string) $placement->link_url;

            // Redis HINCRBY 累加点击(失败降级,不影响跳转)
            $redis = $this->redis();
            if ($redis !== null) {
                try {
                    $key = self::CLICK_KEY_PREFIX . date('Y-m-d');
                    $redis->hIncrBy($key, (string) $placementId, 1);
                    $redis->expire($key, self::HASH_TTL);
                } catch (\Throwable $e) {
                    trace('ad_click_incr_error: ' . $e->getMessage(), 'error');
                }
            }

            return ['link_url' => $linkUrl];
        } catch (\Throwable $e) {
            trace('ad_click_error: ' . $e->getMessage(), 'error');
            return ['link_url' => ''];
        }
    }

    /**
     * 每日归档:将 Redis 数据归档到 ad_stats 表
     *
     * 供 ad:agg 命令每天 0 点调用。
     *
     * @return void
     */
    public function aggregateDaily(): void
    {
        try {
            $redis = $this->redis();
            if ($redis === null) {
                return;
            }

            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $impKey    = self::IMP_KEY_PREFIX . $yesterday;
            $clickKey  = self::CLICK_KEY_PREFIX . $yesterday;

            // 1. 读取前日展示/点击 Hash
            $impData   = [];
            $clickData = [];
            try {
                $rows = $redis->hGetAll($impKey);
                if (is_array($rows)) {
                    foreach ($rows as $pid => $cnt) {
                        $impData[(int) $pid] = (int) $cnt;
                    }
                }
            } catch (\Throwable $e) {
                trace('ad_agg_imp_read_error: ' . $e->getMessage(), 'error');
            }
            try {
                $rows = $redis->hGetAll($clickKey);
                if (is_array($rows)) {
                    foreach ($rows as $pid => $cnt) {
                        $clickData[(int) $pid] = (int) $cnt;
                    }
                }
            } catch (\Throwable $e) {
                trace('ad_agg_click_read_error: ' . $e->getMessage(), 'error');
            }

            // 2. 合并所有 placement_id
            $placementIds = array_unique(
                array_merge(array_keys($impData), array_keys($clickData))
            );

            if (!empty($placementIds)) {
                $statTable      = (new AdStat())->getTable();
                $placementTable = (new AdPlacement())->getTable();
                $now            = date('Y-m-d H:i:s');

                foreach ($placementIds as $placementId) {
                    $placementId = (int) $placementId;
                    $imp         = $impData[$placementId] ?? 0;
                    $click       = $clickData[$placementId] ?? 0;

                    // upsert AdStat(UNIQUE uk_placement_date 触发 ON DUPLICATE KEY UPDATE)
                    try {
                        Db::execute(
                            'INSERT INTO `' . $statTable . '` (placement_id, stat_date, impressions, clicks, create_time) '
                            . 'VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE '
                            . 'impressions = VALUES(impressions), clicks = VALUES(clicks)',
                            [$placementId, $yesterday, $imp, $click, $now]
                        );
                    } catch (\Throwable $e) {
                        trace('ad_agg_stat_upsert_error pid=' . $placementId . ': ' . $e->getMessage(), 'error');
                    }

                    // 同步累加 AdPlacement.impressions/clicks
                    try {
                        Db::execute(
                            'UPDATE `' . $placementTable . '` '
                            . 'SET impressions = impressions + ?, clicks = clicks + ? WHERE id = ?',
                            [$imp, $click, $placementId]
                        );
                    } catch (\Throwable $e) {
                        trace('ad_agg_placement_incr_error pid=' . $placementId . ': ' . $e->getMessage(), 'error');
                    }
                }
            }

            // 3. 完成后清理 Redis Hash
            try {
                $redis->del([$impKey, $clickKey]);
            } catch (\Throwable $e) {
                trace('ad_agg_del_error: ' . $e->getMessage(), 'error');
            }
        } catch (\Throwable $e) {
            trace('aggregate_daily_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 加权随机不放回抽样
     *
     * 累计权重法:每次按剩余候选的 weight 计算总权重,
     * random_int(1, totalWeight) 落点决定中选项;选中后从候选列表移除并重新计算。
     *
     * @param array $candidates 候选列表,每个元素含 weight 字段
     * @param int   $count      抽取数量
     * @return array 抽中的元素列表
     */
    protected function weightedSample(array $candidates, int $count): array
    {
        $pool = array_values($candidates);
        $n    = min($count, count($pool));

        $selected = [];
        for ($i = 0; $i < $n; $i++) {
            $totalWeight = 0;
            $poolCount   = count($pool);
            for ($j = 0; $j < $poolCount; $j++) {
                $totalWeight += (int) ($pool[$j]['weight'] ?? 0);
            }
            if ($totalWeight <= 0) {
                break;
            }

            $rand         = random_int(1, $totalWeight);
            $pickedIndex  = $poolCount - 1; // 兜底:循环未命中时取末位
            for ($j = 0; $j < $poolCount; $j++) {
                $w = (int) ($pool[$j]['weight'] ?? 0);
                if ($rand <= $w) {
                    $pickedIndex = $j;
                    break;
                }
                $rand -= $w;
            }

            $selected[] = $pool[$pickedIndex];
            array_splice($pool, $pickedIndex, 1);
        }

        return $selected;
    }

    /**
     * 获取底层 Redis 实例
     */
    protected function redis(): ?\Redis
    {
        try {
            $store = Cache::store('redis');
            if ($store instanceof RedisCacheDriver) {
                $handler = $store->handler();
                if ($handler instanceof \Redis) {
                    return $handler;
                }
            }
            // 兜底:部分版本通过 handler() 返回 \Redis
            if (method_exists($store, 'handler')) {
                $handler = $store->handler();
                return $handler instanceof \Redis ? $handler : null;
            }
        } catch (\Throwable $e) {
            trace('ad_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }
}
