<?php
declare(strict_types=1);

namespace Pansou\Helper;

/**
 * 哈希/编号工具
 */
class HashHelper
{
    /**
     * 计算 URL 的 MD5 哈希(用于 resource_links.url_hash 去重)
     *
     * @param string $url 网盘分享链接
     * @return string 32 位小写 md5
     */
    public static function urlHash(string $url): string
    {
        return md5(trim($url));
    }

    /**
     * 生成订单号
     *
     * 格式: PS + YYYYMMDD + 10 位唯一 ID
     * 示例: PS202607281234567890
     *
     * @param string $prefix 订单号前缀
     * @return string 22 位订单号
     */
    public static function orderNo(string $prefix = 'PS'): string
    {
        $date = date('Ymd');
        // 10 位唯一: 时间戳后 6 位(秒级) + 4 位随机数
        $unique = substr((string) time(), -6) . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        return $prefix . $date . $unique;
    }
}
