<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\CrawlTask;
use app\common\model\CrawlLog;
use app\common\model\Resource;
use app\common\model\ResourceLink;
use Pansou\Helper\HashHelper;
use think\facade\Db;
use think\facade\Queue;

/**
 * 采集服务
 *
 * 参见架构设计文档 3.5 节。
 *
 * 调度策略:
 *   - think crawl:dispatch 每分钟扫描 crawl_tasks 表,next_run_at <= now 的任务入队
 *   - think crawl:consume 多进程并行(按网盘源隔离队列通道)
 *   - 单源采集失败不影响其他源
 */
class CrawlerService
{
    /**
     * 投递采集任务到队列
     *
     * @param CrawlTask $task 采集任务
     * @return void
     */
    public function dispatch(CrawlTask $task): void
    {
        // 1. 校验任务 enabled=1
        if ((int) $task->enabled !== 1) {
            return;
        }

        try {
            // 2. 投递 CrawlJob 到 crawl 队列
            Queue::push(
                \app\job\CrawlJob::class,
                ['task_id' => (int) $task->id],
                config('queue.channels.crawl')
            );

            // 3. 更新 last_run_at 与 next_run_at(投递成功后才更新,失败则下轮重试)
            $now       = date('Y-m-d H:i:s');
            $frequency = (int) $task->frequency;
            $nextRunAt = date('Y-m-d H:i:s', time() + $frequency * 60);

            $task->last_run_at = $now;
            $task->next_run_at = $nextRunAt;
            $task->save();
        } catch (\Throwable $e) {
            trace('crawl_dispatch_error: task_id=' . $task->id . ' ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 执行采集任务(队列消费入口)
     *
     * @param CrawlTask $task 采集任务
     * @return void
     */
    public function execute(CrawlTask $task): void
    {
        $start       = microtime(true);
        $foundCount  = 0;
        $newCount    = 0;
        $panSourceId = (int) $task->pan_source_id;

        try {
            // 1. 通过 pan_sources.crawler_class 反射实例化采集器
            $panSource = $task->panSource;
            if (!$panSource) {
                throw new \RuntimeException('网盘源不存在, pan_source_id=' . $panSourceId);
            }

            $className = (string) $panSource->crawler_class;
            if ($className === '' || !class_exists($className)) {
                throw new \RuntimeException('采集器类不存在: ' . $className);
            }

            $crawler = new $className();

            // 2. 调用 crawl() 获取资源项列表
            $items = $crawler->crawl($task);
            if (!is_array($items)) {
                $items = [];
            }
            $foundCount = count($items);

            // 3. 敏感词过滤服务
            $sensitiveFilter = app(SensitiveFilter::class);

            // 4. 逐个处理 item(单个失败不影响其他)
            foreach ($items as $item) {
                try {
                    $itemArray = is_array($item) ? $item : (array) $item;

                    $title       = trim((string) ($itemArray['title'] ?? ''));
                    $shareUrl    = trim((string) ($itemArray['share_url'] ?? ''));
                    $extractCode = (string) ($itemArray['extract_code'] ?? '');
                    $fileSize    = (int) ($itemArray['file_size'] ?? 0);
                    $cover       = (string) ($itemArray['cover'] ?? '');
                    $intro       = (string) ($itemArray['intro'] ?? '');

                    // 资源类型: 默认取 item 的, 未指定则 7(其他)
                    $resourceType = (int) ($itemArray['resource_type'] ?? 7);
                    if ($resourceType <= 0) {
                        $resourceType = 7;
                    }

                    // 跳过无效项
                    if ($title === '' || $shareUrl === '') {
                        continue;
                    }

                    // 敏感词过滤: 标题或简介命中则跳过该 item
                    $titleCheck = $sensitiveFilter->check($title);
                    if ($titleCheck['hit']) {
                        continue;
                    }
                    $introCheck = $sensitiveFilter->check($intro);
                    if ($introCheck['hit']) {
                        continue;
                    }

                    // a. 计算 url_hash
                    $urlHash = HashHelper::urlHash($shareUrl);

                    // b. URL 去重: 已存在则跳过
                    $existsLink = ResourceLink::where('url_hash', $urlHash)->find();
                    if ($existsLink) {
                        continue;
                    }

                    // c/d. 查找/创建 Resource + 创建 ResourceLink(事务保证原子入库)
                    Db::transaction(function () use (
                        $title, $cover, $intro, $resourceType, $fileSize,
                        $shareUrl, $extractCode, $urlHash, $panSourceId
                    ) {
                        $resource = Resource::where('title', $title)->find();
                        if (!$resource) {
                            $resource = Resource::create([
                                'title'           => $title,
                                'cover'           => $cover,
                                'intro'           => $intro,
                                'resource_type'   => $resourceType,
                                'file_size'       => $fileSize,
                                'file_format'     => '',
                                'source_type'     => 1,
                                'submitter_id'    => 0,
                                'status'          => 1,
                                'view_count'      => 0,
                                'link_view_count' => 0,
                            ]);
                        }

                        ResourceLink::create([
                            'resource_id'   => (int) $resource->id,
                            'pan_source_id' => $panSourceId,
                            'share_url'     => $shareUrl,
                            'extract_code'  => $extractCode,
                            'url_hash'      => $urlHash,
                            'status'        => 1,
                        ]);
                    });

                    $newCount++;
                } catch (\Throwable $e) {
                    // 单个 item 失败不影响其他
                    trace('crawl_item_error: task_id=' . $task->id . ' ' . $e->getMessage(), 'error');
                }
            }

            // 5. 写 CrawlLog(成功)
            $this->writeLog($task, 1, $foundCount, $newCount, '', $start);
        } catch (\Throwable $e) {
            // 整体失败写日志
            $this->writeLog($task, 0, $foundCount, $newCount, $e->getMessage(), $start);
            trace('crawl_execute_error: task_id=' . $task->id . ' ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 扫描并分发到期任务
     *
     * @return int 分发数量
     */
    public function dispatchDueTasks(): int
    {
        $count = 0;

        try {
            $now = date('Y-m-d H:i:s');

            // 查询已启用且到期的任务(next_run_at <= now 或 next_run_at IS NULL)
            $tasks = CrawlTask::where('enabled', 1)
                ->where(function ($query) use ($now) {
                    $query->where('next_run_at', '<=', $now)
                          ->whereOr('next_run_at', 'null');
                })
                ->select();

            foreach ($tasks as $task) {
                try {
                    $this->dispatch($task);
                    $count++;
                } catch (\Throwable $e) {
                    trace('crawl_dispatch_due_item_error: task_id=' . $task->id . ' ' . $e->getMessage(), 'error');
                }
            }
        } catch (\Throwable $e) {
            trace('crawl_dispatch_due_tasks_error: ' . $e->getMessage(), 'error');
        }

        return $count;
    }

    /**
     * 写采集日志(失败降级,不阻塞主流程)
     *
     * @param CrawlTask $task       采集任务
     * @param int       $status     状态: 1成功 0失败
     * @param int       $foundCount 发现数量
     * @param int       $newCount   新增数量
     * @param string    $errorMsg   错误信息
     * @param float     $start      开始时间(microtime)
     * @return void
     */
    protected function writeLog(CrawlTask $task, int $status, int $foundCount, int $newCount, string $errorMsg, float $start): void
    {
        try {
            CrawlLog::create([
                'task_id'       => (int) $task->id,
                'pan_source_id' => (int) $task->pan_source_id,
                'status'        => $status,
                'found_count'   => $foundCount,
                'new_count'     => $newCount,
                'error_msg'     => $errorMsg,
                'duration_ms'   => (int) ((microtime(true) - $start) * 1000),
            ]);
        } catch (\Throwable $e) {
            trace('crawl_log_write_error: task_id=' . $task->id . ' ' . $e->getMessage(), 'error');
        }
    }
}
