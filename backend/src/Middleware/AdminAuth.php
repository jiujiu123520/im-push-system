<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Jwt;
use App\Service\Response;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * 管理员鉴权中间件
 *
 * 职责：
 *  - 校验 JWT 且 role 必须为 admin 或 super_admin
 *  - 将 admin_id 等载荷信息注入请求上下文（通过引用传递修改）
 *
 * 调用方式：控制器在入口处调用 AdminAuth::authenticate($context)
 * 成功返回 admin payload（含 admin_id、username、role），
 * 同时将 admin_id 与 jwt_payload 注入到 $context；
 * 失败时直接输出 401/403 响应并返回 null。
 */
class AdminAuth
{
    /**
     * 校验请求并返回管理员身份
     *
     * @param array &$context 请求上下文（引用传递，鉴权成功后写入 admin_id）
     * @return array|null 成功返回 JWT payload，失败返回 null（并已输出错误响应）
     */
    public static function authenticate(array &$context): ?array
    {
        $server = self::mergeAuthHeader($context);
        $get = $context['get'] ?? [];
        $response = $context['response'] ?? null;

        $token = Jwt::extractToken($server, $get);
        if ($token === null || $token === '') {
            self::unauthorized($response, '缺少管理员令牌');
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

        // 校验令牌类型
        $type = $payload['type'] ?? '';
        if ($type !== 'admin') {
            self::forbidden($response, '令牌类型不正确，非管理员令牌');
            return null;
        }

        // 校验角色
        $role = $payload['role'] ?? '';
        if (!in_array($role, ['super_admin', 'admin'], true)) {
            self::forbidden($response, '管理员角色无效');
            return null;
        }

        if (!isset($payload['admin_id'])) {
            self::unauthorized($response, '令牌缺少管理员标识');
            return null;
        }

        // 将 admin_id 与 jwt_payload 注入到请求上下文
        $context['admin_id'] = (int)$payload['admin_id'];
        $context['jwt_payload'] = $payload;

        return $payload;
    }

    /**
     * 获取当前登录管理员ID
     *
     * @param array $context
     * @return int|null
     */
    public static function getAdminId(array $context): ?int
    {
        $adminId = $context['admin_id'] ?? null;
        if ($adminId === null) {
            return null;
        }
        return (int)$adminId;
    }

    /**
     * 从请求上下文中获取客户端 IP
     *
     * @param array $context
     * @return string
     */
    public static function getClientIp(array $context): string
    {
        $server = $context['server'] ?? [];
        $header = $context['header'] ?? [];
        // 优先取反向代理转发的真实 IP
        $ip = $header['x-forwarded-for'] ?? ($header['X-Forwarded-For'] ?? '');
        if ($ip !== '' && strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        if ($ip === '') {
            $ip = $server['remote_addr'] ?? '0.0.0.0';
        }
        return (string)$ip;
    }

    /**
     * 输出未授权响应（401）
     *
     * @param mixed $response
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
     * 输出禁止访问响应（403）
     *
     * @param mixed $response
     * @param string $message
     * @return void
     */
    private static function forbidden($response, string $message): void
    {
        if ($response === null) {
            return;
        }
        Response::fail(
            $response,
            $message,
            Response::CODE_FORBIDDEN,
            403
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

        if (!isset($server['HTTP_AUTHORIZATION']) && !isset($server['http_authorization'])) {
            $auth = $header['authorization'] ?? ($header['Authorization'] ?? '');
            if ($auth !== '') {
                $server['HTTP_AUTHORIZATION'] = $auth;
            }
        }
        return $server;
    }
}
