<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 邮件队列消费
 * 从邮件队列消费验证码/通知邮件, 通过 SMTP 发送
 */
class MailConsume extends Command
{
    protected function configure()
    {
        $this->setName('mail:consume')
            ->setDescription('消费邮件队列, 发送邮件');
    }

    protected function execute(Input $input, Output $output)
    {
        // TODO: 从队列 mail_queue 消费邮件任务
        //       调用 MailService/SmtpMailer 发送
        $output->writeln('<info>mail:consume - TODO: 邮件消费逻辑待实现</info>');
    }
}
