<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\service\PayService;
use Pansou\Pay\CaihongPay;
use think\Response;

/**
 * 彩虹易支付
 */
class Pay extends BaseController
{
    /**
     * 创建订单并跳转支付
     * PayService::createOrder → CaihongPay::buildPayUrl → redirect 跳转
     */
    public function create(int $packageId)
    {
        $userId = $this->userId();

        // 创建订单
        try {
            $order = app(PayService::class)->createOrder((int) $userId, $packageId);
        } catch (\app\common\exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            trace('pay_create_order_error: ' . $e->getMessage(), 'error');
            return $this->error('创建订单失败,请稍后重试');
        }

        // 构建支付跳转 URL
        $packageName = $order->package ? (string) $order->package->name : '积分套餐充值';
        try {
            $payUrl = app(CaihongPay::class)->buildPayUrl([
                'out_trade_no' => (string) $order->order_no,
                'name'         => $packageName,
                'money'        => number_format((float) $order->amount, 2, '.', ''),
            ]);
        } catch (\Throwable $e) {
            trace('pay_build_url_error: ' . $e->getMessage(), 'error');
            return $this->error('支付链接生成失败,请稍后重试');
        }

        $this->redirect($payUrl, 302);
    }

    /**
     * 异步回调
     * PayService::handleNotify → 直接输出字符串(非 JSON)
     */
    public function notify()
    {
        $params = $this->request->post();
        $result = app(PayService::class)->handleNotify($params);
        return Response::create($result);
    }

    /**
     * 同步跳转页
     * PayService::handleReturn → 渲染 pay/return
     */
    public function return()
    {
        $params = $this->request->get();
        $result = app(PayService::class)->handleReturn($params);

        return view('pay/return', [
            'result' => $result,
        ]);
    }
}
