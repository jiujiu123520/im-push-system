<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Database;
use App\Service\Jwt;
use App\Service\Response;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * 用户端鉴权中间件
 *
 * 职责：
 *  - 校验 JWT 且 type === 'user'，role === 'normal'
 *  - 确认 users.status === 1
 *  - 将 user_id 等载荷注入请求上下文
 *
 * 调用方式：UserConsole\* 控制器在入口处调用 UserApiAuth::authenticate($context)
 */
class UserApiAuth
{
    public static function authenticate(array &$context): ?array
    {
        $server = self::mergeAuthHeader($context);
        $get    = $context['get'] ?? [];
        $response = $context['response'] ?? null;

        $token = Jwt::extractToken($server, $get);
        if ($token === null || $token === '') {
            self::unauthorized($response, '缺少用户令牌');
            return null;
        }

        try {
            $payload = Jwt::verify($token);
        } catch (ExpiredException $e) {
            self::unauthorized($response, '令牌已过期，请重新登录');
            return null;
        } catch (SignatureInvalidException $e) {
            self::unauthorized($response, '令牌签名无效');
            return null;
        } catch (UnexpectedValueException $e) {
            self::unauthorized($response, '令牌无效：' . $e->getMessage());
            return null;
        }

        if (Jwt::isRevoked($token)) {
            self::unauthorized($response, '令牌已失效，请重新登录');
            return null;
        }

        $type = $payload['type'] ?? '';
        if ($type !== 'user') {
            self::forbidden($response, '令牌类型不正确');
            return null;
        }
        $userId = (int)($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            self::unauthorized($response, '令牌缺少用户标识');
            return null;
        }

        // 验证用户状态
        try {
            $row = Database::fetch(
                'SELECT id, username, status FROM users WHERE id = ? LIMIT 1',
                [$userId]
            );
            if ($row === false) {
                self::forbidden($response, '用户不存在');
                return null;
            }
            if ((int)$row['status'] !== 1) {
                self::forbidden($response, '账号已被禁用，请联系管理员');
                return null;
            }
        } catch (\Throwable $e) {
            self::error($response, '用户状态校验失败');
            return null;
        }

        $context['user_id']     = $userId;
        $context['jwt_payload'] = $payload;

        return $payload;
    }

    /**
     * 把 header/$_GET 等位置的 token 合并到 server 格式，与 AdminAuth 保持一致
     */
    private static function mergeAuthHeader(array $context): array
    {
        $header = $context['header'] ?? [];
        $server = $context['server'] ?? [];
        foreach ($header as $k => $v) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', (string)$k));
            if (!isset($server[$key])) {
                $server[$key] = $v;
            }
        }
        return $server;
    }

    private static function unauthorized($response, string $msg): void
    {
        if ($response) {
            Response::fail($response, $msg, Response::CODE_UNAUTHORIZED, 401);
        }
    }

    private static function forbidden($response, string $msg): void
    {
        if ($response) {
            Response::fail($response, $msg, Response::CODE_FORBIDDEN, 403);
        }
    }

    private static function error($response, string $msg): void
    {
        if ($response) {
            Response::fail($response, $msg, Response::CODE_INTERNAL, 500);
        }
    }
}
