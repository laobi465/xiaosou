<?php
declare(strict_types=1);

namespace app\common\crawler;

/**
 * 移动云盘采集器
 *
 * url_pattern (data.sql): ^https?://yun\.139\.com/
 * 任务指定域名: caiyun.feixin.10086.cn (移动云盘新版)
 *
 * 兼容两者: yun.139.com / caiyun.feixin.10086.cn
 *
 * crawl() 继承 AbstractCrawler 默认实现;真实采集需登录态,留待后续接入。
 */
class YidongCrawler extends AbstractCrawler
{
    /**
     * 校验移动云盘分享 URL
     *
     * 兼容: caiyun.feixin.10086.cn / yun.139.com
     *
     * {@inheritdoc}
     */
    public function validateUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        return (bool) preg_match(
            '#^https?://(?:caiyun\.feixin\.10086\.cn|yun\.139\.com)/#i',
            $url
        );
    }

    /**
     * 解析移动云盘分享页 HTML
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
