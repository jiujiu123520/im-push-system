# IM Push System - 即时消息推送系统

> 基于 PHP + Swoole 的实时消息推送平台，支持 WebSocket 长连接、iOS 双通道推送（APNs + PWA/Web Push）、Android 深度保活、敏感字段 AES 加密。

## 系统架构

```
┌─────────────┐  ┌─────────────┐  ┌──────────────┐  ┌─────────────┐
│  Android APP │  │  iOS APP    │  │  iOS/Edge    │  │   HTTP API   │
│  (uni-app/   │  │  (原生Swift) │  │  PWA(主屏幕) │  │  (Swoole)    │
│   Compose)   │  └──────┬──────┘  └──────┬───────┘  └──────┬──────┘
└──────┬───────┘         │  APNs          │  Web Push       │
       │  WebSocket      │  (离线推送)     │  (Apple/WNS/FCM)│
       │  (前台/在线)     │                │                 │
       └────────┬─────────┴────────────────┴─────────────────┘
                │                    ┌───────────────────────┐
                │                    │  Swoole HTTP/WS 双服务  │
                │                    └───────────┬───────────┘
                │                                │
                │                    ┌───────────┴───────────┐
                │                    │   Redis     │  MySQL  │
                │                    │ 连接/离线/聚合│ 持久化  │
                │                    └───────────┬───────────┘
                │                                │
                └────────────────────────►  Nginx（反向代理/静态托管）
```

## 核心功能

* **实时推送** - WebSocket 长连接毫秒级送达，消息无字数限制

* **iOS 双通道推送**
  * **APNs** - 原生 iOS App 设备离线/被清理时自动切换 Apple 推送通道（Token-Based .p8，含熔断保护）
  * **PWA + Web Push** - 无需安装原生 App，iOS 用户通过 Safari「添加到主屏幕」即可接收推送（VAPID + minishlink/web-push）

* **Web Push 聚合推送** - 同一设备 3 秒窗口内的多条消息合并为 1 条推送，多条时显示「收到 N 条消息」

* **Key 订阅推送** - 一个 Key 多设备订阅，支持单人/多人/批量推送

* **订阅设备明细** - 五方数据源（Redis key:subscribe / devices 表 / device:key / ws:conn 在线会话 / web_push_subscriptions 表）合并展示，准确识别 PWA 设备、在线状态与僵尸订阅

* **离线消息** - 离线时消息存 Redis，上线自动补发

* **双向心跳 + 僵尸连接巡检** - 30 秒巡检自动清理死连接

* **设备掉线邮件通知** - 含掉线时间/在线时长/IP，间隔可控（Key 级 5-1440 分钟），SMTP 密码加密存储

* **Android 深度保活** - 前台服务 + WakeLock + AlarmManager 心跳 + WifiLock + 电池白名单五层保活，通知渠道高优先级/锁屏展示

* **APK 云端构建** - GitHub Actions 打包，无需服务器安装 JDK/SDK；支持自托管/小飞机网盘分发

* **域名 + SSL 证书管理** - 内置 acme.sh 签发与自动续期、强制 HTTPS

* **API Key 管理** - 支持签发、续期（+30 天/+90 天/+1 年/永久/自定义）、启用禁用

* **独立用户控制台** - 用户端独立权限体系（密钥/设备/推送/通知/文档）

* **安全加固** - 敏感字段 AES-256-CBC 加密、管理后台路径混淆、JWT 鉴权、黑名单、登录失败锁定、响应头加固

## 技术栈

| 模块          | 技术                                              |
| ----------- | ----------------------------------------------- |
| 后端          | PHP 8.2+ + Swoole（WebSocket/HTTP 双服务）             |
| Web Push     | minishlink/web-push（VAPID RFC 8292 + 消息加密 RFC 8291） |
| 数据库         | MySQL 8.0                                       |
| 缓存          | Redis 7.x                                       |
| 反向代理        | Nginx（含 `/webpush/` 静态托管 + SSL 终止）              |
| 管理后台/用户端    | Vue3 + Element Plus + Vite + TypeScript         |
| PWA 前端      | 原生 HTML/JS + Service Worker（iOS Safari 主屏幕）    |
| Android APP | HBuilderX uni-app (Vue 3) / Compose 原生源码模板         |
| iOS APP     | SwiftUI 原生源码模板                                  |

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

***

## 推送通道配置

### iOS APNs（原生 App）

在管理后台「系统设置」中配置（存储于 `admin_settings` 表）：

1. Apple Developer 生成 APNs AuthKey（`.p8`），上传至 `backend/config/apns/`
2. 填写 Team ID、Key ID、Bundle ID，并启用 APNs
3. 用 `backend/bin/apns_test.php` 自检（需 `sudo` 读取 `.env`，自动校验 HTTP/2 支持）

### Web Push（iOS/Edge/Chrome PWA）

**开箱即用，无需手动配置**：

1. 用户用 iOS Safari 打开部署后的 Web Push 页面（`/webpush/`），点击「添加到主屏幕」
2. 输入推送 Key 并「启用推送」，完成订阅
3. 后台按 Key 推送时，PWA 设备与原生 App 设备一并送达

> VAPID 密钥对首次使用时自动生成，私钥经 AES 加密存储于 `admin_settings` 表。
> iOS Safari 需 iOS 16.4+ 且「添加到主屏幕」后才能订阅；国内 Chrome 走 FCM 可能被墙，建议 iOS/Edge。

Web Push API 端点：

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/web-push/public-key` | 获取 VAPID 公钥 |
| POST | `/api/web-push/subscribe` | 保存订阅（含设备信息） |
| POST | `/api/web-push/unsubscribe` | 取消订阅 |
| POST | `/api/web-push/send-test` | 测试推送 |

***

## 项目结构

```
im-push-system/
├── backend/            # 后端（Controller + Service / config / database/migrations / bin）
├── admin/              # 管理后台前端（Vue3 + Vite + TS）
├── user/               # 用户端前端（Vue3 + Vite + TS，独立权限体系）
├── webpush/            # PWA + Web Push 前端（index.html + app.js + sw.js）
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

**优先使用 setup.sh 的菜单 \[3 更新] 和 \[4 服务管理]。** 以下为快速命令：

```bash
# 服务状态 / 重启
sudo systemctl status  push-http push-websocket
sudo systemctl restart push-http push-websocket
sudo journalctl -u push-websocket -n 80 --no-pager

# HTTP 健康检查
curl http://127.0.0.1:9501/health

# 数据库迁移（幂等，按 schema_migrations 记录顺序执行）
cd /www/push-system/backend
sudo php bin/migrate_sql.php

# 敏感字段加密迁移（SMTP 密码明文 → AES）
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
| iOS 原生收不到推送     | 后台「系统设置」核对 APNS 四项配置与 `.p8` 路径，跑 `sudo php bin/apns_test.php` 自检                              |
| iOS PWA 收不到推送    | 确认 iOS 16.4+ 且已「添加到主屏幕」；`/webpush/sw.js` 需带 `Service-Worker-Allowed` 响应头；检查后台订阅设备明细该设备是否为「Web Push 订阅」 |
| Web Push 不弹通知     | 后端网关返回 200/201 仅代表投递成功，通知不弹多为系统「勿扰/专注」静默；Android 检查通知渠道是否被禁用                          |
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
