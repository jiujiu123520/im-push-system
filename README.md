# IM Push System - 即时消息推送系统

> 基于 PHP + Swoole 的实时消息推送平台，支持 WebSocket 长连接、iOS APNs 离线推送、Android 深度保活、敏感字段 AES-256-CBC 加密。

## 系统架构

```
┌─────────────┐  ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Android APP │  │  iOS APP    │     │  WebSocket   │◄──►│   HTTP API   │
│  (uni-app)   │  │  (iOS 原生) │     │  (Swoole)    │     │  (Swoole)    │
└──────┬───────┘  └──────┬──────┘     └──────┬──────┘     └──────┬──────┘
       │  WebSocket       │  APNs              │                    │
       │  (前台/在线)     │  (后台/离线)        │                    │
       └────────┬─────────┘                    │                    │
                │                    ┌─────────┴─────┐     ┌──────┴──────┐
                │                    │    Redis       │     │   MySQL     │
                │                    │ 连接映射/离线  │     │ (数据持久化) │
                │                    └─────────┬─────┘     └─────────────┘
                │                              │
                └──────────────────────►┌──────┴──────┐
                                        │   Nginx     │
                                        │ (反向代理)   │
                                        └─────────────┘
```

## 功能特性

### 核心功能
- **实时推送** - WebSocket 长连接，毫秒级消息送达
- **iOS APNs 推送** - APP 被系统清理/网络断开时自动走 Apple Push Notification service 通道，服务端根据设备在线状态自动选择 WebSocket 或 APNs
- **Key 订阅推送** - 一个 Key 多人订阅，管理后台 Key 列表显示「订阅总数/在线设备数/最大设备数」实时统计，支持单人/多人/批量推送
- **离线消息** - 设备离线时消息存储 Redis，上线自动补发
- **双向心跳** - 服务端主动 ping + 客户端主动 ping，pong 携带时间戳、设备状态、在线连接数，支持 RTT 网络延迟计算
- **僵尸连接巡检** - 每 30 秒检测 Redis 在线但实际已断开的连接并清理
- **推送消息无字数限制** - title 字段 TEXT 类型、content 字段 MEDIUMTEXT 类型
- **真实客户端 IP** - Nginx `X-Real-IP` 透传，设备记录的 IP 不再是 127.0.0.1，管理后台和订阅设备明细可直接查看公网/内网真实 IP
- **推送错误可追溯** - `push_logs` 记录 `err_code`（Swoole 底层错误码）、`fail_reason` 详细文本、`payload_size` 消息体大小，后台推送记录页可直接查看

### APP 功能（HBuilderX uni-app 版本）
- **消息推送** - 实时接收推送消息，统计卡片（今日/累计/设备ID），**消息记录列表分页加载**（APP 端分页查询，避免大量消息卡顿）
- **音频播放器** - 云端音频 + 本地音频双列表，支持列表循环/单曲循环/播放一次三种模式
- **用户中心** - 用户信息卡片、连接信息（推送Key/服务器/RTT延迟/连接状态）、权限管理、设备信息、清除缓存、复制设备信息
- **掉线提醒增强** - 掉线时 Toast + 顶部橙色提醒条，**显示上次掉线时间、累计在线时长、上次重连耗时、重连进度百分比**；重连成功后弹出 Toast 显示「本次离线时长 xx:xx:xx」
- **锁屏保活** - 五层保活机制确保锁屏后连接不中断（前台服务 + WakeLock + AlarmManager 备用心跳 + WifiLock + 电池优化白名单）
- **锁屏通知** - 推送消息在锁屏页面直接显示，支持全屏 Intent、CATEGORY_MESSAGE、VISIBILITY_PUBLIC
- **通知栏媒体控制** - 通知栏显示播放器控件，支持上一首/播放暂停/下一首

### 订阅设备管理（管理后台）
- **订阅设备明细弹窗** - Key 列表点击「订阅设备」按钮查看明细弹窗，顶部统计卡片显示「订阅总数 / 在线数 / 僵尸订阅（设备已在 DB 删除但仍在 Redis 订阅中）」
- **删除订阅设备** - 支持单个删除（列表操作栏按钮）；删除时同步移除 Redis 订阅映射、关闭对应 WebSocket 连接，Key 订阅数实时刷新
- **搜索与筛选** - 按设备 ID 搜索、按在线状态（在线/离线）筛选、按平台筛选（Android/iOS/Web）、僵尸订阅一键清理
- **设备维度字段** - 显示平台、APP 版本、设备品牌/型号/系统版本、**真实 IP 地址**、最后活跃时间
- **iOS APNs 状态** - iOS 设备额外显示 APNs 是否注册成功

### 通知功能
- **设备掉线通知** - 设备断开连接自动发送邮件通知，邮件内容包含**掉线时间、累计在线时长、上次成功重连时间、设备 IP、平台、APP 版本**
- **QQ 邮箱支持** - 支持 QQ 邮箱、QQ 企业邮箱等 SMTP 服务
- **多邮箱推送** - 支持配置多个收件邮箱（逗号分隔）
- **通知间隔控制** - 避免频繁通知，可自定义间隔时间（Key 级别，默认 300 秒）
- **SMTP 密码加密存储** - SMTP 授权码在数据库中以 AES-256-CBC 加密，带 `ENC:` 前缀标识，运行时透明解密

### 安全功能
- **敏感字段 AES-256-CBC 加密** - SMTP 密码等敏感字段自动加密存储，密钥由 `.env` 的 `AES_KEY` 控制；首次安装时自动生成 64 位十六进制密钥
- **加密迁移脚本** - `backend/bin/migrate_encrypt.php` 支持对已有明文数据进行就地加密（支持 `--dry-run` 预演）和密钥轮换
- **管理后台路径混淆** - 可在系统设置中将 `/admin` 改成任意自定义路径，IP 直接访问根路径自动跳转到用户端
- **用户注册** - 用户可通过注册页自助注册账号（手机号/邮箱验证码）
- **忘记密码** - 通过 8 位数字安全码重置密码
- **验证码加密** - 注册/登录验证码 AES 加密传输
- **图形验证码开关** - 后台可控制登录是否需要图形验证码
- **设备指纹** - 记录设备 IP、UA、指纹，支持拉黑
- **黑名单管理** - 按用户/设备/IP 维度拉黑，实时断连
- **管理员鉴权** - JWT Token 鉴权，支持多角色权限
- **登录失败限制** - 管理员登录失败次数限制（Redis 计数，默认 5 次锁定 30 分钟）
- **响应头加固** - 自动设置 `X-Frame-Options`、`X-Content-Type-Options`、`Referrer-Policy`、CORS 白名单

### 管理功能
- **管理后台** - Vue3 + Element Plus + Vite + TypeScript，美观易用，路径支持随机生成
- **用户端独立域名** - 用户端前端可绑定独立域名，管理后台和用户端完全隔离
- **消息导出** - 支持导出推送记录和消息记录（CSV/JSON）
- **测试推送** - 内置调试推送功能，方便开发排查
- **用户管理** - 管理员可修改用户信息（昵称/头像/QQ）、重置密码、切换状态
- **音频管理** - 后台上传音频文件，APP 端自动同步播放列表
- **域名与 SSL** - 独立绑定域名、端口访问、自动申请/续费 Let's Encrypt 证书
- **APK 分发** - 构建后自动生成分发记录，支持自托管下载/小飞机网盘（CDN 直链解析+2小时缓存）/自定义上传，二维码下载 + 下载次数/IP/UA 统计
- **APK 云端构建** - GitHub Actions 自动打包，无需服务器安装 JDK/SDK

## 技术栈

| 模块 | 技术 | 说明 |
|------|------|------|
| 后端 | PHP 8.3 + Swoole 6.x | WebSocket/HTTP 双服务 |
| 数据库 | MySQL 8.0 | 数据持久化（utf8mb4） |
| 缓存 | Redis 7.x | 连接映射、离线消息、通知间隔、在线计数 |
| 反向代理 | Nginx | HTTP/WebSocket 反向代理，支持 IP/域名共存 |
| 管理后台 | Vue3 + Element Plus + Vite + TypeScript | 响应式管理界面，路径支持混淆 |
| 用户端 | Vue3 + Vite + TypeScript | 独立前端，支持自定义域名 |
| Android APP | HBuilderX uni-app (Vue 3) + plus.android 原生 API | 主推版本 |
| 邮件服务 | PHPMailer | SMTP 邮件发送（密码 AES 加密存储） |
| 推送通道 | WebSocket + APNs | Android 走 WebSocket；iOS 在线 WebSocket、离线 APNs |
| 加密 | AES-256-CBC (OpenSSL) | SMTP 密码等敏感字段加密存储 |

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
| 2 | 更新代码 | 拉取最新代码 + 依赖 + 数据库迁移 + 前端构建 + 重启服务 |
| 3 | 重启服务 | 单独重启 HTTP/WebSocket/Nginx/MySQL/Redis |
| 4 | 查看服务状态 | 服务状态 + 磁盘/内存/Git 版本 + 实时日志 |
| 5 | 清理缓存 | NPM/Composer/系统缓存等 |
| 6 | 修改环境配置 | 端口/数据库/Redis/邮件/GitHub Actions 配置 |
| 7 | 构建前端 | npm install + npm run build（管理端 + 用户端） |
| 8 | 生成 APP 签名 keystore | 生成 release.keystore 用于 APP 签名 |
| 9 | 回滚代码 | 回滚到上次更新前或指定 commit |
| 10 | 卸载环境(保留源码) | 停止服务 + 卸载 PHP/MySQL/Redis/Nginx 等 |
| 11 | 卸载源码(保留环境) | 删除项目目录,保留运行环境 |
| 12 | 完全卸载 | 彻底清除环境 + 源码 + 数据库 |

### 一键部署（独立脚本推荐）

#### 方式 0：独立一键脚本 quick-deploy.sh（安装/更新通用，**强烈推荐**）

`quick-deploy.sh` 是完全自包含的独立脚本，无需提前克隆项目，**下载后可直接离线运行**，自动支持以下增强：
- ✅ 6 种 Linux 发行版自动适配（Ubuntu/Debian/CentOS/RHEL/Alpine/openSUSE/Arch）
- ✅ 国内服务器自动加速（GitHub 代理、Composer 阿里云镜像、npmmirror、CentOS 7 vault 源）
- ✅ Swoole 编译 3 次降级（完整→禁 brotli→最小化）+ pecl 兜底
- ✅ 断点续装（`--resume` 从失败步骤继续，`--restart` 强制重来）
- ✅ 前端构建降级（TypeScript 类型检查失败自动降级 vite build + NODE_OPTIONS 2048MB 内存）
- ✅ 自定义项目目录（非 `/www/push-system`）自动替换所有 systemd/Nginx 路径
- ✅ 安装完成自动生成 AES_KEY 并加密 SMTP 密码

```bash
# ====== 国内服务器（一键命令，自动使用 gh-proxy 代理）======
curl -sSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh -o /tmp/deploy.sh && sudo bash /tmp/deploy.sh --gh-proxy

# ====== 可直接下载 quick-deploy.sh 后离线运行（任意目录执行均可）======
curl -sSL -o /tmp/quick-deploy.sh https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/quick-deploy.sh
sudo bash /tmp/quick-deploy.sh --install --yes --gh-proxy

# ====== 自定义项目目录 + 自定义参数 ======
sudo bash /tmp/quick-deploy.sh \
  --project-dir=/data/my-push-system \
  --db-pass=YourStrongPassword@2024 \
  --http-port=9501 \
  --ws-port=9502 \
  --gh-proxy --yes
```

#### 方式 1：先克隆再部署（开发场景）

```bash
git clone https://github.com/jiujiu123520/im-push-system.git
cd im-push-system
# 国内服务器建议加 --gh-proxy
sudo bash deploy/quick-deploy.sh --install --yes --gh-proxy
```

> 注意：`curl | bash` 管道模式下无法自动 sudo 提权，请使用 `curl -o /tmp/*.sh && sudo bash /tmp/*.sh` 方式。

> ⚠️ **架构变更说明**：APP 打包已迁移到 **GitHub Actions 云端构建**（见 `.github/workflows/build-apk.yml`），**无需在服务器安装 JDK/Android SDK/Gradle**。原服务器端 BuildWorker 服务已废弃，部署脚本检测到旧服务会自动停止并卸载。

### 部署脚本参数说明（quick-deploy.sh / backend/deploy/update.sh）

| 参数 | 说明 | 示例 |
|------|------|------|
| `--install` | 首次安装模式（触发完整 install.sh 流程） | `--install` |
| `--yes` / `-y` | 跳过所有确认提示（默认回车即继续） | `--yes` |
| `--gh-proxy` | 启用 GitHub 国内加速代理（gh.jasonzeng.dev），国内服务器必加 | `--gh-proxy` |
| `--project-dir=PATH` | 自定义项目安装目录（默认 `/www/push-system`），自动替换 systemd/Nginx 路径 | `--project-dir=/data/apps/push` |
| `--http-port=PORT` | 自定义 HTTP API 端口（默认 9501） | `--http-port=8080` |
| `--ws-port=PORT` | 自定义 WebSocket 端口（默认 9502） | `--ws-port=8081` |
| `--db-pass=PASSWORD` | 自定义 MySQL im_push 用户密码（默认随机生成） | `--db-pass=Pass@2024` |
| `--db-root-pass=PASSWORD` | 自定义 MySQL root 临时密码 | `--db-root-pass=Root@2024` |
| `--skip-build` | 跳过前端构建和后端依赖安装（仅拉代码+迁移+重启，快速纯后端小版本更新） | `--skip-build` |
| `--skip-frontend` | 仅跳过前端构建 | `--skip-frontend` |
| `--skip-migration` | 跳过数据库迁移（已确认无新增迁移文件时使用） | `--skip-migration` |
| `--force-update` | 强制 git pull 覆盖本地修改（放弃本地所有变更） | `--force-update` |
| `--resume` | 断点续装：从上次失败步骤继续（update.sh 专用，记录于 `.update-progress`） | `--resume` |
| `--restart` | 清除断点续装进度，强制从头开始更新 | `--restart` |
| `--no-resume` | 本次更新不使用断点，不影响进度文件 | `--no-resume` |
| `--verbose` / `--debug` | 显示详细执行日志 | `--verbose` |

完整示例：
```bash
# 国内服务器，首次安装，所有参数自定义，无需交互
sudo bash /tmp/quick-deploy.sh --install --yes --gh-proxy \
  --project-dir=/data/my-push \
  --http-port=8080 --ws-port=8081 \
  --db-pass='MyStrongPass@2024'

# 日常更新后端代码（无前端变动），跳过构建，强制覆盖本地修改
cd /www/push-system
bash backend/deploy/update.sh --yes --skip-build --skip-migration --force-update

# 更新失败后断点续装
bash backend/deploy/update.sh --resume --verbose
```

### 日常更新（3 种方式任选）

安装完成后，日常代码更新使用以下任意方式即可：

```bash
# ====== 方式 1：增量更新脚本（推荐，支持断点续装）======
cd /www/push-system
bash backend/deploy/update.sh
# 或国内服务器加速 + 跳过确认 + 强制重建前端（默认不跳过）
bash backend/deploy/update.sh --yes --gh-proxy --restart

# ====== 方式 2：独立 quick-deploy.sh 更新模式（无需 cd 到项目目录）======
sudo bash /tmp/quick-deploy.sh --yes --gh-proxy --project-dir=/www/push-system

# ====== 方式 3：交互式菜单（可视化操作）======
cd /www/push-system && bash manage.sh   # 选 2 更新代码
```

> 更新脚本默认回车即开始更新（输入 n 才取消），自动：
> 1. 清理残留的 `.deploy/push-update-progress.env` 防止跨次污染
> 2. 备份后端 + Git 快照
> 3. 拉取最新代码（支持 `--force-update` 覆盖本地）
> 4. Composer/npm 依赖安装（国内镜像）
> 5. 前端构建（管理端 + 用户端，TypeScript 类型检查失败自动降级 vite build + 2G 内存）
> 6. 数据库迁移（001-021，已执行过的迁移通过 `record_if_applied` 智能补录）
> 7. 修复运行时权限（仅 storage/runtime/build 目录，不破坏 .git）
> 8. 更新 systemd/Nginx 配置（适配自定义项目目录和 PHP 路径）
> 9. 重启 HTTP/WebSocket/Nginx 服务

### 安装流程（9 步 = 7 步核心 + 2 步可选）

`deploy/install.sh` 首次安装步骤：

| 步骤 | 内容 | 必选 | 失败自动处理 |
|------|------|------|-------------|
| [1/9] | 检测发行版 → 安装系统依赖（PHP 8.3 + Swoole 6.x + MySQL 8.0 + Redis 7 + Nginx） | 是 | Swoole 3 次编译降级（完整→禁 brotli→最小化+OpenSSL），再失败走 pecl 兜底；CentOS 7 自动切 vault 源 + openssl11 + devtoolset-11 |
| [2/9] | 创建项目目录与 MySQL 数据库 | 是 | MySQL 初次启动失败自动移除 ib_logfile 重建；密码随机生成或使用 `--db-pass` |
| [3/9] | 克隆代码或复制本地代码 | 是 | 自动 Git 代理、HTTPS 凭证缓存 |
| [4/9] | 后端依赖安装（Composer） | 是 | 国内阿里云镜像；自动关闭 audit 阻断 |
| [5/9] | 构建管理后台 + 用户端（npm install + npm run build） | 是 | npmmirror 镜像；NODE_OPTIONS=--max-old-space-size=2048；vue-tsc 失败自动降级 vite build |
| [6/9] | 配置 systemd 服务（push-http / push-websocket）与 Nginx | 是 | 自动检测 Web 用户（www-data/nginx/http）；动态替换 User/Group/项目路径；systemd < 227 移除 cgroup 限制 |
| [7/9] | 启动服务并通过端口验证；自动生成 AES_KEY | 是 | 启动失败自动抓取 journalctl 最后 30 行报错；Nginx 语法失败自动修复 |
| [8/9] | 安装 SSL 证书环境（acme.sh + 自动续费 cron 每天 3 点检查） | 可选 | — |
| [9/9] | 配置 sudoers 权限（允许 Web 用户重启服务/Nginx/证书申请） | 可选 | — |

## APP 打包

项目支持两种 APP 打包方式：

### 方式一：HBuilderX 云打包（Android 主推版本）

APP 源码位于 `build/hbuilderx/`，基于 uni-app (Vue 3) 开发，使用 HBuilderX 云打包生成 APK。

1. 下载安装 [HBuilderX](https://www.dcloud.io/hbuilderx.html)
2. 打开 `build/hbuilderx/` 目录作为项目
3. 在 HBuilderX 中配置 `manifest.json`（App 图标、模块、权限）
4. 点击「发行」→「原生 App-云打包」→ 选择 Android → 提交

> 也可使用 `build/build_hbuilderx.sh` 脚本生成可导入 HBuilderX 的项目结构。

### 方式二：GitHub Actions 云端构建（iOS 适用）

APP 打包已完全迁移到 GitHub Actions，在 GitHub 云端构建，无需在服务器安装 JDK/Android SDK/Gradle。

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

## iOS APNs 推送配置

推送服务已原生支持 iOS APNs 通道，iOS 设备在 WebSocket 在线时走 WebSocket，离线/被清理时自动切 APNs。

### 前提条件

1. 在 Apple Developer 开启 **Push Notifications** 能力，生成 APNs AuthKey（`.p8` 文件）
2. 记录 AuthKey 的 **Key ID**、**Team ID** 和 **Bundle ID**
3. 将 AuthKey 文件放到服务器：`backend/config/apns/AuthKey_<KeyID>.p8`

### .env 配置

```env
APNS_ENABLED=true
APNS_TEAM_ID=XXXXXXXXXX
APNS_KEY_ID=XXXXXXXXXX
APNS_BUNDLE_ID=com.your.push.app
APNS_AUTH_KEY_PATH=/www/push-system/backend/config/apns/AuthKey_XXXXXXXXXX.p8
```

> 旧版 `.p12` 证书方式已不再推荐，AuthKey (JWT) 方式同时支持推送和 VoIP，且无需每年续订证书。

## 服务器配置建议

### 3-5 人使用（最低配置）

| 项目 | 配置 |
|------|------|
| CPU | 2 核 |
| 内存 | 2 GB |
| 硬盘 | 40 GB SSD |
| 带宽 | 3 Mbps |
| 系统 | Ubuntu 24.04 LTS |

### 10-20 人使用（推荐配置）

| 项目 | 配置 |
|------|------|
| CPU | 4 核 |
| 内存 | 4 GB |
| 硬盘 | 80 GB SSD |
| 带宽 | 5 Mbps |
| 系统 | Ubuntu 24.04 LTS |

## 项目结构

```
im-push-system/
├── backend/                  # 后端服务
│   ├── bin/
│   │   ├── migrate_encrypt.php      # 敏感字段 AES 加密迁移工具（支持 --dry-run）
│   │   ├── start.sh                 # 开发模式快速启动
│   │   ├── stop.sh                  # 开发模式快速停止
│   │   └── update_build_status.php  # 构建回调更新 Redis 状态
│   ├── src/
│   │   ├── Controller/             # 控制器（API 路由处理）
│   │   ├── Middleware/              # 中间件（鉴权、日志、响应头加固）
│   │   ├── Service/                 # 服务层（业务逻辑）
│   │   │   ├── HeartbeatManager.php      # 心跳管理（ping/pong 双向）
│   │   │   ├── ConnectionManager.php     # 连接管理（fd↔device_id 映射）
│   │   │   ├── PushDispatcher.php        # 推送分发（WebSocket → APNs 自动切换）
│   │   │   ├── DeviceOfflineNotifier.php # 设备掉线邮件通知
│   │   │   ├── AesService.php            # AES-256-CBC 加解密
│   │   │   ├── ApnsService.php           # iOS APNs 推送（JWT AuthKey）
│   │   │   ├── SslService.php            # SSL 证书与 Nginx 配置
│   │   │   ├── AudioService.php          # 音频文件管理
│   │   │   ├── CaptchaService.php        # 图形验证码
│   │   │   └── ...
│   │   ├── HttpServer.php           # HTTP API 服务
│   │   ├── WebSocketServer.php      # WebSocket 推送服务
│   │   └── Router.php               # 路由器
│   ├── config/               # 配置文件（database/redis/apns）
│   ├── database/
│   │   ├── migrations/       # 数据库迁移（001-021）
│   │   └── seeders/          # 种子数据
│   ├── deploy/               # 后端运维脚本（更新/版本检查/回滚）
│   │   ├── update.sh         # 增量更新（支持断点续装 + 迁移补录）
│   │   ├── check-version.sh  # 本地与云端版本对比
│   │   └── rollback.sh       # 回滚工具
│   ├── storage/              # 运行时存储（audio/apk/logs）
│   ├── runtime/              # 运行时缓存（必须 www-data:www-data）
│   ├── public/               # 入口文件
│   └── .env.example          # 环境变量示例（含 AES_KEY/Apns_* 配置）
├── admin/                    # 管理后台前端（Vue3 + Element Plus + TS）
│   ├── src/
│   │   ├── api/              # API 接口定义
│   │   ├── views/            # 页面组件
│   │   │   ├── dashboard/    # 仪表盘（含测试推送）
│   │   │   ├── api-keys/     # API Key 管理
│   │   │   ├── audio/        # 音频管理
│   │   │   ├── domains/      # 域名与 SSL
│   │   │   ├── apk-distribution/ # APK 分发（小飞机网盘）
│   │   │   ├── app-build/    # APP 云端构建
│   │   │   ├── settings/     # 系统设置（SMTP/路径混淆/APNs/安全）
│   │   │   └── ...
│   │   ├── layout/           # 布局组件
│   │   ├── router/           # 路由配置
│   │   └── stores/           # Pinia 状态管理
│   └── vite.config.ts        # Vite 构建配置
├── user/                     # 用户端前端（Vue3 + TS，独立域名）
│   ├── src/
│   │   ├── views/            # 登录/注册/找回密码/个人中心
│   │   ├── api/              # API 接口定义
│   │   ├── router/           # 路由配置
│   │   └── stores/           # Pinia 状态管理
│   └── vite.config.ts
├── build/                    # APP 构建相关
│   ├── hbuilderx/            # HBuilderX uni-app 源码（Android 主推）
│   ├── build_apk.sh          # APK 打包脚本（GitHub Actions 调用）
│   ├── inject_config.sh      # 配置注入脚本
│   └── generate_keystore.sh  # 签名生成脚本
├── .github/workflows/
│   └── build-apk.yml         # APK 云端构建 workflow
├── deploy/                   # 部署脚本
│   ├── deploy.sh             # 一键部署入口（自动路由到 quick-deploy.sh）
│   ├── quick-deploy.sh       # 独立一键脚本（安装/更新通用，**推荐**）
│   ├── install.sh            # 首次安装脚本（9 步，Swoole 3 次降级，自动生成 AES_KEY）
│   ├── update.sh             # 更新脚本（顶层转发到 backend/deploy/update.sh）
│   ├── rollback.sh           # 代码回滚
│   ├── uninstall.sh          # 卸载脚本（环境/源码/完全卸载三种模式）
│   ├── nginx/                # Nginx 配置模板
│   ├── systemd/              # systemd 服务文件（push-http / push-websocket）
│   └── apk/                  # APK 分发脚本（小飞机网盘上传）
└── manage.sh                 # 交互式管理菜单（项目根目录入口）
```

## 数据库迁移演进

| 版本 | 文件 | 内容 |
|------|------|------|
| 001 | `001_init.sql` | 初始化 9 张表（users/admins/push_keys/devices/messages/blacklists/push_logs/admin_logs/api_keys） |
| 002 | `002_add_notify_fields.sql` | 通知相关字段 |
| 003 | `003_add_admin_settings.sql` | 管理员设置表（含 mail_config 字段） |
| 004 | `004_admin_login_logs.sql` | 管理员登录日志 |
| 005 | `005_domains.sql` | 域名表 |
| 006 | `006_domains_extend.sql` | 域名表扩展 |
| 007 | `007_users_security_code.sql` | 用户安全码 |
| 008 | `008_apk_distribution.sql` | APK 分发记录 |
| 009 | `009_audio_files.sql` | 音频文件表 |
| 010 | `010_domains_force_https.sql` | 域名强制 HTTPS |
| 011 | `011_push_message_unlimited.sql` | 推送消息无字数限制（TEXT/MEDIUMTEXT） |
| 012 | `012_devices_extend.sql` | 设备表扩展字段（platform/app_version/device_brand/device_model/system_version/manufacturer 等设备信息） |
| 013 | `013_push_logs_extend.sql` | 推送日志扩展字段（fail_reason 失败原因、payload_size 消息体大小） |
| 014 | `014_apk_download_logs.sql` | APK 下载统计：新增 `download_count` 字段 + `apk_download_logs` 表（IP/UA/来源/时间） |
| 015 | `015_apk_distribution_feijii.sql` | APK 分发切换到**小飞机网盘**（feijipan.com），新增 `feijipan_url` / `feijipan_share_id` 字段，保留原 lanzou_* 字段做兼容 |
| 016 | `016_apk_feijii_direct_url.sql` | 小飞机分享页 → CDN 真实直链**懒解析 + 2 小时缓存**，新增 `feijipan_direct_url` / `feijipan_direct_expires` / `feijipan_fetch_count` 字段 |
| 017 | `017_drop_lanzou_fields.sql` | 彻底清理蓝奏云残留字段（lanzou_url / lanzou_password），用存储过程兼容 MySQL 5.7 |
| 018 | `018_apns_support.sql` | iOS **APNS 推送支持**：devices 表新增 `apns_token` / `apns_active` / `apns_bundle_id` / `apns_updated_at` 字段 |
| 019 | `019_fix_users_phone_unique.sql` | 修复 users 表 phone/email 空字符串导致 UNIQUE 冲突：改为 NULL DEFAULT NULL |
| 020 | `020_user_console.sql` | 用户端独立控制台：users 新增 qq 字段；api_keys 新增 user_id 外键；新增 `user_notices` / `user_notice_reads` 表；admin_settings 新增 `settings_paths` / `settings_security` / `settings_user_app` JSON 字段 |
| 021 | `021_users_nickname_avatar.sql` | users 表新增 nickname / avatar 字段，支持用户端个人中心 |

> **迁移执行机制**：所有 21 个迁移脚本都包含幂等检查（存储过程 + `record_if_applied` 补录），在旧库上直接跑 update 不会报重复错误，在全新服务器上跑 install 会完整执行全部迁移。

## 安全加固

### 敏感字段 AES-256-CBC 加密

项目对 SMTP 密码等敏感字段执行加密存储：

- **加密算法**：AES-256-CBC + PKCS7 padding，IV 随机生成并 prepend 到密文
- **标识**：加密值带 `ENC:` 前缀，运行时透明解密，业务代码无感知
- **密钥位置**：`backend/.env` 的 `AES_KEY`（64 位十六进制，install.sh 首次安装时自动生成）
- **迁移补录**：已有明文数据的老服务器，执行 `backend/bin/migrate_encrypt.php` 就地加密

```bash
# 预演（推荐先跑，不写数据）
cd /www/push-system/backend && php bin/migrate_encrypt.php --dry-run

# 正式执行
php bin/migrate_encrypt.php --apply

# 密钥轮换
php bin/migrate_encrypt.php --rotate-key --new-key=xxx
```

### 其他加固

- **响应头**：自动注入 `X-Frame-Options: DENY`、`X-Content-Type-Options: nosniff`、`Referrer-Policy: no-referrer-when-downgrade`
- **CORS**：`.env` 的 `CORS_ALLOWED_ORIGINS` 可配置白名单，非白名单跨域请求自动拒绝
- **LIKE 通配符转义**：所有 SQL LIKE 查询对输入的 `%` `_` `\` 进行转义，防止通配符注入
- **日志脱敏**：推送日志对 token/password/Authorization 等字段自动打码

## 默认账号

| 角色 | 账号 | 密码 |
|------|------|------|
| 管理员 | admin | admin123 |

> 部署后请尽快修改默认密码！

## 常用运维命令

```bash
# ==================== 服务状态与日志 ====================
# 查看服务状态
sudo systemctl status push-http push-websocket

# 查看实时日志
sudo journalctl -u push-websocket -f          # WebSocket 推送服务
sudo journalctl -u push-http -f               # HTTP API 服务
sudo tail -f /www/push-system/backend/runtime/logs/ws_debug.log  # ping/pong 调试

# 重启服务
sudo systemctl restart push-http push-websocket

# ==================== 代码更新（3 种方式） ====================
# 方式1：增量更新 + 断点续装（推荐）
cd /www/push-system && bash backend/deploy/update.sh
# 国内加速 + 跳过确认 + 跳过迁移（确认无新迁移时）+ 强制重启
bash backend/deploy/update.sh --yes --gh-proxy --skip-migration --restart

# 方式2：后端纯小版本更新（无前端无迁移）
bash backend/deploy/update.sh --yes --skip-build --skip-migration

# 方式3：独立一键脚本（无需 cd）
sudo bash /tmp/quick-deploy.sh --yes --gh-proxy --project-dir=/www/push-system

# ==================== 敏感字段加密迁移 ====================
cd /www/push-system/backend

# 预演当前哪些字段是明文、哪些已加密
php bin/migrate_encrypt.php --dry-run

# 就地加密明文字段（SMTP 密码等）
php bin/migrate_encrypt.php --apply

# ==================== 版本检查与回滚 ====================
bash backend/deploy/check-version.sh --verbose   # 对比 commit hash + 迁移数 + 构建时间
bash deploy/rollback.sh                          # 回滚到上次更新前
bash deploy/rollback.sh <commit_hash>            # 回滚到指定 commit

# ==================== 故障速查 ====================
# 健康检查端点
curl http://127.0.0.1:9501/health

# 推送日志失败原因 Top10
mysql -u im_push -p im_push -e "
  SELECT fail_reason, COUNT(*) cnt FROM push_logs
  WHERE fail_reason IS NOT NULL GROUP BY fail_reason ORDER BY cnt DESC LIMIT 10;
"

# 查看 SMTP 配置是否已加密
mysql -u im_push -p im_push -e "
  SELECT id, JSON_EXTRACT(settings_json, '$.mail_config') mc FROM admin_settings LIMIT 1;
"
# mail_config 中 auth_code 应显示 ENC: 前缀
```

## 故障排查

### 管理后台无法登录（500）

```bash
sudo systemctl status push-http
sudo journalctl -u push-http -f --since "10 minutes ago"
cd /www/push-system/backend && php -r "new PDO('mysql:host=127.0.0.1', 'im_push', 'YourPass');"
```

### 管理后台登录跳不过去 / 404

大概率是前端没构建或构建用的旧代码。重新构建：
```bash
cd /www/push-system/admin && npm install && npm run build
# 或通过 manage.sh 选 7
```

### SMTP 发送失败 / Authorization failed

1. 检查管理后台系统设置中的 SMTP 授权码是否正确（不是邮箱登录密码）
2. 如果授权码是**明文存的**（没有 `ENC:` 前缀），执行加密迁移：
   ```bash
   cd /www/push-system/backend && php bin/migrate_encrypt.php --apply
   sudo systemctl restart push-http push-websocket
   ```

### iOS 设备收不到推送

1. `.env` 中 `APNS_ENABLED=true`
2. AuthKey `.p8` 文件路径正确（`APNS_AUTH_KEY_PATH`）
3. Bundle ID、Team ID、Key ID 和 Apple Developer 后台一致
4. iOS APP 前台时走 WebSocket（正常），切后台后应自动走 APNs；可用日志观察 `ApnsService` 输出

### 前端类型检查报错阻断部署

更新脚本已内置降级逻辑。如果仍想纯命令行手动构建：
```bash
# 先试完整构建（含类型检查）
npm run build || npx vite build
# 第二个命令是兜底，跳过类型检查，直接打包
```

### 端口 9501/9502 被占用

```bash
sudo lsof -i :9501
sudo lsof -i :9502
sudo systemctl restart push-http push-websocket   # systemd 会自动清理旧进程
```

### git pull 失败：Permission denied

```bash
sudo chown -R ubuntu:ubuntu /www/push-system
git pull origin main
```

## Swoole 推送错误码参考

推送失败时，**错误详细信息已持久化到 `push_logs.fail_reason` 字段**（迁移 013），后台推送记录页可直接查看。同时 `push_logs.payload_size` 字段记录了消息体大小，便于排查超过 `max_packet_size` 的大消息。

| 错误码 | 常量 | 含义 | 处理方式 |
|--------|------|------|----------|
| 0 | — | push 返回 false（连接刚被关闭，时序竞态） | 无需处理，下次自动跳过 |
| 503 | BAD_REQUEST | fd 存在但 WebSocket 状态非 ACTIVE | **自动清理**僵尸连接 |
| 1001 | SESSION_NOT_EXIST | 连接不存在或已关闭 | **自动清理**连接映射 |
| 1002 | PACKAGE_TOO_LARGE | 数据包超过 max_packet_size（2MB） | 缩减内容或分条发送 |
| 1003 | SEND_BUFFER_FULL | 发送缓冲区满（客户端慢） | 临时问题，无需清理 |
| 1202 | BAD_HOST | 跨 worker 连接不存在 | **自动清理**连接映射 |

## 许可证

MIT License
