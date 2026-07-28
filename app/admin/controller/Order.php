<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\BaseAdminController;
use app\common\enum\CreditType;
use app\common\enum\OrderStatus;
use app\common\exception\CreditNotEnoughException;
use app\common\model\Order as OrderModel;
use app\common\model\PaymentLog;
use app\common\service\CreditService;
use app\common\validate\OrderValidate;
use think\facade\Db;

/**
 * 订单管理
 */
class Order extends BaseAdminController
{
    /**
     * 订单列表(筛选 status)
     */
    public function index()
    {
        $status   = $this->request->get('status', '');
        $orderNo  = (string) $this->request->get('order_no', '');

        $list = OrderModel::with(['user', 'package'])
            ->when($orderNo !== '', function ($query) use ($orderNo) {
                $query->whereLike('order_no', '%' . $orderNo . '%');
            })->when($status !== '' && $status !== null, function ($query) use ($status) {
                $query->where('status', (int) $status);
            })->order('id', 'desc')
              ->paginate(config('pan.page_size.admin', 15), false, ['query' => $this->request->param()]);

        return view('order/index', [
            'list'     => $list,
            'status'   => $status,
            'order_no' => $orderNo,
        ]);
    }

    /**
     * 订单详情 + 支付日志
     */
    public function detail(int $id)
    {
        $order = OrderModel::with(['user', 'package'])->find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        $paymentLogs = PaymentLog::where('order_no', $order->order_no)
            ->order('id', 'desc')
            ->select();

        return view('order/detail', [
            'vo'           => $order,
            'paymentLogs'  => $paymentLogs,
        ]);
    }

    /**
     * 手动补单(更新 status=1/pay_at/trade_no + CreditService::recharge RECHARGE)
     *
     * 并发安全: 事务内通过 where('status', PENDING)->update 原子流转,
     * 仅当 affected=1(抢到补单权)时才执行积分充值,避免并发补单重复发放积分。
     */
    public function manualComplete(int $id)
    {
        $order = OrderModel::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        // 事务外快速校验,减少无效请求进入事务
        if ((int) $order->status !== OrderStatus::PENDING) {
            return $this->error('订单状态不允许补单');
        }

        $data = $this->request->only(['trade_no']);
        $data['order_id'] = $id;

        $validate = new OrderValidate();
        if (!$validate->scene('manualComplete')->check($data)) {
            return $this->error($validate->getError());
        }

        $adminId  = $this->adminId();
        $orderNo  = (string) $order->order_no;
        $tradeNo  = (string) $data['trade_no'];
        $now      = date('Y-m-d H:i:s');
        $userId   = (int) $order->user_id;
        $credits  = (int) $order->credits;
        $orderId  = (int) $order->id;

        try {
            $recharged = false;
            Db::transaction(function () use (
                $orderNo, $tradeNo, $now, $userId, $credits, $orderId, $adminId, &$recharged
            ) {
                // 原子流转: 仅 PENDING → PAID,affected=0 表示已被其他请求处理
                $affected = OrderModel::where('order_no', $orderNo)
                    ->where('status', OrderStatus::PENDING)
                    ->update([
                        'status'   => OrderStatus::PAID,
                        'pay_at'   => $now,
                        'trade_no' => $tradeNo,
                    ]);

                if ($affected !== 1) {
                    // 并发场景下状态已变更,放弃补单(不抛异常,外层按已处理处理)
                    return;
                }

                app(CreditService::class)->recharge(
                    $userId,
                    $credits,
                    CreditType::RECHARGE,
                    $orderId,
                    '订单补单充值',
                    $adminId
                );
                $recharged = true;
            });
        } catch (\Throwable $e) {
            return $this->errorWithLog('补单失败,请稍后重试', $e, 'order_manual_complete_error');
        }

        if (!$recharged) {
            // 并发或状态已变更
            return $this->error('订单状态不允许补单');
        }

        $this->logAction('order', 'manual_complete', $id, [
            'trade_no' => $tradeNo,
            'credits'  => $credits,
        ]);
        return $this->success([], '补单成功');
    }

    /**
     * 退款处理(status=2/refund_at/refund_reason + CreditService::consume REFUND)
     *
     * 并发安全: 事务内通过 where('status', PAID)->update 原子流转,
     * 仅当 affected=1(抢到退款权)时才扣回积分,避免并发退款重复扣回。
     */
    public function refund(int $id)
    {
        $order = OrderModel::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        // 事务外快速校验
        if ((int) $order->status !== OrderStatus::PAID) {
            return $this->error('订单状态不允许退款');
        }

        $refundReason = (string) $this->request->post('refund_reason', '');
        if ($refundReason === '') {
            return $this->error('退款原因不能为空');
        }

        $adminId = $this->adminId();
        $orderNo = (string) $order->order_no;
        $now     = date('Y-m-d H:i:s');
        $userId  = (int) $order->user_id;
        $credits = (int) $order->credits;
        $orderId = (int) $order->id;

        try {
            $refunded = false;
            Db::transaction(function () use (
                $orderNo, $refundReason, $now, $userId, $credits, $orderId, $adminId, &$refunded
            ) {
                // 原子流转: 仅 PAID → REFUND,affected=0 表示已被其他请求处理
                $affected = OrderModel::where('order_no', $orderNo)
                    ->where('status', OrderStatus::PAID)
                    ->update([
                        'status'        => OrderStatus::REFUND,
                        'refund_at'     => $now,
                        'refund_reason' => $refundReason,
                    ]);

                if ($affected !== 1) {
                    return;
                }

                app(CreditService::class)->consume(
                    $userId,
                    $credits,
                    CreditType::REFUND,
                    $orderId,
                    '订单退款扣回积分',
                    $adminId
                );
                $refunded = true;
            });
        } catch (CreditNotEnoughException $e) {
            return $this->error('用户积分不足,无法退款扣回');
        } catch (\Throwable $e) {
            return $this->errorWithLog('退款失败,请稍后重试', $e, 'order_refund_error');
        }

        if (!$refunded) {
            return $this->error('订单状态不允许退款');
        }

        $this->logAction('order', 'refund', $id, [
            'refund_reason' => $refundReason,
            'credits'       => $credits,
        ]);
        return $this->success([], '退款成功');
    }
}
