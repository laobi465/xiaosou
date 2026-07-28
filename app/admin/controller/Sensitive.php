<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 敏感词
 */
class Sensitive extends BaseAdminController
{
    public function index()
    {
        return view('sensitive/index');
    }

    public function add()
    {
        return view('sensitive/add');
    }

    public function edit(int $id)
    {
        return view('sensitive/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
