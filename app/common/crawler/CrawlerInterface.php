<?php
declare(strict_types=1);

namespace app\common\crawler;

use app\common\model\CrawlTask;

/**
 * 采集器接口
 *
 * 参见架构设计文档 3.5 节。
 * 每个网盘源实现该接口,通过 pan_sources.crawler_class 反射实例化。
 */
interface CrawlerInterface
{
    /**
     * 执行采集
     *
     * @param CrawlTask $task 采集任务
     * @return array 采集到的资源项列表(ResourceItem[])
     */
    public function crawl(CrawlTask $task): array;

    /**
     * 校验网盘分享 URL 是否合法
     *
     * @param string $url 分享链接
     * @return bool true=合法 false=非法
     */
    public function validateUrl(string $url): bool;

    /**
     * 解析分享页面 HTML,提取资源信息
     *
     * @param string $html 分享页 HTML
     * @return array 解析出的资源信息
     */
    public function parseSharePage(string $html): array;
}
