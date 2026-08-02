# PushApp - iOS 推送客户端

iOS 推送消息接收 App，支持 WebSocket（前台实时）和 APNS（后台兜底）双通道推送。

## 项目结构

```
ios/
├── PushApp.xcodeproj/          # Xcode 项目配置
│   └── project.pbxproj
└── PushApp/                    # 源码目录
    ├── PushAppApp.swift        # App 入口，前后台生命周期管理
    ├── AppDelegate.swift       # APNS 注册与推送回调
    ├── ContentView.swift       # 主界面（消息列表 + 设置）
    ├── PushManager.swift       # 推送管理器（双通道协调、token 上报）
    ├── PushWebSocketClient.swift  # WebSocket 客户端
    ├── PreferencesManager.swift   # 本地配置存储（UserDefaults）
    ├── Info.plist              # 应用配置（权限、后台模式）
    ├── PushApp.entitlements    # 权限配置（APNS 环境）
    ├── LaunchScreen.storyboard # 启动屏
    └── Assets.xcassets/        # 资源目录（AppIcon、AccentColor）
```

## 编译要求

- **macOS 13.0+**
- **Xcode 15.0+**
- **iOS 16.0+** 部署目标
- **Apple Developer 账号**（$99/年，APNS 必须）
- **iPhone 真机**（模拟器不支持 APNS）

## 编译步骤

### 1. 打开项目

```bash
# 克隆代码后，进入 ios 目录
cd ios

# 用 Xcode 打开
open PushApp.xcodeproj
```

### 2. 配置签名

1. 在 Xcode 左侧选中 `PushApp` 项目
2. 选择 `PushApp` Target → `Signing & Capabilities` 标签
3. 勾选 `Automatically manage signing`
4. 在 `Team` 下拉框选择你的 Apple Developer 团队
5. 修改 `Bundle Identifier` 为你自己的（如 `com.yourcompany.pushapp`）

### 3. 配置 APNS 环境

编辑 `PushApp/PushApp.entitlements`：

```xml
<!-- 开发调试用 development，上架 App Store 改为 production -->
<key>aps-environment</key>
<string>development</string>
```

### 4. 连接真机编译

1. 用数据线连接 iPhone 到 Mac
2. 在 Xcode 顶部设备列表中选择你的 iPhone
3. 按 `Cmd + R` 编译运行
4. 首次运行需要在 iPhone 上信任开发者：设置 → 通用 → VPN与设备管理

### 5. 配置推送参数

App 启动后：

1. 进入「设置」Tab
2. 填写服务器地址（如 `http://116.62.222.38:9501`）
3. 填写推送 Key（在后台管理系统创建）
4. 点击「保存并连接」
5. 系统会弹出通知权限请求，选择「允许」

## 双通道推送机制

```
┌──────────────────────────────────────────────────┐
│                   iOS 设备                        │
│                                                   │
│  前台时                  后台/被杀时               │
│  ┌──────────┐           ┌──────────┐             │
│  │ WebSocket │           │   APNS   │             │
│  │ (实时)    │           │ (通知栏)  │             │
│  └─────┬────┘           └─────┬────┘             │
│        │                      │                   │
│        └──────────┬───────────┘                   │
│                   ▼                               │
│            ┌─────────────┐                        │
│            │  PushManager │                        │
│            │  (消息合并)   │                        │
│            └─────────────┘                        │
└──────────────────────────────────────────────────┘
```

| 设备状态 | 推送通道 | 说明 |
|---------|---------|------|
| 前台 + WebSocket 在线 | WebSocket | 低延迟，实时投递 |
| 后台 / 被杀 / WebSocket 断开 | APNS | 走苹果服务器，弹通知栏 |
| APNS 也失败 | 存离线消息 | App 重开后拉取 |

## iOS 推送限制说明

1. **后台 WebSocket 被挂起**：iOS 进入后台几秒后会挂起所有网络连接
2. **必须用 APNS**：苹果强制第三方推送走 APNS，不能自建后台推送服务
3. **需要 Developer 账号**：APNS 需要 .p8 Auth Key
4. **真机调试**：模拟器不支持 APNS
5. **Payload 限制**：APNS 单条消息最大 4KB
6. **Token 失效**：卸载重装、系统更新等情况下 token 会失效，后端已自动处理

## 后端配套

iOS App 需要后端支持以下接口：

- `POST /api/device/register-token` — APNS token 上报
- `WS /ws` — WebSocket 鉴权与消息推送
- `GET /api/device/messages` — 离线消息拉取

后端 APNS 配置：在后台管理系统 → 系统设置 → iOS APNS 推送配置中填入：
- Team ID
- Key ID
- Bundle ID
- .p8 私钥内容
- 环境（development/production）

## 常见问题

### Q: 编译报错 "No such module"

A: 本项目只使用 Apple 原生框架（SwiftUI、UIKit、UserNotifications），无需安装第三方依赖。

### Q: APNS 注册失败

A: 检查以下几点：
1. 使用真机而非模拟器
2. App 是否有通知权限（设置 → 推送助手 → 通知）
3. Bundle ID 是否与 Apple Developer 后台注册的一致
4. entitlements 中的 aps-environment 是否正确

### Q: 收不到推送

A: 排查步骤：
1. 检查后端 APNS 配置是否正确（Team ID / Key ID / .p8）
2. 检查后端 `apns_active` 字段是否为 1
3. 查看后端日志：`journalctl -u push-http -f`
4. 在后台管理界面使用「测试推送」功能

### Q: WebSocket 连接不上

A: 检查以下几点：
1. 服务器地址是否正确（含端口号）
2. 服务器 9502 端口是否开放
3. 如果用 Nginx 代理，确认 /ws 路径已正确代理
4. HTTP 协议会自动转为 ws://，HTTPS 会自动转为 wss://

### Q: 后台收不到消息

A: 这是正常的 iOS 限制：
1. App 在后台时 WebSocket 会被系统挂起
2. 后台消息会通过 APNS 投递（需配置 APNS）
3. 如果 APNS 未配置，消息会存为离线，App 重开后拉取
