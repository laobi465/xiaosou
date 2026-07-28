<?php
declare(strict_types=1);

namespace app\common\crawler;

/**
 * 芒果网盘采集器(虚构)
 *
 * 任务指定 url_pattern: mangguo.com / pan.mangguo.com
 * data.sql 种子: ^https?://pan\.mangox\.net/
 *
 * 同时兼容两种域名形式,以适应后续可能的域名映射。
 *
 * crawl() 继承 AbstractCrawler 默认实现;真实采集需登录态,留待后续接入。
 */
class MangoCrawler extends AbstractCrawler
{
    /**
     * 校验芒果网盘分享 URL
     *
     * 兼容: mangguo.com / pan.mangguo.com / pan.mangox.net
     *
     * {@inheritdoc}
     */
    public function validateUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        return (bool) preg_match(
            '#^https?://(?:[a-z0-9-]+\.)?(?:mangguo\.com|mangox\.net)/#i',
            $url
        );
    }

    /**
     * 解析芒果网盘分享页 HTML
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
