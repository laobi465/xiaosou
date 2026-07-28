<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;

/**
 * 彩虹易支付
 */
class Pay extends BaseController
{
    /**
     * 创建订单并跳转支付
     * TODO: 创建订单记录, 调用 PayService/CaihongPay 构建跳转 URL
     */
    public function create(int $packageId)
    {
        return $this->success(['package_id' => $packageId], 'success');
    }

    /**
     * 异步回调
     * TODO: 验签, 更新订单状态, 发放积分
     */
    public function notify()
    {
        return $this->success([], 'success');
    }

    /**
     * 同步跳转页
     */
    public function return()
    {
        return view('pay/return');
    }
}
