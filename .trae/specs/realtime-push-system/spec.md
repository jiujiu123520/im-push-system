# 即时消息推送系统 Spec

## Why
当前需要一个支持高并发、多场景的即时消息推送解决方案，覆盖 APP 自动生成、Key 分组推送、后台保活、设备指纹管理等核心能力，解决小米最新系统（HyperOS）下推送可达性差、APP 易被杀进程、推送通道分散难管理的问题。

## What Changes
- 新增后端推送服务（PHP + Swoole WebSocket 长连接服务）
- 新增 Android APP（原生 Kotlin，适配 HyperOS / Android 14+，支持前台保活 + 通知栏展示）
- 新增后台 APK 自动打包环境（Jenkins / Gradle Docker 镜像，按 Key 注入配置后产出 APK）
- 新增管理后台（Vue3 + Element Plus 美化 UI，非简单堆砌组件）
- 新增用户注册/登录（图形验证码 + 短信/邮件验证码，验证码加密存储）
- 新增 Key 管理体系（一个 Key 多人接收、单人推送、多人推送、Key 维度推送）
- 新增对外开放 API（基于 API Key 鉴权的推送接口）
- 新增设备指纹 & IP 记录（用于拉黑、风控）
- 新增心跳机制（客户端可自定义心跳间隔）
- 新增管理员账号管理（支持修改账号、密码、权限）

## Impact
- Affected specs: 无（全新项目）
- Affected code: 全新代码库，包含 `backend/`（PHP 推送服务 + API）、`app/`（Android 工程）、`admin/`（管理后台）、`build/`（APK 打包流水线）

## ADDED Requirements

### Requirement: APP 自动生成
系统 SHALL 支持管理员在后台触发 APP 打包，打包时将指定的默认 Key、服务器地址、应用名称等配置注入 APK，产出可分发的安装包。

#### Scenario: 后台生成 APP
- **WHEN** 管理员在后台填写应用名称、默认 Key、服务器地址、图标后点击「生成 APP」
- **THEN** 系统将任务加入打包队列，调用构建环境执行 Gradle 打包
- **AND** 打包完成后在后台展示下载链接，记录构建日志

#### Scenario: 打包环境就绪
- **WHEN** 服务器首次部署
- **THEN** 提供一键脚本初始化 JDK 17 + Android SDK + Gradle + Node 构建环境

### Requirement: 推送通道
系统 SHALL 提供基于 WebSocket 的长连接推送通道，支持单设备、多设备、Key 维度推送。

#### Scenario: 单人推送
- **WHEN** 调用推送 API 指定单个设备 ID
- **THEN** 仅该设备收到消息并在通知栏展示

#### Scenario: 多人推送
- **WHEN** 调用推送 API 指定多个设备 ID
- **THEN** 所有目标设备收到消息

#### Scenario: Key 推送
- **WHEN** 调用推送 API 指定一个 Key
- **THEN** 所有订阅该 Key 的在线设备收到消息
- **AND** 离线设备在重新上线后收到离线消息（保留 24 小时）

#### Scenario: 一个 Key 多人使用
- **WHEN** 多个设备输入同一个 Key
- **THEN** 所有设备均订阅该 Key 的消息通道，均可接收推送

### Requirement: APP 后台保活（HyperOS 适配）
APP SHALL 在小米最新系统（HyperOS / MIUI 14+）下保持后台运行，避免被系统杀死导致收不到推送。

#### Scenario: 前台服务保活
- **WHEN** APP 启动后
- **THEN** 启动前台 Service（Foreground Service）并展示常驻通知
- **AND** 申请自启动权限、省电策略白名单、锁屏清理白名单

#### Scenario: 通知栏展示
- **WHEN** 收到推送消息
- **THEN** 在系统通知栏展示，支持自定义标题、内容、图标、点击跳转
- **AND** 支持渠道（Channel）分类，重要消息使用高优先级渠道

### Requirement: 心跳机制
系统 SHALL 支持客户端自定义心跳间隔，维持长连接稳定。

#### Scenario: 自定义心跳
- **WHEN** 客户端连接时携带 `heartbeat_interval` 参数（单位秒，范围 10-300）
- **THEN** 服务端按该间隔检测连接存活
- **AND** 超过 3 次心跳未响应则断开连接并标记离线

### Requirement: 开放 API
系统 SHALL 提供基于 API Key 鉴权的 HTTP 推送接口，供第三方系统对接。

#### Scenario: API 推送
- **WHEN** 第三方携带 API Key 调用 `POST /api/push`
- **THEN** 校验 API Key 有效性后转发到 WebSocket 推送通道
- **AND** 返回推送结果（成功数、失败数）

### Requirement: Key 接收
APP SHALL 支持用户仅输入 Key 即可开始接收消息，无需复杂注册流程。

#### Scenario: 输入 Key 接收
- **WHEN** 用户在 APP 输入有效 Key
- **THEN** APP 建立到服务器的 WebSocket 连接并订阅该 Key
- **AND** 在首页展示连接状态与最近消息

### Requirement: 用户注册与登录
用户注册与登录 SHALL 输入验证码，验证码加密存储，防止暴力破解。

#### Scenario: 注册流程
- **WHEN** 用户提交手机号/邮箱并请求验证码
- **THEN** 系统生成 6 位验证码，使用 AES 加密后存入缓存（5 分钟过期）
- **AND** 通过短信/邮件发送明文验证码

#### Scenario: 登录流程
- **WHEN** 用户输入账号、密码、验证码登录
- **THEN** 校验验证码密文与密码哈希后签发 JWT Token

### Requirement: 管理员账号管理
系统 SHALL 支持管理员修改账号、密码、权限，记录操作日志。

#### Scenario: 修改管理员
- **WHEN** 超级管理员在后台修改某管理员的账号或密码
- **THEN** 即时生效，旧密码失效
- **AND** 记录修改人、修改时间、被修改人

### Requirement: 设备指纹与 IP 记录
系统 SHALL 记录每个连接设备的 IP 与设备指纹，支持拉黑处理。

#### Scenario: 记录设备
- **WHEN** 设备首次连接
- **THEN** 记录设备 ID、IP、UA、设备型号、系统版本、连接时间、设备指纹

#### Scenario: 拉黑处理
- **WHEN** 管理员在后台对某设备/IP/指纹点击「拉黑」
- **THEN** 该设备再次连接时被拒绝
- **AND** 已建立的连接被立即断开

### Requirement: 分页处理
后台所有列表页 SHALL 采用分页展示，每页 10 条，支持翻页与搜索。

#### Scenario: 分页查询
- **WHEN** 管理员访问设备列表、用户列表、推送记录等
- **THEN** 默认每页 10 条，提供上一页/下一页/跳页控件
- **AND** 支持按关键词搜索过滤

### Requirement: UI 美化
管理后台 SHALL 采用高品质 UI 设计，非简单组件堆砌，具备统一设计语言、动效、主题切换。

#### Scenario: UI 设计规范
- **WHEN** 访问管理后台任意页面
- **THEN** 遵循统一的设计 Token（颜色、圆角、间距、字体）
- **AND** 包含侧边导航、顶部栏、面包屑、数据卡片、图表等精细化组件
- **AND** 支持亮色/暗色主题切换

## MODIFIED Requirements
无（全新项目）

## REMOVED Requirements
无
