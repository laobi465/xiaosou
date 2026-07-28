<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\enum\CreditType;
use app\common\enum\OrderStatus;
use app\common\exception\BusinessException;
use app\common\model\CreditPackage;
use app\common\model\Order;
use app\common\model\PaymentLog;
use Pansou\Helper\HashHelper;
use Pansou\Pay\CaihongPay;
use think\facade\Db;

/**
 * 支付服务
 *
 * 参见架构设计文档 3.3 节。
 *
 * 关键设计:
 *   - 订单号格式: PS + YYYYMMDD + 10 位唯一 ID
 *   - 幂等: order_no 唯一约束 + status 校验双重保证
 *   - 异步通知是积分到账唯一权威来源,同步跳转仅展示
 *   - 超时关闭: think order:close 每分钟扫描过期订单
 */
class PayService
{
    protected CaihongPay $pay;

    public function __construct(CaihongPay $pay)
    {
        $this->pay = $pay;
    }

    /**
     * 创建支付订单
     *
     * @param int $userId    用户ID
     * @param int $packageId 套餐ID(credit_packages)
     * @return Order 订单(status=0 待支付, expire_at = now + config('pan.order.expire_minutes') 分钟)
     *
     * @throws BusinessException 套餐不存在或已下架
     */
    public function createOrder(int $userId, int $packageId): Order
    {
        // 1. 校验套餐有效(status=1 上架)
        $package = CreditPackage::where('id', $packageId)
            ->where('status', 1)
            ->find();
        if (!$package) {
            throw new BusinessException('套餐不存在或已下架');
        }

        // 2. 计算积分(基础 + 赠送)
        $credits = (int)$package->credits + (int)$package->bonus;

        // 3. 生成订单号 PS + YYYYMMDD + 10 位唯一
        $orderNo = HashHelper::orderNo('PS');

        // 4. 过期时间(配置驱动,不硬编码)
        $expireMinutes = (int)config('pan.order.expire_minutes');
        $expireAt      = date('Y-m-d H:i:s', time() + $expireMinutes * 60);

        // 5. 创建订单
        $order = Order::create([
            'order_no'   => $orderNo,
            'user_id'    => $userId,
            'package_id' => $packageId,
            'amount'     => (float)$package->price,
            'credits'    => $credits,
            'status'     => OrderStatus::PENDING,
            'expire_at'  => $expireAt,
        ]);

        // 6. 写 PaymentLog event=create(外部副作用降级)
        $this->safeLog($orderNo, 'create', [
            'user_id'    => $userId,
            'package_id' => $packageId,
            'amount'     => $package->price,
            'credits'    => $credits,
        ]);

        return $order;
    }

    /**
     * 处理支付异步通知
     *
     * @param array $params 第三方回调参数
     * @return string 回调响应("success" 成功 / "fail" 失败)
     */
    public function handleNotify(array $params): string
    {
        $orderNo = (string)($params['out_trade_no'] ?? '');

        // 1. 写 PaymentLog event=notify(外部副作用降级)
        $this->safeLog($orderNo, 'notify', $params);

        // 2. 验签
        try {
            if (!$this->pay->verifyNotify($params)) {
                trace('pay_notify_verify_fail: order_no=' . $orderNo, 'error');
                return 'fail';
            }
        } catch (\Throwable $e) {
            trace('pay_notify_verify_exception: ' . $e->getMessage(), 'error');
            return 'fail';
        }

        // 3. 查询订单
        $order = Order::where('order_no', $orderNo)->find();
        if (!$order) {
            trace('pay_notify_order_not_found: order_no=' . $orderNo, 'error');
            return 'fail';
        }

        // 4. 幂等: 已支付直接返回 success
        if ((int)$order->status === OrderStatus::PAID) {
            return 'success';
        }

        // 5. 事务: 更新订单 + 积分到账
        try {
            Db::transaction(function () use ($order, $params) {
                // a. 更新订单为已支付
                $order->status   = OrderStatus::PAID;
                $order->pay_at   = date('Y-m-d H:i:s');
                $order->trade_no = (string)($params['trade_no'] ?? '');
                $order->pay_type = (string)($params['type'] ?? '');
                $order->save();

                // b. 积分到账(关联订单主键 id)
                $packageName = $order->package ? (string)$order->package->name : '未知套餐';
                app(CreditService::class)->recharge(
                    (int)$order->user_id,
                    (int)$order->credits,
                    CreditType::RECHARGE,
                    (int)$order->id,
                    '充值套餐:' . $packageName
                );
            });
        } catch (\Throwable $e) {
            trace('pay_notify_transaction_error: order_no=' . $orderNo . ' msg=' . $e->getMessage(), 'error');
            return 'fail';
        }

        return 'success';
    }

    /**
     * 处理支付同步跳转(仅展示,不触发积分到账)
     *
     * @param array $params 同步跳转参数
     * @return array {status, order_no, credits} status=-1 表示订单不存在
     */
    public function handleReturn(array $params): array
    {
        $orderNo = (string)($params['out_trade_no'] ?? '');

        // 写 PaymentLog event=sync(外部副作用降级)
        $this->safeLog($orderNo, 'sync', $params);

        $order = Order::where('order_no', $orderNo)->find();
        if (!$order) {
            return [
                'status'   => -1,
                'order_no' => $orderNo,
                'credits'  => 0,
            ];
        }

        return [
            'status'   => (int)$order->status,
            'order_no' => $order->order_no,
            'credits'  => (int)$order->credits,
        ];
    }

    /**
     * 关闭过期订单(供 think order:close 命令调用)
     *
     * @return int 关闭数量
     */
    public function closeExpiredOrders(): int
    {
        try {
            $now = date('Y-m-d H:i:s');

            // 扫描过期待支付订单
            $expiredOrders = Order::where('status', OrderStatus::PENDING)
                ->where('expire_at', '<', $now)
                ->select();

            if ($expiredOrders->isEmpty()) {
                return 0;
            }

            $orderNos = $expiredOrders->column('order_no');

            // 批量更新为已关闭(双条件防止并发误更新)
            $affected = Order::whereIn('order_no', $orderNos)
                ->where('status', OrderStatus::PENDING)
                ->update(['status' => OrderStatus::CLOSED]);

            // 写 PaymentLog event=close(外部副作用降级,逐条独立)
            foreach ($expiredOrders as $order) {
                $this->safeLog($order->order_no, 'close', [
                    'order_no'  => $order->order_no,
                    'expire_at' => $order->expire_at,
                    'closed_at' => $now,
                ]);
            }

            return (int)$affected;
        } catch (\Throwable $e) {
            trace('close_expired_orders_error: ' . $e->getMessage(), 'error');
            return 0;
        }
    }

    /**
     * 安全写入支付日志(异常降级,不影响主流程)
     *
     * @param string                $orderNo 订单号
     * @param string                $event   create/notify/sync/refund/close
     * @param array|string|scalar   $data    原始数据(将 json_encode)
     */
    protected function safeLog(string $orderNo, string $event, $data): void
    {
        try {
            PaymentLog::create([
                'order_no'     => $orderNo,
                'event'        => $event,
                'request_data' => is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip'           => $this->resolveIp(),
            ]);
        } catch (\Throwable $e) {
            trace('payment_log_write_error: event=' . $event . ' order_no=' . $orderNo . ' msg=' . $e->getMessage(), 'error');
        }
    }

    /**
     * 解析客户端 IP(CLI 模式或异常时返回 null)
     */
    protected function resolveIp(): ?string
    {
        try {
            $ip = request()->ip();
            return is_string($ip) && $ip !== '' ? $ip : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
