<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 系统配置
 */
class Config extends BaseAdminController
{
    public function index()
    {
        return view('config/index');
    }

    public function add()
    {
        return view('config/add');
    }

    public function edit(int $id)
    {
        return view('config/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
