<?php
declare(strict_types=1);

namespace app\common\model;

use app\common\enum\OrderStatus;
use think\Model;

/**
 * 订单
 * 表: orders
 * 状态: 0待支付 1已支付 2已退款 3已关闭
 */
class Order extends Model
{
    protected $name = 'orders';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    protected $type = [
        'user_id'    => 'int',
        'package_id' => 'int',
        'amount'     => 'decimal',
        'credits'    => 'int',
        'status'     => 'int',
    ];

    /**
     * 反向关联: 下单用户
     */
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 反向关联: 套餐
     */
    public function package(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(CreditPackage::class, 'package_id');
    }

    /**
     * 一对多: 支付日志
     */
    public function paymentLogs(): \think\model\relation\HasMany
    {
        return $this->hasMany(PaymentLog::class, 'order_no', 'order_no');
    }

    /**
     * 查询范围: 已支付(语义化,推荐使用)
     */
    public function scopePaid($query)
    {
        return $query->where('status', OrderStatus::PAID);
    }

    /**
     * 查询范围: 待支付
     */
    public function scopePending($query)
    {
        return $query->where('status', OrderStatus::PENDING);
    }

    /**
     * 查询范围: 已支付(兼容旧调用,语义不精确,推荐使用 scopePaid)
     * @deprecated 请使用 scopePaid
     */
    public function scopeNormal($query)
    {
        return $query->where('status', OrderStatus::PAID);
    }
}
