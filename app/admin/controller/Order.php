<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;

/**
 * 订单管理
 */
class Order extends BaseAdminController
{
    public function index()
    {
        return view('order/index');
    }

    public function add()
    {
        return view('order/add');
    }

    public function edit(int $id)
    {
        return view('order/edit', ['id' => $id]);
    }

    public function delete(int $id)
    {
        return $this->success(['id' => $id], 'success');
    }
}
