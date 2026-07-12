# Checklist

## 后端推送服务
- [x] backend/ 工程骨架创建完成，Composer 依赖正确引入（Swoole、JWT、Redis）
- [x] .env 配置模板包含数据库、Redis、WebSocket 端口、JWT 密钥、AES 密钥
- [x] 数据库迁移脚本可执行，8 张核心表结构正确（users/admins/devices/push_keys/messages/blacklists/push_logs/admin_logs）
- [x] Swoole WebSocket Server 可启动，onOpen/onMessage/onClose 事件处理正确
- [x] 连接鉴权：Key 有效性校验、设备指纹记录、黑名单拦截生效
- [x] Key→设备 映射关系在内存表与 Redis 中一致
- [x] 心跳检测：客户端自定义间隔生效，3 次无响应断开
- [x] 推送分发：单人/多人/Key 维度推送均可达
- [x] 离线消息在 Redis 中保留 24 小时，设备上线后补发

## 用户与管理员认证
- [x] 图形验证码生成接口返回 base64 图片 + 加密 token
- [x] 短信/邮件验证码使用 AES 加密存储，5 分钟过期
- [x] 注册接口校验验证码密文，密码 bcrypt 哈希存储
- [x] 登录接口签发 JWT Token
- [x] 管理员登录独立接口，操作日志中间件记录所有修改动作
- [x] 管理员账号可被超级管理员修改账号、密码、权限
- [x] 修改管理员后旧密码立即失效

## 开放 API
- [x] 管理员后台可生成、禁用 API Key
- [x] `POST /api/push` 接口校验 API Key 后转发到 WebSocket 推送通道
- [x] 返回推送结果包含成功数、失败数、设备明细
- [x] 推送日志写入 push_logs 表

## 设备指纹与风控
- [x] WebSocket 连接时采集设备 ID、IP、UA、型号、系统版本、指纹（SHA256）
- [x] 设备列表查询接口分页 10 条，支持关键词搜索
- [x] 拉黑接口按设备 ID / IP / 指纹拉黑，立即断开在线连接
- [x] 解黑接口生效后设备可重新连接

## Android APP
- [x] Android 工程 targetSdk 适配 Android 14 / HyperOS，minSdk 21
- [x] Key 输入页：输入 Key → 校验 → 保存本地
- [x] WebSocket 长连接：连接、重连、心跳、消息接收正常
- [x] 通知栏展示：Channel 分类、高优先级、点击跳转
- [x] 消息列表页展示历史消息
- [x] 前台 Service + 常驻通知保活
- [x] 引导申请自启动权限、省电白名单、锁屏清理白名单
- [x] WorkManager 定时唤醒检查连接状态

## APK 自动打包环境
- [x] 一键初始化脚本安装 JDK 17 + Android SDK + Gradle + Node
- [x] Gradle 构建脚本支持注入应用名、默认 Key、服务器地址、图标
- [x] 打包任务队列：接收请求 → 执行构建 → 产出 APK → 返回下载链接
- [x] 构建日志可查看

## 管理后台 UI
- [x] Vue3 + Vite + Element Plus + Pinia 工程初始化完成
- [x] 设计 Token 统一（颜色、圆角、间距、字体）
- [x] 亮色/暗色主题切换功能
- [x] 布局含侧边导航、顶部栏、面包屑、标签页
- [x] 登录页含图形验证码 + 账号密码
- [x] 仪表盘含数据卡片 + ECharts 图表
- [x] 所有列表页分页 10 条，支持搜索
- [x] 用户管理、Key 管理、设备管理、推送记录、黑名单、管理员管理页面功能完整
- [x] APP 生成页可触发打包并下载 APK
- [x] 开放 API 管理页可生成、禁用 API Key
- [x] 系统设置页可配置服务器地址、默认心跳间隔、离线消息保留时长

## 测试与部署
- [x] 后端单元测试通过（推送分发、心跳、鉴权、黑名单）
- [x] API 接口测试集合可运行
- [x] 部署脚本含 systemd 服务、Nginx 反代、PHP-FPM 重启脚本
- [x] 服务器更新 bash 脚本可拉取代码并重启 PHP-FPM 清 opcache
