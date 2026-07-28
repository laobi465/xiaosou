<?php
declare(strict_types=1);

namespace app\common\service;

/**
 * 统计服务
 *
 * 提供后台仪表盘聚合数据。
 */
class StatService
{
    /**
     * 后台仪表盘数据
     *
     * @return array 仪表盘统计指标
     */
    public function dashboard(): array
    {
        // TODO: 聚合以下指标(可缓存 5 分钟):
        //   - 资源总数 / 今日新增 / 待审数
        //   - 用户总数 / 今日新增 / 活跃
        //   - 今日搜索量 / 热搜词 TOP10
        //   - 今日订单数 / 收入
        //   - 采集任务状态 / 失败数
        //   - 广告展示/点击数
        return [];
    }
}
