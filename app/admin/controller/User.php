<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 用户管理
 */
class User extends BaseAdminController
{
    public function index()
    {
        return view('user/index');
    }

    public function add()
    {
        return view('user/add');
    }

    public function edit(int $id)
    {
        return view('user/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
