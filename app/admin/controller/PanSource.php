<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 网盘源
 */
class PanSource extends BaseAdminController
{
    public function index()
    {
        return view('pan_source/index');
    }

    public function add()
    {
        return view('pan_source/add');
    }

    public function edit(int $id)
    {
        return view('pan_source/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
