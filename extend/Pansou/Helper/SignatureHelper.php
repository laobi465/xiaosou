<?php
declare(strict_types=1);

namespace Pansou\Helper;

/**
 * 签名工具
 *
 * 通用 MD5 签名:参数按 key 升序拼接 + 拼接密钥 + md5
 * 用于彩虹易支付等第三方接口签名/验签。
 */
class SignatureHelper
{
    /**
     * 生成 MD5 签名
     *
     * 规则:
     *   1. 过滤掉值为空、key 为 sign/sign_type 的参数
     *   2. 按 key 升序排序
     *   3. 拼接为 key1=value1&key2=value2&...
     *   4. 末尾拼接商户密钥 key
     *   5. md5 取小写
     *
     * @param array  $params 待签名参数
     * @param string $key    商户密钥
     * @return string 32 位小写 md5 签名
     */
    public static function md5Sign(array $params, string $key): string
    {
        $filtered = self::filterParams($params);
        ksort($filtered);
        $parts = [];
        foreach ($filtered as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        $signString = implode('&', $parts) . $key;
        return md5($signString);
    }

    /**
     * 验证签名
     *
     * @param array  $params 待验签参数(含 sign 字段)
     * @param string $key    商户密钥
     * @param string $sign   待比对的签名
     * @return bool true=验证通过
     */
    public static function verifySign(array $params, string $key, string $sign): bool
    {
        if ($sign === '') {
            return false;
        }
        $expected = self::md5Sign($params, $key);
        return hash_equals($expected, $sign);
    }

    /**
     * 过滤参数:移除空值与签名相关字段
     */
    protected static function filterParams(array $params): array
    {
        $result = [];
        foreach ($params as $k => $v) {
            if (in_array((string) $k, ['sign', 'sign_type'], true)) {
                continue;
            }
            if ($v === '' || $v === null) {
                continue;
            }
            $result[$k] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v;
        }
        return $result;
    }
}
