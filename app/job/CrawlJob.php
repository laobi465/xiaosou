<?php
declare(strict_types=1);

namespace app\job;

use app\common\model\CrawlTask;
use app\common\service\CrawlerService;
use think\cache\driver\Redis as RedisCacheDriver;
use think\facade\Cache;
use think\queue\Job;

/**
 * 采集任务队列 Job
 *
 * 消费 crawl 队列任务, 调用 CrawlerService::execute 执行采集。
 * 处理成功调 $job->delete(); 失败抛异常由 think-queue 自动重试,
 * attempts 超限后进入死信。
 *
 * 并发安全: 基于 Redis 分布式锁(crawl_task_lock:{taskId}, TTL 300s)防止
 * 多消费者重复消费同一 task; CrawlerService 内部基于 url_hash 唯一约束保证入库幂等。
 */
class CrawlJob
{
    /**
     * 任务最大尝试次数, 超限进入死信
     */
    protected const MAX_ATTEMPTS = 3;

    /**
     * 分布式锁 TTL(秒)
     */
    protected const LOCK_TTL = 300;

    /**
     * @param Job   $job
     * @param array $data ['task_id'=>int]
     * @return void
     */
    public function fire(Job $job, array $data): void
    {
        $taskId = (int) ($data['task_id'] ?? 0);

        // attempts 上限判断: 超限进入死信(删除任务, 不再重试)
        $maxAttempts = (int) (config('pan.queue.crawl_max_attempts', self::MAX_ATTEMPTS));
        if ($maxAttempts > 0 && $job->attempts() > $maxAttempts) {
            trace('crawl_job_dead: task_id=' . $taskId . ' attempts=' . $job->attempts(), 'error');
            $job->delete();
            return;
        }

        // 查询任务: 不存在则降级直接删除(不重试, 避免无效任务堆积)
        $task = CrawlTask::find($taskId);
        if (!$task) {
            trace('crawl_job_task_not_found: task_id=' . $taskId, 'warning');
            $job->delete();
            return;
        }

        // Redis 分布式锁防并发重复消费
        $lockKey = 'crawl_task_lock:' . $taskId;
        $redis   = $this->redis();
        $token   = null;
        if ($redis !== null) {
            try {
                $token  = (string) random_int(100000, 999999);
                $locked = $redis->set($lockKey, $token, ['nx', 'ex' => self::LOCK_TTL]);
                if ($locked === false) {
                    // 已有其他消费者在处理该 task, 删除当前任务避免重复执行
                    trace('crawl_job_locked: task_id=' . $taskId, 'info');
                    $job->delete();
                    return;
                }
            } catch (\Throwable $e) {
                trace('crawl_job_lock_error: task_id=' . $taskId . ' ' . $e->getMessage(), 'error');
                $token = null;
            }
        }

        try {
            $service = app(CrawlerService::class);
            $service->execute($task);

            trace('crawl_job_done: task_id=' . $taskId, 'info');
            $job->delete();
        } catch (\Throwable $e) {
            trace('crawl_job_error: task_id=' . $taskId . ' ' . $e->getMessage(), 'error');
            throw $e;
        } finally {
            // 释放锁(仅释放自己持有的, 用 Lua 原子 check-and-del)
            if ($redis !== null && $token !== null) {
                $this->safeReleaseLock($redis, $lockKey, $token);
            }
        }
    }

    /**
     * 安全释放分布式锁(原子 check-and-del, 避免误删他人持有的锁)
     */
    protected function safeReleaseLock(\Redis $redis, string $key, string $token): void
    {
        try {
            $lua = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
            $redis->eval($lua, [$key, $token], 1);
        } catch (\Throwable $e) {
            trace('crawl_job_unlock_error: ' . $e->getMessage(), 'error');
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
            trace('crawl_job_redis_init_error: ' . $e->getMessage(), 'error');
        }
        return null;
    }
}
