<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

/**
 * 订单/套餐
 */
class Order extends BaseController
{
    /**
     * 套餐列表页
     */
    public function packages()
    {
        return view('order/packages');
    }

    /**
     * 订单列表页
     * 登录校验由路由中间件 UserAuth 处理
     */
    public function myList()
    {
        return view('order/my_list');
    }

    /**
     * 订单详情页
     */
    public function detail(int $id)
    {
        return view('order/detail', ['id' => $id]);
    }
}
