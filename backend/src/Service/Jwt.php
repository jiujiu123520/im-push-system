<?php
declare(strict_types=1);

namespace App\Service;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;
use RuntimeException;

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
        // V-02: 刷新前检查黑名单，防止已登出的 token 通过 refresh 续命
        if (self::isRevoked($token)) {
            throw new UnexpectedValueException('令牌已失效，无法刷新');
        }

        $payload = self::verify($token);
        // 移除时间相关字段，由 issue 重新生成
        unset($payload['iat'], $payload['nbf'], $payload['exp']);
        $newToken = self::issue($payload, $expire);

        // V-02: 旧 token 加入黑名单，防止旧 token 继续使用
        self::revoke($token);

        return $newToken;
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
        $secret = (string)Config::env('JWT_SECRET', 'default_secret_change_me');

        // V-01 安全修复：拒绝弱密钥，防止攻击者用公开默认值伪造 token
        $allowWeak = (string)Config::env('JWT_ALLOW_WEAK_SECRET', '0') === '1';
        if (!$allowWeak) {
            $weakSecrets = [
                'default_secret_change_me', 'secret', 'jwt_secret',
                'your-secret-key', 'change_me', '', '123456', 'password',
            ];
            if (in_array($secret, $weakSecrets, true) || strlen($secret) < 32) {
                throw new RuntimeException(
                    'JWT_SECRET 配置不安全：请在 backend/.env 中设置至少 32 位的随机字符串。'
                    . '可执行：openssl rand -base64 48 生成。'
                    . '如开发环境需临时跳过，可设置 JWT_ALLOW_WEAK_SECRET=1'
                );
            }
        }

        return $secret;
    }

    /**
     * 将 token 加入黑名单（登出 / 刷新时调用）
     *
     * V-02 安全修复：登出后令牌立即失效，防止泄露的 token 在有效期内被重用。
     * 黑名单 key 用 token 的 SHA-256 哈希，不存储原始 token。
     * TTL 自动设为 token 剩余有效期，过期后黑名单记录自动清除。
     *
     * @param string $token JWT 字符串
     * @return void
     */
    public static function revoke(string $token): void
    {
        $enabled = (string)Config::env('JWT_BLACKLIST_ENABLED', '1') === '1';
        if (!$enabled) {
            return;
        }

        try {
            $payload = self::verify($token);
            $exp = $payload['exp'] ?? 0;
            $ttl = (int)$exp - time();
            if ($ttl <= 0) {
                return; // token 已过期，无需加入黑名单
            }

            $key = 'jwt:blacklist:' . hash('sha256', $token);
            Redis::setex($key, $ttl, '1');
        } catch (\Throwable $e) {
            // token 无效或 Redis 不可用，静默忽略（不阻断业务流程）
        }
    }

    /**
     * 检查 token 是否已被吊销
     *
     * @param string $token JWT 字符串
     * @return bool true=已吊销(应拒绝)，false=有效或黑名单功能关闭
     */
    public static function isRevoked(string $token): bool
    {
        $enabled = (string)Config::env('JWT_BLACKLIST_ENABLED', '1') === '1';
        if (!$enabled) {
            return false;
        }

        try {
            $key = 'jwt:blacklist:' . hash('sha256', $token);
            $val = Redis::get($key);
            return $val !== false && $val !== null;
        } catch (\Throwable $e) {
            // Redis 不可用时降级为"未吊销"（不阻断正常请求）
            // 原因：Redis 宕机不应导致所有已登录用户被踢出
            return false;
        }
    }
}
