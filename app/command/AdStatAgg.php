<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 广告统计聚合
 * 定时将 ad_stats 明细聚合到日/小时维度
 */
class AdStatAgg extends Command
{
    protected function configure()
    {
        $this->setName('ad:agg')
            ->setDescription('聚合广告点击统计数据');
    }

    protected function execute(Input $input, Output $output)
    {
        // TODO: 读取 ad_stats 明细, 按 ad_id + 日期维度聚合
        //       写入聚合表或 Redis ZSet
        $output->writeln('<info>ad:agg - TODO: 广告统计聚合逻辑待实现</info>');
    }
}
