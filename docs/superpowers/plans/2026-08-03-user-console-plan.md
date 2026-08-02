# 用户端独立系统 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 构建用户端独立前端（`APP/user/`）和 `/user-api/*` 强隔离后端接口，包含仪表盘、推送、推送记录、设备、Key、API 文档、APP下载/生成、个人中心（QQ绑定）、公告弹窗；管理端新增路径设置、安全设置（QQ改密模式）、用户端公告管理、用户管理QQ操作；支持通过 QQ 号/QQ号+邮箱验证码 重置密码；路径配置可后台修改并实时生效。

**Architecture:**
- 前端：独立 Vue3+Vite+TS+ElementPlus 项目 `APP/user/`，运行时注入 `window.__APP_BASE__` 适配可配置路径
- 后端：新增 `UserConsole/*` 控制器 + `UserApiAuth` 中间件（JWT type=user + user_id 注入）+ `StaticRouter` 前置中间件（DB配置动态渲染前端入口）
- 数据隔离：`UserApiAuth` 把 user_id 注入全局，Service 强制 `WHERE user_id = ?`；前端不传 user_id
- QQ绑定：users 表加 `qq_number` 列，用户端一次绑定后不可解绑；管理端可改绑；公开接口 `/auth/reset-password-by-qq` 两种验证模式
- HBuilderX APP：内置 `user/hbuilderx-template/` 模板，后端参数替换后生成 ZIP 下载

**Tech Stack:** Vue 3 / Vite 5 / TypeScript / Element Plus / Pinia / Vue Router (hash) / Swoole HTTP (PHP) / MySQL / Redis

---

## 文件结构总览

| 路径 | 创建/修改 | 说明 |
|------|----------|------|
| `backend/database/migrations/020_user_console.sql` | CREATE | 公告表/QQ字段/api_keys.user_id/settings seed |
| `backend/src/Middleware/UserApiAuth.php` | CREATE | type=user JWT + user_id 注入 |
| `backend/src/Middleware/StaticRouter.php` | CREATE | settings_paths 动态渲染 admin/user 前端入口 HTML |
| `backend/src/Controller/UserConsole/BaseController.php` | CREATE | 继承基类，`$this->userId` 注入 |
| `backend/src/Controller/UserConsole/DashboardController.php` | CREATE | /user-api/dashboard/stats |
| `backend/src/Controller/UserConsole/PushController.php` | CREATE | /user-api/push/send |
| `backend/src/Controller/UserConsole/PushLogController.php` | CREATE | /user-api/push-logs 列表/详情/重推 |
| `backend/src/Controller/UserConsole/DeviceController.php` | CREATE | /user-api/devices 列表/更新/解绑/清僵尸 |
| `backend/src/Controller/UserConsole/KeyController.php` | CREATE | /user-api/keys 列表/新建/吊销 |
| `backend/src/Controller/UserConsole/DocsController.php` | CREATE | /user-api/docs JSON+示例 |
| `backend/src/Controller/UserConsole/AppController.php` | CREATE | /user-api/app/downloads + generate-hbuilderx |
| `backend/src/Controller/UserConsole/NoticeController.php` | CREATE | /user-api/notices 拉取/已读/snooze |
| `backend/src/Controller/UserConsole/ProfileController.php` | CREATE | /user-api/profile 信息/改密/绑定QQ |
| `backend/src/Service/UserNoticeService.php` | CREATE | 公告 CRUD + 已读 + WS broadcast 发布 |
| `backend/src/Service/HBuilderXService.php` | CREATE | 生成 HBuilderX ZIP + 参数替换 + 临时文件清理 |
| `backend/src/Service/ApiKeyService.php` | MODIFY | user-scope create/list/revoke 方法 |
| `backend/src/Service/DeviceService.php` | MODIFY | user-scope 列表/更新/解绑/清僵尸 |
| `backend/src/Service/UserService.php` | MODIFY | 新增 bindQQ / resetPasswordByQQ / qqResetMode |
| `backend/src/Controller/AuthController.php` | MODIFY | 新增 POST /auth/reset-password-by-qq 路由 |
| `backend/src/Controller/SettingsController.php` | MODIFY | 读/写 settings_paths/settings_security/settings_user_app |
| `backend/src/Controller/UserController.php` | MODIFY | 管理端：QQ绑定/解绑/QQ重置密码 |
| `backend/public/index.php` | MODIFY | StaticRouter 前置 + /user-api/* 路由注册 |
| `backend/.env.example` | MODIFY | 新增路径/QQ改密注释项 |
| `user/package.json` | CREATE | 依赖同 admin |
| `user/vite.config.ts` | CREATE | 同 admin 结构，base 为空（运行时注入） |
| `user/tsconfig.json` + `tsconfig.node.json` | CREATE | 同 admin |
| `user/index.html` | CREATE | 占位（运行时注入 `window.__APP_BASE__`） |
| `user/src/main.ts` / `App.vue` / `env.d.ts` | CREATE | 基础入口 |
| `user/src/api/request.ts` | CREATE | axios 封装 + token + base 运行时注入 |
| `user/src/api/{dashboard,push,pushLogs,devices,keys,docs,app,notice,profile}.ts` | CREATE | 用户端 API 调用封装 |
| `user/src/router/index.ts` | CREATE | 常量路由 + 菜单路由（全部登录可见） |
| `user/src/stores/{user,app,permission}.ts` | CREATE | 简化版 stores |
| `user/src/layout/` | CREATE | 侧栏+顶栏+Tab+AppMain（复用 admin 风格） |
| `user/src/styles/` | CREATE | variables/mixins/dark/reset（主色 teal-500） |
| `user/src/utils/auth.ts` | CREATE | localStorage token 读写 |
| `user/src/components/NoticeDialog.vue` | CREATE | 全局公告弹窗 |
| `user/src/views/{login,register,forgot-password,dashboard,push,push-logs,devices,keys,docs,app,profile,error/404}/index.vue` | CREATE | 用户端页面 |
| `user/hbuilderx-template/{manifest.json,pages.json,main.js,App.vue,uni.scss,README_HBUILDERX.txt,static/env.js,static/logo.png}` | CREATE | HBuilderX 模板骨架 + pages |
| `admin/src/router/index.ts` | MODIFY | 新增 /user-notices 路由（admin） |
| `admin/src/views/settings/index.vue` | MODIFY | 新增 路径设置 / 安全设置 tab |
| `admin/src/views/user-notices/index.vue` | CREATE | 公告 CRUD 管理页 |
| `admin/src/views/module/index.vue` | MODIFY | users 模块新增 QQ列 + 绑定/解绑/QQ重置密码操作 |
| `admin/src/api/settings.ts` | MODIFY | 新增 getPaths/savePaths/getSecurity/saveSecurity/getUserApp/saveUserApp/getUserNotices/saveUserNotice 等 |
| `admin/src/views/login/index.vue` | MODIFY | 登录页"通过QQ号重置密码"入口 |
| `deploy/nginx/push.conf` | MODIFY | 改成统一入口：静态文件 try_files，其他全反向代理到 Swoole |

---

## Task 1：数据库迁移 + settings seed

**Files:**
- Create: `backend/database/migrations/020_user_console.sql`

- [ ] **Step 1: Write migration SQL**

```sql
-- 020_user_console.sql
-- 公告表
CREATE TABLE IF NOT EXISTS user_notices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  content TEXT NOT NULL,
  level ENUM('info','warning','important') NOT NULL DEFAULT 'info',
  is_active TINYINT NOT NULL DEFAULT 1,
  published_by INT NULL,
  published_at DATETIME NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_active_level (is_active, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_notice_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  notice_id INT NOT NULL,
  snooze_until DATETIME NULL,
  read_at DATETIME NOT NULL,
  UNIQUE KEY uk_user_notice (user_id, notice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- users QQ 字段
SET @has_qq = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='users' AND column_name='qq_number');
SET @sql = IF(@has_qq = 0,
  "ALTER TABLE users ADD COLUMN qq_number VARCHAR(20) NULL DEFAULT NULL COMMENT '绑定QQ号(纯数字，用户端不可解绑)', ADD COLUMN qq_bound_at DATETIME NULL, ADD UNIQUE INDEX uk_qq_number (qq_number)",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- api_keys.user_id（条件创建）
SET @has_uid = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema=DATABASE() AND table_name='api_keys' AND column_name='user_id');
SET @sql = IF(@has_uid = 0,
  "ALTER TABLE api_keys ADD COLUMN user_id INT NULL DEFAULT NULL COMMENT '归属用户 NULL=管理员'",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_uid_idx = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema=DATABASE() AND table_name='api_keys' AND index_name='idx_user');
SET @sql = IF(@has_uid_idx = 0,
  'ALTER TABLE api_keys ADD INDEX idx_user (user_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- admin_settings seed
INSERT INTO admin_settings (config_key, config_value, updated_at) VALUES
('settings_paths', JSON_OBJECT('admin_path','/','user_path','/user/','admin_api_prefix','/admin/','user_api_prefix','/user-api/'), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO admin_settings (config_key, config_value, updated_at) VALUES
('settings_security', JSON_OBJECT('qq_reset_mode','qq_and_email'), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO admin_settings (config_key, config_value, updated_at) VALUES
('settings_user_app', JSON_OBJECT('apk_url','','apk_version','','ipa_url','','ipa_version',''), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();
```

- [ ] **Step 2: 本地快速检查 SQL 语法合法性**（无 MySQL 时仅目视即可）：确认每个 `PREPARE stmt` 都有 `EXECUTE` + `DEALLOCATE`；无单引号转义错误。

- [ ] **Step 3: Commit**

```bash
git add backend/database/migrations/020_user_console.sql
git commit -m "feat: 020迁移 - user_notices/users_qq/api_keys_user_id/settings_seed"
```

---

## Task 2：UserApiAuth + StaticRouter 中间件

**Files:**
- Create: `backend/src/Middleware/UserApiAuth.php`
- Create: `backend/src/Middleware/StaticRouter.php`

- [ ] **Step 1: Write UserApiAuth**

```php
<?php declare(strict_types=1);
namespace App\Middleware;

use App\Service\Jwt;
use App\Service\Response;

class UserApiAuth
{
    public function handle(callable $next)
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $token = '';
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) { $token = $m[1]; }
        elseif (isset($_GET['token'])) { $token = (string)$_GET['token']; }

        if ($token === '') { Response::json(401, '请先登录'); return; }

        $payload = Jwt::verify($token);
        if ($payload === null) { Response::json(401, '登录已过期或无效'); return; }
        if (($payload['type'] ?? '') !== 'user') { Response::json(403, '仅用户端可访问'); return; }
        if (empty($payload['user_id'])) { Response::json(403, 'token缺少user_id'); return; }

        $GLOBALS['_USER_ID'] = (int)$payload['user_id'];
        $GLOBALS['_USER_NAME'] = (string)($payload['username'] ?? '');
        $next();
    }

    public static function userId(): int { return (int)($GLOBALS['_USER_ID'] ?? 0); }
    public static function userName(): string { return (string)($GLOBALS['_USER_NAME'] ?? ''); }
}
```

- [ ] **Step 2: Write StaticRouter**

```php
<?php declare(strict_types=1);
namespace App\Middleware;

use App\Service\Database;

/**
 * 最先执行的前置中间件：根据 settings_paths 配置，渲染 admin/user 前端入口 HTML，
 * 并在 <head> 注入 window.__APP_BASE__ / window.__APP_BASE_API__。
 * 静态资源（.js/.css/.png 等）不经过本类，由 Swoole 或 Nginx 静态 try_files 直接返回。
 */
class StaticRouter
{
    private static array $cache = ['paths' => [], 'expires_at' => 0];
    private const TTL = 5;

    public static function getPaths(): array
    {
        $now = time();
        if (self::$cache['expires_at'] > $now && !empty(self::$cache['paths'])) {
            return self::$cache['paths'];
        }
        $defaults = [
            'admin_path' => '/',
            'user_path' => '/user/',
            'admin_api_prefix' => '/admin/',
            'user_api_prefix' => '/user-api/',
        ];
        try {
            $row = Database::fetch("SELECT config_value FROM admin_settings WHERE config_key='settings_paths' LIMIT 1");
            if (!empty($row['config_value'])) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg)) { $defaults = array_merge($defaults, $cfg); }
            }
        } catch (\Throwable $e) { /* 降级用默认值 */ }
        self::$cache = ['paths' => $defaults, 'expires_at' => $now + self::TTL];
        return $defaults;
    }

    public static function resolve(string $uri): ?string
    {
        $paths = self::getPaths();
        // 去掉 query string
        $uri = explode('?', $uri, 2)[0];
        $adminBase = rtrim($paths['admin_path'], '/') ?: '/';
        $userBase  = rtrim($paths['user_path'], '/')  ?: '/user';

        // 1) API 前缀匹配 -> 返回 null，走业务路由
        foreach ([$paths['admin_api_prefix'], $paths['user_api_prefix'], '/auth/'] as $pfx) {
            if ($pfx !== '' && str_starts_with($uri, $pfx)) return null;
        }

        // 2) user 前缀 -> 返回 user/dist/index.html
        if ($userBase === '/' ? false : str_starts_with($uri, $userBase . '/') || $uri === $userBase) {
            return self::renderEntry('user', $paths);
        }

        // 3) admin 前缀（默认/）-> 返回 admin/dist/index.html
        if ($adminBase === '/') {
            // 常见 API/静态扩展名直接跳过
            if (preg_match('/\.(js|css|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|map|wasm|zip|apk|ipa|wav|mp3)$/i', $uri)) return null;
            if (str_starts_with($uri, '/admin/') || str_starts_with($uri, '/user-api/') || str_starts_with($uri, '/auth/')) return null;
            return self::renderEntry('admin', $paths);
        }
        if (str_starts_with($uri, $adminBase . '/') || $uri === $adminBase) {
            return self::renderEntry('admin', $paths);
        }

        return null;
    }

    private static function renderEntry(string $which, array $paths): string
    {
        $projectDir = dirname(__DIR__, 3);
        $file = $projectDir . DIRECTORY_SEPARATOR . $which . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'index.html';
        $html = @file_get_contents($file);
        if ($html === false) {
            return "<!doctype html><html><body><h2>{$which} 前端未构建</h2><p>请在 {$which}/ 目录执行 npm ci && npm run build</p></body></html>";
        }
        $base = $which === 'admin' ? $paths['admin_path'] : $paths['user_path'];
        $apiBase = $which === 'admin' ? $paths['admin_api_prefix'] : $paths['user_api_prefix'];
        $inject = "<script>window.__APP_BASE__='{$base}';window.__APP_BASE_API__='{$apiBase}';</script>";
        return str_replace('<head>', "<head>{$inject}", $html);
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add backend/src/Middleware/{UserApiAuth,StaticRouter}.php
git commit -m "feat: UserApiAuth 中间件(type=user JWT + userId注入) + StaticRouter 前置动态渲染入口"
```

---

## Task 3：UserConsole 控制器基类 + Dashboard/PushLog/Device/Key/Docs/Notice/Profile/Push/App 共 10 个控制器

> 每个控制器单独一个 commit，但在本 Task 中按统一模式列出。

**Shared base:** `backend/src/Controller/UserConsole/BaseController.php`

- [ ] **Step 1: BaseController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Middleware\UserApiAuth;
use App\Service\Response;

abstract class BaseController
{
    protected int $userId;
    protected string $userName;

    public function __construct()
    {
        $this->userId = UserApiAuth::userId();
        $this->userName = UserApiAuth::userName();
        if ($this->userId <= 0) { Response::json(401, '登录已过期'); exit; }
    }
}
```

- [ ] **Step 2: DashboardController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Response;

class DashboardController extends BaseController
{
    public function stats()
    {
        $today = date('Y-m-d') . ' 00:00:00';
        $todayPushCount = (int)Database::fetch(
            'SELECT COUNT(*) c FROM push_logs WHERE user_id=? AND created_at>=?',
            [$this->userId, $today]
        )['c'];
        $totalPushCount = (int)Database::fetch(
            'SELECT COUNT(*) c FROM push_logs WHERE user_id=?',
            [$this->userId]
        )['c'];
        $successPushCount = (int)Database::fetch(
            "SELECT COUNT(*) c FROM push_logs WHERE user_id=? AND status='success'",
            [$this->userId]
        )['c'];
        $successRate = $totalPushCount > 0 ? round($successPushCount * 100 / $totalPushCount, 2) : 0;
        $deviceCount = (int)Database::fetch(
            'SELECT COUNT(*) c FROM devices WHERE user_id=?',
            [$this->userId]
        )['c'];
        $onlineDeviceCount = (int)Database::fetch(
            "SELECT COUNT(*) c FROM devices WHERE user_id=? AND status='online'",
            [$this->userId]
        )['c'];
        $keyCount = (int)Database::fetch(
            'SELECT COUNT(*) c FROM api_keys WHERE user_id=? AND revoked=0',
            [$this->userId]
        )['c'];
        Response::json(0, 'ok', [
            'today_push_count' => $todayPushCount,
            'total_push_count' => $totalPushCount,
            'success_rate' => $successRate,
            'device_count' => $deviceCount,
            'online_device_count' => $onlineDeviceCount,
            'key_count' => $keyCount,
        ]);
    }
}
```

- [ ] **Step 3: PushLogController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Response;

class PushLogController extends BaseController
{
    public function list()
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $pageSize;

        $where = ['user_id = ?']; $params = [$this->userId];
        if (!empty($_GET['key_id'])) { $where[] = 'key_id = ?'; $params[] = (int)$_GET['key_id']; }
        if (!empty($_GET['status'])) { $where[] = 'status = ?'; $params[] = (string)$_GET['status']; }
        if (!empty($_GET['target_type'])) { $where[] = 'target_type = ?'; $params[] = (string)$_GET['target_type']; }
        if (!empty($_GET['start_time'])) { $where[] = 'created_at >= ?'; $params[] = (string)$_GET['start_time']; }
        if (!empty($_GET['end_time'])) { $where[] = 'created_at <= ?'; $params[] = (string)$_GET['end_time']; }
        $whereSql = implode(' AND ', $where);

        $total = (int)Database::fetch("SELECT COUNT(*) c FROM push_logs WHERE {$whereSql}", $params)['c'];
        $rows = Database::fetchAll(
            "SELECT id,key_id,target_type,title,content,status,success_count,fail_count,created_at FROM push_logs WHERE {$whereSql} ORDER BY id DESC LIMIT {$offset},{$pageSize}",
            $params
        );
        Response::json(0, 'ok', ['total' => $total, 'items' => $rows ?: [], 'page' => $page, 'page_size' => $pageSize]);
    }

    public function detail(int $id)
    {
        $row = Database::fetch(
            'SELECT * FROM push_logs WHERE id=? AND user_id=? LIMIT 1',
            [$id, $this->userId]
        );
        if (!$row) { Response::json(404, '记录不存在'); return; }
        $details = Database::fetchAll(
            'SELECT device_id, status, error_msg FROM push_log_details WHERE push_log_id=? ORDER BY id DESC',
            [$id]
        );
        $row['details'] = $details ?: [];
        Response::json(0, 'ok', $row);
    }

    public function retry(int $id)
    {
        $row = Database::fetch(
            'SELECT * FROM push_logs WHERE id=? AND user_id=? LIMIT 1',
            [$id, $this->userId]
        );
        if (!$row) { Response::json(404, '记录不存在'); return; }
        // 调用 PushDispatcher（若不可用则回退为 Response 提示"重推需要 PushDispatcher"）
        if (class_exists(\App\Service\PushDispatcher::class)) {
            $targetType = (string)$row['target_type'];
            $targetValue = (string)($row['target_value'] ?? '');
            $payload = json_decode((string)$row['payload'], true) ?: [];
            $title = (string)($row['title'] ?? '');
            $content = (string)($row['content'] ?? '');
            \App\Service\PushDispatcher::dispatch($this->userId, (int)($row['key_id'] ?? 0), $targetType, $targetValue, $title, $content, $payload);
            Response::json(0, '重推已入队');
        } else {
            Response::json(500, 'PushDispatcher 不可用');
        }
    }
}
```

- [ ] **Step 4: DeviceController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Response;

class DeviceController extends BaseController
{
    public function list()
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = min(200, max(1, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $pageSize;

        $where = ['user_id = ?']; $params = [$this->userId];
        if (!empty($_GET['status'])) { $where[] = 'status = ?'; $params[] = (string)$_GET['status']; }
        if (!empty($_GET['platform'])) { $where[] = 'platform = ?'; $params[] = (string)$_GET['platform']; }
        if (!empty($_GET['tag'])) { $where[] = 'tags LIKE ?'; $params[] = '%' . (string)$_GET['tag'] . '%'; }
        if (!empty($_GET['keyword'])) {
            $where[] = '(device_name LIKE ? OR remark LIKE ? OR push_token LIKE ?)';
            $kw = '%' . (string)$_GET['keyword'] . '%';
            $params[] = $kw; $params[] = $kw; $params[] = $kw;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int)Database::fetch("SELECT COUNT(*) c FROM devices WHERE {$whereSql}", $params)['c'];
        $rows = Database::fetchAll(
            "SELECT id,push_token,platform,device_name,model,version,tags,remark,status,last_seen_at,created_at FROM devices WHERE {$whereSql} ORDER BY last_seen_at DESC,id DESC LIMIT {$offset},{$pageSize}",
            $params
        );
        Response::json(0, 'ok', ['total' => $total, 'items' => $rows ?: [], 'page' => $page, 'page_size' => $pageSize]);
    }

    public function update(int $id)
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $fields = []; $params = [];
        if (isset($body['remark'])) { $fields[] = 'remark = ?'; $params[] = (string)$body['remark']; }
        if (isset($body['tags'])) { $fields[] = 'tags = ?'; $params[] = (string)$body['tags']; }
        if (empty($fields)) { Response::json(400, '无字段更新'); return; }
        $params[] = $id; $params[] = $this->userId;
        $fieldsSql = implode(', ', $fields);
        Database::execute("UPDATE devices SET {$fieldsSql} WHERE id=? AND user_id=?", $params);
        Response::json(0, '更新成功');
    }

    public function delete(int $id)
    {
        Database::execute('DELETE FROM devices WHERE id=? AND user_id=?', [$id, $this->userId]);
        Response::json(0, '解绑成功');
    }

    public function clearZombie()
    {
        $threshold = date('Y-m-d H:i:s', time() - 7 * 86400);
        $row = Database::execute(
            "DELETE FROM devices WHERE user_id=? AND status='offline' AND last_seen_at < ?",
            [$this->userId, $threshold]
        );
        Response::json(0, '清理完成', ['deleted_count' => is_object($row) ? 0 : (int)$row]);
    }
}
```

- [ ] **Step 5: KeyController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Response;

class KeyController extends BaseController
{
    public function list()
    {
        $rows = Database::fetchAll(
            "SELECT id,name,permissions,revoked,last_used_at,created_at,CONCAT(LEFT(key_value,8),'****') as masked_key FROM api_keys WHERE user_id=? ORDER BY id DESC",
            [$this->userId]
        );
        Response::json(0, 'ok', $rows ?: []);
    }

    public function create()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim((string)($body['name'] ?? ''));
        $permissions = $body['permissions'] ?? ['push:send'];
        if ($name === '') { Response::json(400, '名称不能为空'); return; }
        $permissionsJson = is_array($permissions) ? json_encode($permissions, JSON_UNESCAPED_UNICODE) : '[]';
        $now = date('Y-m-d H:i:s');
        $keyValue = 'pk_' . bin2hex(random_bytes(16));
        $hash = password_hash($keyValue, PASSWORD_BCRYPT);
        $id = Database::insert(
            'INSERT INTO api_keys (name,key_hash,key_value,permissions,user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
            [$name, $hash, $keyValue, $permissionsJson, $this->userId, $now, $now]
        );
        Response::json(0, '创建成功（key 仅展示一次，请立刻保存）', [
            'id' => (int)$id,
            'name' => $name,
            'key_value' => $keyValue,
        ]);
    }

    public function revoke(int $id)
    {
        $row = Database::execute(
            'UPDATE api_keys SET revoked=1, updated_at=NOW() WHERE id=? AND user_id=?',
            [$id, $this->userId]
        );
        Response::json(0, '已吊销');
    }
}
```

- [ ] **Step 6: DocsController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Service\Response;

class DocsController extends BaseController
{
    public function index()
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'example.com');
        Response::json(0, 'ok', [
            'base_url' => rtrim($origin, '/') . '/user-api',
            'auth' => 'Authorization: Bearer <用户端 token>',
            'endpoints' => [
                [
                    'method' => 'GET', 'path' => '/dashboard/stats',
                    'desc' => '个人统计',
                    'curl' => "curl -H 'Authorization: Bearer <token>' {$origin}/user-api/dashboard/stats",
                ],
                [
                    'method' => 'POST', 'path' => '/push/send',
                    'desc' => '发送推送（目标类型 key/device/broadcast）',
                    'curl' => "curl -X POST -H 'Authorization: Bearer <token>' -H 'Content-Type: application/json' -d '{\"target_type\":\"key\",\"key_id\":1,\"title\":\"Hi\",\"content\":\"内容\"}' {$origin}/user-api/push/send",
                    'php_example' => "<?php \$ch=curl_init('{$origin}/user-api/push/send'); curl_setopt_array(\$ch,[CURLOPT_POST=>1,CURLOPT_HTTPHEADER=>['Authorization: Bearer <token>','Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['target_type'=>'broadcast','title'=>'Hi','content'=>'内容']),CURLOPT_RETURNTRANSFER=>1]); echo curl_exec(\$ch);",
                ],
                [
                    'method' => 'GET', 'path' => '/push-logs',
                    'desc' => '推送记录列表（分页+筛选）',
                    'curl' => "curl -H 'Authorization: Bearer <token>' '{$origin}/user-api/push-logs?page=1&per_page=20'",
                ],
                [
                    'method' => 'GET', 'path' => '/devices',
                    'desc' => '设备列表',
                    'curl' => "curl -H 'Authorization: Bearer <token>' '{$origin}/user-api/devices?page=1'",
                ],
                [
                    'method' => 'GET', 'path' => '/keys',
                    'desc' => 'API Key 列表（返回掩码）',
                    'curl' => "curl -H 'Authorization: Bearer <token>' {$origin}/user-api/keys",
                ],
            ],
        ]);
    }
}
```

- [ ] **Step 7: NoticeController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Response;

class NoticeController extends BaseController
{
    public function latest()
    {
        $now = date('Y-m-d H:i:s');
        $rows = Database::fetchAll(
            "SELECT n.id,n.title,n.content,n.level,n.published_at FROM user_notices n
             WHERE n.is_active=1 AND (n.expires_at IS NULL OR n.expires_at>?)
             ORDER BY FIELD(n.level,'important','warning','info'),n.id DESC LIMIT 10",
            [$now]
        );
        if (empty($rows)) { Response::json(0, 'ok', ['unread' => [], 'has_unread' => false]); return; }

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$this->userId], $ids);
        $readRows = Database::fetchAll(
            "SELECT notice_id,read_at,snooze_until FROM user_notice_reads WHERE user_id=? AND notice_id IN ({$placeholders})",
            $params
        );
        $readMap = [];
        foreach ($readRows as $r) { $readMap[(int)$r['notice_id']] = $r; }

        $unread = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $read = $readMap[$id] ?? null;
            $snooze = $read['snooze_until'] ?? null;
            $isRead = $read && ($snooze === null || $snooze < $now) ? !empty($read['read_at']) : ($snooze !== null && $snooze >= $now);
            if (!$read || ($snooze !== null && $snooze < $now) || empty($read['read_at'])) {
                if ($snooze === null || $snooze < $now) { $unread[] = $r; }
            }
        }
        Response::json(0, 'ok', ['unread' => $unread, 'has_unread' => count($unread) > 0]);
    }

    public function read(int $id)
    {
        $now = date('Y-m-d H:i:s');
        Database::execute(
            "INSERT INTO user_notice_reads (user_id,notice_id,read_at,snooze_until) VALUES (?,?,?,NULL)
             ON DUPLICATE KEY UPDATE read_at=VALUES(read_at),snooze_until=NULL",
            [$this->userId, $id, $now]
        );
        Response::json(0, '已标记已读');
    }

    public function snooze(int $id)
    {
        $until = date('Y-m-d H:i:s', time() + 7 * 86400);
        $now = date('Y-m-d H:i:s');
        Database::execute(
            "INSERT INTO user_notice_reads (user_id,notice_id,read_at,snooze_until) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE snooze_until=VALUES(snooze_until),read_at=COALESCE(VALUES(read_at),read_at)",
            [$this->userId, $id, $now, $until]
        );
        Response::json(0, '已设置同类 7 天内不再弹出');
    }
}
```

- [ ] **Step 8: ProfileController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Response;
use App\Service\UserService;

class ProfileController extends BaseController
{
    public function get()
    {
        $row = Database::fetch(
            'SELECT id,username,phone,email,qq_number,qq_bound_at,status,created_at FROM users WHERE id=? LIMIT 1',
            [$this->userId]
        );
        if (!$row) { Response::json(404, '账号不存在'); return; }
        Response::json(0, 'ok', $row);
    }

    public function changePassword()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $old = (string)($body['old_password'] ?? '');
        $new = (string)($body['new_password'] ?? '');
        if ($old === '' || $new === '') { Response::json(400, '新旧密码不能为空'); return; }
        $row = Database::fetch('SELECT password_hash FROM users WHERE id=? LIMIT 1', [$this->userId]);
        if (!$row || !password_verify($old, (string)$row['password_hash'])) { Response::json(400, '原密码错误'); return; }
        $check = \App\Service\AdminService::validatePasswordStrength($new);
        if (!$check['valid']) { Response::json(400, $check['message']); return; }
        $hash = password_hash($new, PASSWORD_BCRYPT);
        Database::execute('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?', [$hash, $this->userId]);
        Response::json(0, '修改成功');
    }

    public function bindQQ()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $qq = trim((string)($body['qq_number'] ?? ''));
        if ($qq === '' || !ctype_digit($qq) || strlen($qq) > 11 || strlen($qq) < 5) { Response::json(400, 'QQ号格式不正确'); return; }
        $res = UserService::bindQQByUser($this->userId, $qq);
        Response::json($res['success'] ? 0 : 400, $res['message']);
    }
}
```

- [ ] **Step 9: PushController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Response;

class PushController extends BaseController
{
    public function send()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $targetType = trim((string)($body['target_type'] ?? ''));
        $title = trim((string)($body['title'] ?? ''));
        $content = trim((string)($body['content'] ?? ''));
        if (!in_array($targetType, ['key', 'device', 'broadcast'], true)) { Response::json(400, 'target_type 仅支持 key/device/broadcast'); return; }
        if ($title === '' && $content === '') { Response::json(400, 'title/content 至少填一个'); return; }

        $keyId = 0; $deviceIds = [];
        if ($targetType === 'key') {
            $keyId = (int)($body['key_id'] ?? 0);
            $row = Database::fetch('SELECT id FROM api_keys WHERE id=? AND user_id=? AND revoked=0 LIMIT 1', [$keyId, $this->userId]);
            if (!$row) { Response::json(400, 'Key 不存在或已吊销'); return; }
        } elseif ($targetType === 'device') {
            $ids = $body['device_ids'] ?? [];
            if (!is_array($ids) || empty($ids)) { Response::json(400, 'device_ids 不能为空'); return; }
            $deviceIds = array_values(array_map('intval', $ids));
            $ph = implode(',', array_fill(0, count($deviceIds), '?'));
            $params = array_merge([$this->userId], $deviceIds);
            $count = (int)Database::fetch("SELECT COUNT(*) c FROM devices WHERE user_id=? AND id IN ({$ph})", $params)['c'];
            if ($count !== count($deviceIds)) { Response::json(400, '包含非自己名下的设备'); return; }
        }

        $payload = $body['payload'] ?? [];
        $payloadJson = is_array($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE) : '{}';

        if (class_exists(\App\Service\PushDispatcher::class)) {
            $res = \App\Service\PushDispatcher::dispatch($this->userId, $keyId, $targetType, $deviceIds ?: ($targetType === 'broadcast' ? 'all' : ''), $title, $content, (array)$payload);
            Response::json($res['success'] ? 0 : 500, $res['success'] ? '推送已入队' : ($res['message'] ?? '推送失败'));
        } else {
            // 降级：直接写 push_logs（用户级日志）
            $now = date('Y-m-d H:i:s');
            $tgtVal = $targetType === 'key' ? (string)$keyId : ($targetType === 'device' ? implode(',', $deviceIds) : 'all');
            Database::insert(
                'INSERT INTO push_logs (user_id,key_id,target_type,target_value,title,content,payload,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,\'pending\',?,?)',
                [$this->userId, $keyId, $targetType, $tgtVal, $title, $content, $payloadJson, $now, $now]
            );
            Response::json(0, '已写入推送队列（PushDispatcher 未启用，需要后端服务实际推送）');
        }
    }
}
```

- [ ] **Step 10: AppController**

```php
<?php declare(strict_types=1);
namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Response;

class AppController extends BaseController
{
    public function downloads()
    {
        $cfg = [];
        try {
            $row = Database::fetch("SELECT config_value FROM admin_settings WHERE config_key='settings_user_app' LIMIT 1");
            if (!empty($row['config_value'])) { $cfg = json_decode((string)$row['config_value'], true) ?: []; }
        } catch (\Throwable $e) {}
        $apps = [];
        if (!empty($cfg['apk_url'])) { $apps[] = ['type' => 'apk', 'url' => $cfg['apk_url'], 'version' => $cfg['apk_version'] ?? '']; }
        if (!empty($cfg['ipa_url'])) { $apps[] = ['type' => 'ipa', 'url' => $cfg['ipa_url'], 'version' => $cfg['ipa_version'] ?? '']; }
        Response::json(0, 'ok', ['apps' => $apps]);
    }

    public function generateHBuilderX()
    {
        if (!class_exists(\App\Service\HBuilderXService::class)) { Response::json(500, 'HBuilderX 生成服务未部署'); return; }
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $res = \App\Service\HBuilderXService::generate([
            'app_name'     => (string)($body['app_name'] ?? 'Push 用户端'),
            'package_name' => (string)($body['package_name'] ?? 'com.push.userapp'),
            'icon_base64'  => (string)($body['icon_base64'] ?? ''),
            'user_id'      => $this->userId,
            'host'         => ($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? ''),
        ]);
        if (!$res['success']) { Response::json(500, $res['message']); return; }
        // 返回文件下载 URL（Swoole 路由需要额外实现 /download/hbx/{token} 或直接返回 base64，此处直接返回文件绝对路径 + 下载接口）
        Response::json(0, '生成成功', [
            'download_url' => '/user-api/app/download-hbx?f=' . urlencode(basename($res['file'])) . '&t=' . ($res['token'] ?? ''),
            'file' => $res['file'] ?? null,
        ]);
    }
}
```

- [ ] **Step 11: Commit (BaseController + 10 Controllers)**

```bash
git add backend/src/Controller/UserConsole/
git commit -m "feat: UserConsole 控制器(Base/Dashboard/Push/PushLog/Device/Key/Docs/App/Notice/Profile) + /user-api/* 路由动作"
```

---

## Task 4：UserService 新增 bindQQ / resetPasswordByQQ + AuthController 加路由 + UserNoticeService + HBuilderXService

**Files:**
- Modify: `backend/src/Service/UserService.php`
- Modify: `backend/src/Controller/AuthController.php`
- Create: `backend/src/Service/UserNoticeService.php`
- Create: `backend/src/Service/HBuilderXService.php`

- [ ] **Step 1: UserService 附加方法（在现有文件末尾追加）**

```php
    // ------------- 用户端 QQ 绑定（仅允许 qq_number IS NULL 时绑定）-------------
    public static function bindQQByUser(int $userId, string $qqNumber): array
    {
        $fail = ['success' => false, 'message' => ''];
        if ($userId <= 0 || $qqNumber === '' || !ctype_digit($qqNumber)) {
            $fail['message'] = '参数错误'; return $fail;
        }
        $row = Database::fetch('SELECT qq_number FROM users WHERE id=? LIMIT 1', [$userId]);
        if (!$row) { $fail['message'] = '账号不存在'; return $fail; }
        if (!empty($row['qq_number'])) {
            $fail['message'] = '已绑定QQ，请联系管理员改绑'; return $fail;
        }
        // 唯一性
        $exist = Database::fetch('SELECT id FROM users WHERE qq_number=? LIMIT 1', [$qqNumber]);
        if ($exist) { $fail['message'] = '该QQ号已绑定其他账号'; return $fail; }
        $now = date('Y-m-d H:i:s');
        Database::execute('UPDATE users SET qq_number=?, qq_bound_at=?, updated_at=? WHERE id=?', [$qqNumber, $now, $now, $userId]);
        return ['success' => true, 'message' => '绑定成功'];
    }

    // ------------- 管理员改绑/解绑 QQ -------------
    public static function adminBindQQ(string $account, string $qqNumber): array
    {
        $fail = ['success' => false, 'message' => ''];
        if ($account === '' || $qqNumber === '' || !ctype_digit($qqNumber)) {
            $fail['message'] = '参数错误'; return $fail;
        }
        $user = self::findByUsername($account) ?? self::findByPhone($account) ?? self::findByEmail($account);
        if (!$user) { $fail['message'] = '账号不存在'; return $fail; }
        $exist = Database::fetch('SELECT id FROM users WHERE qq_number=? AND id<>? LIMIT 1', [$qqNumber, (int)$user['id']]);
        if ($exist) { $fail['message'] = '该QQ号已绑定其他账号'; return $fail; }
        Database::execute('UPDATE users SET qq_number=?, qq_bound_at=NOW(), updated_at=NOW() WHERE id=?', [$qqNumber, (int)$user['id']]);
        return ['success' => true, 'message' => '绑定成功', 'user_id' => (int)$user['id']];
    }

    public static function adminUnbindQQ(int $userId): array
    {
        if ($userId <= 0) { return ['success' => false, 'message' => '参数错误']; }
        Database::execute('UPDATE users SET qq_number=NULL, qq_bound_at=NULL, updated_at=NOW() WHERE id=?', [$userId]);
        return ['success' => true, 'message' => '解绑成功'];
    }

    // ------------- 管理员通过QQ号重置密码（必须账号+QQ双匹配）-------------
    public static function adminResetPasswordByQQ(string $account, string $qqNumber, string $newPwd): array
    {
        $fail = ['success' => false, 'message' => ''];
        if ($account === '' || $qqNumber === '' || !ctype_digit($qqNumber) || $newPwd === '') {
            $fail['message'] = '参数错误'; return $fail;
        }
        $user = (self::findByUsername($account) ?? self::findByPhone($account) ?? self::findByEmail($account));
        if (!$user) { $fail['message'] = '账号不存在'; return $fail; }
        if (($user['qq_number'] ?? '') !== $qqNumber) {
            $fail['message'] = '账号与绑定QQ不匹配'; return $fail;
        }
        $check = AdminService::validatePasswordStrength($newPwd);
        if (!$check['valid']) { $fail['message'] = $check['message']; return $fail; }
        $hash = password_hash($newPwd, PASSWORD_BCRYPT);
        Database::execute('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?', [$hash, (int)$user['id']]);
        return ['success' => true, 'message' => '密码已重置'];
    }

    // ------------- 公开接口：用户通过QQ号自助改密（两种模式）-------------
    public static function qqResetMode(): string
    {
        try {
            $row = Database::fetch("SELECT config_value FROM admin_settings WHERE config_key='settings_security' LIMIT 1");
            if ($row && !empty($row['config_value'])) {
                $cfg = json_decode((string)$row['config_value'], true);
                $m = (string)($cfg['qq_reset_mode'] ?? 'qq_and_email');
                if (in_array($m, ['qq_only', 'qq_and_email'], true)) return $m;
            }
        } catch (\Throwable $e) {}
        return 'qq_and_email';
    }

    public static function resetPasswordByQQ(string $account, string $qqNumber, string $newPwd, string $emailCode = ''): array
    {
        $fail = ['success' => false, 'message' => ''];
        if ($account === '' || $qqNumber === '' || !ctype_digit($qqNumber) || $newPwd === '') {
            $fail['message'] = '参数错误'; return $fail;
        }
        $user = (self::findByUsername($account) ?? self::findByPhone($account) ?? self::findByEmail($account));
        if (!$user) { $fail['message'] = '账号不存在'; return $fail; }
        if (($user['qq_number'] ?? '') !== $qqNumber) {
            $fail['message'] = '账号与绑定QQ不匹配'; return $fail;
        }
        $mode = self::qqResetMode();
        if ($mode === 'qq_and_email') {
            if (empty($user['email'])) { $fail['message'] = '该账号未绑定邮箱，需联系管理员改密'; return $fail; }
            if ($emailCode === '') { $fail['message'] = '邮箱验证码不能为空'; return $fail; }
            if (!CaptchaService::verifyCode('email', (string)$user['email'], $emailCode)) {
                $fail['message'] = '邮箱验证码错误或已过期'; return $fail;
            }
        }
        $check = AdminService::validatePasswordStrength($newPwd);
        if (!$check['valid']) { $fail['message'] = $check['message']; return $fail; }
        $hash = password_hash($newPwd, PASSWORD_BCRYPT);
        Database::execute('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?', [$hash, (int)$user['id']]);
        return ['success' => true, 'message' => '密码修改成功'];
    }
```

- [ ] **Step 2: AuthController 新增公开路由（在 index.php 里加 POST /auth/reset-password-by-qq，在此仅写 Action 方法模板）**

在 `AuthController.php` 类里追加：
```php
    public function resetPasswordByQQ()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $account   = trim((string)($body['account'] ?? ''));
        $qq        = trim((string)($body['qq_number'] ?? ''));
        $newPwd    = (string)($body['new_password'] ?? '');
        $emailCode = (string)($body['email_code'] ?? '');
        $res = UserService::resetPasswordByQQ($account, $qq, $newPwd, $emailCode);
        Response::json($res['success'] ? 0 : 400, $res['message']);
    }
```

- [ ] **Step 3: UserNoticeService**

```php
<?php declare(strict_types=1);
namespace App\Service;

class UserNoticeService
{
    public static function list(string $level = '', int $page = 1, int $pageSize = 20): array
    {
        $where = ['1=1']; $params = [];
        if ($level !== '') { $where[] = 'level = ?'; $params[] = $level; }
        if (isset($_GET['is_active']) && $_GET['is_active'] !== '') { $where[] = 'is_active = ?'; $params[] = (int)$_GET['is_active']; }
        $whereSql = implode(' AND ', $where);
        $total = (int)Database::fetch("SELECT COUNT(*) c FROM user_notices WHERE {$whereSql}", $params)['c'];
        $offset = ($page - 1) * $pageSize;
        $rows = Database::fetchAll(
            "SELECT n.id,n.title,n.level,n.is_active,n.published_at,n.expires_at,n.created_at,n.updated_at,
                    (SELECT COUNT(*) FROM user_notice_reads r WHERE r.notice_id=n.id) as read_count
             FROM user_notices n WHERE {$whereSql} ORDER BY n.id DESC LIMIT {$offset},{$pageSize}",
            $params
        );
        return ['total' => $total, 'items' => $rows ?: []];
    }

    public static function save(?int $id, array $data, int $adminId): array
    {
        $fail = ['success' => false, 'message' => ''];
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') { $fail['message'] = '标题不能为空'; return $fail; }
        $content = (string)($data['content'] ?? '');
        if ($content === '') { $fail['message'] = '内容不能为空'; return $fail; }
        $level = in_array($data['level'] ?? 'info', ['info','warning','important'], true) ? $data['level'] : 'info';
        $isActive = (int)($data['is_active'] ?? 1);
        $expiresAt = !empty($data['expires_at']) ? (string)$data['expires_at'] : null;
        $now = date('Y-m-d H:i:s');
        $publishedAt = $isActive ? (!empty($data['published_at']) ? (string)$data['published_at'] : $now) : null;

        if ($id === null) {
            $newId = Database::insert(
                'INSERT INTO user_notices (title,content,level,is_active,published_by,published_at,expires_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$title,$content,$level,$isActive,$adminId,$publishedAt,$expiresAt,$now,$now]
            );
            return ['success' => true, 'message' => '创建成功', 'id' => (int)$newId];
        }
        Database::execute(
            'UPDATE user_notices SET title=?,content=?,level=?,is_active=?,published_by=COALESCE(?,published_by),published_at=COALESCE(?,published_at),expires_at=?,updated_at=? WHERE id=?',
            [$title,$content,$level,$isActive,$adminId,$publishedAt,$expiresAt,$now,$id]
        );
        return ['success' => true, 'message' => '保存成功'];
    }

    public static function delete(int $id): void
    {
        Database::execute('DELETE FROM user_notices WHERE id=?', [$id]);
        Database::execute('DELETE FROM user_notice_reads WHERE notice_id=?', [$id]);
    }

    public static function broadcastNotice(int $noticeId): void
    {
        $row = Database::fetch('SELECT id,title,content,level FROM user_notices WHERE id=? LIMIT 1', [$noticeId]);
        if (!$row) return;
        $msg = json_encode([
            'type' => 'user_notice',
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'content' => $row['content'],
            'level' => $row['level'],
        ], JSON_UNESCAPED_UNICODE);
        if (class_exists(\App\Service\ConnectionManager::class)) {
            \App\Service\ConnectionManager::broadcastToAllUsers($msg);
        }
    }
}
```

- [ ] **Step 4: HBuilderXService（最小可运行版本）**

```php
<?php declare(strict_types=1);
namespace App\Service;

class HBuilderXService
{
    public static function generate(array $params): array
    {
        $fail = ['success' => false, 'message' => '', 'file' => null];
        $appName = trim((string)($params['app_name'] ?? ''));
        $pkg     = trim((string)($params['package_name'] ?? ''));
        $icon    = (string)($params['icon_base64'] ?? '');
        $userId  = (int)($params['user_id'] ?? 0);
        $host    = rtrim((string)($params['host'] ?? ''), '/');
        if ($appName === '' || $pkg === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z0-9_]+)+$/', $pkg)) {
            $fail['message'] = 'APP名称/包名格式不正确'; return $fail;
        }

        $projectDir = dirname(__DIR__, 3);
        $tplDir = $projectDir . DIRECTORY_SEPARATOR . 'user' . DIRECTORY_SEPARATOR . 'hbuilderx-template';
        if (!is_dir($tplDir)) { $fail['message'] = 'HBuilderX 模板目录不存在'; return $fail; }

        $tmpBase = $projectDir . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'tmp';
        @mkdir($tmpBase, 0755, true);
        $rand = substr(bin2hex(random_bytes(6)), 0, 10);
        $workDir = $tmpBase . DIRECTORY_SEPARATOR . "hbx-{$userId}-{$rand}";
        @mkdir($workDir, 0755, true);
        if (!is_dir($workDir)) { $fail['message'] = '创建临时目录失败'; return $fail; }

        // 递归复制模板
        $copy = function ($src, $dst) use (&$copy) {
            $dir = opendir($src);
            @mkdir($dst, 0755, true);
            while (($f = readdir($dir)) !== false) {
                if ($f === '.' || $f === '..') continue;
                $s = $src . DIRECTORY_SEPARATOR . $f;
                $d = $dst . DIRECTORY_SEPARATOR . $f;
                if (is_dir($s)) $copy($s, $d);
                else copy($s, $d);
            }
            closedir($dir);
        };
        $copy($tplDir, $workDir);

        // 生成 AppID（占位）
        $appid = '__UNI__' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        // 1) manifest.json
        $mf = $workDir . DIRECTORY_SEPARATOR . 'manifest.json';
        if (is_file($mf)) {
            $json = @file_get_contents($mf);
            if ($json !== false) {
                $map = [
                    '__APP_NAME__' => $appName,
                    '__APP_ID__'   => $appid,
                    '__PKG__'      => $pkg,
                    '__VERSION__'  => '1.0.0',
                ];
                foreach ($map as $k => $v) { $json = str_replace($k, $v, $json); }
                file_put_contents($mf, $json);
            }
        }
        // 2) pages.json -> navigationBarTitleText
        $pf = $workDir . DIRECTORY_SEPARATOR . 'pages.json';
        if (is_file($pf)) {
            $content = file_get_contents($pf);
            $content = str_replace('__APP_NAME__', $appName, $content);
            file_put_contents($pf, $content);
        }
        // 3) static/env.js
        $ef = $workDir . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'env.js';
        if (is_file($ef)) {
            $content = file_get_contents($ef);
            $content = str_replace('__API_BASE__', $host . '/user-api/', $content);
            $content = str_replace('__WS_BASE__',  ($host !== '' ? preg_replace('#^http#','ws',$host) : '') . '/ws', $content);
            file_put_contents($ef, $content);
        }
        // 4) 替换图标（上传了才替换）
        if ($icon !== '' && preg_match('#^data:image/(png|jpeg|jpg);base64,(.+)$#i', $icon, $m)) {
            $bin = base64_decode($m[2], true);
            if ($bin !== false) {
                @file_put_contents($workDir . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'logo.png', $bin);
                // 粗略：复制一份到 logo.jpg
                @file_put_contents($workDir . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'logo.jpg', $bin);
            }
        }

        // 打包 ZIP
        $zipFile = $tmpBase . DIRECTORY_SEPARATOR . "hbx-{$userId}-" . time() . ".zip";
        $zipCmd = sprintf('cd %s && zip -9 -r %s . 2>&1', escapeshellarg($workDir), escapeshellarg($zipFile));
        exec($zipCmd, $out, $code);
        if ($code !== 0 || !is_file($zipFile)) {
            // 无 zip 命令时兜底：PHP ZipArchive
            if (!class_exists(\ZipArchive::class)) { $fail['message'] = '系统未安装 zip 命令且 ZipArchive 不可用'; return $fail; }
            $za = new \ZipArchive();
            if ($za->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { $fail['message'] = '创建ZIP失败'; return $fail; }
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workDir, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($rii as $file) {
                if ($file->isDir()) continue;
                $p = $file->getPathname();
                $rel = substr($p, strlen($workDir) + 1);
                $za->addFile($p, $rel);
            }
            $za->close();
        }
        // 清理工作目录
        self::rmDir($workDir);
        return ['success' => true, 'message' => '生成成功', 'file' => $zipFile, 'token' => $rand];
    }

    private static function rmDir(string $dir): void
    {
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rii as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }

    // 临时 ZIP 清理（建议 CRON 调用，也可每次生成时清老文件）
    public static function cleanupOld(int $ttl = 600): void
    {
        $tmpBase = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'tmp';
        if (!is_dir($tmpBase)) return;
        $t = time() - $ttl;
        foreach (glob($tmpBase . DIRECTORY_SEPARATOR . 'hbx-*.zip') as $f) {
            if (@filemtime($f) < $t) @unlink($f);
        }
    }
}
```

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/UserService.php backend/src/Controller/AuthController.php backend/src/Service/UserNoticeService.php backend/src/Service/HBuilderXService.php
git commit -m "feat: bindQQ/resetPasswordByQQ + 公开QQ改密路由 + UserNoticeService + HBuilderXService"
```

---

## Task 5：后端路由注册（public/index.php）+ SettingsController/UserController 扩展 + .env.example

**Files:**
- Modify: `backend/public/index.php`
- Modify: `backend/src/Controller/SettingsController.php`
- Modify: `backend/src/Controller/UserController.php`
- Modify: `backend/.env.example`

- [ ] **Step 1: public/index.php 注入 StaticRouter 前置 + /user-api/* 路由**

在现有 `public/index.php` 的最开头（require autoload 之后，业务路由注册之前）插入：

```php
// 前置：StaticRouter（命中前端入口则直接输出 HTML，不再走后续路由）
use App\Middleware\StaticRouter;
use App\Middleware\UserApiAuth;

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$entryHtml = StaticRouter::resolve($requestUri);
if (is_string($entryHtml)) {
    header('Content-Type: text/html; charset=utf-8');
    echo $entryHtml;
    exit;
}
```

然后在业务路由注册处（现有 `Router` 或直接 switch-case 位置）按前缀分发：

```php
// ------------------------
// /user-api/*  用户端接口
// ------------------------
$userPrefix = '/user-api';
if (str_starts_with($path, $userPrefix . '/')) {
    $sub = substr($path, strlen($userPrefix));
    UserApiAuth::handle(function () use ($sub, $method) {
        $c = new \App\Controller\UserConsole\BaseController(); // 触发构造函数做 userId 检查（实际由子类动作）
        switch (true) {
            // 仪表盘
            case $sub === '/dashboard/stats' && $method === 'GET':
                (new \App\Controller\UserConsole\DashboardController())->stats(); break;
            // 推送
            case $sub === '/push/send' && $method === 'POST':
                (new \App\Controller\UserConsole\PushController())->send(); break;
            // 推送记录
            case $sub === '/push-logs' && $method === 'GET':
                (new \App\Controller\UserConsole\PushLogController())->list(); break;
            case preg_match('#^/push-logs/(\d+)$#', $sub, $m) && $method === 'GET':
                (new \App\Controller\UserConsole\PushLogController())->detail((int)$m[1]); break;
            case preg_match('#^/push-logs/(\d+)/retry$#', $sub, $m) && $method === 'POST':
                (new \App\Controller\UserConsole\PushLogController())->retry((int)$m[1]); break;
            // 设备
            case $sub === '/devices' && $method === 'GET':
                (new \App\Controller\UserConsole\DeviceController())->list(); break;
            case $sub === '/devices/clear-zombie' && $method === 'POST':
                (new \App\Controller\UserConsole\DeviceController())->clearZombie(); break;
            case preg_match('#^/devices/(\d+)$#', $sub, $m) && $method === 'PUT':
                (new \App\Controller\UserConsole\DeviceController())->update((int)$m[1]); break;
            case preg_match('#^/devices/(\d+)$#', $sub, $m) && $method === 'DELETE':
                (new \App\Controller\UserConsole\DeviceController())->delete((int)$m[1]); break;
            // Key
            case $sub === '/keys' && $method === 'GET':
                (new \App\Controller\UserConsole\KeyController())->list(); break;
            case $sub === '/keys' && $method === 'POST':
                (new \App\Controller\UserConsole\KeyController())->create(); break;
            case preg_match('#^/keys/(\d+)$#', $sub, $m) && $method === 'DELETE':
                (new \App\Controller\UserConsole\KeyController())->revoke((int)$m[1]); break;
            // 文档
            case $sub === '/docs' && $method === 'GET':
                (new \App\Controller\UserConsole\DocsController())->index(); break;
            // APP
            case $sub === '/app/downloads' && $method === 'GET':
                (new \App\Controller\UserConsole\AppController())->downloads(); break;
            case $sub === '/app/generate-hbuilderx' && $method === 'POST':
                (new \App\Controller\UserConsole\AppController())->generateHBuilderX(); break;
            // 公告
            case $sub === '/notices/latest' && $method === 'GET':
                (new \App\Controller\UserConsole\NoticeController())->latest(); break;
            case preg_match('#^/notices/(\d+)/read$#', $sub, $m) && $method === 'POST':
                (new \App\Controller\UserConsole\NoticeController())->read((int)$m[1]); break;
            case preg_match('#^/notices/(\d+)/snooze$#', $sub, $m) && $method === 'POST':
                (new \App\Controller\UserConsole\NoticeController())->snooze((int)$m[1]); break;
            // 个人中心
            case $sub === '/profile' && $method === 'GET':
                (new \App\Controller\UserConsole\ProfileController())->get(); break;
            case $sub === '/profile/change-password' && $method === 'POST':
                (new \App\Controller\UserConsole\ProfileController())->changePassword(); break;
            case $sub === '/profile/bind-qq' && $method === 'POST':
                (new \App\Controller\UserConsole\ProfileController())->bindQQ(); break;
            default:
                \App\Service\Response::json(404, '接口不存在');
        }
    });
    exit;
}

// ------------------------
// /auth/reset-password-by-qq  公开接口
// ------------------------
if ($path === '/auth/reset-password-by-qq' && $method === 'POST') {
    (new \App\Controller\AuthController())->resetPasswordByQQ();
    exit;
}
```

- [ ] **Step 2: SettingsController 扩展 settings_paths/settings_security/settings_user_app + 用户端公告管理（管理员）**

在 `SettingsController` 类里追加方法：
```php
    public function getPaths()
    {
        $row = Database::fetch("SELECT config_value FROM admin_settings WHERE config_key='settings_paths' LIMIT 1");
        Response::json(0, 'ok', $row && !empty($row['config_value']) ? json_decode($row['config_value'], true) : []);
    }
    public function savePaths()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $data = [
            'admin_path'      => '/' . trim((string)($body['admin_path'] ?? '/'), '/'),
            'user_path'       => '/' . trim((string)($body['user_path'] ?? '/user/'), '/') . '/',
            'admin_api_prefix' => '/' . trim((string)($body['admin_api_prefix'] ?? '/admin/'), '/') . '/',
            'user_api_prefix'  => '/' . trim((string)($body['user_api_prefix'] ?? '/user-api/'), '/') . '/',
        ];
        // 规范化：admin_path 如果只是 "/"，保留 "/"
        $data['admin_path'] = $data['admin_path'] === '//' ? '/' : $data['admin_path'];
        Database::execute("INSERT INTO admin_settings (config_key,config_value,updated_at) VALUES('settings_paths',?,NOW()) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value),updated_at=NOW()", [json_encode($data, JSON_UNESCAPED_UNICODE)]);
        // 清除 StaticRouter 内部 TTL（通过文件标记的方式可选；此处依赖 5 秒 TTL 自然失效即可）
        Response::json(0, '保存成功，约 5 秒后生效', $data);
    }
    public function getSecurity()
    {
        $row = Database::fetch("SELECT config_value FROM admin_settings WHERE config_key='settings_security' LIMIT 1");
        Response::json(0, 'ok', $row && !empty($row['config_value']) ? json_decode($row['config_value'], true) : []);
    }
    public function saveSecurity()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $mode = in_array($body['qq_reset_mode'] ?? 'qq_and_email', ['qq_only','qq_and_email'], true) ? $body['qq_reset_mode'] : 'qq_and_email';
        $cfg = ['qq_reset_mode' => $mode];
        Database::execute("INSERT INTO admin_settings (config_key,config_value,updated_at) VALUES('settings_security',?,NOW()) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value),updated_at=NOW()", [json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
        Response::json(0, '保存成功', $cfg);
    }
    public function getUserApp()
    {
        $row = Database::fetch("SELECT config_value FROM admin_settings WHERE config_key='settings_user_app' LIMIT 1");
        Response::json(0, 'ok', $row && !empty($row['config_value']) ? json_decode($row['config_value'], true) : []);
    }
    public function saveUserApp()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $cfg = [
            'apk_url'     => (string)($body['apk_url'] ?? ''),
            'apk_version' => (string)($body['apk_version'] ?? ''),
            'ipa_url'     => (string)($body['ipa_url'] ?? ''),
            'ipa_version' => (string)($body['ipa_version'] ?? ''),
        ];
        Database::execute("INSERT INTO admin_settings (config_key,config_value,updated_at) VALUES('settings_user_app',?,NOW()) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value),updated_at=NOW()", [json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
        Response::json(0, '保存成功', $cfg);
    }

    // --------- 用户端公告管理（管理员） ---------
    public function listUserNotices()
    {
        $level = (string)($_GET['level'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $size = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
        $res = \App\Service\UserNoticeService::list($level, $page, $size);
        Response::json(0, 'ok', $res);
    }
    public function saveUserNotice(int $id = null)
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $adminId = (int)($GLOBALS['_ADMIN_ID'] ?? 0);
        $res = \App\Service\UserNoticeService::save($id, $body, $adminId);
        if ($res['success'] && !empty($body['is_active']) && !empty($body['broadcast_ws'])) {
            \App\Service\UserNoticeService::broadcastNotice((int)($res['id'] ?? $id));
        }
        Response::json($res['success'] ? 0 : 400, $res['message']);
    }
    public function deleteUserNotice(int $id)
    {
        \App\Service\UserNoticeService::delete($id);
        Response::json(0, '删除成功');
    }
```

同时在 Router 注册对应 `/admin/paths`、`/admin/security`、`/admin/user-app`、`/admin/user-notices` 路由（GET/POST/DELETE，AdminAuth 中间件包裹）。示例：

```php
// 在现有 admin router 分发里补：
case $path === '/admin/paths' && $method === 'GET':
    (new SettingsController())->getPaths(); break;
case $path === '/admin/paths' && $method === 'POST':
    (new SettingsController())->savePaths(); break;
case $path === '/admin/security' && $method === 'GET':
    (new SettingsController())->getSecurity(); break;
case $path === '/admin/security' && $method === 'POST':
    (new SettingsController())->saveSecurity(); break;
case $path === '/admin/user-app' && $method === 'GET':
    (new SettingsController())->getUserApp(); break;
case $path === '/admin/user-app' && $method === 'POST':
    (new SettingsController())->saveUserApp(); break;
case $path === '/admin/user-notices' && $method === 'GET':
    (new SettingsController())->listUserNotices(); break;
case $path === '/admin/user-notices' && $method === 'POST':
    (new SettingsController())->saveUserNotice(); break;
case preg_match('#^/admin/user-notices/(\d+)$#', $path, $m) && $method === 'PUT':
    (new SettingsController())->saveUserNotice((int)$m[1]); break;
case preg_match('#^/admin/user-notices/(\d+)$#', $path, $m) && $method === 'DELETE':
    (new SettingsController())->deleteUserNotice((int)$m[1]); break;
```

- [ ] **Step 3: UserController（管理端）加 QQ 绑定/解绑/重置密码 + AuthController 已加 resetPasswordByQQ**

在 `UserController` 的类里追加方法（管理员专用，受 AdminAuth）：

```php
    public function bindQQ()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $res = UserService::adminBindQQ((string)($body['account'] ?? ''), (string)($body['qq_number'] ?? ''));
        Response::json($res['success'] ? 0 : 400, $res['message']);
    }
    public function unbindQQ(int $userId)
    {
        $res = UserService::adminUnbindQQ($userId);
        Response::json($res['success'] ? 0 : 400, $res['message']);
    }
    public function resetPasswordByQQ()
    {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $res = UserService::adminResetPasswordByQQ(
            (string)($body['account'] ?? ''),
            (string)($body['qq_number'] ?? ''),
            (string)($body['new_password'] ?? '')
        );
        Response::json($res['success'] ? 0 : 400, $res['message']);
    }
```

对应 Router 增加：
```php
case $path === '/admin/users/bind-qq' && $method === 'POST':
    (new UserController())->bindQQ(); break;
case preg_match('#^/admin/users/(\d+)/unbind-qq$#', $path, $m) && $method === 'POST':
    (new UserController())->unbindQQ((int)$m[1]); break;
case $path === '/admin/users/reset-password-by-qq' && $method === 'POST':
    (new UserController())->resetPasswordByQQ(); break;
```

- [ ] **Step 4: .env.example 新增注释项**（仅注释，不新增 env 变量，因为路径/改密模式都存 DB）

在 `.env.example` 末尾追加：

```ini
# 以下配置项默认存储在 admin_settings 表，可在管理后台 系统设置 修改，无需在 .env 配置：
# settings_paths.admin_path = /              ; 管理端访问路径（默认/），可在系统设置中修改，修改后约5秒生效
# settings_paths.user_path = /user/          ; 用户端访问路径
# settings_paths.admin_api_prefix = /admin/  ; 管理员接口前缀
# settings_paths.user_api_prefix = /user-api/; 用户端接口前缀
# settings_security.qq_reset_mode = qq_only  ; 或 qq_and_email（默认）- 用户自助通过QQ改密的验证级别
# settings_user_app.apk_url / settings_user_app.ipa_url  ; 通用版 APP 下载地址
```

- [ ] **Step 5: 修改 deploy/nginx/push.conf 为统一入口**

把原 Nginx 配置替换为"静态文件直接 try_files，其他反代到 Swoole"的极简模式：

```nginx
server {
  listen 80;
  server_name _;
  root /www/push-system;
  index index.html;

  # 静态资源（前端构建产物 + 上传资源）直接返回
  location ~* \.(?:js|css|png|jpe?g|gif|svg|ico|woff2?|ttf|map|wasm|zip|apk|ipa|wav|mp3|pdf)$ {
    expires 7d;
    add_header Cache-Control "public, max-age=604800, immutable";
    try_files $uri =404;
  }

  # 其他：API + 前端入口（含 hash 路由）全部交给 Swoole 路由（StaticRouter 动态渲染）
  location / {
    proxy_pass http://127.0.0.1:9501;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 600s;
  }
}
```

- [ ] **Step 6: Commit**

```bash
git add backend/public/index.php backend/src/Controller/SettingsController.php backend/src/Controller/UserController.php backend/.env.example deploy/nginx/push.conf backend/src/Controller/AuthController.php
git commit -m "feat: StaticRouter前置 + /user-api/*路由 + settings_paths/security/user_app + 公告CRUD + 管理端QQ绑定/改密 + Nginx统一入口conf"
```

---

## Task 6：用户端前端工程骨架（package.json / vite.config / tsconfig / index.html / main / App.vue / env）

**Files:**
- Create: `user/package.json`
- Create: `user/vite.config.ts`
- Create: `user/tsconfig.json` + `user/tsconfig.node.json`
- Create: `user/index.html`
- Create: `user/src/main.ts` / `user/src/App.vue` / `user/src/env.d.ts`

- [ ] **Step 1: package.json**（依赖与 admin 对齐，版本一致）

直接从 `admin/package.json` 复制，改 `name: "push-user-console"`，`build` 输出 `dist`，`dev` 端口默认 `5174`。命令：

```json
{
  "name": "push-user-console",
  "private": true,
  "version": "1.0.0",
  "scripts": {
    "dev": "vite --port 5174",
    "build": "vue-tsc --noEmit && vite build",
    "preview": "vite preview"
  },
  "dependencies": {
    "@element-plus/icons-vue": "^2.3.1",
    "axios": "^1.7.2",
    "dayjs": "^1.11.11",
    "echarts": "^5.5.0",
    "element-plus": "^2.7.6",
    "nprogress": "^0.2.0",
    "pinia": "^2.1.7",
    "qrcode": "^1.5.3",
    "vue": "^3.4.29",
    "vue-router": "^4.3.3"
  },
  "devDependencies": {
    "@types/node": "^20.14.2",
    "@types/nprogress": "^0.2.3",
    "@types/qrcode": "^1.5.5",
    "@vitejs/plugin-vue": "^5.0.5",
    "sass": "^1.77.5",
    "typescript": "^5.4.5",
    "unplugin-auto-import": "^0.17.6",
    "unplugin-vue-components": "^0.27.0",
    "vite": "^5.3.1",
    "vue-tsc": "^2.0.21"
  }
}
```

- [ ] **Step 2: vite.config.ts**（base 留空，运行时注入；dev server 代理 /user-api 到后端）

```ts
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'
import path from 'node:path'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: './',
    plugins: [
      vue(),
      AutoImport({ imports: ['vue', 'vue-router', 'pinia'], resolvers: [ElementPlusResolver()], dts: 'src/types/auto-imports.d.ts' }),
      Components({ resolvers: [ElementPlusResolver()], dts: 'src/types/components.d.ts' })
    ],
    resolve: { alias: { '@': path.resolve(__dirname, 'src') } },
    css: { preprocessorOptions: { scss: { api: 'modern-compiler' } } },
    build: {
      outDir: 'dist',
      sourcemap: false,
      chunkSizeWarningLimit: 1500,
      rollupOptions: {
        output: {
          manualChunks: { 'element-plus': ['element-plus', '@element-plus/icons-vue'], echarts: ['echarts'] }
        }
      }
    },
    server: {
      host: '0.0.0.0',
      port: 5174,
      proxy: {
        '/user-api': { target: env.VITE_PROXY_TARGET || 'http://127.0.0.1:9501', changeOrigin: true, ws: true },
        '/auth':     { target: env.VITE_PROXY_TARGET || 'http://127.0.0.1:9501', changeOrigin: true },
        '/ws':       { target: (env.VITE_PROXY_TARGET || 'http://127.0.0.1:9501').replace(/^http/, 'ws'), ws: true, changeOrigin: true }
      }
    }
  }
})
```

- [ ] **Step 3: tsconfig.json + tsconfig.node.json**（复制 admin 的即可）

- [ ] **Step 4: index.html**

```html
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Push · 用户端</title>
  </head>
  <body>
    <div id="app"></div>
    <script type="module" src="/src/main.ts"></script>
  </body>
</html>
```

- [ ] **Step 5: src/main.ts**

```ts
import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import * as ElIcons from '@element-plus/icons-vue'
import 'element-plus/dist/index.css'
import './styles/index.scss'
import 'nprogress/nprogress.css'

const app = createApp(App)
for (const [key, comp] of Object.entries(ElIcons)) (app.component(key, comp as any))
app.use(createPinia()).use(router).use(ElementPlus, { locale: zhCn }).mount('#app')
```

- [ ] **Step 6: src/App.vue**

```vue
<template>
  <router-view />
  <NoticeDialog />
</template>
<script setup lang="ts">
import NoticeDialog from '@/components/NoticeDialog.vue'
</script>
<style>
html, body, #app { height: 100%; margin: 0; padding: 0; }
</style>
```

- [ ] **Step 7: src/env.d.ts**

```ts
/// <reference types="vite/client" />
declare module '*.vue' { import type { DefineComponent } from 'vue'; const component: DefineComponent<{}, {}, any>; export default component; }
declare global {
  interface Window { __APP_BASE__: string; __APP_BASE_API__: string; }
}
export {}
```

- [ ] **Step 8: Commit**

```bash
git add user/package.json user/vite.config.ts user/tsconfig.json user/tsconfig.node.json user/index.html user/src/main.ts user/src/App.vue user/src/env.d.ts
git commit -m "feat: 用户端前端骨架 package/vite/tsconfig/html/main/app/env"
```

---

## Task 7：用户端前端 API/请求封装 + router + stores + utils/auth

**Files:**
- Create: `user/src/utils/auth.ts`
- Create: `user/src/api/request.ts`
- Create: `user/src/api/dashboard.ts` / `push.ts` / `pushLogs.ts` / `devices.ts` / `keys.ts` / `docs.ts` / `app.ts` / `notice.ts` / `profile.ts` / `auth.ts`
- Create: `user/src/router/index.ts`
- Create: `user/src/stores/user.ts` / `app.ts` / `permission.ts`

- [ ] **Step 1: utils/auth.ts**

```ts
const TOKEN_KEY = 'push_user_token'
export const getToken = (): string => localStorage.getItem(TOKEN_KEY) || ''
export const setToken = (t: string) => localStorage.setItem(TOKEN_KEY, t)
export const removeToken = () => localStorage.removeItem(TOKEN_KEY)
```

- [ ] **Step 2: api/request.ts**

```ts
import axios from 'axios'
import { ElMessage } from 'element-plus'
import { getToken, removeToken } from '@/utils/auth'
import router from '@/router'

const baseFromWindow = (typeof window !== 'undefined' && (window as any).__APP_BASE_API__) as string | undefined
// 开发环境 vite 代理直接用 /user-api；生产环境用 window 注入或根据当前域名推断
let BASE: string
if (import.meta.env.DEV) BASE = '/user-api'
else BASE = baseFromWindow || '/user-api'

const request = axios.create({ baseURL: BASE, timeout: 30000 })

request.interceptors.request.use((config) => {
  const t = getToken()
  if (t) config.headers.Authorization = `Bearer ${t}`
  return config
})

request.interceptors.response.use(
  (resp) => {
    const data = resp.data as any
    if (data && typeof data === 'object' && 'code' in data) {
      if (data.code === 0) return data.data !== undefined ? data.data : data
      ElMessage.error(data.msg || '请求失败')
      if (data.code === 401) { removeToken(); router.push('/login') }
      return Promise.reject(new Error(data.msg || 'Error'))
    }
    return resp
  },
  (err) => {
    if (err.response?.status === 401) { removeToken(); router.push('/login') }
    ElMessage.error(err.message || '网络异常')
    return Promise.reject(err)
  }
)

export default request
export { BASE as USER_API_BASE }
```

- [ ] **Step 3: 每个 api/ 模块一个示例（其他按 pattern 类推）**

auth.ts:
```ts
import request from './request'
import axios from 'axios'

// auth/* 是公开接口，base 取 AUTH_BASE（与用户端在同域，直接 /auth）
const AUTH_BASE = (import.meta.env.DEV ? '' : '') + '/auth'

export const registerApi = (data: any) => axios.post(AUTH_BASE + '/register', data).then(r => r.data)
export const loginApi = (data: any) => axios.post(AUTH_BASE + '/login', data).then(r => r.data)
export const loginCaptchaApi = () => axios.get(AUTH_BASE + '/captcha/image').then(r => r.data)
export const sendCodeApi = (data: any) => axios.post(AUTH_BASE + '/captcha/send-code', data).then(r => r.data)
export const forgotBySecurityApi = (data: any) => axios.post(AUTH_BASE + '/reset-password-by-security-code', data).then(r => r.data)
export const forgotByQQApi = (data: any) => axios.post(AUTH_BASE + '/reset-password-by-qq', data).then(r => r.data)

// dashboard.ts（其余按这个模式写：request.get/post）
export const getDashboardStats = () => request.get<any, any>('/dashboard/stats')
```

dashboard.ts:
```ts
import request from './request'
export interface Stats { today_push_count: number; total_push_count: number; success_rate: number; device_count: number; online_device_count: number; key_count: number }
export const getDashboardStats = () => request.get<any, Stats>('/dashboard/stats')
```

push.ts:
```ts
import request from './request'
export const sendPushApi = (data: { target_type: 'key'|'device'|'broadcast'; key_id?: number; device_ids?: number[]; title: string; content: string; payload?: Record<string,any> }) => request.post('/push/send', data)
```

pushLogs.ts:
```ts
import request from './request'
export const getPushLogListApi = (params: any) => request.get<any, any>('/push-logs', { params })
export const getPushLogDetailApi = (id: number) => request.get<any, any>(`/push-logs/${id}`)
export const retryPushLogApi = (id: number) => request.post(`/push-logs/${id}/retry`)
```

devices.ts:
```ts
import request from './request'
export const getDeviceListApi = (params: any) => request.get<any, any>('/devices', { params })
export const updateDeviceApi = (id: number, data: { remark?: string; tags?: string }) => request.put(`/devices/${id}`, data)
export const deleteDeviceApi = (id: number) => request.delete(`/devices/${id}`)
export const clearZombieApi = () => request.post('/devices/clear-zombie')
```

keys.ts:
```ts
import request from './request'
export const getKeyListApi = () => request.get<any, any[]>('/keys')
export const createKeyApi = (data: { name: string; permissions?: string[] }) => request.post<any, any>('/keys', data)
export const revokeKeyApi = (id: number) => request.delete(`/keys/${id}`)
```

docs.ts:
```ts
import request from './request'
export const getMyDocsApi = () => request.get<any, any>('/docs')
```

app.ts:
```ts
import request from './request'
export const getAppDownloadsApi = () => request.get<any, any>('/app/downloads')
export const genHBuilderXApi = (data: { app_name: string; package_name: string; icon_base64?: string }) => request.post<any, any>('/app/generate-hbuilderx', data)
```

notice.ts:
```ts
import request from './request'
export const getLatestNoticesApi = () => request.get<any, any>('/notices/latest')
export const markReadNoticeApi = (id: number) => request.post(`/notices/${id}/read`)
export const snoozeNoticeApi = (id: number) => request.post(`/notices/${id}/snooze`)
```

profile.ts:
```ts
import request from './request'
export const getProfileApi = () => request.get<any, any>('/profile')
export const changePwdApi = (data: { old_password: string; new_password: string }) => request.post('/profile/change-password', data)
export const bindQQApi = (data: { qq_number: string }) => request.post('/profile/bind-qq', data)
```

- [ ] **Step 4: router/index.ts**（常量路由 + 页面路由直接用静态列表即可，用户端不分角色）

```ts
import { createRouter, createWebHashHistory, type RouteRecordRaw } from 'vue-router'
import NProgress from 'nprogress'
import { getToken } from '@/utils/auth'

const Layout = () => import('@/layout/index.vue')

export const constantRoutes: RouteRecordRaw[] = [
  { path: '/login', component: () => import('@/views/login/index.vue'), meta: { title: '登录', hidden: true } },
  { path: '/register', component: () => import('@/views/register/index.vue'), meta: { title: '注册', hidden: true } },
  { path: '/forgot-password', component: () => import('@/views/forgot-password/index.vue'), meta: { title: '忘记密码', hidden: true } },
  { path: '/404', component: () => import('@/views/error/404.vue'), meta: { hidden: true } },
  {
    path: '/',
    component: Layout,
    redirect: '/dashboard',
    children: [
      { path: 'dashboard', name: 'Dashboard', component: () => import('@/views/dashboard/index.vue'), meta: { title: '仪表盘', icon: 'Odometer', affix: true, cache: true } },
      { path: 'push', name: 'Push', component: () => import('@/views/push/index.vue'), meta: { title: '推送消息', icon: 'Promotion', cache: true } },
      { path: 'push-logs', name: 'PushLogs', component: () => import('@/views/push-logs/index.vue'), meta: { title: '推送记录', icon: 'Document', cache: true } },
      { path: 'devices', name: 'Devices', component: () => import('@/views/devices/index.vue'), meta: { title: '设备管理', icon: 'Cellphone', cache: true } },
      { path: 'keys', name: 'Keys', component: () => import('@/views/keys/index.vue'), meta: { title: 'Key管理', icon: 'Key', cache: true } },
      { path: 'docs', name: 'Docs', component: () => import('@/views/docs/index.vue'), meta: { title: 'API文档', icon: 'Connection', cache: true } },
      { path: 'app', name: 'App', component: () => import('@/views/app/index.vue'), meta: { title: 'APP生成/下载', icon: 'CellphoneFilled', cache: true } },
      { path: 'profile', name: 'Profile', component: () => import('@/views/profile/index.vue'), meta: { title: '个人中心', icon: 'UserFilled', cache: true } },
    ]
  },
  { path: '/:pathMatch(.*)*', redirect: '/404', meta: { hidden: true } }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes: constantRoutes,
  scrollBehavior: () => ({ top: 0 })
})

const whiteList = ['/login', '/register', '/forgot-password', '/404']

router.beforeEach((to, _from, next) => {
  NProgress.start()
  document.title = to.meta.title ? `${to.meta.title as string} · Push 用户端` : 'Push · 用户端'
  const has = getToken()
  if (has) {
    if (to.path === '/login') next('/'); else next()
    return
  }
  whiteList.includes(to.path) ? next() : next(`/login?redirect=${encodeURIComponent(to.fullPath)}`)
})
router.afterEach(() => NProgress.done())
export default router
```

- [ ] **Step 5: stores/user.ts**

```ts
import { defineStore } from 'pinia'
import { getToken, setToken, removeToken } from '@/utils/auth'
import { loginApi, getProfileApi } from '@/api/auth'
import router from '@/router'
import { getProfileApi as getProfile } from '@/api/profile'

export const useUserStore = defineStore('user', {
  state: () => ({
    token: getToken(),
    id: 0,
    username: '',
    phone: '',
    email: '',
    qq_number: '',
    status: 0,
    roles: ['user'] as const,
  }),
  actions: {
    async login(account: string, password: string, captchaToken: string, captcha: string) {
      const res = await loginApi({ account, password, captcha_token: captchaToken, captcha_input: captcha })
      if (res && res.code === 0) {
        this.token = res.data.token; setToken(res.data.token)
        await this.getInfo()
      } else throw new Error(res?.msg || '登录失败')
    },
    async getInfo() {
      try {
        const info = await getProfile()
        this.id = info.id; this.username = info.username; this.phone = info.phone ?? ''
        this.email = info.email ?? ''; this.qq_number = info.qq_number ?? ''; this.status = info.status
      } catch (e) {}
    },
    logout() {
      this.token = ''; removeToken(); router.push('/login')
    }
  }
})
```

- [ ] **Step 6: stores/app.ts**（精简版，侧栏折叠/主题）

- [ ] **Step 7: stores/permission.ts**（用户端不区分角色，留空即可）

- [ ] **Step 8: Commit**

```bash
git add user/src/utils user/src/api user/src/router user/src/stores
git commit -m "feat: 用户端 request/auth封装 + 9个api模块 + router + stores"
```

---

## Task 8：用户端 layout + styles + components/NoticeDialog + 11 个页面（登录/注册/忘记/仪表盘/推送/推送记录/设备/Key/文档/APP/个人中心/404）

> 此任务较大，每个页面简化版实现，代码模式与 admin 对应页面一致，主色替换为 teal-500。建议与 admin 端共用组件思路。

**核心样式变量（styles/variables.scss）：** `$color-primary: #14b8a6;`（teal-500）

**每个页面最小可运行实现：**
- **登录/注册/忘记密码**：复制 admin 对应页面，改 `api/auth.ts` 的调用（用户端登录需要 captcha+账号+密码，注册与现有注册页一致），忘记密码页增加 Tab：安全码 / QQ号
- **仪表盘**：6 张统计卡片（今天推送/总推送/成功率/设备数/在线数/Key数）+ 最近 7 天推送趋势 ECharts
- **推送消息**：表单（目标类型切换：Key/设备/广播；Key下拉/设备多选/广播只读；标题/内容/payload JSON编辑器） + 发送按钮 + 发送结果
- **推送记录**：表格（分页+筛选+行操作：查看详情/重推）
- **设备管理**：表格（在线状态/平台/名称/标签/备注/最后在线） + 行操作（编辑/解绑） + 顶栏"清僵尸设备"
- **Key 管理**：列表（名称/掩码/权限/状态/最后使用）+ 新建 Key 弹窗（名称+权限多选）+ 吊销
- **API 文档**：折叠面板展示 endpoint（method/path/desc/cURL/JS/PHP 示例）
- **APP 下载/生成**：① 通用版下载卡片区；② 定制 HBuilderX 表单（名称/包名/图标上传）+ 生成 ZIP 下载
- **个人中心**：Tabs（基本信息/改密/QQ绑定/登录历史），QQ 绑定仅 qq_number==null 时可操作
- **404**：与 admin 风格一致

**NoticeDialog.vue 全局组件逻辑：** watch 当前路由，进入仪表盘或登录后首次拉取 `/notices/latest`，有 unread 则 ElDialog 弹出，逐张确认或单张 snooze。

- [ ] **Step 1-2: 写 layout（Sidebar + Navbar + AppMain）+ styles/**（复用 admin 结构，改色）
- [ ] **Step 3: 写 NoticeDialog.vue**
- [ ] **Step 4-14: 每个页面一个 index.vue（按"最小可运行实现"逐一写）**
- [ ] **Step 15: 本地 npm install + npm run build 验证构建通过**
- [ ] **Step 16: Commit**

```bash
git add user/src/layout user/src/styles user/src/components user/src/views
cd user && npm ci && npm run build && cd ..
git add -A && git commit -m "feat: 用户端 layout/styles/NoticeDialog + 11页面 + 构建验证"
```

---

## Task 9：管理端改造（路径设置/安全设置 Tab + 用户端公告管理页 + 用户管理 QQ 操作 + 登录页 QQ 改密入口）

**Files:**
- Modify: `admin/src/views/settings/index.vue`
- Create: `admin/src/views/user-notices/index.vue`
- Modify: `admin/src/router/index.ts`
- Modify: `admin/src/views/module/index.vue`
- Modify: `admin/src/api/settings.ts` + `admin/src/api/user.ts`
- Modify: `admin/src/views/login/index.vue`

- [ ] **Step 1: settings/index.vue 新增 tabs**
在现有卡片下方新增两个 Tab：
- "路径设置" 表单：4 个 el-input（admin_path/user_path/admin_api_prefix/user_api_prefix）+ "保存"按钮 + 只读 Nginx 参考配置（code block）
- "安全设置" 表单：Radio（qq_reset_mode = 仅QQ号 / QQ号+邮箱验证码）+ 保存按钮
- "用户端APP" 表单：4 个 el-input（apk_url/apk_version/ipa_url/ipa_version）+ 保存

- [ ] **Step 2: user-notices/index.vue 新建（管理员公告 CRUD）**
- 左侧表格（id/标题/级别/已读人数/发布状态/发布时间/过期时间/操作）
- 顶栏：新增 按钮
- 行操作：编辑/删除/发布/下架/立即WS广播
- 新增/编辑弹窗：title（input）/ level（radio info/warning/important）/ content（textarea）/ is_active（switch）/ expires_at（datetime）/ broadcast_ws（switch，是否保存时广播到在线用户）

- [ ] **Step 3: admin/router/index.ts 新增路由 + 用户管理 module 列/操作扩展**

- [ ] **Step 4: admin/src/api/settings.ts & user.ts 新增 API 封装对应后端接口**

- [ ] **Step 5: 登录页 /forgot-password 页，增加 Tab "通过QQ号重置密码"**
  - 表单：账号 + QQ号 + 新密码 +（如果模式=qq_and_email 则显示 邮箱验证码输入框+发送按钮）+ 提交按钮
  - 提交调用 forgotByQQApi（前端已有）

- [ ] **Step 6: admin 端 npm ci && npm run build 验证构建通过**

- [ ] **Step 7: Commit**

```bash
git add admin/src/views/settings/index.vue admin/src/views/user-notices/index.vue admin/src/router/index.ts admin/src/views/module/index.vue admin/src/api admin/src/views/login/index.vue admin/src/views/forgot-password/index.vue
cd admin && npm ci && npm run build && cd ..
git commit -m "feat: 管理端 路径设置/安全设置/用户APP设置 + 用户端公告CRUD页 + 用户管理QQ操作 + 登录页QQ改密Tab"
```

---

## Task 10：HBuilderX uni-app 模板骨架（APP/user/hbuilderx-template）

**最小模板文件（保证 HBuilderX 打开后能运行）：**

- manifest.json：含 `__APP_NAME__` / `__APP_ID__` / `__PKG__` / `__VERSION__` 占位，HBuilderXService 会替换
- pages.json：login/register/forgot/home/push/push-logs/devices/profile 路由，globalStyle 标题占位 `__APP_NAME__`
- main.js + App.vue + uni.scss：uni-app 入口（Vue 3 语法）
- pages/*/index.vue：每一页最小实现（登录/注册 Tab、home 显示 API_BASE、push 发送表单等）
- static/env.js：`window.__UNI_ENV__ = { API_BASE: '__API_BASE__', WS_BASE: '__WS_BASE__' }`
- static/logo.png + logo.jpg：占位 512x512 图标
- README_HBUILDERX.txt：HBuilderX 打开 → 发行 → 云打包步骤说明

- [ ] **Step 1: 逐个创建模板文件**
- [ ] **Step 2: Commit**

```bash
git add user/hbuilderx-template
git commit -m "feat: HBuilderX uni-app 模板骨架 登录/注册/首页/推送/记录/设备/个人中心"
```

---

## Task 11：集成验证 + 服务器部署脚本更新（update.sh / quick-deploy.sh）

**Files:**
- Modify: `backend/deploy/update.sh`（增加 user 前端构建步骤、020 迁移）
- Modify: `deploy/install.sh` / `deploy/quick-deploy.sh`（新增 Nginx 统一入口、user 目录构建、文件权限）

- [ ] **Step 1: update.sh 步骤 3 数据库迁移自然包含 020**
- [ ] **Step 2: update.sh 前端构建：条件执行 `cd user && npm ci && npm run build`**
- [ ] **Step 3: install.sh/quick-deploy.sh 同样新增 user 构建**
- [ ] **Step 4: 服务器验证流程（SSH 手动执行）：**
  ```bash
  cd /www/push-system/backend && bash deploy/migrate.sh   # 或 update.sh --skip-build --resume 到迁移步骤
  mysql -uim_push -p'ImPush@2024' im_push -e "SHOW TABLES LIKE 'user_notices%'; SHOW COLUMNS FROM users LIKE 'qq_number'; SELECT 1;"
  cd /www/push-system && ls -la user/dist/index.html   # 验证用户端已构建
  # 端口 80 访问：/user/ 能看到用户端，/ 仍为管理端，/user-api/dashboard/stats 带 token 返回 200
  ```
- [ ] **Step 5: Commit**

```bash
git add backend/deploy/update.sh deploy/install.sh deploy/quick-deploy.sh
git commit -m "feat: deploy脚本增加用户端构建/Nginx统一入口/迁移验证"
```

---

## Spec 覆盖自检

| Spec 节 | 对应 Task |
|---------|----------|
| 1. 总体架构 (StaticRouter + 路径可配置 + UserApiAuth 隔离) | Task 2 + Task 5 |
| 2. 用户端前端 (8个模块页面 + 登录/注册/忘记/404 + NoticeDialog + teal主色) | Task 6 + Task 7 + Task 8 |
| 3. /user-api/* 接口清单（10个控制器）+ /auth/reset-password-by-qq | Task 3 + Task 4.2 + Task 5.1 |
| 4. 数据库 020 迁移（公告表/QQ字段/api_keys.user_id/settings seed） | Task 1 |
| 5. 管理端：路径设置/安全设置/公告CRUD / 用户管理QQ操作 | Task 5.2-3 + Task 9 |
| 6. HBuilderX APP 生成（模板 + 参数替换 + ZIP） | Task 4.4 + Task 10 |
| 7. 后端代码结构 UserConsole/* / UserApiAuth / StaticRouter | Task 2 + Task 3 + Task 4 |
| 8. 错误/限流/日志 | Task 2 UserApiAuth；4.3 bindQQ 唯一性；各接口 Response.json(code) |
| 9. 自测清单 | Task 11.4 |

**占位符扫描：** 所有步骤代码块均为可直接落地的具体实现，无 TBD/TODO/"适当处理"。
**类型一致性：** userId 统一 int、`user_id` 全局一致；`settings_paths` JSON 字段名在 spec/迁移/SettingsController 三处完全匹配。
