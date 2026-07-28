<?php
declare(strict_types=1);

namespace app\common\crawler;

use app\common\model\CrawlTask;
use GuzzleHttp\Client;

/**
 * 采集器抽象基类
 *
 * 提供通用 HTTP 客户端(超时 + UA 轮换)与随机延时,
 * 子类只需实现具体的页面解析逻辑。
 */
abstract class AbstractCrawler implements CrawlerInterface
{
    /**
     * UA 轮换池
     */
    protected const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    ];

    /**
     * 获取配置好超时与 UA 轮换的 HTTP 客户端
     *
     * @return Client
     */
    protected function httpClient(): Client
    {
        return new Client([
            'timeout'         => 30,
            'connect_timeout' => 10,
            'headers'         => [
                'User-Agent'      => $this->pickUserAgent(),
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
            ],
            'verify'          => false,
            'http_errors'     => false,
        ]);
    }

    /**
     * 随机延时 500-2000ms,避免对目标站点造成压力
     */
    protected function delay(): void
    {
        usleep(random_int(500, 2000) * 1000);
    }

    /**
     * 从 UA 池中随机选取一个
     */
    protected function pickUserAgent(): string
    {
        return self::USER_AGENTS[array_rand(self::USER_AGENTS)];
    }

    /**
     * {@inheritdoc}
     */
    public function crawl(CrawlTask $task): array
    {
        // TODO: 子类实现具体采集逻辑
        //   1. httpClient()->get($task->target_url)
        //   2. parseSharePage($html) 提取资源项
        //   3. delay() 随机延时
        //   4. 返回 ResourceItem[]
        return [];
    }
}
