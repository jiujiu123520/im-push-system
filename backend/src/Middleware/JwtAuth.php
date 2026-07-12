<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Jwt;
use App\Service\Response;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * JWT 鉴权中间件（前台用户）
 *
 * 职责：
 *  - 从 Authorization 头提取 Bearer Token
 *  - 校验 JWT 有效性（签名、过期、格式）
 *  - 将 user_id 等载荷信息注入到请求上下文（通过引用传递修改）
 *
 * 由于当前 Router 不支持 PSR-15 风格的中间件链，
 * 控制器在需要鉴权的入口直接调用 JwtAuth::authenticate()，
 * 该方法返回 JWT payload 或在失败时直接输出 401 响应。
 */
class JwtAuth
{
    /**
     * 校验请求并返回用户身份
     *
     * 成功时返回 JWT payload（包含 user_id、username 等），
     * 同时将 user_id 与 jwt_payload 注入到 $context（按引用传递）；
     * 失败时直接输出错误响应到 $context['response']，并返回 null。
     *
     * @param array &$context 请求上下文（引用传递，鉴权成功后写入 user_id）
     * @return array|null
     */
    public static function authenticate(array &$context): ?array
    {
        $server = self::mergeAuthHeader($context);
        $get = $context['get'] ?? [];
        $response = $context['response'] ?? null;

        $token = Jwt::extractToken($server, $get);
        if ($token === null || $token === '') {
            self::unauthorized($response, '缺少授权令牌');
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

        // 校验令牌类型，避免使用管理员令牌访问用户接口
        $type = $payload['type'] ?? '';
        if ($type !== 'user') {
            self::unauthorized($response, '令牌类型不正确');
            return null;
        }

        if (!isset($payload['user_id'])) {
            self::unauthorized($response, '令牌缺少用户标识');
            return null;
        }

        // 将 user_id 与 jwt_payload 注入到请求上下文
        $context['user_id'] = (int)$payload['user_id'];
        $context['jwt_payload'] = $payload;

        return $payload;
    }

    /**
     * 从上下文中获取 user_id
     *
     * 与 authenticate 配合使用：authenticate 通过引用把 user_id 注入到 context，
     * 控制器后续可直接从 $context['user_id'] 读取，或调用本方法获取。
     *
     * @param array $context
     * @return int|null
     */
    public static function getUserId(array $context): ?int
    {
        $userId = $context['user_id'] ?? null;
        if ($userId === null) {
            return null;
        }
        return (int)$userId;
    }

    /**
     * 输出未授权响应
     *
     * @param mixed $response Swoole HTTP Response
     * @param string $message
     * @return void
     */
    private static function unauthorized($response, string $message): void
    {
        if ($response === null) {
            return;
        }
        Response::fail(
            $response,
            $message,
            Response::CODE_UNAUTHORIZED,
            401
        );
    }

    /**
     * 合并 Authorization 头到 server 数组
     *
     * Swoole 中 Authorization 头可能在 $request->header['authorization']
     * 而非 $request->server['HTTP_AUTHORIZATION']。
     * 本方法将 header 中的 Authorization 合并到 server 数组，
     * 保证 Jwt::extractToken 能正确提取。
     *
     * @param array $context
     * @return array
     */
    private static function mergeAuthHeader(array $context): array
    {
        $server = $context['server'] ?? [];
        $header = $context['header'] ?? [];

        // 优先使用 server 中的 HTTP_AUTHORIZATION
        if (!isset($server['HTTP_AUTHORIZATION']) && !isset($server['http_authorization'])) {
            // 从 header 中提取（Swoole header 键为小写）
            $auth = $header['authorization'] ?? ($header['Authorization'] ?? '');
            if ($auth !== '') {
                $server['HTTP_AUTHORIZATION'] = $auth;
            }
        }
        return $server;
    }
}
