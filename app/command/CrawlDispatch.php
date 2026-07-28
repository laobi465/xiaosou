<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 采集任务分发
 * 根据启用的采集任务, 将待采集关键词分发到队列
 */
class CrawlDispatch extends Command
{
    protected function configure()
    {
        $this->setName('crawl:dispatch')
            ->setDescription('分发采集任务到队列');
    }

    protected function execute(Input $input, Output $output)
    {
        // TODO: 查询 crawl_tasks 表中 enabled=1 且 next_run_at <= now 的任务
        //       分发到队列 crawl_queue, 由 crawl:consume 消费
        $output->writeln('<info>crawl:dispatch - TODO: 采集任务分发逻辑待实现</info>');
    }
}
