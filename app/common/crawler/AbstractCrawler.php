<?php
declare(strict_types=1);

namespace app\common\crawler;

use app\common\model\CrawlTask;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * 采集器抽象基类
 *
 * 提供通用 HTTP 客户端(超时 + UA 轮换)与随机延时,
 * 子类只需实现具体的页面解析逻辑。
 *
 * crawl() 默认流程:
 *   1. 读取 task->keywords,调子类 buildSearchUrl() 构造搜索 URL
 *   2. 子类未提供 buildSearchUrl(返回 null)时,保护性降级返回空数组
 *   3. httpClient 请求搜索 URL,parseSharePage 解析 HTML
 *   4. delay() 随机延时,返回 ResourceItem[]
 *
 * 注:CrawlTask 表无 target_url 字段,实际由 keywords + panSource 决定采集入口。
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
     * 构造搜索 URL
     *
     * 默认返回 null,表示该采集器未提供公开搜索接口,
     * crawl() 将保护性降级返回空数组。
     * 子类可覆盖此方法以返回真实搜索 URL。
     *
     * @param string $keywords 采集关键词
     * @return string|null 搜索 URL,null 表示无可用搜索入口
     */
    protected function buildSearchUrl(string $keywords): ?string
    {
        return null;
    }

    /**
     * 从 HTML 中提取 <title> 标签内容
     *
     * 通用辅助方法,供各子类 parseSharePage 复用:
     *   1. 通过 <meta charset> 检测编码
     *   2. mb_convert_encoding 转换为 UTF-8
     *   3. 正则提取 <title> 内容并解码 HTML 实体
     *
     * @param string $html 页面 HTML
     * @return string|null 标题文本,失败返回 null
     */
    protected function extractTitleFromHtml(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        // 1. 检测页面编码(默认 UTF-8)
        $encoding = 'UTF-8';
        if (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $html, $m)) {
            $detected = strtoupper(trim($m[1]));
            if ($detected !== '') {
                $encoding = $detected;
            }
        }

        // 2. 非 UTF-8 时转换为 UTF-8
        if ($encoding !== 'UTF-8' && function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $encoding);
            if ($converted !== false && $converted !== '') {
                $html = $converted;
            }
        }

        // 3. 提取 <title> 标签内容
        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            return null;
        }

        $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // 移除首尾空白与换行(含全角空格)
        $title = preg_replace('/[\s\x{3000}]+/u', ' ', $title) ?? $title;
        $title = trim($title);

        return $title !== '' ? $title : null;
    }

    /**
     * {@inheritdoc}
     *
     * 通用采集流程实现:
     *   1. 读取 task->keywords,子类 buildSearchUrl 构造搜索 URL
     *   2. buildSearchUrl 返回 null 时,保护性降级返回空数组
     *   3. httpClient 请求搜索 URL
     *   4. parseSharePage 解析 HTML,转换为 ResourceItem[]
     *   5. delay() 随机延时,返回结果
     *
     * 任何环节失败均返回空数组,不抛异常。
     */
    public function crawl(CrawlTask $task): array
    {
        // 1. 读取关键词
        $keywords = '';
        try {
            $raw = $task->keywords ?? '';
            if (is_string($raw)) {
                $keywords = trim($raw);
            }
        } catch (\Throwable $e) {
            $keywords = '';
        }
        if ($keywords === '') {
            return [];
        }

        // 2. 子类构造搜索 URL,为 null 时降级
        $searchUrl = $this->buildSearchUrl($keywords);
        if ($searchUrl === null || $searchUrl === '') {
            return [];
        }

        // 3. 请求搜索 URL
        try {
            $response = $this->httpClient()->get($searchUrl);
            $html = (string) $response->getBody();
        } catch (GuzzleException $e) {
            return [];
        } catch (\Throwable $e) {
            return [];
        }

        // 4. 解析 HTML 转换为 ResourceItem[]
        $rows = $this->parseSharePage($html);
        $items = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $items[] = new ResourceItem($row);
            }
        }

        // 5. 随机延时
        $this->delay();

        return $items;
    }
}
