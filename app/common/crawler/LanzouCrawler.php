<?php
declare(strict_types=1);

namespace app\common\crawler;

use app\common\model\CrawlTask;

/**
 * 蓝奏云网盘采集器
 *
 * url_pattern: ^https?://[a-z]+\.lanzou[a-z]*\. (pan.lanzou.com / pan.lanzoui.com / pan.lanzoux.com / pan.lanzouo.com / pan.lanzout.com / www.lanzou.com 等)
 *
 * 注:蓝奏云无公开搜索接口,真实采集需登录态/Cookie,超出本任务范围。
 *     crawl() 返回空数组并保留 TODO,仅 validateUrl + parseSharePage 完整可用。
 */
class LanzouCrawler extends AbstractCrawler
{
    /**
     * 校验蓝奏云分享 URL
     *
     * 匹配规则:
     *   - 主域: lanzou.com / lanzoui.com / lanzoux.com / lanzouo.com / lanzout.com 等
     *   - 子域: pan.* / www.* / *.lanzou*
     *
     * {@inheritdoc}
     */
    public function validateUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }
        // 匹配 https://pan.lanzou.com/  https://www.lanzoui.com/  https://pan.lanzoux.com/ 等
        return (bool) preg_match(
            '#^https?://(?:[a-z0-9-]+\.)?lanzou[a-z]*\.[a-z]+/#i',
            $url
        );
    }

    /**
     * 解析蓝奏云分享页 HTML
     *
     * 提取:
     *   - title: <title> 标签
     *   - file_size: 文件大小(从页面文本或 meta 中尽力提取,转字节)
     *   - intro: meta description
     *
     * {@inheritdoc}
     */
    public function parseSharePage(string $html): array
    {
        $title = $this->extractTitleFromHtml($html);
        if ($title === null) {
            return [];
        }

        $result = ['title' => $title];

        // 文件大小: 蓝奏云页面常见 "大小：12.3 M" / "文件大小: 1.2 GB" / "n_filesize=1234567"
        if (preg_match('/(?:文件大小|大小|size)\s*[:：]?\s*([0-9.]+)\s*([KMGTPEkmgtpe]?B?)/i', $html, $m)) {
            $bytes = $this->parseSizeToBytes($m[1], $m[2] ?? '');
            if ($bytes !== null) {
                $result['file_size'] = $bytes;
            }
        }

        // 简介: meta description
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) {
            $intro = trim($m[1]);
            if ($intro !== '') {
                $result['intro'] = $intro;
            }
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     *
     * 蓝奏云无公开搜索接口,真实采集需登录态/Cookie,超出本任务范围。
     * 保留方法结构完整,返回空数组。
     *
     * TODO: 接入登录态 Cookie 池后,可走站内搜索或第三方网盘搜索 API。
     */
    public function crawl(CrawlTask $task): array
    {
        // TODO: 蓝奏云真实采集需登录态,超出后端开发范围
        return [];
    }

    /**
     * 蓝奏云无公开搜索接口
     *
     * {@inheritdoc}
     */
    protected function buildSearchUrl(string $keywords): ?string
    {
        return null;
    }

    /**
     * 将人类可读的文件大小转换为字节
     *
     * @param string $num   数字部分
     * @param string $unit  单位(B/KB/MB/GB/TB)
     * @return int|null 字节数,失败返回 null
     */
    private function parseSizeToBytes(string $num, string $unit): ?int
    {
        $value = (float) $num;
        if ($value < 0) {
            return null;
        }
        $unit = strtoupper(trim($unit));
        $multipliers = [
            'B'   => 1,
            'KB'  => 1024,
            'MB'  => 1024 ** 2,
            'GB'  => 1024 ** 3,
            'TB'  => 1024 ** 4,
            'PB'  => 1024 ** 5,
            'K'   => 1024,
            'M'   => 1024 ** 2,
            'G'   => 1024 ** 3,
            'T'   => 1024 ** 4,
            ''    => 1,
        ];
        $multiplier = $multipliers[$unit] ?? null;
        if ($multiplier === null) {
            return null;
        }
        return (int) round($value * $multiplier);
    }
}
