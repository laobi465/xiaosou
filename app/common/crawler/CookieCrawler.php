<?php
declare(strict_types=1);

namespace app\common\crawler;

/**
 * 通用兜底采集器(Cookie 网盘)
 *
 * data.sql url_pattern: ^https?://pan\.quark\.cn/share/
 * 任务说明: 始终返回 true,作为无法识别网盘源时的通用兜底。
 *
 * crawl() 继承 AbstractCrawler 默认实现。
 */
class CookieCrawler extends AbstractCrawler
{
    /**
     * 通用兜底:始终返回 true
     *
     * {@inheritdoc}
     */
    public function validateUrl(string $url): bool
    {
        return true;
    }

    /**
     * 解析分享页 HTML
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
