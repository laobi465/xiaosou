<?php
declare(strict_types=1);

namespace app\job;

use Exception;
use Pansou\Mail\SmtpMailer;
use think\cache\driver\Redis as RedisCacheDriver;
use think\facade\Cache;
use think\queue\Job;

/**
 * 邮件发送队列 Job
 *
 * 消费 mail 队列任务, 调用 SmtpMailer 发送邮件。
 * 处理成功调 $job->delete(); 失败抛异常由 think-queue 自动重试,
 * attempts 超限后进入 failed。
 *
 * 幂等性: 基于 to+subject+body 哈希的 Redis 标记防重(TTL 1 小时),
 * 避免 SMTP 发送超时后重试导致重复发送。
 */
class MailJob
{
    /**
     * 防重标记 TTL(秒)
     */
    protected const DEDUP_TTL = 3600;

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

        // 收件人邮箱格式前置校验, 非法直接删除(不重试)
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            trace('mail_job_invalid_to: to=' . $to, 'warning');
            $job->delete();
            return;
        }

        // 幂等防重: 基于 to+subject+body 哈希, Redis SET NX 标记, TTL 1 小时
        $dedupKey = 'mail:sent:' . hash('sha256', $to . '|' . $subject . '|' . $body);
        $redis    = $this->redis();
        if ($redis !== null) {
            try {
                $marked = $redis->set($dedupKey, '1', ['nx', 'ex' => self::DEDUP_TTL]);
                if ($marked === false) {
                    // 同内容邮件已发送过(重放/重试), 跳过
                    trace('mail_job_dedup_skip: to=' . $to, 'info');
                    $job->delete();
                    return;
                }
            } catch (\Throwable $e) {
                trace('mail_job_dedup_error: ' . $e->getMessage(), 'error');
                // Redis 异常降级: 继续发送
            }
        }

        try {
            $mailer = new SmtpMailer();
            $ok     = $mailer->send($to, $subject, $body);

            if (!$ok) {
                // 发送失败: 释放防重标记以便重试
                $this->clearDedup($redis, $dedupKey);
                throw new Exception('SmtpMailer 发送返回 false');
            }

            // trace 日志脱敏: subject 截断, body 仅记录长度(避免泄露验证码)
            trace('mail_job_sent: to=' . $to . ' subject=' . $this->maskSubject($subject) . ' body_len=' . strlen($body) . ' ip=' . $ip, 'info');
            $job->delete();
        } catch (\Throwable $e) {
            // 发送异常: 释放防重标记以便重试
            $this->clearDedup($redis, $dedupKey);
            trace('mail_job_error: to=' . $to . ' subject=' . $this->maskSubject($subject) . ' body_len=' . strlen($body) . ' ip=' . $ip . ' ' . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * 脱敏 subject: 截断至 20 字符, 不记录完整内容
     */
    protected function maskSubject(string $subject): string
    {
        $truncated = function_exists('mb_substr')
            ? mb_substr($subject, 0, 20, 'UTF-8')
            : substr($subject, 0, 20);
        return $truncated . (strlen($subject) > 20 ? '...' : '');
    }

    /**
     * 清除防重标记(发送失败时调用, 以便重试)
     */
    protected function clearDedup(?\Redis $redis, string $key): void
    {
        if ($redis === null) {
            return;
        }
        try {
            $redis->del($key);
        } catch (\Throwable $e) {
            trace('mail_job_dedup_clear_error: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 获取底层 Redis 实例(不可用时返回 null)
     */
    protected function redis(): ?\Redis
    {
        try {
            $store = Cache::store('redis');
            if ($store instanceof RedisCacheDriver) {
                $handler = $store->handler();
                if ($handler instanceof \Redis) {
                    return $handler;
                }
            }
            if (method_exists($store, 'handler')) {
                $handler = $store->handler();
                return $handler instanceof \Redis ? $handler : null;
            }
        } catch (\Throwable $e) {
            trace('mail_job_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }
}
