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

- **实时推送** - WebSocket 长连接毫秒级送达，消息无字数限制
- **iOS APNs** - 设备离线/被清理时自动切换 Apple 推送通道
- **Key 订阅推送** - 一个 Key 多设备订阅，支持单人/多人/批量推送
- **离线消息** - 离线时消息存 Redis，上线自动补发
- **双向心跳 + 僵尸连接巡检** - 30 秒巡检自动清理死连接
- **设备掉线邮件通知** - 含掉线时间/在线时长/IP，间隔可控，SMTP 密码加密存储
- **Android 深度保活** - 前台服务 + WakeLock + AlarmManager 心跳 + WifiLock + 电池白名单五层保活
- **APK 云端构建** - GitHub Actions 打包，无需服务器安装 JDK/SDK；支持自托管/小飞机网盘分发
- **安全加固** - 敏感字段 AES-256-CBC 加密、管理后台路径混淆、JWT 鉴权、黑名单、登录失败锁定、响应头加固

## 技术栈

| 模块 | 技术 |
|------|------|
| 后端 | PHP 8.3 + Swoole 6.x（WebSocket/HTTP 双服务） |
| 数据库 | MySQL 8.0 |
| 缓存 | Redis 7.x |
| 反向代理 | Nginx |
| 管理后台/用户端 | Vue3 + Element Plus + Vite + TypeScript |
| Android APP | HBuilderX uni-app (Vue 3) + plus.android 原生 API |

## 快速部署

```bash
# 国内服务器一键安装（推荐）
curl -sSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/quick-deploy.sh -o /tmp/quick-deploy.sh
sudo bash /tmp/quick-deploy.sh --install --yes --gh-proxy

# 或交互式管理菜单
cd /www/push-system && bash manage.sh
```

支持 6 种 Linux 发行版自动适配、Swoole 编译 3 次降级、断点续装（`--resume`）、自定义目录/端口/数据库密码。

## 日常更新

```bash
cd /www/push-system
rm -f .deploy/push-update-progress.env /tmp/push-update-progress.env 2>/dev/null || true
bash backend/deploy/update.sh --yes --gh-proxy --restart
```

常用参数：`--skip-build`（仅后端变更跳过前端构建）、`--skip-migration`（无新迁移文件时）、`--resume`（断点续装）、`--force-update`（覆盖本地修改）。

push 到 main 后 GitHub Actions 也会自动部署。

## APP 打包

### Android（HBuilderX 云打包）

1. 用 HBuilderX 打开 `build/hbuilderx/` 目录
2. 配置 `manifest.json` 后点击「发行」→「原生 App-云打包」

### GitHub Actions 云端构建

1. 服务器 `.env` 配置 `GITHUB_TOKEN`、`GITHUB_REPO`、`SERVER_SSH_*`
2. GitHub 仓库 Secrets 配置 keystore（`APK_KEYSTORE_BASE64` 等）和 SSH 私钥
3. 管理后台「APP 生成」页面提交构建任务

详见 [docs/github-actions-build.md](docs/github-actions-build.md)。

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
├── backend/           # 后端（src/Controller + Service、config、database/migrations、deploy）
├── admin/             # 管理后台前端
├── user/              # 用户端前端（独立域名）
├── build/
│   └── hbuilderx/     # Android APP 源码（uni-app）
├── deploy/            # 部署脚本（quick-deploy.sh / install.sh / update.sh）
└── manage.sh          # 交互式管理菜单
```

## 常用运维命令

```bash
# 服务状态与日志
sudo systemctl status push-http push-websocket
sudo journalctl -u push-websocket -f

# 重启服务
sudo systemctl restart push-http push-websocket

# 健康检查
curl http://127.0.0.1:9501/health

# 敏感字段加密迁移（SMTP 密码明文 → AES 加密）
cd /www/push-system/backend && php bin/migrate_encrypt.php --dry-run  # 预演
php bin/migrate_encrypt.php --apply                                    # 执行

# 版本检查与回滚
bash backend/deploy/check-version.sh --verbose
bash deploy/rollback.sh [commit_hash]
```

## 故障排查

| 问题 | 处理 |
|------|------|
| 管理后台 500 | `journalctl -u push-http -f` 查报错，检查 MySQL 连接 |
| 管理后台 404 | 前端未构建：`cd admin && npm install && npm run build` |
| SMTP 发送失败 | 检查授权码（非登录密码）；明文存储时跑 `migrate_encrypt.php --apply` 后重启 |
| iOS 收不到推送 | 核对 `.env` 的 APNS 四项配置与 `.p8` 路径 |
| 端口 9501/9502 占用 | `lsof -i :9501` 查进程，`systemctl restart` 自动清理 |
| 推送失败 | 后台推送记录页查看 `fail_reason` 和 `payload_size`（push_logs 表） |

## 默认账号

| 角色 | 账号 | 密码 |
|------|------|------|
| 管理员 | admin | admin123 |

> 部署后请尽快修改默认密码！

## 许可证

MIT License
