# IM Push System - 即时消息推送系统

> 基于 PHP + Swoole 的实时消息推送平台，支持 WebSocket 长连接、设备掉线邮箱通知、API 对接推送、Android APP 后台保活。

## 系统架构

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│  Android APP │◄──►│  WebSocket   │◄──►│   HTTP API   │
│  (Kotlin)    │     │  (Swoole)    │     │  (Swoole)    │
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
- **心跳保活** - 客户端自定义心跳间隔（10-300 秒）

### 通知功能
- **设备掉线通知** - 设备断开连接自动发送邮件通知
- **QQ 邮箱支持** - 支持 QQ 邮箱、QQ 企业邮箱等 SMTP 服务
- **多邮箱推送** - 支持配置多个收件邮箱（逗号分隔）
- **通知间隔控制** - 避免频繁通知，可自定义间隔时间

### 安全功能
- **验证码加密** - 注册/登录验证码 AES 加密传输
- **设备指纹** - 记录设备 IP、UA、指纹，支持拉黑
- **黑名单管理** - 按用户/设备/IP 维度拉黑，实时断连
- **管理员鉴权** - JWT Token 鉴权，支持多角色权限
- **安全码** - 注册时自动生成 8 位数字安全码，用于忘记密码时重置
- **登录失败限制** - 管理员登录失败次数限制（Redis 计数，默认 5 次锁定 30 分钟）

### 管理功能
- **管理后台** - Vue3 + Element Plus，美观易用
- **消息导出** - 支持导出推送记录和消息记录（CSV/JSON）
- **测试推送** - 内置调试推送功能，方便开发排查
- **分页处理** - 列表分页展示，每页 10 条
- **用户管理** - 管理员可修改用户信息、重置密码、切换状态
- **APK 分发** - 构建后自动生成分发记录，支持自托管下载/蓝奏云/自定义上传，二维码下载

### Android APP
- **Kotlin + Compose** - 最新 Jetpack Compose UI
- **后台保活** - WorkManager 前台服务 + 自启动
- **通知栏消息** - 系统通知栏实时显示推送消息
- **消息历史** - 本地存储消息记录，支持查看

## 技术栈

| 模块 | 技术 | 说明 |
|------|------|------|
| 后端 | PHP 8.2 + Swoole 5.x | WebSocket/HTTP 双服务 |
| 数据库 | MySQL 8.0 | 数据持久化 |
| 缓存 | Redis 7.x | 连接映射、离线消息、通知间隔 |
| 反向代理 | Nginx | HTTP/WebSocket 反向代理 |
| 管理后台 | Vue3 + Element Plus + Vite | 响应式管理界面 |
| Android APP | Kotlin + Jetpack Compose | 原生 Android 应用 |
| APP 打包 | GitHub Actions | 在 GitHub 云端打包，无需服务器资源 |
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

### 分步安装（已有代码）

```bash
# 1. 核心服务安装（需要 root）
sudo bash deploy/install.sh

# 2. 单独安装 SSL 证书环境（可选，需要 root）
sudo bash backend/deploy/ssl/setup-acme.sh
sudo cp deploy/sudoers-push-system-ssl /etc/sudoers.d/push-system
sudo chmod 440 /etc/sudoers.d/push-system
sudo visudo -c

# 3. 安装 SSL 自动续费 cron
echo "0 3 * * * root /www/push-system/backend/deploy/ssl/auto-renew-cron.sh" | sudo tee /etc/cron.d/push-ssl-renew
sudo chmod 644 /etc/cron.d/push-ssl-renew
```

### 日常更新（无需 root）

安装完成后，日常代码更新使用更新脚本即可，无需 root 权限：

```bash
cd /www/push-system
bash backend/deploy/update.sh
```

> 更新脚本会自动拉取代码、安装依赖、执行数据库迁移、构建前端、重启服务。
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

## APP 打包（GitHub Actions）

APP 打包已完全迁移到 GitHub Actions，在 GitHub 云端构建，无需在服务器安装 JDK/Android SDK/Gradle，节省服务器资源。

### 配置步骤

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
│   │   ├── HttpServer.php    # HTTP API 服务
│   │   ├── WebSocketServer.php # WebSocket 推送服务
│   │   └── Router.php        # 路由器
│   ├── config/               # 配置文件
│   ├── database/             # 数据库迁移脚本
│   ├── public/               # 入口文件
│   └── .env.example          # 环境变量示例
├── admin/                    # 管理后台
│   ├── src/
│   │   ├── api/              # API 接口定义
│   │   ├── views/            # 页面组件
│   │   ├── layout/           # 布局组件
│   │   ├── router/           # 路由配置
│   │   └── stores/           # 状态管理
│   └── vite.config.ts        # Vite 构建配置
├── app/                      # Android APP 源码
│   └── src/main/java/com/push/app/
│       ├── data/             # 数据层（WebSocket、存储）
│       ├── keepalive/        # 后台保活
│       ├── service/          # 推送服务、通知
│       ├── ui/               # Compose UI
│       └── util/             # 工具类
├── build/                    # APP 构建脚本
│   ├── build_apk.sh          # APK 打包脚本（由 GitHub Actions 调用）
│   ├── inject_config.sh      # 配置注入脚本（包名、服务器地址等）
│   ├── queue/                # 构建队列管理
│   │   └── BuildQueue.php    # 队列管理（Redis List + Hash + Sorted Set）
│   ├── logs/                 # 构建日志
│   └── output/               # APK 产物输出目录
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
│   └── systemd/              # systemd 服务文件（push-http/push-websocket）
└── .gitignore
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

## APK 分发管理

APP 构建成功后，系统自动创建分发记录，支持三种分发方式：

### 分发方式

| 方式 | 说明 | 文件大小限制 | 是否需要额外配置 |
|------|------|------------|----------------|
| **自托管下载** | APK 存储在服务器，通过 Nginx 直接提供下载 | 无限制 | 无需配置，默认启用 |
| **蓝奏云上传** | 自动上传到蓝奏云并生成分享链接 | 100MB | 需配置蓝奏云 Cookie |
| **自定义上传** | 调用自定义脚本上传到任意存储服务 | 无限制 | 需配置上传脚本 |

### 使用流程

1. **构建 APP**：在管理后台「APP 生成」页面提交打包任务
2. **自动创建分发记录**：构建成功后，系统自动在「APK 分发」页面创建分发记录
3. **下载/分享**：
   - **自托管下载**：点击「下载」按钮直接下载，或点击「二维码」生成扫码下载链接
   - **蓝奏云**：点击「上传蓝奏云」按钮，自动上传并生成分享链接
   - **自定义上传**：点击「自定义上传」按钮，执行配置的上传脚本

### 分发设置

在「APK 分发」页面点击右上角「分发设置」按钮：

| 配置项 | 说明 |
|--------|------|
| 启用自动分发 | 开关，构建成功后是否自动创建分发记录 |
| 蓝奏云 Cookie | 从浏览器开发者工具获取的蓝奏云登录 Cookie |
| 自定义上传脚本路径 | 可执行脚本的绝对路径（如 /www/push-system/deploy/apk/custom-upload.sh） |
| 下载基础 URL | 用于生成完整下载链接（留空则使用当前访问域名） |

### 获取蓝奏云 Cookie

1. 在浏览器登录蓝奏云（https://pan.lanzou.com）
2. 按 F12 打开开发者工具 → Network 面板
3. 刷新页面，找到任意请求 → Headers → Cookie
4. 复制完整 Cookie 值，粘贴到分发设置中

### 自定义上传脚本

参考 `deploy/apk/custom-upload-example.sh`，复制为 `custom-upload.sh` 并修改上传逻辑：

```bash
# 复制示例脚本
cp deploy/apk/custom-upload-example.sh deploy/apk/custom-upload.sh
chmod +x deploy/apk/custom-upload.sh

# 编辑脚本，实现你的上传逻辑（如上传到阿里云 OSS、腾讯 COS、七牛云等）
vim deploy/apk/custom-upload.sh
```

脚本约定：
- 参数：`<apk_path> <build_id> <app_name>`
- 输出：第一行为上传后的 URL（http/https 开头），后续行可选

### 公开下载链接

每个分发记录生成一个带 token 的公开下载链接（无需登录）：

```
https://your-domain.com/api/apk-distribution/download/{token}
```

此链接可直接用于二维码分享或发送给用户下载。

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

### 3. 获取 QQ 邮箱授权码

1. 登录 QQ 邮箱 → 设置 → 账户
2. 找到"POP3/SMTP 服务" → 开启
3. 按提示发送短信 → 获取授权码
4. 使用授权码作为 SMTP 密码

## APP 使用说明

1. 安装 APK 后打开 APP
2. 在首页输入推送 Key
3. 点击连接，APP 自动建立 WebSocket 长连接
4. 消息通过系统通知栏实时显示
5. 支持在设置中自定义心跳间隔

## 常用运维命令

```bash
# 查看服务状态（HTTP + WebSocket）
sudo systemctl status push-http push-websocket

# 查看实时日志
sudo journalctl -u push-websocket -f          # WebSocket 推送服务
sudo journalctl -u push-http -f               # HTTP API 服务

# 重启服务
sudo systemctl restart push-http push-websocket

# 更新代码（一键更新：代码 + 依赖 + 迁移 + 权限 + 服务重启）
cd /www/push-system && bash backend/deploy/update.sh --yes

# 回滚代码
cd /www/push-system && bash deploy/rollback.sh

# 交互式管理菜单
cd /www/push-system && bash manage.sh

# 查看 APP 构建日志（替换 <build_id>）
cat /www/push-system/build/logs/<build_id>.log

# 查看 GitHub Actions 构建历史
# 访问 GitHub 仓库 → Actions 页面

# 查看 SSL 证书自动续费日志
cat /var/log/push-ssl-renew.log
```

## 域名与 SSL 管理

### 在管理后台配置

1. **添加域名**：管理后台 → 域名与SSL → 添加域名
   - 选择目标类型：管理后台 / 后端API / WebSocket / 全部
   - 设置监听端口（0=默认80/443，>0=指定端口，支持前后端分开端口）
   - 设置后端目标地址（支持 IP+端口 直连）

2. **申请 SSL 证书**：点击「申请SSL」自动申请 Let's Encrypt 免费证书
   - 需先在 DNS 解析域名到服务器 IP
   - 需 80 端口可被外网访问（ACME 验证用）

3. **部署 Nginx**：点击「部署」自动生成 Nginx 配置并重载
   - 每个域名独立 server 块
   - 前端和后端可绑定不同域名和端口

4. **自动续费**：开启「自动续费」开关
   - cron 每天凌晨 3 点自动检查
   - 30 天内到期自动续费

### 命令行操作

```bash
# 安装 SSL 环境
sudo bash /www/push-system/backend/deploy/ssl/setup-acme.sh

# 配置 sudoers
sudo cp /www/push-system/deploy/sudoers-push-system-ssl /etc/sudoers.d/push-system
sudo chmod 440 /etc/sudoers.d/push-system && sudo visudo -c

# 安装自动续费 cron
echo "0 3 * * * root /www/push-system/backend/deploy/ssl/auto-renew-cron.sh" | sudo tee /etc/cron.d/push-ssl-renew
sudo chmod 644 /etc/cron.d/push-ssl-renew
```

## 故障排查

### APP 构建失败

APP 打包已迁移到 GitHub Actions，请查看：

1. **GitHub Actions 运行日志**：访问 GitHub 仓库 → Actions 页面，点击对应 workflow 查看详细日志
2. **管理后台构建状态**：「APP 生成」页面查看构建任务状态
3. **服务器端日志**：`cat /www/push-system/build/logs/<build_id>.log`

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

## 默认账号

| 角色 | 账号 | 密码 |
|------|------|------|
| 管理员 | admin | admin123 |

> 部署后请尽快修改默认密码！

## 许可证

MIT License
