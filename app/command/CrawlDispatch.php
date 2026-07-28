<?php
declare(strict_types=1);

namespace app\command;

use app\common\service\CrawlerService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 采集任务分发
 *
 * 扫描 crawl_tasks 表中到期(enabled=1 且 next_run_at <= now)的任务,
 * 投递到 crawl 队列, 由 crawl:consume 消费。
 *
 * 适用于 crontab 每分钟执行:
 *   * * * * * php think crawl:dispatch
 */
class CrawlDispatch extends Command
{
    protected function configure()
    {
        $this->setName('crawl:dispatch')
            ->setDescription('分发到期采集任务到队列');
    }

    protected function execute(Input $input, Output $output)
    {
        $service = app(CrawlerService::class);
        $count   = $service->dispatchDueTasks();

        $output->writeln('<info>已分发 ' . $count . ' 个采集任务</info>');
    }
}
