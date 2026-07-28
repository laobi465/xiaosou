<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\model\CreditPackage;
use app\common\validate\PackageValidate;

/**
 * 积分套餐
 */
class Package extends BaseAdminController
{
    /**
     * 套餐列表
     */
    public function index()
    {
        $status = $this->request->get('status', '');

        $list = CreditPackage::when($status !== '' && $status !== null, function ($query) use ($status) {
            $query->where('status', (int) $status);
        })->order('sort', 'asc')
            ->order('id', 'desc')
            ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

        return view('package/index', [
            'list'   => $list,
            'status' => $status,
        ]);
    }

    /**
     * 新增套餐
     */
    public function add()
    {
        if (!$this->request->isPost()) {
            return view('package/add');
        }

        $data = $this->request->only([
            'name', 'price', 'credits', 'bonus',
            'is_recommended', 'status', 'sort',
        ]);

        $validate = new PackageValidate();
        if (!$validate->scene('add')->check($data)) {
            return $this->error($validate->getError());
        }

        try {
            $package = CreditPackage::create($data);
        } catch (\Throwable $e) {
            return $this->errorWithLog('新增失败', $e, 'package_create_error');
        }

        $this->logAction('package', 'create', (int) $package->id, $data);
        return $this->success(['id' => $package->id, 'url' => '/admin/package'], '新增成功');
    }

    /**
     * 编辑套餐
     */
    public function edit(int $id)
    {
        $package = CreditPackage::find($id);
        if (!$package) {
            return $this->error('套餐不存在');
        }

        if (!$this->request->isPost()) {
            return view('package/edit', ['vo' => $package]);
        }

        $data = $this->request->only([
            'name', 'price', 'credits', 'bonus',
            'is_recommended', 'status', 'sort',
        ]);

        $validate = new PackageValidate();
        if (!$validate->scene('edit')->check($data)) {
            return $this->error($validate->getError());
        }

        try {
            $package->save($data);
        } catch (\Throwable $e) {
            return $this->errorWithLog('保存失败', $e, 'package_update_error');
        }

        $this->logAction('package', 'update', $id, $data);
        return $this->success(['url' => '/admin/package'], '保存成功');
    }

    /**
     * 删除套餐
     */
    public function delete(int $id)
    {
        $package = CreditPackage::find($id);
        if (!$package) {
            return $this->error('套餐不存在');
        }

        try {
            $package->delete();
        } catch (\Throwable $e) {
            return $this->errorWithLog('删除失败', $e, 'package_delete_error');
        }

        $this->logAction('package', 'delete', $id, ['name' => $package->name]);
        return $this->success([], '删除成功');
    }

    /**
     * 上下架切换(status 字段)
     */
    public function toggle(int $id)
    {
        $package = CreditPackage::find($id);
        if (!$package) {
            return $this->error('套餐不存在');
        }

        $package->status = (int) $package->status === 1 ? 0 : 1;
        try {
            $package->save();
        } catch (\Throwable $e) {
            return $this->errorWithLog('操作失败', $e, 'package_toggle_error');
        }

        $this->logAction('package', 'toggle', $id, ['status' => $package->status]);
        return $this->success(['status' => $package->status], '操作成功');
    }
}
