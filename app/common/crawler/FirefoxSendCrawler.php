<?php
declare(strict_types=1);

namespace app\common\crawler;

use app\common\model\CrawlTask;

/**
 * Firefox Send 采集器
 *
 * url_pattern: ^https?://send\.firefox\.com/
 *
 * 注:Firefox Send 已于 2020 年停止服务,本采集器仅保留框架完整性,
 *     validateUrl + parseSharePage 可用,crawl 返回空数组。
 */
class FirefoxSendCrawler extends AbstractCrawler
{
    /**
     * 校验 Firefox Send 分享 URL
     *
     * {@inheritdoc}
     */
    public function validateUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        return (bool) preg_match('#^https?://send\.firefox\.com/#i', $url);
    }

    /**
     * 解析 Firefox Send 分享页 HTML
     *
     * 提取 <title> 标签内容。
     *
     * {@inheritdoc}
     */
    public function parseSharePage(string $html): array
    {
        $title = $this->extractTitleFromHtml($html);
        if ($title === null) {
            return [];
        }
        return ['title' => $title];
    }

    /**
     * {@inheritdoc}
     *
     * Firefox Send 已停止服务,无可采集内容,返回空数组。
     *
     * TODO: 服务已下线,本方法仅保留接口契约完整。
     */
    public function crawl(CrawlTask $task): array
    {
        // Firefox Send 已停止服务,无内容可采集
        return [];
    }

    /**
     * Firefox Send 无搜索接口
     *
     * {@inheritdoc}
     */
    protected function buildSearchUrl(string $keywords): ?string
    {
        return null;
    }
}
