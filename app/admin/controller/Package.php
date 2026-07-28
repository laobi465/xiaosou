<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 积分套餐
 */
class Package extends BaseAdminController
{
    public function index()
    {
        return view('package/index');
    }

    public function add()
    {
        return view('package/add');
    }

    public function edit(int $id)
    {
        return view('package/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
