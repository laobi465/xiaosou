<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\exception\BusinessException;
use Pansou\Mail\SmtpMailer;
use think\facade\Queue;

/**
 * 邮件服务
 *
 * 参见架构设计文档 3.4 节。
 *
 * 关键设计:
 *   - 渲染模板(注册/登录/重置),内联 HTML 字符串
 *   - 投递到 mail 队列(异步),消费时调用 PHPMailer 实际发送
 *   - 失败重试由 think-queue 的 attempts 配置处理(Service 层不重试)
 *   - email_send_logs 由队列消费方 MailJob 记录
 */
class MailService
{
    /**
     * 发送验证码邮件(异步投递到 mail 队列)
     *
     * @param string $email 收件邮箱
     * @param string $code  验证码
     * @param int    $type  类型: 1注册 2登录 3重置密码
     * @return void
     */
    public function sendVerifyCode(string $email, string $code, int $type): void
    {
        [$subject, $body] = $this->renderTemplate($code, $type);

        Queue::push(\app\job\MailJob::class, [
            'to'      => $email,
            'subject' => $subject,
            'body'    => $body,
            'ip'      => request() ?-> ip() ?? '',
        ], (string) config('queue.channels.mail'));
    }

    /**
     * 同步发送 HTML 邮件(管理后台用)
     *
     * @param string $to      收件人邮箱
     * @param string $subject 主题
     * @param string $body    正文(HTML)
     * @return void
     * @throws BusinessException 邮件发送失败时抛出
     */
    public function sendHtml(string $to, string $subject, string $body): void
    {
        $mailer = new SmtpMailer();

        if (!$mailer->send($to, $subject, $body)) {
            throw new BusinessException('邮件发送失败');
        }
    }

    /**
     * 渲染验证码邮件模板
     *
     * @param string $code 验证码
     * @param int    $type 类型: 1注册 2登录 3重置密码
     * @return array{0:string,1:string} [subject, body]
     */
    private function renderTemplate(string $code, int $type): array
    {
        $subjects = [
            1 => '【网盘搜索】注册验证码',
            2 => '【网盘搜索】登录验证码',
            3 => '【网盘搜索】重置密码验证码',
        ];

        $purposes = [
            1 => '注册账号',
            2 => '登录账号',
            3 => '重置密码',
        ];

        $subject     = $subjects[$type] ?? '【网盘搜索】验证码';
        $purpose     = $purposes[$type] ?? '身份验证';
        $ttl         = (int) config('pan.verify_code.ttl', 300);
        $ttlMinutes  = max(1, (int) ceil($ttl / 60));
        $fromName    = (string) (env('MAIL.SMTP_FROM_NAME') ?? '网盘搜索');

        $body = $this->buildVerifyCodeHtml($code, $purpose, $ttlMinutes, $fromName);

        return [$subject, $body];
    }

    /**
     * 构建验证码邮件 HTML(内联样式,兼容主流邮件客户端)
     *
     * @param string $code        验证码
     * @param string $purpose     用途说明
     * @param int    $ttlMinutes  有效期(分钟)
     * @param string $fromName    发件人名称
     * @return string
     */
    private function buildVerifyCodeHtml(string $code, string $purpose, int $ttlMinutes, string $fromName): string
    {
        $codeHtml    = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $purposeHtml = htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8');
        $nameHtml    = htmlspecialchars($fromName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>验证码</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:'Microsoft YaHei',Arial,Helvetica,sans-serif;">
<table cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f6f9;padding:20px 0;">
<tr>
<td align="center">
<table cellpadding="0" cellspacing="0" width="600" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
<tr>
<td style="background-color:#1890ff;padding:20px 30px;color:#ffffff;font-size:18px;font-weight:bold;">{$nameHtml}</td>
</tr>
<tr>
<td style="padding:30px;">
<p style="margin:0 0 16px;color:#333333;font-size:14px;line-height:1.6;">您好，您正在进行<strong style="color:#1890ff;">{$purposeHtml}</strong>操作，验证码为：</p>
<div style="margin:16px 0;padding:20px;background-color:#f0f5ff;border:1px dashed #1890ff;border-radius:6px;text-align:center;">
<span style="font-size:28px;font-weight:bold;color:#1890ff;letter-spacing:6px;">{$codeHtml}</span>
</div>
<p style="margin:0 0 10px;color:#999999;font-size:12px;line-height:1.6;">验证码有效期为 {$ttlMinutes} 分钟，请尽快使用，过期需重新获取。</p>
<p style="margin:0;color:#999999;font-size:12px;line-height:1.6;">如非本人操作，请忽略此邮件，您的账户安全不受影响。</p>
</td>
</tr>
<tr>
<td style="padding:14px 30px;background-color:#fafafa;color:#cccccc;font-size:12px;text-align:center;border-top:1px solid #eeeeee;">此邮件由系统自动发送，请勿直接回复</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
HTML;
    }
}
