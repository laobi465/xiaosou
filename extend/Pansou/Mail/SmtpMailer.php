<?php
declare(strict_types=1);

namespace Pansou\Mail;

use PHPMailer\PHPMailer\PHPMailer;

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
     * @return bool true=发送成功 false=发送失败或收件人非法
     */
    public function send(string $to, string $subject, string $body): bool
    {
        // 收件人邮箱格式前置校验, 非法直接返回 false
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        try {
            // clearAllRecipients 同时清理 To/Cc/Bcc, 避免上次发送残留
            $this->mailer->clearAllRecipients();
            $this->mailer->addAddress($to);
            $this->mailer->Subject = $subject;
            $this->mailer->isHTML(true);
            $this->mailer->Body    = $body;
            $this->mailer->AltBody = strip_tags($body);
            return $this->mailer->send();
        } catch (\Throwable $e) {
            // 扩展为 \Throwable, 捕获所有异常(含 PHPMailerException 及底层网络异常)
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
        // 显式设置超时(秒), 避免长时间挂起
        $this->mailer->Timeout    = 10;
        if ($encryption === 'ssl') {
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
        $this->mailer->CharSet = PHPMailer::CHARSET_UTF8;
        if ($from !== '') {
            try {
                $this->mailer->setFrom($from, $fromName);
            } catch (\Throwable $e) {
                trace('smtp_setfrom_error: ' . $e->getMessage(), 'error');
            }
        }
    }
}
