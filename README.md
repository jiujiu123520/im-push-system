# IM Push System - 即时消息推送系统

> 基于 PHP + Swoole 的实时消息推送平台，支持 WebSocket 长连接、设备掉线邮箱通知、API 对接推送、Android APP 深度保活（前台服务 + AlarmManager + WakeLock + WifiLock）。

## 系统架构

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Android APP │◄──►│  WebSocket   │◄──►│   HTTP API   │
│  (uni-app)   │     │  (Swoole)    │     │  (Swoole)    │
└─────────────┘     └──────┬──────┘     └──────┬──────┘
                           │                    │
                    ┌──────┴──────┐     ┌──────┴──────┐
                    │   Redis     │     │   MySQL     │
                    │ (连接映射)   │     │ (数据持久化) │
                    └─────────────┘     └─────────────┘
                           │
                    ┌──────┴──────┐
                    │   Nginx     │
                    │ (反向代理)   │
                    └─────────────┘
```

## 功能特性

### 核心功能
- **实时推送** - WebSocket 长连接，毫秒级消息送达
- **Key 推送** - 一个 Key 多人订阅，支持单人/多人/批量推送
- **离线消息** - 设备离线时消息存储 Redis，上线自动补发
- **双向心跳** - 服务端主动 ping + 客户端主动 ping，pong 携带时间戳、设备状态、在线连接数，支持 RTT 网络延迟计算
- **僵尸连接巡检** - 每 30 秒检测 Redis 在线但实际已断开的连接并清理
- **推送消息无字数限制** - title 字段 TEXT 类型、content 字段 MEDIUMTEXT 类型

### APP 功能（HBuilderX uni-app 版本）
- **消息推送** - 实时接收推送消息，统计卡片（今日/累计/设备ID），消息记录列表
- **音频播放器** - 云端音频 + 本地音频双列表，支持列表循环/单曲循环/播放一次三种模式
- **用户中心** - 用户信息卡片、连接信息（推送Key/服务器/RTT延迟/连接状态）、权限管理、设备信息、清除缓存、复制设备信息
- **掉线提醒** - 掉线时 Toast 提醒 + 顶部橙色提醒条（显示上次掉线时间和重连进度），重连成功后显示离线时长
- **锁屏保活** - 五层保活机制确保锁屏后连接不中断
- **锁屏通知** - 推送消息在锁屏页面直接显示，支持全屏 Intent、CATEGORY_MESSAGE、VISIBILITY_PUBLIC
- **通知栏媒体控制** - 通知栏显示播放器控件，支持上一首/播放暂停/下一首

### 通知功能
- **设备掉线通知** - 设备断开连接自动发送邮件通知
- **QQ 邮箱支持** - 支持 QQ 邮箱、QQ 企业邮箱等 SMTP 服务
- **多邮箱推送** - 支持配置多个收件邮箱（逗号分隔）
- **通知间隔控制** - 避免频繁通知，可自定义间隔时间

### 安全功能
- **用户注册** - 用户可通过注册页自助注册账号（手机号/邮箱验证码）
- **忘记密码** - 通过 8 位数字安全码重置密码
- **验证码加密** - 注册/登录验证码 AES 加密传输
- **图形验证码开关** - 后台可控制登录是否需要图形验证码
- **设备指纹** - 记录设备 IP、UA、指纹，支持拉黑
- **黑名单管理** - 按用户/设备/IP 维度拉黑，实时断连
- **管理员鉴权** - JWT Token 鉴权，支持多角色权限
- **登录失败限制** - 管理员登录失败次数限制（Redis 计数，默认 5 次锁定 30 分钟）

### 管理功能
- **管理后台** - Vue3 + Element Plus + Vite，美观易用
- **消息导出** - 支持导出推送记录和消息记录（CSV/JSON）
- **测试推送** - 内置调试推送功能，方便开发排查
- **用户管理** - 管理员可修改用户信息、重置密码、切换状态
- **音频管理** - 后台上传音频文件，APP 端自动同步播放列表
- **域名与 SSL** - 独立绑定域名、端口访问、自动申请/续费 Let's Encrypt 证书
- **APK 分发** - 构建后自动生成分发记录，支持自托管下载/蓝奏云/自定义上传，二维码下载

## 技术栈

| 模块 | 技术 | 说明 |
|------|------|------|
| 后端 | PHP 8.2 + Swoole 5.x | WebSocket/HTTP 双服务 |
| 数据库 | MySQL 8.0 | 数据持久化（utf8mb4） |
| 缓存 | Redis 7.x | 连接映射、离线消息、通知间隔、构建队列 |
| 反向代理 | Nginx | HTTP/WebSocket 反向代理，支持 IP/域名共存 |
| 管理后台 | Vue3 + Element Plus + Vite + TypeScript | 响应式管理界面 |
| Android APP | HBuilderX uni-app (Vue 3) + plus.android 原生 API | 主推版本 |
| APP 打包 | GitHub Actions / HBuilderX 云打包 | JDK 17 / Android SDK 34 / Gradle 8.7 |
| 邮件服务 | PHPMailer | SMTP 邮件发送（QQ 邮箱等） |

## 快速开始

### 交互式管理菜单(推荐)

通过数字菜单管理服务器的环境、代码、服务、卸载等所有操作:

```bash
cd /www/push-system  # 或项目所在目录
bash manage.sh
```

菜单功能:

| 编号 | 功能 | 说明 |
|------|------|------|
| 1 | 安装环境 | 首次部署,自动检测已安装组件并跳过 |
| 2 | 更新代码 | 拉取最新代码 + 依赖 + 数据库迁移 + 重启服务 |
| 3 | 重启服务 | 单独重启 HTTP/WebSocket/Nginx/MySQL/Redis |
| 4 | 查看服务状态 | 服务状态 + 磁盘/内存/Git 版本 + 实时日志 |
| 5 | 清理缓存 | NPM/Composer/系统缓存等 |
| 6 | 修改环境配置 | 端口/数据库/Redis/邮件/GitHub Actions 配置 |
| 7 | 构建管理后台前端 | npm install + npm run build |
| 8 | 生成 APP 签名 keystore | 生成 release.keystore 用于 APP 签名 |
| 9 | 回滚代码 | 回滚到上次更新前或指定 commit |
| 10 | 卸载环境(保留源码) | 停止服务 + 卸载 PHP/MySQL/Redis/Nginx 等 |
| 11 | 卸载源码(保留环境) | 删除项目目录,保留运行环境 |
| 12 | 完全卸载 | 彻底清除环境 + 源码 + 数据库 |

### 一键部署

#### Root 用户安装（推荐）

```bash
# 方式1: 使用 gh.jasonzeng.dev 代理（国内服务器推荐，解决 GitHub 访问慢）
curl -sSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh -o /tmp/deploy.sh && sudo bash /tmp/deploy.sh

# 方式2: 直连 GitHub（需能访问 GitHub）
curl -sSL https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh -o /tmp/deploy.sh && sudo bash /tmp/deploy.sh

# 方式3: 先克隆再部署
git clone https://github.com/jiujiu123520/im-push-system.git
cd im-push-system
sudo bash deploy/deploy.sh
```

> 注意：`curl | bash` 管道模式下无法自动 sudo 提权，请使用 `curl -o /tmp/deploy.sh && sudo bash /tmp/deploy.sh` 方式。

### 交互式安装

部署脚本会自动询问安装组件：

```bash
sudo bash deploy/deploy.sh
```

安装过程中会询问：
1. **核心服务**（必选）：PHP 8.2 + Swoole + MySQL + Redis + Nginx + 管理后台
2. **SSL 证书自动申请环境**：acme.sh + Let's Encrypt + 自动续费 cron
3. **sudoers 权限配置**：允许 www-data 重启服务 / 部署 Nginx / 申请证书
4. **uninstall**：卸载系统（环境/源码/完全卸载）

> APP 打包已迁移到 GitHub Actions，无需在服务器安装 JDK/Android SDK/Gradle。

### 自定义部署参数

```bash
sudo bash deploy/deploy.sh \
  --project-dir=/www/push-system \
  --db-pass=YourPassword@2024 \
  --http-port=9501 \
  --ws-port=9502 \
  --gh-proxy
```

### 日常更新（默认 Y 确认）

安装完成后，日常代码更新使用更新脚本即可：

```bash
cd /www/push-system
bash backend/deploy/update.sh
```

> 更新脚本默认回车即开始更新（输入 n 才取消），自动拉取代码、安装依赖、执行数据库迁移、构建前端、重启服务。
> 如涉及端口变更或服务配置修改，需使用 `sudo systemctl restart push-http push-websocket`。

### 安装流程（9 步）

| 步骤 | 内容 | 必选 |
|------|------|------|
| [1/9] | 安装系统依赖（PHP 8.2 + Swoole + MySQL + Redis + Nginx） | 是 |
| [2/9] | 创建项目目录与数据库 | 是 |
| [3/9] | 复制项目代码并执行迁移 | 是 |
| [4/9] | 安装后端依赖（composer install） | 是 |
| [5/9] | 构建管理后台（npm install && npm run build） | 是 |
| [6/9] | 配置 systemd 服务与 Nginx | 是 |
| [7/9] | 启动服务 | 是 |
| [8/9] | 安装 SSL 证书环境（acme.sh + 自动续费 cron） | 可选 |
| [9/9] | 配置 sudoers 权限 | 可选 |

## APP 打包

项目支持两种 APP 打包方式：

### 方式一：HBuilderX 云打包（推荐，当前主推版本）

APP 源码位于 `build/hbuilderx/`，基于 uni-app (Vue 3) 开发，使用 HBuilderX 云打包生成 APK。

1. 下载安装 [HBuilderX](https://www.dcloud.io/hbuilderx.html)
2. 打开 `build/hbuilderx/` 目录作为项目
3. 在 HBuilderX 中配置 `manifest.json`（App 图标、模块、权限）
4. 点击「发行」→「原生 App-云打包」→ 选择 Android → 提交

> 也可使用 `build/build_hbuilderx.sh` 脚本生成可导入 HBuilderX 的项目结构。

### 方式二：GitHub Actions 云端构建

APP 打包已完全迁移到 GitHub Actions，在 GitHub 云端构建，无需在服务器安装 JDK/Android SDK/Gradle，节省服务器资源。

#### 配置步骤

详细配置请参考 [docs/github-actions-build.md](docs/github-actions-build.md)，或在管理后台「APP 生成」页面查看配置提示面板。

1. **服务器端 .env 配置**

   ```env
   GITHUB_TOKEN=ghp_xxxxxxxxxxxx
   GITHUB_REPO=jiujiu123520/im-push-system
   GITHUB_WORKFLOW_ID=build-apk.yml
   SERVER_SSH_HOST=your_server_ip
   SERVER_SSH_PORT=22
   SERVER_SSH_USER=ubuntu
   ```

2. **GitHub 仓库 Secrets 配置**

   在 GitHub 仓库 Settings → Secrets and variables → Actions 中添加：
   - `APK_KEYSTORE_BASE64` - keystore 文件的 base64 编码
   - `APK_KEYSTORE_PASSWORD` - keystore 密码
   - `APK_KEY_ALIAS` - 密钥别名
   - `APK_KEY_PASSWORD` - 密钥密码
   - `SERVER_SSH_HOST` / `SERVER_SSH_PORT` / `SERVER_SSH_USER` - 服务器 SSH 连接信息
   - `SERVER_SSH_KEY` - SSH 私钥

3. **在管理后台提交构建任务**

   - 「APP 生成」页面：提交构建任务到队列，由后端调用 GitHub API 触发
   - 「GitHub Actions 手动构建」页面：直接调用 GitHub API 触发，用于测试

### 构建流程

```
用户提交构建 → 后端调用 GitHub API → GitHub Actions Runner 构建
→ SCP 上传 APK 到服务器 → SSH 调用回调脚本更新 Redis 状态
→ 前端轮询 list 接口获取最新状态 → 用户下载 APK
```

## Android APP 深度保活机制

APP 通过 `plus.android` 调用原生 API 实现**五层保活**，确保锁屏和后台状态下 WebSocket 连接不中断：

| 层级 | 机制 | 说明 |
|------|------|------|
| 1. 前台服务 | `startForegroundService` | 创建常驻通知栏，进程优先级提升至前台，防止被系统杀死 |
| 2. WakeLock | `PowerManager.PARTIAL_WAKE_LOCK` | 保持 CPU 唤醒，防止锁屏后 CPU 休眠导致心跳停止 |
| 3. AlarmManager | `setExactAndAllowWhileIdle` | 锁屏后 JS 引擎被冻结时作为备用心跳，25 秒间隔定时唤醒 CPU 发送心跳 |
| 4. WifiLock | `WifiManager.WIFI_MODE_FULL_HIGH_PERF` | 保持 WiFi 不休眠，防止锁屏后网络断开 |
| 5. 电池优化白名单 | `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` | 申请加入电池优化白名单，避免 Doze 模式限制 |

### 心跳与重连策略

- **客户端心跳**：10 秒间隔主动发送 ping，20 秒未收到任何消息则主动断开重连
- **服务端心跳**：HeartbeatManager 定时发送 ping，连续 3 次未收到 pong 则断开连接（60 秒超时）
- **重连策略**：前 3 次快速重连（2s/5s/10s），之后指数退避（最大 60 秒）
- **AlarmManager 唤醒**：锁屏后闹钟触发时获取临时 WakeLock（10 秒），发送心跳或触发重连
- **onShow 检测**：APP 切回前台时主动发送验证 ping，5 秒无响应则强制重连

### 锁屏通知显示

- 通知渠道 `VISIBILITY_PUBLIC`（锁屏完全可见）
- `setBypassDnd` 绕过勿扰模式
- `setFullScreenIntent` 全屏 Intent（Android 10 以下弹出到锁屏上方）
- `setCategory('msg')` 分类为消息，锁屏界面优先展示
- 振动模式 `[0, 200, 200, 200]`，绿色灯光提示

### 小米手机专属优化

小米 / Redmi / POCO 手机（MIUI / HyperOS）需额外开启以下权限，APP 设置页提供一键跳转：

- 自启动（设为允许）
- 省电策略（设为无限制）
- 后台弹出界面（允许）
- 锁屏显示（允许）
- 悬浮窗（允许）
- 通知使用权

## 服务器配置建议

### 3-5 人使用（最低配置）

| 项目 | 配置 |
|------|------|
| CPU | 2 核 |
| 内存 | 2 GB |
| 硬盘 | 40 GB SSD |
| 带宽 | 3 Mbps |
| 系统 | Ubuntu 22.04 LTS |

### 10-20 人使用（推荐配置）

| 项目 | 配置 |
|------|------|
| CPU | 4 核 |
| 内存 | 4 GB |
| 硬盘 | 80 GB SSD |
| 带宽 | 5 Mbps |
| 系统 | Ubuntu 22.04 LTS |

## 项目结构

```
im-push-system/
├── backend/                  # 后端服务
│   ├── src/
│   │   ├── Controller/       # 控制器（API 路由处理）
│   │   ├── Middleware/        # 中间件（鉴权、日志）
│   │   ├── Service/          # 服务层（业务逻辑）
│   │   │   ├── HeartbeatManager.php    # 心跳管理（ping/pong 双向）
│   │   │   ├── ConnectionManager.php  # 连接管理（fd↔device_id 映射）
│   │   │   ├── PushDispatcher.php      # 推送分发（跨进程队列）
│   │   │   ├── DeviceOfflineNotifier.php # 设备掉线邮件通知
│   │   │   ├── SslService.php          # SSL 证书与 Nginx 配置
│   │   │   ├── AudioService.php        # 音频文件管理
│   │   │   ├── CaptchaService.php      # 图形验证码
│   │   │   └── ...
│   │   ├── HttpServer.php    # HTTP API 服务
│   │   ├── WebSocketServer.php # WebSocket 推送服务
│   │   └── Router.php        # 路由器
│   ├── config/               # 配置文件（database/redis/github）
│   ├── database/
│   │   ├── migrations/       # 数据库迁移（001-011）
│   │   └── seeders/          # 种子数据
│   ├── public/               # 入口文件
│   └── .env.example          # 环境变量示例
├── admin/                    # 管理后台
│   ├── src/
│   │   ├── api/              # API 接口定义
│   │   ├── views/            # 页面组件（16 个页面）
│   │   │   ├── dashboard/    # 仪表盘（含测试推送）
│   │   │   ├── api-keys/     # API Key 管理
│   │   │   ├── audio/        # 音频管理
│   │   │   ├── domains/      # 域名与 SSL
│   │   │   ├── apk-distribution/ # APK 分发
│   │   │   ├── app-build/    # APP 构建
│   │   │   ├── settings/     # 系统设置
│   │   │   └── ...
│   │   ├── layout/           # 布局组件
│   │   ├── router/           # 路由配置
│   │   └── stores/           # 状态管理
│   └── vite.config.ts        # Vite 构建配置
├── build/                    # APP 构建脚本
│   ├── hbuilderx/            # HBuilderX uni-app 源码（主推版本）
│   │   ├── pages/
│   │   │   ├── index/        # 登录页
│   │   │   └── home/         # 主页面（消息/音频/用户中心三 Tab）
│   │   ├── static/           # 静态资源（APP 图标）
│   │   ├── App.vue
│   │   ├── config.js         # 配置注入（构建时生成）
│   │   ├── manifest.json     # APP 配置（权限、图标、模块）
│   │   └── pages.json        # 页面路由
│   ├── app/                  # Kotlin 原生 APP 源码（旧版本）
│   ├── build_apk.sh          # APK 打包脚本（GitHub Actions 调用）
│   ├── build_hbuilderx.sh    # HBuilderX 项目生成脚本
│   ├── inject_config.sh      # 配置注入脚本
│   ├── generate_keystore.sh  # 签名生成脚本
│   └── queue/                # 构建队列管理
├── .github/workflows/        # GitHub Actions 工作流
│   └── build-apk.yml         # APP 构建 workflow
├── deploy/                   # 部署脚本
│   ├── deploy.sh             # 一键部署（国内服务器）
│   ├── install.sh            # 安装脚本
│   ├── update.sh             # 更新脚本
│   ├── rollback.sh           # 回滚脚本
│   ├── uninstall.sh          # 卸载脚本
│   ├── manage.sh             # 交互式管理菜单
│   ├── nginx/                # Nginx 配置
│   ├── systemd/              # systemd 服务文件
│   └── apk/                  # APK 分发脚本
└── manage.sh                 # 交互式管理菜单入口
```

## API 接口

### 开放推送 API

```bash
# 推送消息到设备
curl -X POST http://localhost:9501/api/push \
  -H "X-Api-Key: your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "target_type": "key",
    "target_value": "your_push_key",
    "title": "通知标题",
    "content": "通知内容",
    "priority": "high"
  }'
```

### 用户认证 API（APP 与前端共用，双路由）

| 接口 | 方法 | 说明 |
|------|------|------|
| `/auth/register` 和 `/api/auth/register` | POST | 用户注册（返回 token + security_code） |
| `/auth/send-code` 和 `/api/auth/send-code` | POST | 发送短信/邮箱验证码 |
| `/auth/login` 和 `/api/auth/login` | POST | 用户登录 |
| `/auth/reset-password` 和 `/api/auth/reset-password` | POST | 通过安全码重置密码 |
| `/captcha/image` 和 `/api/captcha/image` | GET | 获取图形验证码（返回 token + image） |

> 说明：APP 端调用 `/auth/*` 路径，前端管理后台因 axios `baseURL=/api` 调用 `/api/auth/*` 路径。

### 管理后台 API

| 接口 | 方法 | 说明 |
|------|------|------|
| `/admin/login` | POST | 管理员登录 |
| `/admin/keys` | GET/POST | Key 列表/创建 |
| `/admin/keys/{id}` | PUT/DELETE | Key 更新/删除 |
| `/admin/devices` | GET | 设备列表 |
| `/admin/blacklist` | GET/POST | 黑名单管理 |
| `/admin/messages` | GET | 消息记录 |
| `/admin/settings/mail` | GET/POST | 邮件配置 |
| `/admin/test-push` | POST | 测试推送 |
| `/admin/audio` | GET/POST | 音频文件管理 |
| `/admin/domains` | GET/POST | 域名与 SSL 管理 |

## APK 分发管理

APP 构建成功后，系统自动创建分发记录，支持三种分发方式：

| 方式 | 说明 | 文件大小限制 | 是否需要额外配置 |
|------|------|------------|----------------|
| **自托管下载** | APK 存储在服务器，通过 Nginx 直接提供下载 | 无限制 | 无需配置，默认启用 |
| **蓝奏云上传** | 自动上传到蓝奏云并生成分享链接 | 100MB | 需配置蓝奏云 Cookie |
| **自定义上传** | 调用自定义脚本上传到任意存储服务 | 无限制 | 需配置上传脚本 |

每个分发记录生成一个带 token 的公开下载链接（无需登录）：

```
https://your-domain.com/api/apk-distribution/download/{token}
```

## 设备掉线邮箱通知配置

### 1. 配置 SMTP 服务（管理后台 → 系统设置）

| 配置项 | QQ 邮箱 | QQ 企业邮箱 |
|--------|---------|------------|
| SMTP 主机 | smtp.qq.com | smtp.exmail.qq.com |
| 端口 | 587 | 465 |
| 加密方式 | TLS | SSL |
| 认证方式 | 授权码 | 授权码 |

### 2. 配置 Key 通知（管理后台 → Key 管理 → 编辑）

- 开启"启用掉线通知"
- 填写通知邮箱（多个用逗号分隔，如 `a@qq.com,b@qq.com`）
- 设置通知间隔（默认 300 秒）

## 域名与 SSL 管理

### 在管理后台配置

1. **添加域名**：管理后台 → 域名与SSL → 添加域名
   - 选择目标类型：管理后台 / 后端API / WebSocket / 全部
   - 设置监听端口（0=默认80/443，>0=指定端口，支持前后端分开端口）
   - 设置后端目标地址（支持 IP+端口 直连）

2. **申请 SSL 证书**：点击「申请SSL」自动申请 Let's Encrypt 免费证书

3. **部署 Nginx**：点击「部署」自动生成 Nginx 配置并重载
   - 支持 IP 访问与域名访问共存
   - 支持 HTTP 与 HTTPS 同时访问
   - 支持强制 HTTPS 开关（开启后 HTTP 自动 301 跳转 HTTPS）

4. **自动续费**：开启「自动续费」开关，cron 每天凌晨 3 点自动检查，30 天内到期自动续费

## APP 使用说明

1. 安装 APK 后打开 APP
2. 在登录页输入推送 Key 和服务器地址
3. APP 自动建立 WebSocket 长连接，启动五层保活机制
4. 消息通过系统通知栏实时显示，锁屏页面可见
5. 底部三个 Tab 切换功能：
   - **消息推送**：查看推送记录和连接状态
   - **音频播放**：播放云端或本地音频，提升进程保活能力
   - **用户中心**：查看连接信息、管理权限、复制设备信息

## 常用运维命令

```bash
# 查看服务状态（HTTP + WebSocket）
sudo systemctl status push-http push-websocket

# 查看实时日志
sudo journalctl -u push-websocket -f          # WebSocket 推送服务
sudo journalctl -u push-http -f               # HTTP API 服务

# 重启服务
sudo systemctl restart push-http push-websocket

# 更新代码（默认回车即开始更新）
cd /www/push-system && bash backend/deploy/update.sh

# 回滚代码
cd /www/push-system && bash deploy/rollback.sh

# 交互式管理菜单
cd /www/push-system && bash manage.sh

# 查看 WebSocket 调试日志（含 ping/pong、设备状态）
sudo tail -50 /www/push-system/backend/runtime/logs/ws_debug.log

# 查看 APP 构建日志（替换 <build_id>）
cat /www/push-system/build/logs/<build_id>.log

# 查看 SSL 证书自动续费日志
cat /var/log/push-ssl-renew.log

# 检查服务器与云端版本是否一致
cd /www/push-system && bash backend/deploy/check-version.sh
```

## 故障排查

### APP 掉线频繁

1. 检查 APP 是否在电池优化白名单中（设置 → 权限管理）
2. 小米手机需开启自启动、省电策略设为无限制
3. 查看服务器日志中 ping/pong 记录：`grep "收到客户端 pong" ws_debug.log`
4. 确认 APP 已开启通知权限（前台服务依赖通知栏）

### APP 通知栏不显示

1. 检查通知权限是否开启（设置 → 通知）
2. 小米手机检查「锁屏显示」权限
3. 确认通知渠道未被禁用

### 端口 9501/9502 被占用

```bash
# 查看占用进程
sudo lsof -i :9501
sudo lsof -i :9502

# 重启服务（systemd 会自动清理旧进程）
sudo systemctl restart push-http push-websocket
```

### git pull 失败：Permission denied

```bash
# 恢复权限后 pull
sudo chown -R ubuntu:ubuntu /www/push-system
git pull origin main
```

### 管理后台无法登录（500 错误）

```bash
# 检查服务状态
sudo systemctl status push-http

# 查看日志
sudo journalctl -u push-http -f --since "10 minutes ago"

# 检查数据库连接
cd /www/push-system/backend && php -r "new PDO('mysql:host=127.0.0.1', 'im_push', 'YourPass');"
```

## 数据库迁移演进

| 版本 | 文件 | 内容 |
|------|------|------|
| 001 | `001_init.sql` | 初始化 9 张表（users/admins/push_keys/devices/messages/blacklists/push_logs/admin_logs/api_keys） |
| 002 | `002_add_notify_fields.sql` | 通知相关字段 |
| 003 | `003_add_admin_settings.sql` | 管理员设置 |
| 004 | `004_admin_login_logs.sql` | 管理员登录日志 |
| 005 | `005_domains.sql` | 域名表 |
| 006 | `006_domains_extend.sql` | 域名表扩展 |
| 007 | `007_users_security_code.sql` | 用户安全码 |
| 008 | `008_apk_distribution.sql` | APK 分发记录 |
| 009 | `009_audio_files.sql` | 音频文件表 |
| 010 | `010_domains_force_https.sql` | 域名强制 HTTPS |
| 011 | `011_push_message_unlimited.sql` | 推送消息无字数限制（TEXT/MEDIUMTEXT） |

## 默认账号

| 角色 | 账号 | 密码 |
|------|------|------|
| 管理员 | admin | admin123 |

> 部署后请尽快修改默认密码！

## 许可证

MIT License
