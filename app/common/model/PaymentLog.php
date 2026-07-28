<?php
declare(strict_types=1);

namespace app\common\model;

use think\Model;

/**
 * 支付日志(按月分表, 保留 1 年)
 * 表: payment_logs (仅 create_time)
 * event: create/notify/sync/refund
 */
class PaymentLog extends Model
{
    protected $name = 'payment_logs';

    protected $autoWriteTimestamp = 'datetime';

    protected $createTime = 'create_time';
    protected $updateTime = false;
}
