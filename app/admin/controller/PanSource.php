<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\model\CrawlTask;
use app\common\model\PanSource as PanSourceModel;
use app\common\model\ResourceLink;
use app\common\validate\PanSourceValidate;

/**
 * 网盘源
 */
class PanSource extends BaseAdminController
{
    /** crawler_class 允许的命名空间前缀白名单 */
    protected const CRAWLER_NS_WHITELIST = [
        'app\\common\\crawler\\',
        'Pansou\\Crawler\\',
    ];

    /**
     * 网盘源列表
     */
    public function index()
    {
        $name    = (string) $this->request->get('name', '');
        $enabled = $this->request->get('enabled', '');

        $list = PanSourceModel::when($name !== '', function ($query) use ($name) {
            $query->whereLike('name', '%' . $name . '%');
        })->when($enabled !== '' && $enabled !== null, function ($query) use ($enabled) {
            $query->where('enabled', (int) $enabled);
        })->order('sort', 'asc')
             ->order('id', 'desc')
             ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

        return view('pan_source/index', [
            'list'    => $list,
            'name'    => $name,
            'enabled' => $enabled,
        ]);
    }

    /**
     * 新增网盘源
     */
    public function add()
    {
        if (!$this->request->isPost()) {
            return view('pan_source/add');
        }

        $data = $this->request->only([
            'name', 'code', 'crawler_class', 'is_mainstream',
            'enabled', 'sort', 'api_config',
        ]);

        $validate = new PanSourceValidate();
        if (!$validate->scene('create')->check($data)) {
            return $this->error($validate->getError());
        }

        // crawler_class 命名空间前缀白名单 + 类存在校验,防 RCE
        if (!$this->isCrawlerClassAllowed((string) $data['crawler_class'])) {
            return $this->error('采集器类名非法或类不存在');
        }

        try {
            $model = PanSourceModel::create($data);
        } catch (\Throwable $e) {
            return $this->errorWithLog('新增失败,请稍后重试', $e, 'pan_source_create_error');
        }

        $this->logAction('pan_source', 'create', (int) $model->id, $data);
        return $this->success(['id' => $model->id, 'url' => '/admin/pan_source'], '新增成功');
    }

    /**
     * 编辑网盘源
     */
    public function edit(int $id)
    {
        $model = PanSourceModel::find($id);
        if (!$model) {
            return $this->error('网盘源不存在');
        }

        if (!$this->request->isPost()) {
            return view('pan_source/edit', ['vo' => $model]);
        }

        $data = $this->request->only([
            'name', 'code', 'crawler_class', 'is_mainstream',
            'enabled', 'sort', 'api_config',
        ]);

        $validate = new PanSourceValidate();
        if (!$validate->scene('edit')->check($data)) {
            return $this->error($validate->getError());
        }

        // crawler_class 命名空间前缀白名单 + 类存在校验,防 RCE
        if (!$this->isCrawlerClassAllowed((string) $data['crawler_class'])) {
            return $this->error('采集器类名非法或类不存在');
        }

        try {
            $model->save($data);
        } catch (\Throwable $e) {
            return $this->errorWithLog('保存失败,请稍后重试', $e, 'pan_source_update_error');
        }

        $this->logAction('pan_source', 'update', $id, $data);
        return $this->success(['url' => '/admin/pan_source'], '保存成功');
    }

    /**
     * 删除网盘源
     *
     * 安全: 删除前校验是否被 CrawlTask / ResourceLink 引用,
     * 存在引用则拒绝删除,避免产生孤儿任务/链接。
     */
    public function delete(int $id)
    {
        $model = PanSourceModel::find($id);
        if (!$model) {
            return $this->error('网盘源不存在');
        }

        // 引用校验: 采集任务
        $taskCount = CrawlTask::where('pan_source_id', $id)->count();
        if ($taskCount > 0) {
            return $this->error('该网盘源存在 ' . $taskCount . ' 个采集任务,请先删除或迁移后再删除');
        }

        // 引用校验: 资源链接
        $linkCount = ResourceLink::where('pan_source_id', $id)->count();
        if ($linkCount > 0) {
            return $this->error('该网盘源存在 ' . $linkCount . ' 条资源链接,请先清理后再删除');
        }

        try {
            $model->delete();
        } catch (\Throwable $e) {
            return $this->errorWithLog('删除失败,请稍后重试', $e, 'pan_source_delete_error');
        }

        $this->logAction('pan_source', 'delete', $id, ['name' => $model->name]);
        return $this->success([], '删除成功');
    }

    /**
     * 启用/禁用切换(enabled 字段)
     */
    public function toggle(int $id)
    {
        $model = PanSourceModel::find($id);
        if (!$model) {
            return $this->error('网盘源不存在');
        }

        $model->enabled = (int) $model->enabled === 1 ? 0 : 1;
        try {
            $model->save();
        } catch (\Throwable $e) {
            return $this->errorWithLog('操作失败,请稍后重试', $e, 'pan_source_toggle_error');
        }

        $this->logAction('pan_source', 'toggle', $id, ['enabled' => $model->enabled]);
        return $this->success(['enabled' => $model->enabled], '操作成功');
    }

    /**
     * 校验 crawler_class 是否在命名空间白名单内且类真实存在
     *
     * @param string $className
     * @return bool
     */
    protected function isCrawlerClassAllowed(string $className): bool
    {
        if ($className === '') {
            return false;
        }

        // 命名空间前缀白名单校验
        $prefixAllowed = false;
        foreach (self::CRAWLER_NS_WHITELIST as $prefix) {
            if (str_starts_with($className, $prefix)) {
                $prefixAllowed = true;
                break;
            }
        }
        if (!$prefixAllowed) {
            return false;
        }

        // 类必须真实存在(防伪造类名)
        return class_exists($className);
    }
}
