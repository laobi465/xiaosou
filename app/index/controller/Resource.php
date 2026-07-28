<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

/**
 * 资源详情
 */
class Resource extends BaseController
{
    /**
     * 资源详情页
     */
    public function detail(int $id)
    {
        return view('resource/detail', ['id' => $id]);
    }

    /**
     * 查看链接(Ajax)
     * TODO: 消耗积分查看链接, 调用 CreditService 扣减后返回真实链接
     */
    public function viewLink(int $id)
    {
        return $this->success([], 'success');
    }
}
