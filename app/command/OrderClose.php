<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 订单超时关闭
 * 定时关闭超过 expire_minutes 未支付的订单
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
        // TODO: 查询 orders 表 status=pending 且 create_time < now - expire_minutes
        //       批量更新 status=closed, 记录日志
        $output->writeln('<info>order:close - TODO: 订单超时关闭逻辑待实现</info>');
    }
}
