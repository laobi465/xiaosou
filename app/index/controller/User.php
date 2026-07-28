<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

/**
 * 用户中心
 * 登录校验由路由中间件 UserAuth 处理, 控制器内不再判断
 */
class User extends BaseController
{
    /**
     * 个人中心
     */
    public function index()
    {
        return view('user/index');
    }

    /**
     * 积分流水页
     */
    public function credits()
    {
        return view('user/credits');
    }

    /**
     * 订单列表页
     */
    public function orders()
    {
        return view('user/orders');
    }
}
