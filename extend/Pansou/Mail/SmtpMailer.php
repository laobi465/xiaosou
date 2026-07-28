<?php
declare(strict_types=1);

namespace Pansou\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * SMTP 邮件发送封装(PHPMailer)
 *
 * 配置来源(优先级):
 *   1. system_configs 表 smtp 分组(后台 /admin/config 可在线修改,即时生效)
 *   2. env MAIL.* (SMTP_HOST/SMTP_PORT/SMTP_USER/SMTP_PASS/SMTP_FROM/SMTP_FROM_NAME/SMTP_ENCRYPTION)
 *   3. 默认值
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
     *
     * 读取优先级: system_configs(smtp 分组) > .env > 默认值
     * 后台 /admin/config 修改 SMTP 配置后, ConfigService::set 已 flushGroup 清缓存,
     * 下次实例化本类即生效, 无需重启 PHP-FPM。
     */
    protected function configure(): void
    {
        // 优先读 system_configs(后台 /admin/config 可在线修改), 降级 .env, 再降级默认值
        $cfg = $this->loadConfig();

        $host       = (string) ($cfg['smtp_host']       ?? env('MAIL.SMTP_HOST')       ?? 'smtp.qq.com');
        $port       = (int)    ($cfg['smtp_port']       ?? env('MAIL.SMTP_PORT')       ?? 465);
        $user       = (string) ($cfg['smtp_user']       ?? env('MAIL.SMTP_USER')       ?? '');
        $pass       = (string) ($cfg['smtp_pass']       ?? env('MAIL.SMTP_PASS')       ?? '');
        // SMTP_FROM 无 system_configs 白名单, 用 user 兜底(.env 优先), 保持原逻辑
        $from       = (string) (env('MAIL.SMTP_FROM') ?? $user);
        $fromName   = (string) ($cfg['smtp_from_name']  ?? env('MAIL.SMTP_FROM_NAME')  ?? '网盘搜索');
        $encryption = (string) ($cfg['smtp_encryption'] ?? env('MAIL.SMTP_ENCRYPTION') ?? 'ssl');

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

    /**
     * 从 system_configs 加载 smtp 分组配置
     *
     * ConfigService 经 LoadConfig 中间件已预加载到容器, 失败降级返回空数组。
     * 使用 class_exists 防护, 避免本 extend 库被外部使用时产生硬依赖(app 目录可能不存在)。
     *
     * @return array<string,mixed> smtp 分组配置 [key => value]
     */
    protected function loadConfig(): array
    {
        try {
            if (class_exists(\app\common\service\ConfigService::class)) {
                return app(\app\common\service\ConfigService::class)->getGroup('smtp');
            }
        } catch (\Throwable $e) {
            // 降级: 容器未就绪 / DB 未初始化 / ConfigService 异常等, 返回空数组走 .env
        }
        return [];
    }
}
