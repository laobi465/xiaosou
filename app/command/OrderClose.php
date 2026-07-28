<?php
declare(strict_types=1);

namespace app\command;

use app\common\service\PayService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 订单超时关闭
 *
 * 扫描 orders 表中 status=pending 且 expire_at < now 的订单,
 * 批量关闭并记录支付日志。过期阈值由 config('pan.order.expire_minutes') 驱动。
 *
 * 适用于 crontab 每分钟执行:
 *   * * * * * php think order:close
 */
class OrderClose extends Command
{
    protected function configure()
    {
        $this->setName('order:close')
            ->setDescription('关闭超时未支付订单');
    }

    protected function execute(Input $input, Output $output)
    {
        $count = app(PayService::class)->closeExpiredOrders();

        $output->writeln('<info>已关闭 ' . $count . ' 个过期订单</info>');
    }
}
