<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\enum\ResourceStatus;
use app\common\enum\ResourceType;
use app\common\model\Resource as ResourceModel;
use app\common\model\ResourceLink;
use app\common\validate\ResourceValidate;
use think\facade\Db;

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
          ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

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
            return $this->errorWithLog('新增失败,请稍后重试', $e, 'resource_create_error');
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
            return $this->errorWithLog('保存失败,请稍后重试', $e, 'resource_update_error');
        }

        $this->logAction('resource', 'update', $id, $data);
        return $this->success(['url' => '/admin/resource'], '保存成功');
    }

    /**
     * 软删除资源
     *
     * 安全: 事务内级联处理 ResourceLink(置为失效 status=0),
     * 再软删资源本身,避免产生孤儿链接。
     */
    public function delete(int $id)
    {
        $resource = ResourceModel::find($id);
        if (!$resource) {
            return $this->error('资源不存在');
        }

        try {
            Db::transaction(function () use ($id) {
                // 级联: 将该资源下的 ResourceLink 置为失效(ResourceLink 无软删除字段)
                ResourceLink::where('resource_id', $id)->update(['status' => 0]);
                // 软删资源本身
                ResourceModel::destroy($id);
            });
        } catch (\Throwable $e) {
            return $this->errorWithLog('删除失败,请稍后重试', $e, 'resource_delete_error');
        }

        $this->logAction('resource', 'delete', $id, ['title' => $resource->title]);
        return $this->success([], '删除成功');
    }

    /**
     * 标记资源失效(status=0)
     *
     * 安全: 校验当前 status 仅允许 NORMAL(1) → INVALID(0) 流转,
     * 原子更新避免并发重复操作。
     */
    public function markInvalid(int $id)
    {
        $resource = ResourceModel::find($id);
        if (!$resource) {
            return $this->error('资源不存在');
        }

        // 校验当前状态: 仅正常资源可标记失效
        if ((int) $resource->status !== ResourceStatus::NORMAL) {
            return $this->error('当前资源状态不允许标记失效');
        }

        try {
            // 原子流转: NORMAL → INVALID
            $affected = ResourceModel::where('id', $id)
                ->where('status', ResourceStatus::NORMAL)
                ->update(['status' => ResourceStatus::INVALID]);

            if ($affected !== 1) {
                return $this->error('当前资源状态不允许标记失效');
            }
        } catch (\Throwable $e) {
            return $this->errorWithLog('操作失败,请稍后重试', $e, 'resource_mark_invalid_error');
        }

        $this->logAction('resource', 'mark_invalid', $id, ['title' => $resource->title]);
        return $this->success([], '已标记为失效');
    }

    /**
     * 批量操作(ids/action=delete|transfer)
     *
     * 安全:
     *   - 限制 ids 数量上限 500,防止超大批量请求
     *   - 批量删除事务内级联处理 ResourceLink
     */
    public function batch()
    {
        $ids    = (array) $this->request->post('ids', []);
        $action = (string) $this->request->post('action', '');

        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn ($id) => $id > 0);
        $ids = array_values($ids);

        if (empty($ids)) {
            return $this->error('请选择操作项');
        }

        // 限制批量数量上限
        $maxBatch = 500;
        if (count($ids) > $maxBatch) {
            return $this->error('单次批量操作不能超过 ' . $maxBatch . ' 条');
        }

        if ($action === 'delete') {
            try {
                Db::transaction(function () use ($ids) {
                    // 级联: 将相关 ResourceLink 置为失效
                    ResourceLink::whereIn('resource_id', $ids)->update(['status' => 0]);
                    // 软删资源
                    ResourceModel::destroy($ids);
                });
            } catch (\Throwable $e) {
                return $this->errorWithLog('批量删除失败,请稍后重试', $e, 'resource_batch_delete_error');
            }
            $this->logAction('resource', 'batch_delete', null, ['ids' => $ids]);
            return $this->success([], '批量删除成功');
        }

        if ($action === 'transfer') {
            $resourceType = (int) $this->request->post('resource_type', 0);
            if (!ResourceType::isValid($resourceType)) {
                return $this->error('资源类型非法');
            }
            try {
                ResourceModel::whereIn('id', $ids)->update(['resource_type' => $resourceType]);
            } catch (\Throwable $e) {
                return $this->errorWithLog('批量转移失败,请稍后重试', $e, 'resource_batch_transfer_error');
            }
            $this->logAction('resource', 'batch_transfer', null, ['ids' => $ids, 'resource_type' => $resourceType]);
            return $this->success([], '批量转移成功');
        }

        return $this->error('未知操作');
    }
}
