<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\queue\Worker;

/**
 * 邮件队列消费
 *
 * 启动 think-queue Worker 长驻消费 mail 通道队列,
 * 通过 SMTP 发送验证码/通知邮件。建议通过 supervisor 守护:
 *   php think mail:consume
 */
class MailConsume extends Command
{
    protected function configure()
    {
        $this->setName('mail:consume')
            ->setDescription('消费邮件队列, 发送邮件')
            ->addOption('tries', 't', Option::VALUE_OPTIONAL, '最大重试次数', 3);
    }

    protected function execute(Input $input, Output $output)
    {
        $tries      = (int) $input->getOption('tries');
        $queue      = (string) config('queue.channels.mail');
        $connection = (string) config('queue.default');

        $output->writeln('<info>[mail:consume] 启动 Worker connection=' . $connection . ' queue=' . $queue . ' tries=' . $tries . '</info>');

        /** @var Worker $worker */
        $worker = $this->app->make(Worker::class);

        try {
            // Worker::daemon($connection, $queue, $delay, $sleep, $maxTries, $memory, $timeout)
            // 死循环长驻进程, 内部通过 pcntl 处理 SIGTERM/SIGUSR2/SIGCONT
            $worker->daemon($connection, $queue, 0, 3, $tries, 128, 60);
        } catch (\Throwable $e) {
            $output->writeln('<error>[mail:consume] Worker 异常退出: ' . $e->getMessage() . '</error>');
            throw $e;
        }
    }
}
