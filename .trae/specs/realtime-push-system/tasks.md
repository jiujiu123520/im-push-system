# Tasks

## 阶段一：后端推送服务基础设施
- [x] Task 1: 初始化后端工程骨架（PHP + Swoole + Composer，目录结构、配置文件、基础路由）
  - [x] SubTask 1.1: 创建 `backend/` 目录，初始化 Composer 项目，引入 Swoole、JWT、Redis 客户端依赖
  - [x] SubTask 1.2: 编写 `.env` 配置模板（数据库、Redis、WebSocket 端口、JWT 密钥、AES 密钥）
  - [x] SubTask 1.3: 实现数据库迁移脚本（users / admins / devices / push_keys / messages / blacklists / push_logs / admin_logs 表）
- [x] Task 2: 实现 WebSocket 长连接推送服务
  - [x] SubTask 2.1: 创建 Swoole WebSocket Server，监听端口，处理 onOpen/onMessage/onClose
  - [x] SubTask 2.2: 实现连接鉴权（校验 Key 有效性、设备指纹、黑名单拦截）
  - [x] SubTask 2.3: 实现 Key→设备 映射关系维护（内存表 + Redis 持久化）
  - [x] SubTask 2.4: 实现心跳检测（解析客户端 heartbeat_interval，定时 ping，3 次无响应断开）
  - [x] SubTask 2.5: 实现推送分发器（单人 / 多人 / Key 维度，离线消息入 Redis 队列保留 24 小时）

## 阶段二：用户与管理员认证
- [x] Task 3: 实现用户注册与登录（验证码加密）
  - [x] SubTask 3.1: 实现图形验证码生成接口（返回 base64 + 加密 token）
  - [x] SubTask 3.2: 实现短信/邮件验证码发送（生成 6 位，AES 加密存 Redis 5 分钟过期）
  - [x] SubTask 3.3: 实现注册接口（校验验证码密文 + 密码 bcrypt 哈希存储）
  - [x] SubTask 3.4: 实现登录接口（校验验证码 + 密码，签发 JWT）
- [x] Task 4: 实现管理员账号管理
  - [x] SubTask 4.1: 实现管理员登录（独立接口、JWT、操作日志中间件）
  - [x] SubTask 4.2: 实现管理员列表 / 新增 / 修改账号密码 / 删除接口
  - [x] SubTask 4.3: 实现操作日志记录（修改人、时间、对象、动作）

## 阶段三：对外开放 API
- [x] Task 5: 实现开放 API 推送接口
  - [x] SubTask 5.1: 实现 API Key 生成与管理接口（管理员后台生成、禁用）
  - [x] SubTask 5.2: 实现 `POST /api/push` 接口（校验 API Key → 解析 target 类型 → 调用推送分发器）
  - [x] SubTask 5.3: 返回推送结果（成功数、失败数、设备明细）
  - [x] SubTask 5.4: 记录推送日志到 push_logs 表

## 阶段四：设备指纹与风控
- [x] Task 6: 实现设备指纹与 IP 记录
  - [x] SubTask 6.1: WebSocket 连接时采集设备 ID、IP、UA、型号、系统版本、指纹（SHA256）
  - [x] SubTask 6.2: 实现设备列表查询接口（分页 10 条，支持关键词搜索）
  - [x] SubTask 6.3: 实现拉黑接口（按设备 ID / IP / 指纹拉黑，立即断开在线连接）
  - [x] SubTask 6.4: 实现解黑接口

## 阶段五：Android APP 开发
- [x] Task 7: 初始化 Android 工程
  - [x] SubTask 7.1: 创建 Kotlin 工程，targetSdk 适配 Android 14 / HyperOS，minSdk 21
  - [x] SubTask 7.2: 配置依赖（WebSocket 客户端 OkHttp、WorkManager、通知渠道）
  - [x] SubTask 7.3: 实现应用图标、启动页、主界面骨架（Jetpack Compose）
- [x] Task 8: 实现 APP 核心功能
  - [x] SubTask 8.1: 实现 Key 输入页（输入 Key → 校验 → 保存到本地）
  - [x] SubTask 8.2: 实现 WebSocket 长连接管理（连接、重连、心跳、消息接收）
  - [x] SubTask 8.3: 实现通知栏展示（Channel 分类、高优先级、点击跳转）
  - [x] SubTask 8.4: 实现消息列表页（展示历史消息）
- [x] Task 9: 实现 HyperOS 后台保活
  - [x] SubTask 9.1: 实现前台 Service + 常驻通知
  - [x] SubTask 9.2: 引导用户申请自启动权限、省电白名单、锁屏清理白名单
  - [x] SubTask 9.3: 实现 WorkManager 定时唤醒检查连接状态

## 阶段六：APK 自动打包环境
- [x] Task 10: 搭建服务器打包环境
  - [x] SubTask 10.1: 编写一键初始化脚本（安装 JDK 17 + Android SDK + Gradle + Node）
  - [x] SubTask 10.2: 配置 Gradle 构建脚本（支持注入应用名、默认 Key、服务器地址、图标）
  - [x] SubTask 10.3: 实现打包任务队列（接收后台请求 → 执行构建 → 产出 APK → 返回下载链接）
  - [x] SubTask 10.4: 记录构建日志并提供查看

## 阶段七：管理后台 UI
- [x] Task 11: 初始化管理后台工程
  - [x] SubTask 11.1: 创建 Vue3 + Vite + Element Plus + Pinia 工程
  - [x] SubTask 11.2: 配置设计 Token（颜色、圆角、间距、字体），实现亮色/暗色主题切换
  - [x] SubTask 11.3: 实现布局（侧边导航、顶部栏、面包屑、标签页）
- [x] Task 12: 实现后台各功能页面
  - [x] SubTask 12.1: 登录页（图形验证码 + 账号密码）
  - [x] SubTask 12.2: 仪表盘（数据卡片 + ECharts 图表：在线设备、今日推送、Key 数量）
  - [x] SubTask 12.3: 用户管理（列表、分页 10 条、搜索、禁用）
  - [x] SubTask 12.4: Key 管理（列表、新增、编辑、禁用、查看订阅设备）
  - [x] SubTask 12.5: 设备管理（列表、分页、搜索、拉黑/解黑、查看详情）
  - [x] SubTask 12.6: 推送记录（列表、分页、搜索、详情、重发）
  - [x] SubTask 12.7: 黑名单管理（列表、分页、解黑）
  - [x] SubTask 12.8: 管理员管理（列表、新增、修改、删除、操作日志）
  - [x] SubTask 12.9: APP 生成页（填写配置 → 触发打包 → 下载 APK）
  - [x] SubTask 12.10: 开放 API 管理（API Key 生成、列表、禁用）
  - [x] SubTask 12.11: 系统设置（服务器地址、默认心跳间隔、离线消息保留时长）

## 阶段八：测试与部署
- [x] Task 13: 编写测试与部署文档
  - [x] SubTask 13.1: 后端单元测试（推送分发、心跳、鉴权、黑名单）
  - [x] SubTask 13.2: API 接口测试（Postman 集合）
  - [x] SubTask 13.3: 部署脚本（systemd 服务、Nginx 反代、PHP-FPM 重启脚本）
  - [x] SubTask 13.4: 服务器更新 bash 脚本（拉取代码 + 重启 PHP-FPM 清 opcache）

# Task Dependencies
- [Task 2] 依赖 [Task 1]
- [Task 3] 依赖 [Task 1]
- [Task 4] 依赖 [Task 1]
- [Task 5] 依赖 [Task 2]
- [Task 6] 依赖 [Task 2]
- [Task 8] 依赖 [Task 7]
- [Task 9] 依赖 [Task 8]
- [Task 10] 依赖 [Task 7]
- [Task 12] 依赖 [Task 11]
- [Task 12.9 APP 生成页] 依赖 [Task 10]
- [Task 13] 依赖 [Task 1-12 全部完成]
- 可并行：[Task 3] 与 [Task 4]；[Task 7] 与 [Task 11]；[Task 10] 与 [Task 12]（除 12.9 外）
