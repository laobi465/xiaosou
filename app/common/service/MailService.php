<?php
declare(strict_types=1);

namespace app\common\service;

/**
 * 邮件服务
 *
 * 参见架构设计文档 3.4 节。
 *
 * 关键设计:
 *   - 渲染模板(注册/登录/重置)
 *   - 投递到 mail 队列(异步),消费时调用 PHPMailer 实际发送
 *   - 失败重试 3 次,指数退避
 *   - 记录 email_send_logs
 */
class MailService
{
    /**
     * 发送验证码邮件
     *
     * @param string $email 收件邮箱
     * @param string $code  验证码
     * @param int    $type  类型: 1注册 2登录 3重置密码
     * @return void
     */
    public function sendVerifyCode(string $email, string $code, int $type): void
    {
        // TODO: 1. 按 type 渲染模板
        // TODO: 2. 投递到 mail 队列(异步)
        // TODO: 3. 队列消费调用 Pansou\Mail\SmtpMailer::send
        // TODO: 4. 失败重试 3 次,指数退避
        // TODO: 5. 记录 email_send_logs
    }
}
