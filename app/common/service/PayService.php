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
     * 创建支付订单(幂等)
     *
     * 同一用户对同一套餐若存在未过期的待支付订单, 则复用, 避免重复创建。
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

        // 2. 幂等: 复用未过期的待支付订单(防止重复下单)
        $now = date('Y-m-d H:i:s');
        $existing = Order::where('user_id', $userId)
            ->where('package_id', $packageId)
            ->where('status', OrderStatus::PENDING)
            ->where('expire_at', '>=', $now)
            ->order('id', 'desc')
            ->find();
        if ($existing) {
            return $existing;
        }

        // 3. 计算积分(基础 + 赠送)
        $credits = (int)$package->credits + (int)$package->bonus;

        // 4. 生成订单号 PS + YYYYMMDD + 10 位唯一
        $orderNo = HashHelper::orderNo('PS');

        // 5. 过期时间(配置驱动,不硬编码;最小 1 分钟防止订单立即过期)
        $expireMinutes = max(1, (int)(config('pan.order.expire_minutes') ?? 30));
        $expireAt      = date('Y-m-d H:i:s', time() + $expireMinutes * 60);

        // 6. 创建订单
        $order = Order::create([
            'order_no'   => $orderNo,
            'user_id'    => $userId,
            'package_id' => $packageId,
            'amount'     => (float)$package->price,
            'credits'    => $credits,
            'status'     => OrderStatus::PENDING,
            'expire_at'  => $expireAt,
        ]);

        // 7. 写 PaymentLog event=create(外部副作用降级)
        $this->safeLog($orderNo, 'create', [
            'user_id'    => $userId,
            'package_id' => $packageId,
            'amount'     => $package->price,
            'credits'    => $credits,
        ]);

        return $order;
    }

    /**
     * 构建支付跳转 URL(业务逻辑下沉, Controller 不直接操作 SDK)
     *
     * @param Order $order 订单
     * @return string 收银台跳转完整 URL
     */
    public function buildPayUrl(Order $order): string
    {
        $packageName = $order->package ? (string) $order->package->name : '积分套餐充值';
        return $this->pay->buildPayUrl([
            'out_trade_no' => (string) $order->order_no,
            'name'         => $packageName,
            'money'        => number_format((float) $order->amount, 2, '.', ''),
        ]);
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

        // 4. 幂等: 已支付或已关闭直接返回 success(已关闭订单回调不再发积分)
        if (in_array((int)$order->status, [OrderStatus::PAID, OrderStatus::CLOSED], true)) {
            trace('pay_notify_idempotent_skip: order_no=' . $orderNo . ' status=' . (int)$order->status, 'info');
            return 'success';
        }

        // 5. 事务: 乐观锁更新订单 + 积分到账
        try {
            Db::transaction(function () use ($order, $params, $orderNo) {
                // a. 校验回调金额与订单金额一致(用 bcmath 避免 float 精度问题)
                $notifyMoney = (string)($params['money'] ?? '');
                $orderAmount = (string)$order->amount;
                // bccomp 返回 0 表示相等; 精度 2 位小数(分)
                if (bccomp($notifyMoney, $orderAmount, 2) !== 0) {
                    // 金额不匹配: 抛 RuntimeException 由外层 catch(\Throwable) 返回 fail
                    throw new \RuntimeException(
                        '回调金额不匹配: notify=' . $notifyMoney . ' order=' . $orderAmount
                    );
                }

                // b. 乐观锁更新订单(仅 PENDING -> PAID, 防并发双发积分)
                $now      = date('Y-m-d H:i:s');
                $tradeNo  = (string)($params['trade_no'] ?? '');
                $payType  = (string)($params['type'] ?? '');
                $affected = Order::where('order_no', $orderNo)
                    ->where('status', OrderStatus::PENDING)
                    ->update([
                        'status'   => OrderStatus::PAID,
                        'pay_at'   => $now,
                        'trade_no' => $tradeNo,
                        'pay_type' => $payType,
                    ]);

                if ($affected === 0) {
                    // 并发回调: 订单已被其他回调处理, 抛 BusinessException 返回 success(幂等)
                    throw new BusinessException('订单状态已变更, 跳过积分发放');
                }

                // c. 积分到账(关联订单主键 id)
                $packageName = $order->package ? (string)$order->package->name : '未知套餐';
                app(CreditService::class)->recharge(
                    (int)$order->user_id,
                    (int)$order->credits,
                    CreditType::RECHARGE,
                    (int)$order->id,
                    '充值套餐:' . $packageName
                );
            });
        } catch (BusinessException $e) {
            // 订单已变更(并发回调幂等): 记录日志并返回 success 避免重复回调
            trace('pay_notify_idempotent_skip: order_no=' . $orderNo . ' msg=' . $e->getMessage(), 'info');
            return 'success';
        } catch (\Throwable $e) {
            // 金额不匹配或其他事务错误: 记录日志并返回 fail
            trace('pay_notify_transaction_error: order_no=' . $orderNo . ' msg=' . $e->getMessage(), 'error');
            return 'fail';
        }

        return 'success';
    }

    /**
     * 处理支付同步跳转(仅展示,不触发积分到账)
     *
     * 同步跳转先调用 CaihongPay->verifyReturn 校验签名,
     * 验签失败时返回 status=-2(支付状态待确认), 防止伪造跳转欺骗用户。
     *
     * @param array $params 同步跳转参数
     * @return array {status, order_no, credits} status=-1 订单不存在; status=-2 验签失败(待确认)
     */
    public function handleReturn(array $params): array
    {
        $orderNo = (string)($params['out_trade_no'] ?? '');

        // 写 PaymentLog event=sync(外部副作用降级)
        $this->safeLog($orderNo, 'sync', $params);

        // 验签: 同步跳转参数必须通过签名校验, 否则不信任展示数据
        try {
            if (!$this->pay->verifyReturn($params)) {
                trace('pay_return_verify_fail: order_no=' . $orderNo, 'error');
                return [
                    'status'   => -2,
                    'order_no' => $orderNo,
                    'credits'  => 0,
                ];
            }
        } catch (\Throwable $e) {
            trace('pay_return_verify_exception: ' . $e->getMessage(), 'error');
            return [
                'status'   => -2,
                'order_no' => $orderNo,
                'credits'  => 0,
            ];
        }

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
