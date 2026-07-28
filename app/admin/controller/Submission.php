<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 用户提交审核
 */
class Submission extends BaseAdminController
{
    public function index()
    {
        return view('submission/index');
    }

    public function add()
    {
        return view('submission/add');
    }

    public function edit(int $id)
    {
        return view('submission/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
