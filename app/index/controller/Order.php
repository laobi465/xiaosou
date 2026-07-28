<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\model\CreditPackage;
use app\common\model\Order as OrderModel;

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
        $packages = [];
        try {
            $packages = CreditPackage::where('status', 1)
                ->order('sort', 'asc')
                ->select();
        } catch (\Throwable $e) {
            trace('order_packages_error: ' . $e->getMessage(), 'error');
        }

        return view('order/packages', [
            'packages' => $packages,
        ]);
    }

    /**
     * 我的订单列表页
     * 登录校验由路由中间件 UserAuth 处理
     */
    public function myList()
    {
        $userId = $this->userId();

        $orders = OrderModel::where('user_id', $userId)
            ->order('create_time', 'desc')
            ->paginate(config('pan.page_size', 15));

        return view('order/my_list', [
            'orders' => $orders,
        ]);
    }

    /**
     * 订单详情页(校验 user_id 权限)
     */
    public function detail(int $id)
    {
        $userId = $this->userId();

        $order = OrderModel::where('id', $id)
            ->where('user_id', $userId)
            ->find();
        if (!$order) {
            $this->fail('订单不存在');
        }

        // 支付日志
        $logs = [];
        try {
            $logs = $order->paymentLogs;
        } catch (\Throwable $e) {
            trace('order_detail_logs_error: ' . $e->getMessage(), 'error');
        }

        return view('order/detail', [
            'order' => $order,
            'logs'  => $logs,
        ]);
    }
}
