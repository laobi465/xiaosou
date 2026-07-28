<?php
declare(strict_types=1);

namespace Pansou\Helper;

use function env;
use function config;

/**
 * AES-256-GCM 加解密工具
 *
 * 密钥从 env('SECURITY.AES_KEY') 读取,要求 32 字节(256 位)。
 * 输出格式: base64(iv || tag || ciphertext)
 */
class EncryptHelper
{
    /** 加密算法 */
    protected const CIPHER = 'aes-256-gcm';
    /** IV 长度(字节) */
    protected const IV_LENGTH = 12;
    /** GCM Tag 长度(字节) */
    protected const TAG_LENGTH = 16;

    /**
     * 加密明文
     *
     * @param string $plain 明文
     * @param string|null $key 32 字节密钥,为空则从 env 读取
     * @return string base64 编码的 payload(iv||tag||ciphertext)
     * @throws \RuntimeException 密钥缺失或 openssl 不可用
     */
    public static function encrypt(string $plain, ?string $key = null): string
    {
        $key = $key ?? self::loadKey();
        $iv  = random_bytes(self::IV_LENGTH);
        $tag = '';

        // 清空 openssl 错误栈,避免读取到历史错误
        while (openssl_error_string() !== false) {
        }

        $ciphertext = openssl_encrypt(
            $plain,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            $err = openssl_error_string();
            throw new \RuntimeException('AES-256-GCM 加密失败: ' . ($err === false ? 'unknown error' : $err));
        }

        // payload = iv || tag || ciphertext
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * 解密密文
     *
     * @param string $cipher base64 编码的 payload
     * @param string|null $key 32 字节密钥,为空则从 env 读取
     * @return string 解密后的明文
     * @throws \RuntimeException 密钥缺失、payload 非法或解密失败
     */
    public static function decrypt(string $cipher, ?string $key = null): string
    {
        $key = $key ?? self::loadKey();
        $payload = base64_decode($cipher, true);
        if ($payload === false || strlen($payload) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new \RuntimeException('AES-256-GCM 解密失败: 无效的密文 payload');
        }

        $iv         = substr($payload, 0, self::IV_LENGTH);
        $tag        = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);

        // 清空 openssl 错误栈,避免读取到历史错误
        while (openssl_error_string() !== false) {
        }

        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plain === false) {
            $err = openssl_error_string();
            throw new \RuntimeException('AES-256-GCM 解密失败: ' . ($err === false ? 'unknown error' : $err));
        }

        return $plain;
    }

    /**
     * 加载密钥
     * 优先 config('security.aes_key'),其次 env('SECURITY.AES_KEY')
     *
     * @return string 32 字节密钥
     * @throws \RuntimeException 密钥未配置或长度不为 32 字节
     */
    protected static function loadKey(): string
    {
        $key = '';
        if (function_exists('config')) {
            $key = (string) (config('security.aes_key') ?? '');
        }
        if ($key === '' && function_exists('env')) {
            $key = (string) (env('SECURITY.AES_KEY') ?? '');
        }

        // 未配置密钥直接抛异常,禁止以 \0 兜底导致加密形同虚设
        if ($key === '') {
            throw new \RuntimeException('AES_KEY 未配置');
        }
        // 严格校验: 必须为 32 字节, 不再补齐/截断
        if (strlen($key) !== 32) {
            throw new \RuntimeException('AES_KEY 必须为 32 字节');
        }

        return $key;
    }
}
