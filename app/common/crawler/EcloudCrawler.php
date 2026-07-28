<?php
declare(strict_types=1);

namespace app\common\crawler;

/**
 * 天翼云盘采集器
 *
 * url_pattern: ^https?://cloud\.189\.cn/
 *
 * crawl() 继承 AbstractCrawler 默认实现;真实采集需登录态,留待后续接入。
 */
class EcloudCrawler extends AbstractCrawler
{
    /**
     * 校验天翼云盘分享 URL
     *
     * {@inheritdoc}
     */
    public function validateUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        return (bool) preg_match('#^https?://cloud\.189\.cn/#i', $url);
    }

    /**
     * 解析天翼云盘分享页 HTML
     *
     * 提取 <title> 标签内容。解析失败返回空数组。
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
}
