<?php
declare(strict_types=1);

namespace app\command;

use app\common\service\AdService;
use app\common\service\SearchService;
use Pansou\Search\MysqlFulltextDriver;
use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 广告统计与热词归档
 *
 * 定时将前日 Redis 明细归档到数据库:
 *   - AdService::aggregateDaily() 广告展示/点击归档到 ad_stats
 *   - SearchService::archiveHotKeywords() 热搜词归档到 hot_keywords
 *
 * 适用于 crontab 每天 0 点执行:
 *   0 0 * * * php think ad:agg
 */
class AdStatAgg extends Command
{
    protected function configure()
    {
        $this->setName('ad:agg')
            ->setDescription('聚合广告点击统计与热搜词归档');
    }

    protected function execute(Input $input, Output $output)
    {
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $output->writeln('<info>[ad:agg] 开始归档 ' . $yesterday . ' 数据</info>');

        // 1. 广告统计归档
        app(AdService::class)->aggregateDaily();
        $output->writeln('<info>[ad:agg] 广告展示/点击统计已归档至 ad_stats</info>');

        // 2. 热搜词归档(显式注入 MySQL 全文搜索驱动)
        $searchService = new SearchService(new MysqlFulltextDriver());
        $searchService->archiveHotKeywords();
        $output->writeln('<info>[ad:agg] 热搜词已归档至 hot_keywords</info>');

        $output->writeln('<info>[ad:agg] 归档完成</info>');
    }
}
