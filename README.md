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

### 管理功能
- **管理后台** - Vue3 + Element Plus，美观易用
- **消息导出** - 支持导出推送记录和消息记录（CSV/JSON）
- **测试推送** - 内置调试推送功能，方便开发排查
- **分页处理** - 列表分页展示，每页 10 条

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
| 邮件服务 | PHPMailer | SMTP 邮件发送（QQ 邮箱等） |

## 快速开始

### 一键部署（国内服务器）

```bash
# 方式1: 直接从仓库部署（推荐）
curl -sSL https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh | bash

# 方式2: 先克隆再部署
git clone https://github.com/jiujiu123520/im-push-system.git
cd im-push-system
sudo bash deploy/deploy.sh
```

### 自定义部署参数

```bash
sudo bash deploy/deploy.sh \
  --project-dir=/www/push-system \
  --domain=push.example.com \
  --db-pass=YourPassword@2024 \
  --http-port=9501 \
  --ws-port=9502
```

### 跳过 APP 构建环境

```bash
sudo bash deploy/deploy.sh --skip-app-build
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
├── app/                      # Android APP
│   └── src/main/java/com/push/app/
│       ├── data/             # 数据层（WebSocket、存储）
│       ├── keepalive/        # 后台保活
│       ├── service/          # 推送服务、通知
│       ├── ui/               # Compose UI
│       └── util/             # 工具类
├── build/                    # APP 构建脚本
├── deploy/                   # 部署脚本
│   ├── deploy.sh             # 一键部署（国内服务器）
│   ├── install.sh            # 安装脚本
│   ├── update.sh             # 更新脚本
│   ├── rollback.sh           # 回滚脚本
│   ├── nginx/                # Nginx 配置
│   └── systemd/              # systemd 服务文件
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
# 查看服务状态
systemctl status push-http push-websocket push-build-worker

# 查看实时日志
journalctl -u push-websocket -f

# 重启服务
systemctl restart push-http push-websocket

# 更新代码
cd /www/push-system && bash deploy/update.sh

# 回滚代码
cd /www/push-system && bash deploy/rollback.sh

# 构建 Android APP
cd /www/push-system && bash build/build_apk.sh
```

## 默认账号

| 角色 | 账号 | 密码 |
|------|------|------|
| 管理员 | admin | admin123 |

> 部署后请尽快修改默认密码！

## 许可证

MIT License