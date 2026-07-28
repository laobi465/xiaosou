<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\CrawlTask;

/**
 * 采集服务
 *
 * 参见架构设计文档 3.5 节。
 *
 * 调度策略:
 *   - think crawl:dispatch 每分钟扫描 crawl_tasks 表,next_run_at <= now 的任务入队
 *   - think crawl:consume 多进程并行(按网盘源隔离队列通道)
 *   - 单源采集失败不影响其他源
 */
class CrawlerService
{
    /**
     * 投递采集任务到队列
     *
     * @param CrawlTask $task 采集任务
     * @return void
     */
    public function dispatch(CrawlTask $task): void
    {
        // TODO: 1. 校验任务 enabled=1
        // TODO: 2. 投递到 crawl 队列(按 pan_source 隔离通道)
        // TODO: 3. 更新 next_run_at = now + frequency
    }

    /**
     * 执行采集任务(队列消费入口)
     *
     * @param CrawlTask $task 采集任务
     * @return void
     */
    public function execute(CrawlTask $task): void
    {
        // TODO: 1. 通过 pan_sources.crawler_class 反射实例化采集器
        // TODO: 2. 调用 crawl() 获取结果
        // TODO: 3. 敏感词过滤(SensitiveFilter)
        // TODO: 4. URL hash 去重(resource_links.url_hash UNIQUE)
        // TODO: 5. 入库 resource_links + 关联 resources
        // TODO: 6. 写 crawl_logs
        // TODO: 7. 失败重试 3 次
    }
}
