<?php
declare(strict_types=1);

namespace app\common\service;

use app\common\model\Order;
use Pansou\Pay\CaihongPay;

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
     * @return Order 订单(status=0 待支付, expire_at = now+30min)
     */
    public function createOrder(int $userId, int $packageId): Order
    {
        // TODO: 1. 校验套餐有效(CreditPackage)
        // TODO: 2. 生成订单号 Pansou\Helper\HashHelper::orderNo('PS')
        // TODO: 3. 创建订单 status=0, expire_at = now + 30min
        // TODO: 4. 记录 payment_logs event=create
        // TODO: 5. 返回订单 + buildPayUrl 拼接支付跳转 URL
        return new Order();
    }

    /**
     * 处理支付异步通知
     *
     * @param array $params 第三方回调参数
     * @return string 回调响应("success" 成功 / "fail" 失败)
     */
    public function handleNotify(array $params): string
    {
        // TODO: 1. $this->pay->verifyNotify($params) 验签,失败返回 'fail'
        // TODO: 2. 幂等检查(订单已支付直接返回 'success')
        // TODO: 3. 事务: 更新订单 status=1 + 积分到账(CreditService) + 写流水
        // TODO: 4. 记录 payment_logs event=notify
        // TODO: 5. 返回 'success'
        return 'fail';
    }
}
