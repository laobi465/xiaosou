<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 广告管理
 */
class Ad extends BaseAdminController
{
    public function index()
    {
        return view('ad/index');
    }

    public function add()
    {
        return view('ad/add');
    }

    public function edit(int $id)
    {
        return view('ad/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
