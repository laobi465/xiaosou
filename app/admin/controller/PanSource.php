<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\model\PanSource as PanSourceModel;

/**
 * 网盘源
 */
class PanSource extends BaseAdminController
{
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
             ->paginate(15, false, ['query' => $this->request->param()]);

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

        try {
            $model = PanSourceModel::create($data);
        } catch (\Throwable $e) {
            return $this->error('新增失败: ' . $e->getMessage());
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

        try {
            $model->save($data);
        } catch (\Throwable $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }

        $this->logAction('pan_source', 'update', $id, $data);
        return $this->success(['url' => '/admin/pan_source'], '保存成功');
    }

    /**
     * 删除网盘源
     */
    public function delete(int $id)
    {
        $model = PanSourceModel::find($id);
        if (!$model) {
            return $this->error('网盘源不存在');
        }

        try {
            $model->delete();
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
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
            return $this->error('操作失败: ' . $e->getMessage());
        }

        $this->logAction('pan_source', 'toggle', $id, ['enabled' => $model->enabled]);
        return $this->success(['enabled' => $model->enabled], '操作成功');
    }
}
