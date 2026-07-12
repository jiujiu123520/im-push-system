<?php
declare(strict_types=1);

namespace App\Service;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * JWT 服务
 *
 * 负责签发与校验 JSON Web Token。
 * 使用 firebase/php-jwt 6.x。
 */
class Jwt
{
    /**
     * 默认使用的哈希算法
     */
    private const ALG = 'HS256';

    /**
     * 签发 JWT
     *
     * @param array $payload 载荷（claims），如 ['uid' => 1, 'name' => 'tom']
     * @param int|null $expire 有效期（秒），为空则读取环境变量 JWT_EXPIRE
     * @return string
     */
    public static function issue(array $payload, ?int $expire = null): string
    {
        $secret = self::getSecret();
        $expire = $expire ?? (int)Config::env('JWT_EXPIRE', 7200);
        $now = time();

        $payload = array_merge([
            'iss' => 'im-push',
            'iat' => $now,
            'nbf' => $now,
        ], $payload);
        $payload['exp'] = $payload['exp'] ?? ($now + $expire);

        return FirebaseJwt::encode($payload, $secret, self::ALG);
    }

    /**
     * 校验并解析 JWT
     *
     * @param string $token JWT 字符串
     * @return array 解析后的载荷
     * @throws ExpiredException           token 过期
     * @throws SignatureInvalidException  签名无效
     * @throws UnexpectedValueException   token 格式错误或其它无效情况
     */
    public static function verify(string $token): array
    {
        $secret = self::getSecret();
        $decoded = FirebaseJwt::decode($token, new Key($secret, self::ALG));
        return (array)$decoded;
    }

    /**
     * 刷新 token（携带原载荷，重新签发）
     *
     * @param string $token
     * @param int|null $expire
     * @return string
     */
    public static function refresh(string $token, ?int $expire = null): string
    {
        $payload = self::verify($token);
        // 移除时间相关字段，由 issue 重新生成
        unset($payload['iat'], $payload['nbf'], $payload['exp']);
        return self::issue($payload, $expire);
    }

    /**
     * 从请求头/查询参数中提取 token
     *
     * @param array $server $_SERVER 或 swoole request->server
     * @param array $get    $_GET 或 swoole request->get
     * @return string|null
     */
    public static function extractToken(array $server, array $get = []): ?string
    {
        // 优先从 Authorization 头提取
        $authHeader = $server['HTTP_AUTHORIZATION'] ?? ($server['http_authorization'] ?? '');
        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
        // 退而求其次从查询参数提取
        return $get['token'] ?? null;
    }

    /**
     * 获取 JWT 密钥
     *
     * @return string
     */
    private static function getSecret(): string
    {
        return (string)Config::env('JWT_SECRET', 'default_secret_change_me');
    }
}
