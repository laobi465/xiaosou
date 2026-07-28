<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\queue\Worker;

/**
 * 采集任务消费
 *
 * 启动 think-queue Worker 长驻消费 crawl 通道队列,
 * 执行网盘资源抓取入库。建议通过 supervisor 守护:
 *   php think crawl:consume
 */
class CrawlConsume extends Command
{
    protected function configure()
    {
        $this->setName('crawl:consume')
            ->setDescription('消费采集队列, 执行网盘资源抓取')
            ->addOption('tries', 't', Option::VALUE_OPTIONAL, '最大重试次数', 3)
            ->addOption('sleep', 's', Option::VALUE_OPTIONAL, '空闲休眠秒数', 3)
            ->addOption('channel', null, Option::VALUE_OPTIONAL, '通道', 'default');
    }

    protected function execute(Input $input, Output $output)
    {
        $tries   = (int) $input->getOption('tries');
        $sleep   = (int) $input->getOption('sleep');
        $channel = (string) $input->getOption('channel');

        // 默认通道消费 crawl 队列; 指定通道时按 config 映射或直接作为队列名
        if ($channel === '' || $channel === 'default') {
            $queue = (string) config('queue.channels.crawl');
        } else {
            $mapped = config('queue.channels.' . $channel);
            $queue  = is_string($mapped) && $mapped !== '' ? $mapped : $channel;
        }

        $connection = (string) config('queue.default');

        $output->writeln('<info>[crawl:consume] 启动 Worker connection=' . $connection . ' queue=' . $queue . ' tries=' . $tries . ' sleep=' . $sleep . '</info>');

        /** @var Worker $worker */
        $worker = $this->app->make(Worker::class);

        try {
            // Worker::daemon($connection, $queue, $delay, $sleep, $maxTries, $memory, $timeout)
            // 死循环长驻进程, 内部通过 pcntl 处理 SIGTERM/SIGUSR2/SIGCONT
            $worker->daemon($connection, $queue, 0, $sleep, $tries, 128, 60);
        } catch (\Throwable $e) {
            $output->writeln('<error>[crawl:consume] Worker 异常退出: ' . $e->getMessage() . '</error>');
            throw $e;
        }
    }
}
