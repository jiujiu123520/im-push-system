<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\Config;
use App\Service\Database;
use App\Service\Response;

/**
 * 静态路由中间件（HTTP 层）
 *
 * 功能：
 *  - 在 HttpServer::onRequest 进入 Router 分发之前执行，
 *    根据 settings_paths 的实时配置，把自定义路径 307 跳转到物理目录；
 *    对非匹配前缀放行到 Router。
 *  - 同时提供 normalizeConfig / getConfig（控制器复用）。
 *
 * 配置键：admin_settings.config_key = 'settings_paths'
 *   admin_path       管理端访问路径（默认 /admin/，可通过系统设置修改为混淆路径）
 *   admin_api_prefix 管理端 API 前缀（默认 /api/）
 *   user_path        用户端访问路径（默认 /user/）
 *   user_api_prefix  用户端 API 前缀（默认 /user-api/）
 */
class StaticRouter
{
    private const DEFAULTS = [
        'admin_path'       => '/admin/',
        'admin_api_prefix' => '/api/',
        'user_path'        => '/user/',
        'user_api_prefix'  => '/user-api/',
    ];

    /**
     * 从数据库读取 settings_paths 并规范化（缺省值补默认、首末斜杠规范）
     */
    public static function getConfig(): array
    {
        static $cache = null;
        static $cacheTime = 0;
        $now = time();
        // 进程内缓存 3 秒，降低 DB 查询压力
        if ($cache !== null && ($now - $cacheTime) < 3) {
            return $cache;
        }

        $fromDb = [];
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_paths']
            );
            if ($row !== false) {
                $decoded = json_decode((string)($row['config_value'] ?? '{}'), true);
                if (is_array($decoded)) {
                    $fromDb = $decoded;
                }
            }
        } catch (\Throwable $e) {
        }

        $cfg = array_merge(self::DEFAULTS, $fromDb);
        foreach (['admin_path', 'user_path', 'admin_api_prefix', 'user_api_prefix'] as $k) {
            $v = (string)($cfg[$k] ?? '');
            if ($v === '' || $v === '/') {
                $v = self::DEFAULTS[$k];
            }
            if (str_starts_with($k, 'admin_') || str_starts_with($k, 'user_')) {
                if (!str_starts_with($v, '/')) {
                    $v = '/' . $v;
                }
                if (str_ends_with($k, '_path') && !str_ends_with($v, '/')) {
                    $v = $v . '/';
                }
                if (str_ends_with($k, '_prefix') && str_ends_with($v, '/') && $v !== '/') {
                    $v = rtrim($v, '/');
                }
            }
            $cfg[$k] = $v;
        }

        $cache = $cfg;
        $cacheTime = $now;
        return $cfg;
    }

    /**
     * 在 Router 分发前，对路径做重写/跳转。
     * 返回 true 表示已经处理完毕（响应已 end），false 表示交给 Router 继续。
     *
     * @param string $path   当前请求 uri
     * @param object $response Swoole\Response 或兼容对象（有 status/header/end/redirect 方法）
     * @return bool 已处理则 true
     */
    public static function handle(string $path, $response): bool
    {
        $cfg = self::getConfig();

        // 1. 根路径跳转：IP 直接访问时默认跳转到用户端（而非管理后台）
        if ($path === '/' || $path === '') {
            if (method_exists($response, 'redirect')) {
                $response->redirect($cfg['user_path'], 307);
            } else {
                $response->status(307);
                $response->header('Location', $cfg['user_path']);
                $response->end();
            }
            return true;
        }

        // 2. 管理端入口：/admin/ 目录或 /admin 重定向，直接放行（Swoole static_handler 或后续逻辑处理）
        //    若管理端改了路径，比如 /console/，则我们只在文档中提醒 Nginx 配置同步；
        //    Swoole 进程内这里只处理根跳转和 API 前缀匹配，静态文件交给 Nginx 更合适。

        // 3. API 前缀错误提示（防止旧接口访问新前缀或反之导致 404 难以排查）
        $isAdminApi = str_starts_with($path, $cfg['admin_api_prefix'] . '/') || $path === $cfg['admin_api_prefix'];
        $isUserApi  = str_starts_with($path, $cfg['user_api_prefix'] . '/')  || $path === $cfg['user_api_prefix'];
        if (!$isAdminApi && !$isUserApi) {
            // 静态资源放行（兼容自定义 admin_path，如 /admin-9f7k2p8x/）
            if (str_starts_with($path, '/static/') || str_starts_with($path, '/assets/')
                || str_starts_with($path, $cfg['admin_path']) || str_starts_with($path, '/user/')
                || str_starts_with($path, '/admin/')) {
                return false;
            }
            // 其它未知根路径直接放行给 Router（由其 404）
            return false;
        }

        return false;
    }
}
