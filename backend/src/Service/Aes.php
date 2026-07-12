<?php
declare(strict_types=1);

namespace App\Service;

/**
 * AES 加解密服务
 *
 * 使用 AES-256-CBC，用于验证码等敏感短数据的加解密。
 * 密钥从环境变量 AES_KEY 读取（应为 64 位 hex 字符，即 32 字节原始密钥）。
 */
class Aes
{
    /**
     * 算法
     */
    private const CIPHER = 'aes-256-cbc';

    /**
     * 加密
     *
     * @param string $plain 明文
     * @return string base64 编码的密文（前缀 iv，方便解密）
     * @throws \RuntimeException 密钥无效或加密失败
     */
    public static function encrypt(string $plain): string
    {
        $key = self::getKey();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new \RuntimeException('获取 IV 长度失败');
        }
        $iv = random_bytes($ivLength);

        $encrypted = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new \RuntimeException('AES 加密失败');
        }

        // 输出格式：base64(iv + ciphertext)
        return base64_encode($iv . $encrypted);
    }

    /**
     * 解密
     *
     * @param string $payload base64 编码的密文（由 encrypt 生成）
     * @return string|false 成功返回明文，失败返回 false
     */
    public static function decrypt(string $payload)
    {
        $key = self::getKey();
        $raw = base64_decode($payload, true);
        if ($raw === false) {
            return false;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false || strlen($raw) < $ivLength) {
            return false;
        }

        $iv = substr($raw, 0, $ivLength);
        $ciphertext = substr($raw, $ivLength);

        $decrypted = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            return false;
        }
        return $decrypted;
    }

    /**
     * 加密并以字符串形式返回（适合存入 Redis）
     */
    public static function encryptString(string $plain): string
    {
        return self::encrypt($plain);
    }

    /**
     * 解密字符串
     */
    public static function decryptString(string $payload): ?string
    {
        $result = self::decrypt($payload);
        return $result === false ? null : $result;
    }

    /**
     * 获取原始密钥（二进制）
     *
     * @return string 32 字节密钥
     * @throws \RuntimeException
     */
    private static function getKey(): string
    {
        $hexKey = (string)Config::env('AES_KEY', '');
        // 兼容 hex 字符串与原始字符串
        if (strlen($hexKey) === 64) {
            $key = @hex2bin($hexKey);
            if ($key === false || strlen($key) !== 32) {
                throw new \RuntimeException('AES_KEY 不是合法的 32 字节 hex 字符串');
            }
            return $key;
        }
        // 退化处理：直接按字符串补齐或截断到 32 字节
        $key = str_pad($hexKey, 32, "\0");
        return substr($key, 0, 32);
    }
}
