<?php
/**
 * 服务入口文件
 *
 * 启动方式：
 *   php public/index.php           前台启动 HTTP API 服务（默认）
 *   php public/index.php --ws      前台启动 WebSocket 推送服务
 *   php public/index.php --daemon  后台守护进程（可配合 --ws 使用）
 */

declare(strict_types=1);

// 项目根目录
define('BASE_PATH', dirname(__DIR__));

// 1. 加载 Composer 自动加载
$autoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "请先执行 composer install 安装依赖\n");
    exit(1);
}
require $autoload;

// 2. 加载环境变量
App\Service\Config::loadEnv();

// 3. 确保运行时目录存在
$dirs = [
    BASE_PATH . '/runtime',
    BASE_PATH . '/runtime/logs',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// 4. 解析启动参数
$daemonize = in_array('--daemon', $argv, true);
$runWs     = in_array('--ws', $argv, true);

if ($runWs) {
    // ------------------------------------------------------------
    // 启动 WebSocket 推送服务
    // ------------------------------------------------------------
    $server = new \App\WebSocketServer();
    if ($daemonize) {
        $server->getServer()->set(['daemonize' => true]);
    }
    $server->start();
} else {
    // ------------------------------------------------------------
    // 注册路由并启动 HTTP API 服务
    // ------------------------------------------------------------
    $routeRegistrar = function (\App\Router $router) {
        // 健康检查
        $router->get('/health', function (array $ctx) {
            return ['status' => 'ok', 'time' => date('Y-m-d H:i:s')];
        });

        $router->get('/', function (array $ctx) {
            return [
                'name'    => 'IM Push Backend',
                'version' => '1.0.0',
                'docs'    => '/health',
            ];
        });

        // ------------------------------------------------------------
        // APP 打包相关路由（需管理员鉴权）
        // ------------------------------------------------------------
        $router->post('/admin/app-build', [\App\Controller\AppBuildController::class, 'submit']);
        $router->get('/admin/app-build/list', [\App\Controller\AppBuildController::class, 'list']);
        $router->get('/admin/app-build/status/{build_id}', [\App\Controller\AppBuildController::class, 'status']);
        $router->get('/admin/app-build/log/{build_id}', [\App\Controller\AppBuildController::class, 'log']);
        $router->get('/admin/app-build/log/{build_id}/download', [\App\Controller\AppBuildController::class, 'downloadLog']);
        $router->get('/admin/app-build/download/{build_id}', [\App\Controller\AppBuildController::class, 'download']);
        $router->get('/admin/app-build/random-config', [\App\Controller\AppBuildController::class, 'randomConfig']);
        $router->get('/admin/app-build/generate-icon', [\App\Controller\AppBuildController::class, 'generateIcon']);

        // ------------------------------------------------------------
        // APK 分发管理（管理员鉴权）
        // ------------------------------------------------------------
        $router->get('/admin/apk-distribution', [\App\Controller\ApkDistributionController::class, 'index']);
        $router->get('/admin/apk-distribution/config', [\App\Controller\ApkDistributionController::class, 'getConfig']);
        $router->put('/admin/apk-distribution/config', [\App\Controller\ApkDistributionController::class, 'saveConfig']);
        $router->get('/admin/apk-distribution/{id}', [\App\Controller\ApkDistributionController::class, 'show']);
        $router->post('/admin/apk-distribution/{id}/lanzou', [\App\Controller\ApkDistributionController::class, 'uploadLanzou']);
        $router->post('/admin/apk-distribution/{id}/custom', [\App\Controller\ApkDistributionController::class, 'uploadCustom']);
        $router->delete('/admin/apk-distribution/{id}', [\App\Controller\ApkDistributionController::class, 'delete']);

        // ------------------------------------------------------------
        // APK 公开下载（无需鉴权，通过 token 验证）
        // ------------------------------------------------------------
        $router->get('/api/apk-distribution/info/{token}', [\App\Controller\ApkDistributionController::class, 'infoByToken']);
        $router->get('/api/apk-distribution/download/{token}', [\App\Controller\ApkDistributionController::class, 'downloadByToken']);

        // ------------------------------------------------------------
        // 任务3：用户注册与登录
        // ------------------------------------------------------------

        // 获取图形验证码（同时注册 /captcha/image 和 /api/captcha/image 两个路由）
        // APP 端调用 /captcha/image（不带 /api 前缀）
        // 前端管理后台调用 /api/captcha/image（带 /api 前缀，因前端 axios baseURL=/api）
        $router->get('/captcha/image', [\App\Controller\AuthController::class, 'captchaImage']);
        $router->get('/api/captcha/image', [\App\Controller\AuthController::class, 'captchaImage']);

        // 发送短信/邮箱验证码
        $router->post('/auth/send-code', [\App\Controller\AuthController::class, 'sendCode']);

        // 用户注册（返回 token + security_code）
        $router->post('/auth/register', [\App\Controller\AuthController::class, 'register']);

        // 用户登录
        $router->post('/auth/login', [\App\Controller\AuthController::class, 'login']);

        // 通过安全码重置密码
        $router->post('/auth/reset-password', [\App\Controller\AuthController::class, 'resetPassword']);

        // ------------------------------------------------------------
        // 任务4：管理员账号管理
        // ------------------------------------------------------------

        // 管理员登录（无需鉴权）
        $router->post('/admin/login', [\App\Controller\AdminController::class, 'login']);

        // 管理员登出（需管理员鉴权，记录登出日志）
        $router->post('/admin/logout', [\App\Controller\AdminController::class, 'logout']);

        // 获取当前登录管理员信息（需管理员鉴权）
        $router->get('/admin/info', [\App\Controller\AdminController::class, 'info']);

        // 管理员列表（分页 10 条，支持 keyword 搜索）
        $router->get('/admin/list',  [\App\Controller\AdminController::class, 'list']);
        $router->get('/admin/admins', [\App\Controller\AdminController::class, 'list']);

        // 创建管理员（仅 super_admin）
        $router->post('/admin/create', [\App\Controller\AdminController::class, 'create']);
        $router->post('/admin/admins',  [\App\Controller\AdminController::class, 'create']);

        // 管理员详情
        $router->get('/admin/admins/{id}', [\App\Controller\AdminController::class, 'detail']);

        // 更新管理员（修改账号/密码/角色/状态，仅 super_admin）
        $router->put('/admin/update/{id}', [\App\Controller\AdminController::class, 'update']);
        $router->put('/admin/admins/{id}', [\App\Controller\AdminController::class, 'update']);

        // 删除管理员（仅 super_admin，不能删除最后一个 super_admin）
        $router->delete('/admin/delete/{id}', [\App\Controller\AdminController::class, 'delete']);
        $router->delete('/admin/admins/{id}',  [\App\Controller\AdminController::class, 'delete']);

        // 切换管理员状态
        $router->put('/admin/admins/{id}/status', [\App\Controller\AdminController::class, 'toggleStatus']);

        // 修改自己的密码
        $router->put('/admin/change-password', [\App\Controller\AdminController::class, 'changePassword']);

        // 操作日志列表（分页 10 条）
        $router->get('/admin/logs', [\App\Controller\AdminController::class, 'logs']);

        // 管理员登录日志列表（分页 10 条，支持 IP 搜索）
        $router->get('/admin/login-logs', [\App\Controller\AdminController::class, 'loginLogs']);

        // ============================================================
        // 开放 API 推送接口（X-Api-Key 鉴权）
        // ============================================================
        $router->post('/api/push', [\App\Controller\ApiPushController::class, 'push']);

        // ============================================================
        // 用户管理（管理员鉴权）
        // ============================================================
        $router->get('/admin/users',              [\App\Controller\UserController::class, 'index']);
        $router->get('/admin/users/{id}',         [\App\Controller\UserController::class, 'show']);
        $router->put('/admin/users/{id}',         [\App\Controller\UserController::class, 'update']);
        $router->put('/admin/users/{id}/password', [\App\Controller\UserController::class, 'resetPassword']);
        $router->delete('/admin/users/{id}',      [\App\Controller\UserController::class, 'delete']);
        $router->delete('/admin/users',           [\App\Controller\UserController::class, 'clearAll']);
        $router->put('/admin/users/{id}/status',  [\App\Controller\UserController::class, 'toggleStatus']);

        // ============================================================
        // Push Key 管理（管理员鉴权）
        // ============================================================
        $router->get('/admin/keys',               [\App\Controller\PushKeyController::class, 'index']);
        $router->get('/admin/keys/{id}',          [\App\Controller\PushKeyController::class, 'show']);
        $router->post('/admin/keys',              [\App\Controller\PushKeyController::class, 'create']);
        $router->put('/admin/keys/{id}',          [\App\Controller\PushKeyController::class, 'update']);
        $router->delete('/admin/keys/{id}',       [\App\Controller\PushKeyController::class, 'delete']);
        $router->put('/admin/keys/{id}/status',   [\App\Controller\PushKeyController::class, 'updateStatus']);

        // ============================================================
        // API Key 管理（管理员鉴权）
        // ============================================================
        $router->get('/admin/api-keys',             [\App\Controller\ApiKeyController::class, 'index']);
        $router->post('/admin/api-keys',            [\App\Controller\ApiKeyController::class, 'create']);
        $router->get('/admin/api-keys/{id}',        [\App\Controller\ApiKeyController::class, 'show']);
        $router->put('/admin/api-keys/{id}',        [\App\Controller\ApiKeyController::class, 'update']);
        $router->delete('/admin/api-keys/{id}',     [\App\Controller\ApiKeyController::class, 'delete']);
        $router->put('/admin/api-keys/{id}/status', [\App\Controller\ApiKeyController::class, 'toggleStatus']);

        // ============================================================
        // 设备管理（管理员鉴权）
        // ============================================================
        $router->get('/admin/devices',            [\App\Controller\DeviceController::class, 'index']);
        $router->get('/admin/devices/{id}',       [\App\Controller\DeviceController::class, 'show']);

        // ============================================================
        // 黑名单管理（管理员鉴权）
        // ============================================================
        $router->get('/admin/blacklist',          [\App\Controller\BlacklistController::class, 'index']);
        $router->post('/admin/blacklist',         [\App\Controller\BlacklistController::class, 'create']);
        $router->delete('/admin/blacklist/{id}',  [\App\Controller\BlacklistController::class, 'delete']);

        // ============================================================
        // 仪表盘统计（管理员鉴权）
        // ============================================================
        $router->get('/admin/dashboard/overview',        [\App\Controller\DashboardController::class, 'overview']);
        $router->get('/admin/dashboard/online-trend',    [\App\Controller\DashboardController::class, 'onlineTrend']);
        $router->get('/admin/dashboard/today-push',      [\App\Controller\DashboardController::class, 'todayPush']);
        $router->get('/admin/dashboard/key-distribution',[\App\Controller\DashboardController::class, 'keyDistribution']);
        $router->get('/admin/dashboard/device-platform', [\App\Controller\DashboardController::class, 'devicePlatform']);
        $router->get('/admin/dashboard/recent-push',     [\App\Controller\DashboardController::class, 'recentPush']);

        // ============================================================
        // 消息记录与导出（管理员鉴权）
        // ============================================================
        $router->get('/admin/messages',          [\App\Controller\MessageController::class, 'index']);
        $router->get('/admin/messages/export',    [\App\Controller\MessageController::class, 'exportMessages']);
        $router->get('/admin/push-logs',          [\App\Controller\MessageController::class, 'pushLogs']);
        $router->get('/admin/push-logs/export',   [\App\Controller\MessageController::class, 'exportPushLogs']);

        // ============================================================
        // 测试调试推送（管理员鉴权）
        // ============================================================
        $router->post('/admin/test-push',         [\App\Controller\TestPushController::class, 'send']);
        $router->get('/admin/test-push/check',    [\App\Controller\TestPushController::class, 'check']);

        // APP 端自测推送（无需鉴权，通过 Key + device_id）
        $router->post('/api/test-push-self',      [\App\Controller\TestPushController::class, 'selfTest']);

        // ============================================================
        // 系统设置（管理员鉴权）
        // ============================================================
        $router->get('/admin/settings',                    [\App\Controller\SettingsController::class, 'getSettings']);
        $router->put('/admin/settings',                     [\App\Controller\SettingsController::class, 'updateSettings']);
        $router->get('/admin/settings/mail',               [\App\Controller\SettingsController::class, 'getMailConfig']);
        $router->post('/admin/settings/mail',              [\App\Controller\SettingsController::class, 'saveMailConfig']);
        $router->post('/admin/settings/mail/test',        [\App\Controller\SettingsController::class, 'testMailConfig']);
        $router->get('/admin/settings/system-info',        [\App\Controller\SettingsController::class, 'getSystemInfo']);
        $router->get('/admin/settings/check-version',      [\App\Controller\SettingsController::class, 'checkVersion']);
        $router->post('/admin/settings/system-update',     [\App\Controller\SettingsController::class, 'systemUpdate']);
        $router->get('/admin/settings/update-progress/{taskId}', [\App\Controller\SettingsController::class, 'getUpdateProgress']);

        // ============================================================
        // 域名绑定与 SSL 证书管理（管理员鉴权）
        // ============================================================
        $router->get('/admin/domains',                          [\App\Controller\DomainController::class, 'index']);
        $router->get('/admin/domains/environment',              [\App\Controller\DomainController::class, 'environment']);
        $router->post('/admin/domains',                         [\App\Controller\DomainController::class, 'create']);
        $router->post('/admin/domains/sync-nginx',              [\App\Controller\DomainController::class, 'syncNginx']);
        $router->post('/admin/domains/renew-all',               [\App\Controller\DomainController::class, 'renewAll']);
        $router->post('/admin/domains/install-acme',            [\App\Controller\DomainController::class, 'installAcme']);
        $router->get('/admin/domains/{id}',                     [\App\Controller\DomainController::class, 'show']);
        $router->put('/admin/domains/{id}',                     [\App\Controller\DomainController::class, 'update']);
        $router->delete('/admin/domains/{id}',                  [\App\Controller\DomainController::class, 'delete']);
        $router->post('/admin/domains/{id}/set-primary',        [\App\Controller\DomainController::class, 'setPrimary']);
        $router->post('/admin/domains/{id}/ssl-apply',         [\App\Controller\DomainController::class, 'applySsl']);
        $router->post('/admin/domains/{id}/ssl-renew',         [\App\Controller\DomainController::class, 'renewSsl']);
        $router->post('/admin/domains/{id}/ssl-deploy',         [\App\Controller\DomainController::class, 'deployNginx']);
        $router->post('/admin/domains/{id}/toggle-auto-renew',  [\App\Controller\DomainController::class, 'toggleAutoRenew']);
    };

    $server = new \App\HttpServer($routeRegistrar);
    if ($daemonize) {
        $server->getServer()->set(['daemonize' => true]);
    }
    $server->start();
}
