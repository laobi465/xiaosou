<?php
declare(strict_types=1);

namespace Pansou\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * SMTP 邮件发送封装(PHPMailer)
 *
 * 配置来源: env MAIL.* (SMTP_HOST/SMTP_PORT/SMTP_USER/SMTP_PASS/SMTP_FROM/SMTP_FROM_NAME/SMTP_ENCRYPTION)
 */
class SmtpMailer
{
    protected PHPMailer $mailer;

    public function __construct(?PHPMailer $mailer = null)
    {
        $this->mailer = $mailer ?? new PHPMailer(true);
        $this->configure();
    }

    /**
     * 发送邮件
     *
     * @param string $to      收件人邮箱
     * @param string $subject 主题
     * @param string $body    正文(HTML)
     * @return bool true=发送成功
     */
    public function send(string $to, string $subject, string $body): bool
    {
        // TODO: 完整实现待补充,以下为骨架
        //   1. 收件人/主题/正文设置
        //   2. $this->mailer->send()
        //   3. 异常捕获并返回 false
        try {
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;
            $this->mailer->isHTML(true);
            $this->mailer->Body    = $body;
            $this->mailer->AltBody = strip_tags($body);
            return $this->mailer->send();
        } catch (PHPMailerException $e) {
            return false;
        }
    }

    /**
     * 配置 PHPMailer 实例(SMTP)
     */
    protected function configure(): void
    {
        $host       = (string) (env('MAIL.SMTP_HOST') ?? 'smtp.qq.com');
        $port       = (int)    (env('MAIL.SMTP_PORT') ?? 465);
        $user       = (string) (env('MAIL.SMTP_USER') ?? '');
        $pass       = (string) (env('MAIL.SMTP_PASS') ?? '');
        $from       = (string) (env('MAIL.SMTP_FROM') ?? $user);
        $fromName   = (string) (env('MAIL.SMTP_FROM_NAME') ?? '网盘搜索');
        $encryption = (string) (env('MAIL.SMTP_ENCRYPTION') ?? 'ssl');

        $this->mailer->isSMTP();
        $this->mailer->Host       = $host;
        $this->mailer->Port       = $port;
        $this->mailer->SMTPAuth   = true;
        $this->mailer->Username   = $user;
        $this->mailer->Password   = $pass;
        if ($encryption === 'ssl') {
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $this->mailer->CharSet = PHPMailer::CHARSET_UTF8;
        if ($from !== '') {
            $this->mailer->setFrom($from, $fromName);
        }
    }
}
