<?php
declare(strict_types=1);

namespace app\job;

use app\common\model\CrawlTask;
use app\common\service\CrawlerService;
use think\queue\Job;

/**
 * 采集任务队列 Job
 *
 * 消费 crawl 队列任务, 调用 CrawlerService::execute 执行采集。
 * 处理成功调 $job->delete(); 失败抛异常由 think-queue 自动重试,
 * attempts 超限后进入 failed。
 */
class CrawlJob
{
    /**
     * @param Job   $job
     * @param array $data ['task_id'=>int]
     * @return void
     */
    public function fire(Job $job, array $data): void
    {
        $taskId = (int) ($data['task_id'] ?? 0);

        // 查询任务: 不存在则降级直接删除(不重试, 避免无效任务堆积)
        $task = CrawlTask::find($taskId);
        if (!$task) {
            trace('crawl_job_task_not_found: task_id=' . $taskId, 'warning');
            $job->delete();
            return;
        }

        try {
            $service = app(CrawlerService::class);
            $service->execute($task);

            trace('crawl_job_done: task_id=' . $taskId, 'info');
            $job->delete();
        } catch (\Throwable $e) {
            trace('crawl_job_error: task_id=' . $taskId . ' ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
}
