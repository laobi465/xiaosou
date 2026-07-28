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
              ->paginate(15, false, ['query' => $this->request->param()]);

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
     */
    public function manualComplete(int $id)
    {
        $order = OrderModel::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        if ((int) $order->status !== OrderStatus::PENDING) {
            return $this->error('订单状态不允许补单');
        }

        $data = $this->request->only(['trade_no']);
        $data['order_id'] = $id;

        $validate = new OrderValidate();
        if (!$validate->scene('manualComplete')->check($data)) {
            return $this->error($validate->getError());
        }

        $adminId = $this->adminId();

        try {
            Db::transaction(function () use ($order, $data, $adminId) {
                $order->status   = OrderStatus::PAID;
                $order->pay_at   = date('Y-m-d H:i:s');
                $order->trade_no = (string) $data['trade_no'];
                $order->save();

                app(CreditService::class)->recharge(
                    (int) $order->user_id,
                    (int) $order->credits,
                    CreditType::RECHARGE,
                    (int) $order->id,
                    '订单补单充值',
                    $adminId
                );
            });
        } catch (\Throwable $e) {
            return $this->error('补单失败: ' . $e->getMessage());
        }

        $this->logAction('order', 'manual_complete', $id, [
            'trade_no' => $data['trade_no'],
            'credits'  => $order->credits,
        ]);
        return $this->success([], '补单成功');
    }

    /**
     * 退款处理(status=2/refund_at/refund_reason + CreditService::consume REFUND)
     */
    public function refund(int $id)
    {
        $order = OrderModel::find($id);
        if (!$order) {
            return $this->error('订单不存在');
        }

        if ((int) $order->status !== OrderStatus::PAID) {
            return $this->error('订单状态不允许退款');
        }

        $refundReason = (string) $this->request->post('refund_reason', '');
        if ($refundReason === '') {
            return $this->error('退款原因不能为空');
        }

        $adminId = $this->adminId();

        try {
            Db::transaction(function () use ($order, $refundReason, $adminId) {
                $order->status        = OrderStatus::REFUND;
                $order->refund_at     = date('Y-m-d H:i:s');
                $order->refund_reason = $refundReason;
                $order->save();

                app(CreditService::class)->consume(
                    (int) $order->user_id,
                    (int) $order->credits,
                    CreditType::REFUND,
                    (int) $order->id,
                    '订单退款扣回积分',
                    $adminId
                );
            });
        } catch (CreditNotEnoughException $e) {
            return $this->error('用户积分不足,无法退款扣回');
        } catch (\Throwable $e) {
            return $this->error('退款失败: ' . $e->getMessage());
        }

        $this->logAction('order', 'refund', $id, [
            'refund_reason' => $refundReason,
            'credits'       => $order->credits,
        ]);
        return $this->success([], '退款成功');
    }
}
