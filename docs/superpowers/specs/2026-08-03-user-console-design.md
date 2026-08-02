# 用户端独立系统设计文档 (方案 A)

- 日期: 2026-08-03
- 状态: 已确认
- 范围: 用户端独立前端 + `/user-api/*` 接口隔离 + 路径可配置 + QQ 绑定改密 + 公告 + HBuilderX APP 生成

---

## 1. 总体架构（含路径可配置 + 实时生效）

### 1.1 部署拓扑

```
┌─────────────────────────────────────────────────────────────┐
│                      服务器 (Nginx)                          │
│  Nginx 只做统一入口：                                        │
│    - 静态资源 (.js/.css/.png/.jpg/.woff) 直接 try_files       │
│    - 其他全部反向代理到 Swoole HTTP                           │
│  Swoole HTTP 根据 DB 配置动态路由：                            │
│    /           (或 admin_path)    → admin/dist/index.html    │
│    /user/*     (或 user_path)     → user/dist/index.html    │
│    /admin/*    (或 admin_api)    → 管理员 API 路由           │
│    /user-api/* (或 user_api)     → 用户端 API 路由 (强隔离)  │
│    /auth/*                        → 公开接口 (登录/注册)     │
└─────────────────────────────────────────────────────────────┘
```

### 1.2 路径配置存储

表: `admin_settings`, key = `settings_paths`
```json
{
  "admin_path": "/",
  "user_path": "/user/",
  "admin_api_prefix": "/admin/",
  "user_api_prefix": "/user-api/"
}
```

### 1.3 实时生效机制（无需重启 Nginx / Swoole）

1. Swoole HTTP 端新增 `StaticRouter` 中间件：
   - 从 `admin_settings` 读最新路径配置，进程内缓存 5 秒
   - 若命中 `admin_path` → 返回 `admin/dist/index.html`（并在 `<head>` 注入 `<script>window.__APP_BASE__='<admin_path>'</script>`）
   - 若命中 `user_path` → 返回 `user/dist/index.html`（注入对应 base）
   - API 前缀匹配 → 走到对应路由
   - 其他 → 404 或 fallback
2. 管理员在「系统设置 → 路径设置」修改保存 → 写入 DB → 5 秒内所有 Swoole Worker 下次请求按新路径路由
3. Nginx 永不需要 reload 或改 conf
4. 前端 Vite base 改为运行时读取 `window.__APP_BASE__`，打包一次即可，不用重新打包

### 1.4 用户端接口数据隔离

所有 `/user-api/*` 接口统一走中间件：
- JWT 验证 → 必须 `type=user`
- 从 JWT claims 解出 `user_id` → 注入到 Request 对象（如 `$GLOBALS['_USER_ID']` 或 DI 容器）
- 所有业务 SQL 必须使用注入值做 `WHERE user_id = ?` 过滤
- **前端绝不允许在请求参数中传递 user_id**
- 接口层统一规范：Service 层构造函数 / 方法签名直接读全局 user_id 注入值，避免漏写

---

## 2. 前端（独立项目 `APP/user/`）

### 2.1 技术栈

与 admin 一致：
- Vue 3 + Vite 5 + TypeScript
- Element Plus (与 admin 同版本)
- Pinia + Vue Router (Hash 模式，避免 Nginx 额外配置)
- SCSS（复用 admin/styles 中的 variables/mixins/dark 主题）
- axios（baseURL 运行时取 `window.__APP_BASE_API__`）

### 2.2 目录结构

```
APP/user/
├── index.html
├── package.json
├── vite.config.ts
├── tsconfig.json
├── src/
│   ├── main.ts
│   ├── App.vue
│   ├── env.d.ts
│   ├── api/               (axios 封装 + /user-api/* 接口)
│   │   ├── request.ts
│   │   ├── dashboard.ts
│   │   ├── push.ts
│   │   ├── pushLogs.ts
│   │   ├── devices.ts
│   │   ├── keys.ts
│   │   ├── docs.ts
│   │   ├── app.ts
│   │   ├── notice.ts
│   │   └── profile.ts
│   ├── router/index.ts    (Hash 路由，base=window.__APP_BASE__)
│   ├── stores/            (Pinia: user/permission/app)
│   ├── layout/            (复用 admin: Sidebar + Navbar + Tabs + AppMain)
│   ├── styles/            (variables/mixins/dark/reset，与 admin 同基调)
│   ├── utils/
│   ├── components/
│   │   └── NoticeDialog.vue （全局公告弹窗）
│   └── views/
│       ├── login/index.vue
│       ├── register/index.vue
│       ├── forgot-password/index.vue
│       ├── dashboard/index.vue
│       ├── push/index.vue
│       ├── push-logs/index.vue
│       ├── devices/index.vue
│       ├── keys/index.vue
│       ├── docs/index.vue
│       ├── app/index.vue
│       ├── profile/index.vue
│       └── error/404.vue
└── hbuilderx-template/    （HBuilderX uni-app 项目模板 ZIP 骨架）
    ├── manifest.json
    ├── pages.json
    ├── main.js
    ├── App.vue
    ├── uni.scss
    ├── pages/
    │   ├── login/index.vue
    │   ├── register/index.vue
    │   ├── forgot/index.vue
    │   ├── home/index.vue
    │   ├── push/index.vue
    │   ├── push-logs/index.vue
    │   ├── devices/index.vue
    │   └── profile/index.vue
    ├── static/
    │   ├── logo.png
    │   └── env.js
    └── README_HBUILDERX.txt
```

### 2.3 路由 & 菜单

| 路径 | 菜单名 | 图标 | 说明 |
|------|--------|------|------|
| `/dashboard` | 仪表盘 | Odometer | 个人统计 |
| `/push` | 推送消息 | Promotion | 发送推送 |
| `/push-logs` | 推送记录 | Document | 历史记录/重推 |
| `/devices` | 设备管理 | Cellphone | 设备列表/标签/解绑 |
| `/keys` | Key 管理 | Key | API Key |
| `/docs` | API 文档 | Connection | 我的 API 文档 |
| `/app` | APP 下载/生成 | CellphoneFilled | 通用版下载 + 定制 HBuilderX ZIP |
| `/profile` | 个人中心 | UserFilled | 改密/安全码/QQ绑定 |

顶栏右侧：通知铃铛（公告）+ 用户下拉（个人中心/退出登录）

### 2.4 公告弹窗

- 登录后 / 每次进入仪表盘 → 拉取 `/user-api/notices/latest`
- 按 `level` 排序（important > warning > info）
- 若最高级别公告未读或未 snooze → 弹出全局对话框
- 弹窗按钮：「确认」→ 标记已读；「7 天内不再弹出同类」→ 写 snooze_until = NOW + 7d
- 公告内容支持换行/简单加粗（后端 plain text + 前端 nl2br + simple markdown）

### 2.5 视觉基调

- 主色：teal-500（`#14b8a6`），与管理端紫色区分
- 暗色主题：复用 admin/dark.scss 的色板映射，仅替换 `--color-primary`

---

## 3. 后端接口（`/user-api/*`）

### 3.1 通用响应结构

与现有 `/admin/*` 一致：`{ code: 0|非0, msg, data: {...} }`

### 3.2 接口清单

| 方法 | 路径 | 说明 | 关键校验 |
|------|------|------|---------|
| GET | `/user-api/dashboard/stats` | 个人统计 | user_id 过滤推送日志/设备/Key |
| POST | `/user-api/push/send` | 发送推送 | key_id 或 device_ids 必须归属 user_id；broadcast=1 仅自己设备广播 |
| GET | `/user-api/push-logs` | 推送记录列表（分页+筛选） | user_id 过滤；筛选：key_id/时间/状态/目标类型 |
| GET | `/user-api/push-logs/:id` | 单条记录详情+设备分发明细 | user_id 匹配 |
| POST | `/user-api/push-logs/:id/retry` | 重新推送 | user_id 匹配；原始 payload 重发 |
| GET | `/user-api/devices` | 设备列表（分页+筛选） | user_id 过滤；筛选：在线/标签/平台 |
| PUT | `/user-api/devices/:id` | 修改备注/标签 | user_id 匹配 |
| DELETE | `/user-api/devices/:id` | 解绑设备 | user_id 匹配 |
| POST | `/user-api/devices/clear-zombie` | 一键清除僵尸连接（自己名下） | user_id 过滤 |
| GET | `/user-api/keys` | Key 列表 | user_id 过滤；返回 mask 后的 key_value |
| POST | `/user-api/keys` | 新建 Key | body: name, permissions[]；写入时绑定 user_id |
| DELETE | `/user-api/keys/:id` | 吊销 Key | user_id 匹配；软删除 + 失效缓存 |
| GET | `/user-api/docs` | 我的 API 文档（JSON 结构） | 返回 /user-api/push/* 等文档，附 cURL/JS/PHP 示例 |
| GET | `/user-api/app/downloads` | 通用版 APP 下载列表 | 读 settings_user_app 配置 |
| POST | `/user-api/app/generate-hbuilderx` | 生成定制 HBuilderX ZIP | body: app_name, package_name, icon_base64；返回临时下载 URL |
| GET | `/user-api/notices/latest` | 最新未读公告 | 未读 + 未过期；级别排序 |
| POST | `/user-api/notices/:id/read` | 标记已读 | user_id 唯一键 INSERT IGNORE |
| POST | `/user-api/notices/:id/snooze` | 同类 7 天免打扰 | user_notice_reads.snooze_until = NOW+7d |
| GET | `/user-api/profile` | 个人信息 | username, phone, email, qq_number, qq_bound_at, status, created_at |
| POST | `/user-api/profile/change-password` | 修改密码 | old+new+确认；bcrypt 校验 |
| POST | `/user-api/profile/bind-qq` | 绑定 QQ 号 | 仅允许 qq_number IS NULL 时绑定；一次绑定不可用户端解绑 |

### 3.3 公开接口（`/auth/*` 补充）

| 方法 | 路径 | 说明 | 关键校验 |
|------|------|------|---------|
| POST | `/auth/reset-password-by-qq` | 通过 QQ 号改密 | 模式读 `settings_security.qq_reset_mode`：<br>`qq_only` 模式：`{ qq_number, account, new_password }` → 校验 qq_number 绑定到 account → 改密；<br>`qq_and_email` 模式：`{ qq_number, account, email_code, new_password }` → 校验 qq↔account 匹配 → 校验 email 验证码 → 改密 |

---

## 4. 数据库变更

迁移文件：`020_user_console.sql`

```sql
-- 4.1 用户端公告
CREATE TABLE user_notices (
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

CREATE TABLE user_notice_reads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  notice_id INT NOT NULL,
  snooze_until DATETIME NULL,
  read_at DATETIME NOT NULL,
  UNIQUE KEY uk_user_notice (user_id, notice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4.2 users 表扩展：QQ 绑定
ALTER TABLE users
  ADD COLUMN qq_number VARCHAR(20) NULL DEFAULT NULL COMMENT '绑定QQ号(纯数字，绑定后不可用户端解绑)',
  ADD COLUMN qq_bound_at DATETIME NULL,
  ADD UNIQUE INDEX uk_qq_number (qq_number);

-- 4.3 api_keys 表扩展（若现有表无 user_id 字段则执行）
-- 若已存在 user_id，跳过此 ALTER
SET @has_user_id = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name='api_keys' AND column_name='user_id'
);
SET @sql = IF(@has_user_id = 0,
  'ALTER TABLE api_keys ADD COLUMN user_id INT NULL DEFAULT NULL COMMENT ''归属用户, NULL=管理员''',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_user_idx = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE table_schema = DATABASE() AND table_name='api_keys' AND index_name='idx_user'
);
SET @sql = IF(@has_user_idx = 0,
  'ALTER TABLE api_keys ADD INDEX idx_user (user_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4.4 admin_settings 默认值 seed（如不存在则插入）
-- settings_paths / settings_security / settings_user_app
INSERT INTO admin_settings (config_key, config_value, updated_at) VALUES
  ('settings_paths', JSON_OBJECT(
    'admin_path', '/',
    'user_path', '/user/',
    'admin_api_prefix', '/admin/',
    'user_api_prefix', '/user-api/'
  ), NOW())
  ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO admin_settings (config_key, config_value, updated_at) VALUES
  ('settings_security', JSON_OBJECT(
    'qq_reset_mode', 'qq_and_email'
  ), NOW())
  ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO admin_settings (config_key, config_value, updated_at) VALUES
  ('settings_user_app', JSON_OBJECT(
    'apk_url', '',
    'apk_version', '',
    'ipa_url', '',
    'ipa_version', ''
  ), NOW())
  ON DUPLICATE KEY UPDATE updated_at = NOW();
```

---

## 5. 管理后台新增功能

### 5.1 新页面

| 菜单 | 页面位置 | 功能 | 权限 |
|------|---------|------|------|
| 系统设置 → 路径设置（新卡片，在 settings 页内卡片式，不新增路由） | `views/settings/index.vue` 新 tab | 编辑 admin_path / user_path / admin_api_prefix / user_api_prefix；展示 Nginx 参考配置（只读） | admin |
| 系统设置 → 安全设置（新卡片） | settings 页内新 tab | QQ 改密模式开关（仅 QQ 号 / QQ 号+邮箱验证码） | admin |
| 用户端公告管理（新菜单） | `views/user-notices/index.vue` + 路由 `/user-notices` | 公告 CRUD：标题/内容/级别/发布状态/过期时间；发布时可选"推送到所有在线用户"(WS broadcast)；行内统计已读/未读人数 | admin |
| 用户管理 → QQ 管理（在 Users ModuleView 中加列+加操作） | `views/module/index.vue` users 模块列 | 新增列：QQ号（已绑定=显示+绿色徽章，未绑定=灰色"-"）；新增行操作：① 绑定QQ（弹窗输入账号+QQ号，双校验后绑定，记操作日志）；② 解绑QQ（二次确认弹窗，记日志）；③ 通过 QQ 改密（弹窗：必填【账号+QQ号+新密码+确认新密码】四个字段，匹配成功才改密） | admin |

### 5.2 QQ 绑定的强约束

- 绑定：
  - 用户端绑定：仅 `qq_number IS NULL` 时可提交 `/user-api/profile/bind-qq`
  - 管理后台绑定：任何时候可改绑（需二次确认），改绑记 `admin_logs`
- 解绑：
  - 用户端 **无** 解绑 API
  - 管理后台可解绑，需二次确认，记日志
- 唯一性：`users.qq_number` 唯一索引，冲突时报错
- 通过 QQ 号改密（管理后台）：
  - 弹窗表单 4 字段：用户账号(username/phone/email)、QQ 号、新密码、确认新密码
  - 后端 SQL：`WHERE account 匹配 AND qq_number = 输入QQ号`，无匹配行 → 拒绝
  - 成功改密后写入 `admin_logs`

---

## 6. HBuilderX APP 生成

### 6.1 模板

路径：`APP/user/hbuilderx-template/`

内容：uni-app 最小骨架（Vue 3 + Vite + uni-ui），pages 含登录/注册/忘记/首页/推送/推送记录/设备/个人中心。

### 6.2 生成流程

`POST /user-api/app/generate-hbuilderx`，参数：
```json
{
  "app_name": "我的推送",
  "package_name": "com.example.mypush",
  "icon_base64": "data:image/png;base64,xxxx..."
}
```

后端：
1. 临时目录 `backend/runtime/tmp/hbx-<userId>-<rand>/`
2. 复制 `APP/user/hbuilderx-template/` 到临时目录
3. 用正则替换：
   - `manifest.json` → name/appid/versionName/package → 用参数 + 自动生成 appid（`__UNI__<rand8>`）
   - `pages.json` → globalStyle.navigationBarTitleText = app_name
   - `static/env.js` → API_BASE = 当前域名的 `/user-api/`，WS_URL = 当前域名的 WebSocket
   - `static/logo.png` → 替换为 icon_base64 解码并 resize 到 PNG
4. 打包 ZIP：`hbx-<userId>-<timestamp>.zip`
5. 通过 Swoole `Response()->sendfile()` 下载
6. 临时文件 10 分钟后由 `deploy/apk/cleanup-tmp.sh` 定时清理，或下次生成时清理老的

### 6.3 ZIP 内 README

`README_HBUILDERX.txt` 内含：
1. 安装 HBuilderX
2. 打开项目 → "文件 → 打开目录 → 本项目"
3. 配置 uni-app AppID（manifest.json 已自动生成，如需自定义请改）
4. 发行 → 原生 APP-云打包
5. Android 打包（勾选"使用自有证书"/"公共测试证书"）→ 下载 APK
6. iOS 需 macOS 及 Apple 开发者证书
7. 常见问题（云打包失败/包名已被占用/证书错误等）

---

## 7. 后端代码结构调整

```
backend/
├── src/
│   ├── Controller/
│   │   └── UserConsole/        (新增，/user-api/* 控制器分组)
│   │       ├── DashboardController.php
│   │       ├── PushController.php
│   │       ├── PushLogController.php
│   │       ├── DeviceController.php
│   │       ├── KeyController.php
│   │       ├── DocsController.php
│   │       ├── AppController.php
│   │       ├── NoticeController.php
│   │       └── ProfileController.php
│   ├── Middleware/
│   │   ├── UserApiAuth.php         (新增，type=user JWT + user_id 注入)
│   │   └── StaticRouter.php        (新增，按 settings_paths 渲染 user/admin 前端入口 HTML + 注入 window.__APP_BASE__)
│   └── Service/
│       ├── UserNoticeService.php   (新增：公告 CRUD + 已读 + 发布 WS broadcast)
│       └── HBuilderXService.php    (新增：生成模板 ZIP + 参数替换 + 临时文件清理)
├── public/index.php                (新增路由注册：/user-api/*、StaticRouter 前置)
└── HttpServer.php                   (如有 DI / 路由注册，在此挂载 StaticRouter 前置)
```

### 7.1 路由注册（`backend/public/index.php` / `Router.php`）

- 前置中间件：`StaticRouter`（最先执行，命中前端入口则直接返回 HTML，不进入业务路由）
- 然后按前缀：
  - `/auth/*` → 公开接口
  - `/admin/*` → `AdminAuth` 中间件
  - `/user-api/*` → `UserApiAuth` 中间件 → 分发到 `UserConsole/*` 控制器
- 所有 `/user-api/*` 控制器统一继承 `UserConsoleBaseController`（构造函数内从 JWT/全局拿 user_id，放到 `$this->userId`）

---

## 8. 错误处理 & 安全

- `/user-api/*` 401 / 403 / 404 / 500 响应结构与 admin 端一致
- UserApiAuth 失败统一响应 `code=401, msg='登录已过期或无权限，请重新登录'`
- 所有写入类接口（绑定 QQ、改密、吊销 Key、发送推送）统一写日志到 `runtime/logs/user-api.log`，包含 user_id / 操作类型 / IP / UA
- 速率限制：`/user-api/push/send` 按 user_id 限流（默认 60 次/分钟），超限返回 429
- `/auth/reset-password-by-qq` IP 限流（5 次/分钟），防止暴力枚举

---

## 9. 自测清单

- [ ] 改路径设置 → 5 秒内新路径可访问前端
- [ ] 用户 A 的 token 访问用户 B 的设备/推送记录 → 404 或空数据（强隔离验证）
- [ ] 用户端绑定 QQ 后，再次请求 bind-qq → 拒绝
- [ ] 用户端请求解绑 QQ → 404（接口不存在）
- [ ] 管理后台 QQ 改密弹窗：账号+QQ号任一不匹配 → 拒绝
- [ ] settings_security qq_reset_mode=qq_and_email 时，缺 email_code → 拒绝
- [ ] 公告发布后，用户端首次进入仪表盘弹出；标记已读之后不再弹；snooze 7 天生效
- [ ] 生成 HBuilderX ZIP：manifest.json / pages.json / static/env.js 中参数已替换
