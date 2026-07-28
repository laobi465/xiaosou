<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/**
 * 采集任务消费
 * 从队列消费采集任务, 调用对应网盘爬虫抓取资源
 */
class CrawlConsume extends Command
{
    protected function configure()
    {
        $this->setName('crawl:consume')
            ->setDescription('消费采集队列, 执行网盘资源抓取')
            ->addOption('channel', null, Option::VALUE_OPTIONAL, '采集通道(网盘源标识)', 'default');
    }

    protected function execute(Input $input, Output $output)
    {
        $channel = $input->getOption('channel');
        // TODO: 从队列 crawl_queue:{channel} 消费任务
        //       调用 CrawlerService 分发到对应爬虫, 抓取结果入库
        $output->writeln('<info>crawl:consume --channel=' . $channel . ' - TODO: 采集消费逻辑待实现</info>');
    }
}
