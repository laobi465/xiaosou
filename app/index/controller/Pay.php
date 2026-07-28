<?php
declare(strict_types=1);

namespace app\index\controller;

use app\BaseController;
use app\common\service\PayService;
use think\Response;

/**
 * 彩虹易支付
 *
 * 业务逻辑下沉到 PayService, Controller 只负责参数转发与响应。
 * notify 路由层挂载 PayIpWhitelist 中间件做 IP 白名单校验。
 */
class Pay extends BaseController
{
    /**
     * 创建订单并跳转支付
     * PayService::createOrder(幂等) → PayService::buildPayUrl → redirect 跳转
     */
    public function create(int $packageId)
    {
        $userId = $this->userId();

        // 创建订单(幂等: 同一用户同一套餐未过期待支付订单复用)
        try {
            $order = app(PayService::class)->createOrder((int) $userId, $packageId);
        } catch (\app\common\exception\BusinessException $e) {
            return $this->error($e->getMessage());
        } catch (\Throwable $e) {
            return $this->errorWithLog('创建订单失败,请稍后重试', $e, 'pay_create_order_error');
        }

        // 构建支付跳转 URL(业务逻辑在 PayService, Controller 不直接操作 SDK)
        try {
            $payUrl = app(PayService::class)->buildPayUrl($order);
        } catch (\Throwable $e) {
            return $this->errorWithLog('支付链接生成失败,请稍后重试', $e, 'pay_build_url_error');
        }

        $this->redirect($payUrl, 302);
    }

    /**
     * 异步回调
     * 路由层已挂载 PayIpWhitelist 中间件做 IP 白名单校验
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
     * PayService::handleReturn(含 verifyReturn 验签) → 渲染 pay/return
     * 验签失败时 status=-2, 页面展示"支付状态待确认"
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
