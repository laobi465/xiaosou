<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 资源管理
 */
class Resource extends BaseAdminController
{
    public function index()
    {
        return view('resource/index');
    }

    public function add()
    {
        return view('resource/add');
    }

    public function edit(int $id)
    {
        return view('resource/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
