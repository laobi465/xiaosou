<?php
declare(strict_types=1);

namespace app\common\crawler;

/**
 * 123云盘采集器
 *
 * url_pattern: ^https?://www\.123pan\.com/s/ (兼容 123912.com)
 *
 * crawl() 继承 AbstractCrawler 默认实现;真实采集需登录态,留待后续接入。
 */
class Pan123Crawler extends AbstractCrawler
{
    /**
     * 校验 123云盘分享 URL
     *
     * 兼容: 123pan.com / 123912.com
     *
     * {@inheritdoc}
     */
    public function validateUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        return (bool) preg_match(
            '#^https?://(?:www\.)?(?:123pan|123912)\.com/s/#i',
            $url
        );
    }

    /**
     * 解析 123云盘分享页 HTML
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
