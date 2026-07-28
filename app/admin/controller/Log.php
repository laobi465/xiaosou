<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 日志查看
 */
class Log extends BaseAdminController
{
    public function index()
    {
        return view('log/index');
    }

    public function add()
    {
        return view('log/add');
    }

    public function edit(int $id)
    {
        return view('log/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
