<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\enum\ResourceStatus;
use app\common\model\Resource as ResourceModel;
use app\common\validate\ResourceValidate;

/**
 * 资源管理
 */
class Resource extends BaseAdminController
{
    /**
     * 资源列表(搜索 title / 筛选 resource_type / status / 分页)
     */
    public function index()
    {
        $title        = (string) $this->request->get('title', '');
        $resourceType = $this->request->get('resource_type', '');
        $status       = $this->request->get('status', '');

        $list = ResourceModel::when($title !== '', function ($query) use ($title) {
            $query->whereLike('title', '%' . $title . '%');
        })->when($resourceType !== '' && $resourceType !== null, function ($query) use ($resourceType) {
            $query->where('resource_type', (int) $resourceType);
        })->when($status !== '' && $status !== null, function ($query) use ($status) {
            $query->where('status', (int) $status);
        })->order('id', 'desc')
          ->paginate(15, false, ['query' => $this->request->param()]);

        return view('resource/index', [
            'list'          => $list,
            'title'         => $title,
            'resource_type' => $resourceType,
            'status'        => $status,
        ]);
    }

    /**
     * 新增资源
     * GET  渲染表单
     * POST 保存(ResourceValidate::add)
     */
    public function add()
    {
        if (!$this->request->isPost()) {
            return view('resource/add');
        }

        $data = $this->request->only([
            'title', 'cover', 'intro', 'resource_type',
            'file_size', 'file_format', 'status',
        ]);

        $validate = new ResourceValidate();
        if (!$validate->scene('add')->check($data)) {
            return $this->error($validate->getError());
        }

        $data['source_type']  = 1;
        $data['submitter_id'] = 0;

        try {
            $resource = ResourceModel::create($data);
        } catch (\Throwable $e) {
            return $this->error('新增失败: ' . $e->getMessage());
        }

        $this->logAction('resource', 'create', (int) $resource->id, ['title' => $data['title']]);
        return $this->success(['id' => $resource->id, 'url' => '/admin/resource'], '新增成功');
    }

    /**
     * 编辑资源
     * GET  渲染表单
     * POST 保存(ResourceValidate::edit)
     */
    public function edit(int $id)
    {
        $resource = ResourceModel::find($id);
        if (!$resource) {
            return $this->error('资源不存在');
        }

        if (!$this->request->isPost()) {
            return view('resource/edit', ['vo' => $resource]);
        }

        $data = $this->request->only([
            'title', 'cover', 'intro', 'resource_type',
            'file_size', 'file_format', 'status',
        ]);

        $validate = new ResourceValidate();
        if (!$validate->scene('edit')->check($data)) {
            return $this->error($validate->getError());
        }

        try {
            $resource->save($data);
        } catch (\Throwable $e) {
            return $this->error('保存失败: ' . $e->getMessage());
        }

        $this->logAction('resource', 'update', $id, $data);
        return $this->success(['url' => '/admin/resource'], '保存成功');
    }

    /**
     * 软删除资源
     */
    public function delete(int $id)
    {
        $resource = ResourceModel::find($id);
        if (!$resource) {
            return $this->error('资源不存在');
        }

        try {
            ResourceModel::destroy($id);
        } catch (\Throwable $e) {
            return $this->error('删除失败: ' . $e->getMessage());
        }

        $this->logAction('resource', 'delete', $id, ['title' => $resource->title]);
        return $this->success([], '删除成功');
    }

    /**
     * 标记资源失效(status=0)
     */
    public function markInvalid(int $id)
    {
        $resource = ResourceModel::find($id);
        if (!$resource) {
            return $this->error('资源不存在');
        }

        try {
            $resource->status = ResourceStatus::INVALID;
            $resource->save();
        } catch (\Throwable $e) {
            return $this->error('操作失败: ' . $e->getMessage());
        }

        $this->logAction('resource', 'mark_invalid', $id, ['title' => $resource->title]);
        return $this->success([], '已标记为失效');
    }

    /**
     * 批量操作(ids/action=delete|transfer)
     */
    public function batch()
    {
        $ids    = (array) $this->request->post('ids', []);
        $action = (string) $this->request->post('action', '');

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn ($id) => $id > 0);

        if (empty($ids)) {
            return $this->error('请选择操作项');
        }

        if ($action === 'delete') {
            try {
                ResourceModel::destroy($ids);
            } catch (\Throwable $e) {
                return $this->error('批量删除失败: ' . $e->getMessage());
            }
            $this->logAction('resource', 'batch_delete', null, ['ids' => $ids]);
            return $this->success([], '批量删除成功');
        }

        if ($action === 'transfer') {
            $resourceType = (int) $this->request->post('resource_type', 0);
            if ($resourceType < 1 || $resourceType > 7) {
                return $this->error('资源类型非法');
            }
            try {
                ResourceModel::whereIn('id', $ids)->update(['resource_type' => $resourceType]);
            } catch (\Throwable $e) {
                return $this->error('批量转移失败: ' . $e->getMessage());
            }
            $this->logAction('resource', 'batch_transfer', null, ['ids' => $ids, 'resource_type' => $resourceType]);
            return $this->success([], '批量转移成功');
        }

        return $this->error('未知操作');
    }
}
