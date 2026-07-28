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
     * 通用兜底校验: 防止 SSRF
     *
     * 仅允许 http/https 协议; 排除 file://、ftp:// 等危险协议;
     * 拦截私有/保留 IP 段(10.x、172.16-31.x、192.168.x、127.x、169.254.x、0.x)
     * 与 localhost 主机名。
     *
     * {@inheritdoc}
     */
    public function validateUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // 1. 协议白名单: 仅允许 http/https (排除 file://、ftp:// 等)
        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }
        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // 2. 主机必填
        $host = strtolower((string) ($parsed['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        // 3. 拦截 localhost 主机名
        if ($host === 'localhost' || $host === 'localhost.localdomain') {
            return false;
        }

        // 4. 字面量 IP 校验: 拦截私有/保留地址段
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!$this->isPublicIp($host)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 判断 IP 是否为公网地址(非私有/保留段)
     *
     * @param string $ip IPv4 或 IPv6 字面量
     * @return bool true=公网地址 false=私有/保留地址
     */
    protected function isPublicIp(string $ip): bool
    {
        // IPv6 本地/保留地址
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if ($ip === '::1') {
                return false;
            }
            // ULA(fc00::/7) 与 link-local(fe80::/10)
            if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd') || str_starts_with($lower, 'fe80')) {
                return false;
            }
            return true;
        }

        // IPv4 私有/保留段校验
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return false;
        }
        $a = (int) $parts[0];
        $b = (int) $parts[1];

        if ($a === 10) {
            return false; // 10.0.0.0/8
        }
        if ($a === 172 && $b >= 16 && $b <= 31) {
            return false; // 172.16.0.0/12
        }
        if ($a === 192 && $b === 168) {
            return false; // 192.168.0.0/16
        }
        if ($a === 127) {
            return false; // 127.0.0.0/8 (环回)
        }
        if ($a === 169 && $b === 254) {
            return false; // 169.254.0.0/16 (链路本地)
        }
        if ($a === 0) {
            return false; // 0.0.0.0/8 (本网络)
        }

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
