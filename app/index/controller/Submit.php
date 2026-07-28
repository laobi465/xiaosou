<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

/**
 * 资源提交
 * 登录校验由路由中间件 UserAuth 处理
 */
class Submit extends BaseController
{
    /**
     * 提交页
     */
    public function index()
    {
        return view('submit/index');
    }

    /**
     * 创建提交(Ajax)
     * TODO: 参数校验, 敏感词过滤, 入库 submission 表
     */
    public function create()
    {
        return $this->success([], 'success');
    }

    /**
     * 我的提交列表页
     */
    public function myList()
    {
        return view('submit/my_list');
    }
}
