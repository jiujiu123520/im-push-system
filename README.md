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

## 核心功能

* **实时推送** - WebSocket 长连接毫秒级送达，消息无字数限制

* **iOS APNs** - 设备离线/被清理时自动切换 Apple 推送通道

* **Key 订阅推送** - 一个 Key 多设备订阅，支持单人/多人/批量推送

* **离线消息** - 离线时消息存 Redis，上线自动补发

* **双向心跳 + 僵尸连接巡检** - 30 秒巡检自动清理死连接

* **设备掉线邮件通知** - 含掉线时间/在线时长/IP，间隔可控，SMTP 密码加密存储

* **Android 深度保活** - 前台服务 + WakeLock + AlarmManager 心跳 + WifiLock + 电池白名单五层保活

* **APK 云端构建** - GitHub Actions 打包，无需服务器安装 JDK/SDK；支持自托管/小飞机网盘分发

* **安全加固** - 敏感字段 AES-256-CBC 加密、管理后台路径混淆、JWT 鉴权、黑名单、登录失败锁定、响应头加固

## 技术栈

| 模块          | 技术                                              |
| ----------- | ----------------------------------------------- |
| 后端          | PHP 8.3 + Swoole 6.x（WebSocket/HTTP 双服务）        |
| 数据库         | MySQL 8.0                                       |
| 缓存          | Redis 7.x                                       |
| 反向代理        | Nginx                                           |
| 管理后台/用户端    | Vue3 + Element Plus + Vite + TypeScript         |
| Android APP | HBuilderX uni-app (Vue 3) + plus.android 原生 API |

## 统一管理脚本

**所有安装 / 更新 / 卸载 / 运维操作统一入口：`bash deploy/setup.sh`**

```bash
# 国内服务器：下载并启动交互式主菜单（数字选择）
curl -sSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/setup.sh -o /tmp/setup.sh
sudo bash /tmp/setup.sh
```

```
╔══════════════════════════════════════════════════════════════╗
║           即时消息推送系统 - 统一管理面板                    ║
╚══════════════════════════════════════════════════════════════╝

  [1]  系统检测与优化      镜像切换 / Swap / sysctl / 时区 / NTP
  [2]  一键安装部署        环境 + 代码 + 数据库 + 构建 + 服务
  [3]  更新系统            git pull → composer → 迁移 → 构建 → 重启
  [4]  服务管理            启动 / 停止 / 重启 / 日志 / 状态
  [5]  卸载                环境 / 源码 / 完全卸载
  [6]  重装                完全卸载 → 重新安装
  [0]  退出
```

### 非交互模式（自动化 / CI 调用）

```bash
# 一键安装（全程无人值守，自动 yes + gh 代理）
sudo bash deploy/setup.sh --install --yes

# 更新代码（支持交互询问：gh-proxy / skip-build / skip-migration / force）
sudo bash deploy/setup.sh --update

# 仅系统优化
sudo bash deploy/setup.sh --optimize --yes

# 卸载：--uninstall=env   仅卸载运行环境
#       --uninstall=source 仅删除项目源码
#       --uninstall=all    完全卸载（环境+源码+数据库）
sudo bash deploy/setup.sh --uninstall=all --yes
```

支持发行版：**Debian / Ubuntu / CentOS 7-8 / Rocky 8-9 / AlmaLinux / Fedora / Alpine / Arch / openSUSE**，自动识别并切换国内镜像源（阿里云/清华），Swoole 编译支持 pecl → gh-proxy 源码安装降级。

***

## APP 打包

### Android（HBuilderX 云打包）

1. 用 HBuilderX 打开 `build/hbuilderx/` 目录
2. 配置 `manifest.json` 后点击「发行」→「原生 App-云打包」

### GitHub Actions 云端构建

1. 服务器 `.env` 配置 `GITHUB_TOKEN`、`GITHUB_REPO`、`SERVER_SSH_*`
2. GitHub 仓库 Secrets 配置 keystore（`APK_KEYSTORE_BASE64` 等）和 SSH 私钥
3. 管理后台「APP 生成」页面提交构建任务

## iOS APNs 配置

1. Apple Developer 生成 APNs AuthKey（`.p8`），放到 `backend/config/apns/`
2. `.env` 配置：

```env
APNS_ENABLED=true
APNS_TEAM_ID=XXXXXXXXXX
APNS_KEY_ID=XXXXXXXXXX
APNS_BUNDLE_ID=com.your.push.app
APNS_AUTH_KEY_PATH=/www/push-system/backend/config/apns/AuthKey_XXXXXXXXXX.p8
```

## 项目结构

```
im-push-system/
├── backend/            # 后端（Controller + Service / config / database/migrations / bin）
├── admin/              # 管理后台前端（Vue3 + Vite + TS）
├── user/               # 用户端前端（Vue3 + Vite + TS，独立权限体系）
├── build/
│   ├── hbuilderx/      # Android APP 源码（uni-app）
│   └── queue/          # APK 云端构建队列（BuildQueue）
├── app/                # Android 原生源码模板（Compose）
├── ios/                # iOS 原生源码模板（SwiftUI）
├── deploy/             # 唯一入口：setup.sh + 配置模板（nginx/systemd/sudoers/ssl/apk）
├── scripts/            # 辅助脚本（pre-push hook 等）
└── .github/workflows/  # CI / 自动部署 / APK 云端构建
```

## 常用运维

**优先使用 setup.sh 的菜单\[3 更新] 和 \[4 服务管理]。** 以下为快速命令：

```bash
# 服务状态 / 重启
sudo systemctl status  push-http push-websocket
sudo systemctl restart push-http push-websocket
sudo journalctl -u push-websocket -n 80 --no-pager

# HTTP 健康检查
curl http://127.0.0.1:9501/health

# 敏感字段加密迁移（SMTP 密码明文 → AES）
cd /www/push-system/backend
php bin/migrate_encrypt.php --status        # 只检查当前状态
php bin/migrate_encrypt.php                 # 干跑预览
php bin/migrate_encrypt.php --apply         # 实际写入数据库
```

## 故障排查

| 问题               | 处理                                                                                               |
| ---------------- | ------------------------------------------------------------------------------------------------ |
| 管理后台 500         | `journalctl -u push-http -f` 查报错，检查 MySQL/Redis 连接、.env 权限 www-data:600                          |
| 管理后台 404         | 前端未构建：setup.sh 菜单 \[3 更新] 或 `cd admin && npm ci && npm run build`                                |
| SMTP 发送失败        | 检查授权码（非登录密码）；明文密码请跑 `migrate_encrypt.php --apply` 后 `systemctl restart push-http push-websocket` |
| iOS 收不到推送        | 核对 `.env` 的 APNS 四项配置与 `.p8` 路径                                                                  |
| 端口 9501/9502 占用  | `lsof -i :9501` 查进程，`systemctl restart` 自动清理；Swoole package\_max\_length 已设 250MB                |
| 推送失败             | 后台推送记录页查看 `fail_reason` 和 `payload_size`（push\_logs 表）                                           |
| WebSocket 鉴权循环断开 | 检查连接 idempotency / 定时器守卫（build/hbuilderx/js/ws.js）                                               |

## 默认账号

| 角色  | 账号    | 密码       |
| --- | ----- | -------- |
| 管理员 | admin | admin123 |

> 部署后请尽快修改默认密码并在 `.env` 设置强 JWT\_SECRET / AES\_KEY。

## 许可证

MIT License
