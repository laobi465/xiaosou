<?php
declare(strict_types=1);

namespace app\job;

use Exception;
use Pansou\Mail\SmtpMailer;
use think\queue\Job;

/**
 * 邮件发送队列 Job
 *
 * 消费 mail 队列任务, 调用 SmtpMailer 发送邮件。
 * 处理成功调 $job->delete(); 失败抛异常由 think-queue 自动重试,
 * attempts 超限后进入 failed。
 */
class MailJob
{
    /**
     * @param Job   $job
     * @param array $data ['to'=>string, 'subject'=>string, 'body'=>string, 'ip'=>?string]
     * @return void
     */
    public function fire(Job $job, array $data): void
    {
        $to      = (string) ($data['to'] ?? '');
        $subject = (string) ($data['subject'] ?? '');
        $body    = (string) ($data['body'] ?? '');
        $ip      = (string) ($data['ip'] ?? '');

        try {
            $mailer = new SmtpMailer();
            $ok     = $mailer->send($to, $subject, $body);

            if (!$ok) {
                throw new Exception('SmtpMailer 发送返回 false');
            }

            trace('mail_job_sent: to=' . $to . ' subject=' . $subject . ' ip=' . $ip, 'info');
            $job->delete();
        } catch (\Throwable $e) {
            trace('mail_job_error: to=' . $to . ' subject=' . $subject . ' ip=' . $ip . ' ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
}
