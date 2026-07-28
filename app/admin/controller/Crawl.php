<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\model\CrawlLog;
use app\common\model\CrawlTask;
use app\common\service\CrawlerService;

/**
 * 采集任务
 */
class Crawl extends BaseAdminController
{
    /**
     * 采集任务列表(含 pan_source 关联)
     */
    public function index()
    {
        $panSourceId = $this->request->get('pan_source_id', '');
        $enabled     = $this->request->get('enabled', '');

        $list = CrawlTask::with('panSource')
            ->when($panSourceId !== '' && $panSourceId !== null, function ($query) use ($panSourceId) {
                $query->where('pan_source_id', (int) $panSourceId);
            })->when($enabled !== '' && $enabled !== null, function ($query) use ($enabled) {
                $query->where('enabled', (int) $enabled);
            })->order('id', 'desc')
              ->paginate(15, false, ['query' => $this->request->param()]);

        return view('crawl/index', [
            'list'          => $list,
            'pan_source_id' => $panSourceId,
            'enabled'       => $enabled,
        ]);
    }

    /**
     * 新增采集任务
     */
    public function add()
    {
        if (!$this->request->isPost()) {
            return view('crawl/add');
        }

        $data = $this->request->only([
            'pan_source_id', 'name', 'frequency', 'enabled', 'config',
        ]);

        if ((int) ($data['pan_source_id'] ?? 0) <= 0) {
            return $this->error('请选择网盘源');
        }
        if ((int) ($data['frequency'] ?? 0) <= 0) {
            return $this->error('采集频率必须大于0');
        }

        try {
            $task = CrawlTask::create($data);
        } catch (\Throwable $e) {
            return $this->error('新增失败: ' . $e->getMessage());
        }

        $this->logAction('crawl', 'create', (int) $task->id, $data);
        return $this->success(['id' => $task->id, 'url' => '/admin/crawl'], '新增成功');
    }

    /**
     * 编辑采集任务
     */
    public function edit(int $id)
    {
        $task = CrawlTask::find($id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        if (!$this->request->isPost()) {
            return view('crawl/edit', ['vo' => $task]);
        }

        $data = $this->request->only([
            'pan_source_id', 'name', 'frequency', 'enabled', 'config',
        ]);

        if ((int) ($data['pan_source_id'] ?? 0) <= 0) {
            return $this->error('请选择网盘源');
        }
        if ((int) ($data['frequency'] ?? 0) <= 0) {
            return $this->error('采集频率必须大于0');
        }

        try {
            $task->save($data);
        } catch (\Throwable $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }

        $this->logAction('crawl', 'update', $id, $data);
        return $this->success(['url' => '/admin/crawl'], '保存成功');
    }

    /**
     * 删除采集任务
     */
    public function delete(int $id)
    {
        $task = CrawlTask::find($id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        try {
            $task->delete();
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }

        $this->logAction('crawl', 'delete', $id, ['name' => $task->name]);
        return $this->success([], '删除成功');
    }

    /**
     * 任务采集日志列表
     */
    public function logs(int $id)
    {
        $task = CrawlTask::find($id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        $status = $this->request->get('status', '');

        $list = CrawlLog::where('task_id', $id)
            ->when($status !== '' && $status !== null, function ($query) use ($status) {
                $query->where('status', (int) $status);
            })->order('id', 'desc')
              ->paginate(15, false, ['query' => $this->request->param()]);

        return view('crawl/logs', [
            'list'   => $list,
            'task'   => $task,
            'status' => $status,
        ]);
    }

    /**
     * 手动触发采集(CrawlerService::dispatch)
     */
    public function trigger(int $id)
    {
        $task = CrawlTask::find($id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        if ((int) $task->enabled !== 1) {
            return $this->error('任务未启用,无法触发');
        }

        try {
            app(CrawlerService::class)->dispatch($task);
        } catch (\Throwable $e) {
            return $this->error('触发失败: ' . $e->getMessage());
        }

        $this->logAction('crawl', 'trigger', $id, ['task_id' => $id]);
        return $this->success([], '已触发采集');
    }
}
