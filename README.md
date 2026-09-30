# IM Push System · 即时消息推送系统

> 基于 PHP + Swoole 的实时消息推送平台。Android APP 走 WebSocket 长连接，iOS / Edge / Chrome 通过 PWA + Web Push 免安装接收推送；后台可一键生成 APP 工程包、管理域名与 SSL 证书。

## 系统架构

```
   Android APP (uni-app / Compose)          iOS / Edge / Chrome PWA
        │                                          │
        │ WebSocket（在线实时）                      │ Web Push（VAPID + RFC 8291）
        ▼                                          ▼
   ┌──────────────────────────────────────────────────────────────┐
   │                    Nginx :443 / :80                          │
   │        反向代理 + SSL 终止 + 静态托管 + 强制 HTTPS              │
   └───────┬──────────────┬───────────────┬───────────────────────┘
           │ /ws/client   │ /api/*        │ /admin/*  /user/*  /webpush/*
           ▼              ▼               ▼
   ┌──────────────────────────────────────────────────────────────┐
   │              Swoole 双服务（systemd 托管，开机自启）             │
   │      push-websocket :9502          push-http :9501           │
   └───────┬──────────────────────────────────────┬───────────────┘
           ▼                                      ▼
      ┌─────────┐                            ┌──────────┐
      │  Redis  │  连接映射 / 离线消息 /        │  MySQL   │  持久化
      │         │  聚合窗口 / 订阅关系 / 计数    └──────────┘
      └────┬────┘
           │
           ├──► Web Push 网关（Apple / Edge / Chrome）  → PWA 通知
           └──► APNS（HTTP/2 + JWT + 熔断）             → 自建 iOS App
```

消息投递优先级：**在线走 WebSocket 直推 → 离线存 Redis 待上线补发 → PWA 设备走 Web Push → 自建 iOS App 走 APNS**。

## 核心功能

### 推送通道

* **实时推送** — WebSocket 长连接毫秒级送达，消息正文无字数限制
* **PWA + Web Push** — 免安装原生 App，iOS / Edge / Chrome「添加到主屏幕」即可收推送（VAPID RFC 8292 + 消息加密 RFC 8291）
* **Web Push 聚合推送** — 同一设备 3 秒窗口内多条消息合并为 1 条，多条目时显示「收到 N 条消息」
* **APNS 推送（可选）** — 面向自建 iOS App，HTTP/2 + JWT 鉴权，含连续失败熔断与 token 黑名单，防 APNS 封号
* **离线消息补发** — 设备离线时消息落 Redis，上线后自动下发
* **Key 订阅推送** — 一个 Key 可多设备订阅，支持单设备 / 多设备 / 按 Key 批量推送

### 设备与订阅治理

* **订阅设备明细** — 五方数据源合并（Redis `key:subscribe` / `devices` 表 / `device:key` 哈希 / `ws:conn` 在线会话 / `web_push_subscriptions` 表），标注每台设备的来源与映射状态，自动识别 PWA 设备与僵尸订阅
* **僵尸连接 / 僵尸订阅管理** — 后台「僵尸连接」页分两个页签：
  * **僵尸连接**（fd 维度）— 还挂在 `ws:online` 上但空闲超阈值的死连接
  * **僵尸订阅**（device_id 维度）— Redis 订阅关系还在、`devices` 表已无该设备
  * 均支持逐条删除与一键清理（一键清理同时处理两类）
* **双向心跳 + 僵尸连接巡检** — 服务端 30 秒巡检自动清理死连接
* **设备掉线邮件通知** — 含掉线时间 / 在线时长 / IP；触发间隔 Key 级可配（5–1440 分钟，0=默认 30 分钟），上线自动补发「已恢复」通知；SMTP 密码 AES 加密存储
* **黑名单** — 支持设备 ID / IP / 指纹三类，命中即拒连并断开已有连接

### PWA 前端能力（`/webpush/`）

* **深色模式** — 跟随系统主题，正文/次要文字/按钮底色逐项做过对比度校验
* **消息搜索** — IndexedDB 全量本地归档 + 180ms 防抖检索
* **分页 / 上拉加载** — 服务端 `before_id` 游标，IntersectionObserver 触底加载（仅用户滚动过后才自动加载）
* **IndexedDB 归档** — 页面与 Service Worker 共用同一存储层（`shared.js`），离线可读
* **本地删除 / 清空** — 墓碑 `deleted_ids` + 清空水位线 `cleared_before_id`，刷新不会被历史消息刷回来
* **订阅失联自愈** — `pushsubscriptionchange` 事件重建订阅上报；iOS 上该事件不可靠，另有「每次打开页面自检」兜底，订阅丢了自动重建
* **长文展开** — 正文超 140 字折叠为 3 行，可展开
* **灵动岛 / 安全区避让** — `env(safe-area-inset-*)` + `100dvh`，iPhone 14 Pro 及以上机型标题不被灵动岛遮挡
* **通知深链** — 点击通知直接定位到对应消息并高亮

### APP 打包与分发

* **APP 工程包生成** — 后台「APP 生成」页填参数即可下载 ZIP，导入 HBuilderX 云打包出 APK，服务器无需 JDK / Android SDK
  * HBuilderX 模式：uni-app 项目包（新模板 / 旧模板可选）
  * 玻璃拟态模式：深色玻璃质感 UI 的 6 页面完整版 uni-app 源码
* **APK 分发** — 支持自托管直链与小飞机网盘，生成下载页 / 二维码，带下载统计（`apk_download_logs`）
* **APP 内检查更新** — `/api/check-update` 返回最新版本信息

### 管理后台

仪表盘（在线趋势 / 今日推送 / Key 分布 / 设备平台 / 最近推送）、用户管理、Key 管理、设备管理、僵尸连接、推送记录（含详情 / 重推 / 导出 CSV·JSON）、测试推送、黑名单、管理员管理、登录日志、开放 API 管理、域名与 SSL、音频管理、用户公告、系统设置（邮件 / APNS / 路径 / 安全 / 用户端 APP）。

### 用户端控制台

独立权限体系（`/user-api/`，走 `UserApiAuth`）：推送消息、推送记录、Key 管理、设备管理、API 文档与自助签发 API Key、APP 下载、公告、个人中心（改密 / 绑定 QQ / 一键下线）。

### 安全

* **敏感字段 AES-256-CBC 加密** — SMTP 密码、APNS 私钥、Web Push VAPID 私钥、飞鸡盘凭据等以 `ENC:` 前缀密文入库，附 `bin/migrate_encrypt.php` 迁移工具
* **JWT 鉴权** — 管理端与用户端两套独立身份体系
* **管理后台路径混淆** — 后台入口路径可在系统设置中自定义（非固定 `/admin/`）
* **登录失败次数限制** — Redis 计数，超限锁定（上限默认 5 次，30 分钟窗口，可在系统设置中调整）
* **图形验证码** — 登录 / 注册 / 找回密码
* **开放 API 独立密钥** — `X-Api-Key` 鉴权，支持有效期与启用禁用

## 技术栈

| 模块 | 技术 |
| --- | --- |
| 后端 | PHP 8.2+ + Swoole（HTTP / WebSocket 双服务） |
| Web Push | minishlink/web-push（VAPID RFC 8292 + 加密 RFC 8291） |
| APNS | HTTP/2 + JWT（ES256），含熔断与 token 黑名单 |
| 数据库 / 缓存 | MySQL 8.0 / Redis 7.x |
| 反向代理 | Nginx（SSL 终止 + `/webpush/` 静态托管） |
| 管理后台 / 用户端 | Vue 3 + Element Plus + Vite + TypeScript |
| PWA 前端 | 原生 HTML / JS + Service Worker + IndexedDB |
| Android APP 模板 | HBuilderX uni-app（Vue 3）/ Compose 原生源码 |
| 证书 | acme.sh 签发与自动续期 |

## 部署与运维

### 统一管理脚本

**所有安装 / 更新 / 卸载 / 运维操作的统一入口：`bash deploy/setup.sh`**

```bash
# 国内服务器：下载并启动交互式主菜单
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

支持发行版：**Debian / Ubuntu / CentOS 7-8 / Rocky 8-9 / AlmaLinux / Fedora / Alpine / Arch / openSUSE**，自动切换国内镜像源（阿里云 / 清华），Swoole 编译支持 pecl → gh-proxy 源码安装降级。

### 非交互模式（自动化 / CI）

```bash
# 一键安装（全程无人值守）
sudo bash deploy/setup.sh --install --yes

# 更新代码（可交互选择 gh-proxy / skip-build / skip-migration / force）
sudo bash deploy/setup.sh --update

# 仅系统优化
sudo bash deploy/setup.sh --optimize --yes

# 卸载：--uninstall=env 仅环境 | =source 仅源码 | =all 环境+源码+数据库
sudo bash deploy/setup.sh --uninstall=all --yes
```

### 更新脚本直调（快速发布）

```bash
cd /www/push-system
bash backend/deploy/update.sh --yes [--gh-proxy] [--skip-build] [--skip-migration] [--restart]
```

参数按改动范围取舍：

| 改动范围 | 建议参数 |
| --- | --- |
| 只改后端 PHP | `--skip-build` |
| 改了 admin / user 前端 | 不加 `--skip-build` |
| 无新迁移文件 | `--skip-migration` |
| 只改模板（`app/` / `build/hbuilderx/`） | `--skip-build --skip-migration` |

### 自动化

* **`.github/workflows/ci.yml`** — PHP Lint（全量 `php -l`）+ 前端构建校验
* **`.github/workflows/deploy.yml`** — push 到 `main` 后自动部署：修权限 → `git reset --hard origin/main` → composer → 数据库迁移 → 构建 admin/user → 还原 dist/runtime 属主 → 重启服务 → 健康检查；也可手动 `workflow_dispatch` 并勾选跳过前端构建

## 推送通道配置

### Web Push（iOS / Edge / Chrome PWA）

**开箱即用，无需手动配置**：

1. 用 iOS Safari 打开部署后的 `/webpush/` 页面，点击「添加到主屏幕」
2. 打开主屏幕图标，输入推送 Key 并点「启用推送」完成订阅
3. 后台按 Key 推送时，PWA 设备与 Android 原生 App 一并送达

> VAPID 密钥对首次使用时自动生成，私钥经 AES 加密存于 `admin_settings` 表。
> iOS 需 16.4+ 且已「添加到主屏幕」才能订阅；国内 Chrome 的 FCM 通道可能不可达，建议 iOS / Edge。

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/api/web-push/public-key` | 获取 VAPID 公钥 |
| POST | `/api/web-push/subscribe` | 保存订阅（含设备信息） |
| POST | `/api/web-push/unsubscribe` | 取消订阅 |
| POST | `/api/web-push/send-test` | 测试推送 |

### APNS（自建 iOS App，可选）

后台「系统设置 → APNS」配置 `.p8` 密钥 ID、Team ID、Bundle ID 后即可启用；提供配置自检、连通性测试、熔断状态查看与手动重置熔断。iOS 客户端需调用 `/api/device/register-token` 上报 device token。

## 开放 API

| 方法 | 路径 | 鉴权 | 说明 |
| --- | --- | --- | --- |
| POST | `/api/push` | `X-Api-Key` 请求头 | 对外推送接口 |

```json
{
  "target_type":  "device" | "key",
  "target_value": "设备ID或Key值（多个用英文逗号分隔）",
  "title":        "消息标题",
  "content":      "消息内容",
  "payload":      {},
  "priority":     "high" | "normal" | "low"
}
```

API Key 在后台「开放 API 管理」页签发，支持续期（+30 天 / +90 天 / 设为永久 / 自定义日期）与启用禁用。未过期的 Key 续期按当前到期时间顺延，已过期或永久 Key 从当前时间起算。

## APP 工程包生成

两条路径都只产出**工程包**，APK 最终由 HBuilderX 云打包生成。

**方式一：后台生成（推荐）**

1. 打开后台「APP 生成」页，填写应用名称 / 包名 / 推送 Key / 服务器地址 / 图标等参数
2. 选择打包方式并点「生成工程包」，浏览器下载 ZIP
   * **HBuilderX** — uni-app 项目包（新模板 `build/hbuilderx/` 或旧模板 `build/hbuilderx-old/`）
   * **玻璃拟态全新 UI** — 深色玻璃质感 6 页面完整源码
3. 用 HBuilderX 打开解压后的目录，点「发行」→「原生 App-云打包」

**方式二：直接用仓库内置模板**

用 HBuilderX 打开 `build/hbuilderx/` 目录，配置 `manifest.json` 后云打包。

## 项目结构

```
im-push-system/
├── backend/            # 后端：Controller / Service / config / database/migrations / bin
│   └── deploy/         # update.sh 更新脚本 + check-version.sh
├── admin/              # 管理后台前端（Vue3 + Vite + TS）
├── user/               # 用户端控制台前端（Vue3 + Vite + TS，独立权限体系）
├── webpush/            # PWA 前端（index.html + app.js + shared.js + sw.js + manifest）
├── build/
│   ├── hbuilderx/      # uni-app 源码模板（HBuilderX 云打包用，后台生成工程包读取）
│   └── hbuilderx-old/  # 旧版 uni-app 源码模板（兼容旧工程）
├── app/                # Android 原生源码模板（Compose）
├── deploy/             # 部署入口：setup.sh + nginx / systemd / sudoers / ssl / apk 配置
├── scripts/            # 辅助脚本（pre-push 检查等）
└── .github/workflows/  # ci.yml（Lint/构建校验）+ deploy.yml（自动部署）
```

## 常用运维

**优先用 `setup.sh` 的菜单 \[3 更新] 与 \[4 服务管理]**，以下为快速命令：

```bash
# 服务状态 / 重启 / 日志
sudo systemctl status  push-http push-websocket
sudo systemctl restart push-http push-websocket
sudo journalctl -u push-websocket -n 80 --no-pager

# HTTP 健康检查（仅监听内网）
curl http://127.0.0.1:9501/health
curl http://127.0.0.1:9501/api/version

# 数据库迁移（幂等，按 schema_migrations 记录顺序执行）
cd /www/push-system/backend
sudo php bin/migrate_sql.php

# 敏感字段加密迁移（明文 → AES）
php bin/migrate_encrypt.php --status   # 仅检查
php bin/migrate_encrypt.php            # 干跑预览
php bin/migrate_encrypt.php --apply    # 实际写入

# APNS 通道自检（需 sudo：.env 属主 www-data 600）
sudo php bin/apns_test.php
```

## 故障排查

| 问题 | 处理 |
| --- | --- |
| 管理后台 500 | `journalctl -u push-http -f` 查报错；检查 MySQL / Redis 连接、`backend/.env` 权限须为 `www-data:600` |
| 管理后台 404 | 前端未构建：`setup.sh` 菜单 \[3 更新]，或 `cd admin && npm ci && npm run build` |
| 后台某个列表恒为空 | 先看接口返回是否被**包了两层信封**（`data.data.list`）。控制器必须返回裸数据数组，由 HttpServer 统一包一层；`return Response::success(...)` 会导致双层，前端 `res.data.list` 取不到值 |
| SMTP 发送失败 | 检查授权码（非登录密码）；明文密码先跑 `migrate_encrypt.php --apply` 再重启服务 |
| iOS PWA 收不到推送 | 确认 iOS 16.4+ 且已「添加到主屏幕」；`/webpush/sw.js` 需带 `Service-Worker-Allowed` 响应头；到后台「订阅设备明细」确认该设备标记为「Web Push 订阅」 |
| PWA 推送长期失效 | 打开 `/webpush/` 页面会自检订阅，失效会自动重建并在页头提示；若提示失败，检查 `web_push_subscriptions` 表该设备 `status` 是否为 0（网关返回 404/410 会被标记失效） |
| Web Push 不弹通知 | 后端网关返回 200/201 只代表投递成功，不弹多为系统「勿扰 / 专注」静默；Android 另需检查通知渠道是否被禁用 |
| 端口 9501 / 9502 被占用 | `lsof -i :9501` 查进程，`systemctl restart` 自动清理；Swoole `package_max_length` 已设 250MB，Nginx `client_max_body_size` 同步为 250MB |
| 推送失败 | 后台「推送记录」页看 `fail_reason` 与 `payload_size`（`push_logs` 表）；APK 类大包体注意 250MB 上限 |
| WebSocket 鉴权循环断开 | 检查连接的幂等守卫与定时器管理（`build/hbuilderx/js/ws.js`）；判断「连接进行中」必须用 `connectTimer`/`authTimeoutTimer` 定时器，不能用会在重连态卡死的 `state` |
| 僵尸订阅清不掉 | 僵尸订阅不在 `devices` 表，清理的是 Redis 的 `key:subscribe`（含大小写变体）与 `device:key`；清理后仍出现说明设备又重连鉴权了一次，属正常 |
| 部署 / 重启时客户端断连 | Swoole 无法向客户端发送 1001（going away）关闭帧——`disconnect()` 只断 TCP，主 worker 要等满 `max_wait_time` 才回调 `onWorkerStop`，此时拆连接已开始，客户端最终只收到裸 EOF。客户端表现为「异常断开」后自动重连，属预期行为；退出耗时由 `max_wait_time`（5s）决定，必须小于 systemd `TimeoutStopSec`（30s），否则被 SIGKILL |

## 默认账号

| 角色 | 账号 | 密码 |
| --- | --- | --- |
| 管理员 | `admin` | `admin123` |

> 部署后请立即修改默认密码，并在 `.env` 中设置强 `JWT_SECRET` / `AES_KEY`，同时自定义管理后台入口路径。

## 许可证

MIT License
