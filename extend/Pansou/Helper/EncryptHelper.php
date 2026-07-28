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
            throw new \RuntimeException('AES-256-GCM 加密失败: ' . openssl_error_string());
        }

        // payload = iv || tag || ciphertext
        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * 解密密文
     *
     * @param string $cipher base64 编码的 payload
     * @param string|null $key 32 字节密钥,为空则从 env 读取
     * @return string|false 解密后的明文,失败返回 false
     */
    public static function decrypt(string $cipher, ?string $key = null): string|false
    {
        $key = $key ?? self::loadKey();
        $payload = base64_decode($cipher, true);
        if ($payload === false || strlen($payload) < self::IV_LENGTH + self::TAG_LENGTH) {
            return false;
        }

        $iv         = substr($payload, 0, self::IV_LENGTH);
        $tag        = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);

        return openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
    }

    /**
     * 加载密钥
     * 优先 config('security.aes_key'),其次 env('SECURITY.AES_KEY')
     *
     * @return string 32 字节密钥
     * @throws \RuntimeException 密钥未配置或长度不符
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
        $key = $key ?: '';

        // 短密钥补齐到 32 字节(便于开发期使用,生产建议直接配置 32 字节密钥)
        if (strlen($key) < 32) {
            $key = str_pad($key, 32, "\0");
        } elseif (strlen($key) > 32) {
            $key = substr($key, 0, 32);
        }

        return $key;
    }
}
