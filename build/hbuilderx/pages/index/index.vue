<template>
    <view class="container">
        <!-- 登录页面 -->
        <view v-if="!isLoggedIn" class="login-page">
            <view class="login-container">
                <view class="logo-section">
                    <view class="logo-icon">
                        <text class="logo-text">📨</text>
                    </view>
                    <text class="app-title">消息推送</text>
                    <text class="app-subtitle">即时消息推送客户端</text>
                </view>
                <view class="login-form">
                    <view class="form-group">
                        <text class="form-label">推送 Key</text>
                        <input class="form-input" v-model="form.key" placeholder="请输入推送 Key" />
                    </view>
                    <view class="form-group">
                        <text class="form-label">服务器地址</text>
                        <input class="form-input" v-model="form.serverUrl" placeholder="http://example.com:9501" />
                    </view>
                    <view class="form-group">
                        <text class="form-label">WebSocket 地址</text>
                        <input class="form-input" v-model="form.wsUrl" placeholder="留空则自动从服务器地址推导" />
                    </view>
                    <button class="btn-primary" @click="handleLogin" :loading="connecting">
                        {{ connecting ? '连接中...' : '连接' }}
                    </button>
                </view>
            </view>
        </view>

        <!-- 主页面 -->
        <view v-else class="main-page">
            <!-- 顶部状态栏 -->
            <view class="header">
                <view class="header-left">
                    <text class="header-title">消息推送</text>
                    <view :class="['status-dot', connected ? 'connected' : 'disconnected']"></view>
                </view>
                <view class="header-right">
                    <text class="setting-icon" @click="showSettings = true">⚙️</text>
                </view>
            </view>

            <!-- 统计卡片 -->
            <view class="stats-section">
                <view class="stat-card">
                    <text class="stat-value">{{ todayCount }}</text>
                    <text class="stat-label">今日推送</text>
                </view>
                <view class="stat-card">
                    <text class="stat-value">{{ totalCount }}</text>
                    <text class="stat-label">累计推送</text>
                </view>
                <view class="stat-card">
                    <text class="stat-value">{{ deviceIdShort }}</text>
                    <text class="stat-label">设备ID</text>
                </view>
            </view>

            <!-- 消息列表 -->
            <view class="message-section">
                <view class="section-header">
                    <text class="section-title">消息记录</text>
                    <text class="clear-btn" @click="clearMessages">清空</text>
                </view>
                <scroll-view scroll-y class="message-list" v-if="messages.length > 0">
                    <view v-for="(msg, index) in messages" :key="index" class="message-item">
                        <text class="message-title">{{ msg.title }}</text>
                        <text class="message-content">{{ msg.content }}</text>
                        <text class="message-time">{{ formatTime(msg.time) }}</text>
                    </view>
                </scroll-view>
                <view v-else class="empty-state">
                    <text class="empty-icon">📭</text>
                    <text class="empty-text">暂无消息</text>
                    <text class="empty-desc">推送的消息将显示在这里</text>
                </view>
            </view>
        </view>

        <!-- 设置弹窗 -->
        <view v-if="showSettings" class="settings-mask" @click="showSettings = false">
            <view class="settings-dialog" @click.stop>
                <view class="settings-header">
                    <text class="settings-title">设置</text>
                    <text class="close-btn" @click="showSettings = false">✕</text>
                </view>
                <view class="settings-content">
                    <view class="setting-item">
                        <text class="setting-label">推送 Key</text>
                        <text class="setting-value">{{ form.key }}</text>
                    </view>
                    <view class="setting-item">
                        <text class="setting-label">服务器地址</text>
                        <text class="setting-value">{{ form.serverUrl }}</text>
                    </view>
                    <view class="setting-item setting-item-column">
                        <text class="setting-label">WebSocket 地址</text>
                        <input class="setting-input" v-model="wsUrl" placeholder="ws://example.com:9502" />
                        <button class="btn-sm" @click="handleChangeWsUrl">应用并重连</button>
                    </view>
                    <view class="setting-item">
                        <text class="setting-label">应用版本</text>
                        <text class="setting-value">{{ versionName }}</text>
                    </view>
                    <view class="setting-item setting-item-column">
                        <text class="setting-label">通用权限</text>
                        <text class="setting-tip">点击下方按钮跳转系统设置，开启对应权限以保证后台推送正常接收</text>
                        <button class="btn-sm" @click="openPermission('app')">应用详情（权限总开关）</button>
                        <button class="btn-sm" @click="openPermission('notification')">通知权限</button>
                        <button class="btn-sm" @click="openPermission('battery')">电池优化白名单</button>
                        <button class="btn-sm" @click="openPermission('autostart')">自启动管理</button>
                    </view>
                    <!-- 小米手机专属权限（MIUI / HyperOS） -->
                    <view v-if="isXiaomiDevice" class="setting-item setting-item-column">
                        <text class="setting-label">小米手机专属权限</text>
                        <text class="setting-tip">小米 / Redmi / POCO 手机（MIUI / HyperOS）需额外开启以下权限以保证后台推送稳定</text>
                        <button class="btn-sm btn-xiaomi" @click="openXiaomiPermission('autostart')">自启动（设为允许）</button>
                        <button class="btn-sm btn-xiaomi" @click="openXiaomiPermission('battery_saver')">省电策略（设为无限制）</button>
                        <button class="btn-sm btn-xiaomi" @click="openXiaomiPermission('background_popup')">后台弹出界面（允许）</button>
                        <button class="btn-sm btn-xiaomi" @click="openXiaomiPermission('lockscreen_show')">锁屏显示（允许）</button>
                        <button class="btn-sm btn-xiaomi" @click="openXiaomiPermission('floating_window')">悬浮窗（允许）</button>
                        <button class="btn-sm btn-xiaomi" @click="openXiaomiPermission('notification_channel')">通知设置（允许通知+渠道）</button>
                        <button class="btn-sm btn-xiaomi" @click="openXiaomiPermission('notification_service')">通知使用权</button>
                        <button class="btn-sm btn-xiaomi" @click="openXiaomiPermission('developer_keep_alive')">开发者选项-后台进程限制</button>
                        <text class="setting-tip setting-tip-warn">⚠️ 以上权限全部开启后，小米手机后台推送稳定性可大幅提升</text>
                    </view>
                </view>
                <view class="settings-footer">
                    <button class="btn-danger" @click="handleLogout">退出登录</button>
                </view>
            </view>
        </view>
    </view>
</template>

<script>
import { APP_CONFIG } from '@/config.js'

export default {
    data() {
        return {
            isLoggedIn: false,
            connecting: false,
            connected: false,
            showSettings: false,
            form: {
                key: '',
                serverUrl: '',
                wsUrl: ''
            },
            wsUrl: '',
            messages: [],
            todayCount: 0,
            totalCount: 0,
            deviceId: '',
            versionName: APP_CONFIG.version_name,
            socketTask: null,
            heartbeatTimer: null,
            heartbeatTimeoutTimer: null,
            reconnectTimer: null,
            connectTimeoutTimer: null,
            reconnectDelay: 3000,
            maxReconnectDelay: 60000,
            isXiaomiDevice: false
        }
    },
    computed: {
        deviceIdShort() {
            return this.deviceId ? this.deviceId.substring(0, 8) : '--'
        }
    },
    onLoad() {
        this.initDeviceId()
        this.checkXiaomiDevice()
        this.loadConfig()
        this.loadMessages()
        this.checkAutoLogin()
        this.registerNetworkListener()
    },
    onShow() {
        // APP 从后台切回前台 / 页面重新显示时，主动检测并重连断开的 WebSocket
        // 移动系统在后台会冻结网络连接，需要主动恢复
        if (this.isLoggedIn) {
            // 连接已断开，直接重连
            if (!this.connected && !this.connecting) {
                console.log('页面 onShow 检测到连接已断开，主动重连')
                this.cleanupAndReconnect()
                return
            }
            // connecting 状态卡住超过 15 秒，强制重连
            if (this.connecting && this._connectStartTime) {
                const elapsed = Date.now() - this._connectStartTime
                if (elapsed > 15000) {
                    console.warn('页面 onShow 检测到 connecting 状态卡住，强制重连')
                    this.cleanupAndReconnect()
                    return
                }
            }
            // connected 为 true 但 socketTask 不存在，状态不一致，重置并重连
            if (this.connected && !this.socketTask) {
                console.warn('页面 onShow 检测到状态不一致（connected=true 但无 socketTask），重置重连')
                this.connected = false
                this.cleanupAndReconnect()
                return
            }
            // 连接存在，发一个验证 ping 确认连接真的活着
            // 后台切回前台时，TCP 连接可能已经静默断开但 onClose 还没触发
            if (this.connected && this.socketTask) {
                console.log('页面 onShow，发送验证 ping 确认连接存活')
                try {
                    this.socketTask.send({
                        data: JSON.stringify({ type: 'ping' }),
                        fail: (err) => {
                            console.warn('onShow 验证 ping 发送失败，连接已失效，触发重连', err)
                            this.cleanupAndReconnect()
                        }
                    })
                } catch (e) {
                    console.warn('onShow 验证 ping 异常，触发重连', e)
                    this.cleanupAndReconnect()
                }
            }
        }
    },
    onUnload() {
        this.closeSocket()
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer)
        }
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer)
        }
        if (this.connectTimeoutTimer) {
            clearTimeout(this.connectTimeoutTimer)
        }
        // 移除网络监听
        // #ifdef APP-PLUS
        try {
            uni.offNetworkStatusChange(this.networkStatusChange)
        } catch (e) {}
        // #endif
    },
    methods: {
        initDeviceId() {
            let deviceId = uni.getStorageSync('push_device_id')
            if (!deviceId) {
                deviceId = 'app-' + Math.random().toString(36).substring(2, 10) + Date.now().toString(36)
                uni.setStorageSync('push_device_id', deviceId)
            }
            this.deviceId = deviceId
        },
        loadConfig() {
            const savedKey = uni.getStorageSync('push_key')
            const savedServer = uni.getStorageSync('push_server')
            const savedWs = uni.getStorageSync('push_ws')

            if (savedKey) {
                this.form.key = savedKey
            } else {
                this.form.key = APP_CONFIG.default_key
            }

            if (savedServer) {
                this.form.serverUrl = savedServer
            } else {
                this.form.serverUrl = APP_CONFIG.server_url
            }

            if (savedWs) {
                this.wsUrl = savedWs
                this.form.wsUrl = savedWs
            } else {
                this.wsUrl = APP_CONFIG.ws_url
                this.form.wsUrl = ''
            }
        },
        loadMessages() {
            try {
                const saved = uni.getStorageSync('push_messages')
                if (saved) {
                    this.messages = JSON.parse(saved)
                    this.updateStats()
                }
            } catch (e) {
                console.error('加载消息失败', e)
            }
        },
        saveMessages() {
            try {
                uni.setStorageSync('push_messages', JSON.stringify(this.messages.slice(0, 100)))
            } catch (e) {
                console.error('保存消息失败', e)
            }
        },
        startForegroundService() {
            // #ifdef APP-PLUS
            try {
                const main = plus.android.runtimeMainActivity()
                const Context = plus.android.importClass('android.content.Context')
                const NotificationManager = plus.android.importClass('android.app.NotificationManager')
                const NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
                const Build = plus.android.importClass('android.os.Build')
                const PendingIntent = plus.android.importClass('android.app.PendingIntent')
                const PowerManager = plus.android.importClass('android.os.PowerManager')
                const WifiManager = plus.android.importClass('android.net.wifi.WifiManager')

                const channelId = 'push_keep_alive'
                const notificationId = 1001

                // 创建通知渠道（Android 8.0+）
                if (Build.VERSION.SDK_INT >= 26) {
                    const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                    const channel = nm.getNotificationChannel(channelId)
                    if (channel === null || channel === undefined) {
                        const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
                        const importance = NotificationManager.IMPORTANCE_LOW
                        const mChannel = new NotificationChannel(channelId, '推送服务保活', importance)
                        mChannel.setShowBadge(false)
                        nm.createNotificationChannel(mChannel)
                    }
                }

                // 创建点击跳转到 APP 的 Intent
                const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
                const contentIntent = PendingIntent.getActivity(
                    main, 0, launchIntent,
                    Build.VERSION.SDK_INT >= 31 ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE : PendingIntent.FLAG_UPDATE_CURRENT
                )

                // 构建前台服务通知
                const builder = new NotificationCompat.Builder(main, channelId)
                builder.setContentTitle('推送服务运行中')
                builder.setContentText('保持后台连接，实时接收推送消息')
                builder.setSmallIcon(main.getApplicationInfo().icon)
                builder.setContentIntent(contentIntent)
                builder.setOngoing(true)
                builder.setAutoCancel(false)
                builder.setPriority(NotificationCompat.PRIORITY_LOW)

                const notification = builder.build()

                // 显示常驻通知
                const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                nm.notify(notificationId, notification)

                // 获取 WakeLock（保持 CPU 唤醒，PARTIAL_WAKE_LOCK）
                try {
                    const pm = main.getSystemService(Context.POWER_SERVICE)
                    if (!this._wakeLock) {
                        this._wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:WakeLock')
                        this._wakeLock.setReferenceCounted(false)
                    }
                    if (!this._wakeLock.isHeld()) {
                        this._wakeLock.acquire()
                        console.log('WakeLock 已获取')
                    }
                } catch (e) {
                    console.warn('获取 WakeLock 失败', e)
                }

                // 获取 WifiLock（保持 Wi-Fi 连接）
                try {
                    const wm = main.getApplicationContext().getSystemService(Context.WIFI_SERVICE)
                    if (!this._wifiLock) {
                        this._wifiLock = wm.createWifiLock(WifiManager.WIFI_MODE_FULL_HIGH_PERF, 'PushApp:WifiLock')
                        this._wifiLock.setReferenceCounted(false)
                    }
                    if (!this._wifiLock.isHeld()) {
                        this._wifiLock.acquire()
                        console.log('WifiLock 已获取')
                    }
                } catch (e) {
                    console.warn('获取 WifiLock 失败', e)
                }

                // 同时使用 plus.push 的常驻通知（双保险）
                if (typeof plus !== 'undefined' && plus.push) {
                    try {
                        plus.push.setAutoNotification(true)
                    } catch (e) {}
                }

                console.log('前台服务保活已启动（通知 + WakeLock + WifiLock）')
            } catch (e) {
                console.error('启动前台服务失败', e)
            }
            // #endif
        },
        stopForegroundService() {
            // #ifdef APP-PLUS
            try {
                const main = plus.android.runtimeMainActivity()
                const Context = plus.android.importClass('android.content.Context')
                const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                nm.cancel(1001)

                // 释放 WakeLock
                if (this._wakeLock) {
                    try {
                        if (this._wakeLock.isHeld()) {
                            this._wakeLock.release()
                        }
                    } catch (e) {}
                    this._wakeLock = null
                }

                // 释放 WifiLock
                if (this._wifiLock) {
                    try {
                        if (this._wifiLock.isHeld()) {
                            this._wifiLock.release()
                        }
                    } catch (e) {}
                    this._wifiLock = null
                }

                console.log('前台服务保活已停止')
            } catch (e) {
                console.error('停止前台服务失败', e)
            }
            // #endif
        },
        createNotificationChannel() {
            // #ifdef APP-PLUS
            try {
                const Build = plus.android.importClass('android.os.Build')
                if (Build.VERSION.SDK_INT < 26) {
                    return
                }
                const main = plus.android.runtimeMainActivity()
                const Context = plus.android.importClass('android.content.Context')
                const NotificationManager = plus.android.importClass('android.app.NotificationManager')
                const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')

                const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)

                // 消息推送通知渠道（高优先级，会响铃震动）
                const msgChannelId = 'push_messages'
                const msgChannel = nm.getNotificationChannel(msgChannelId)
                if (msgChannel === null || msgChannel === undefined) {
                    const importance = NotificationManager.IMPORTANCE_HIGH
                    const channel = new NotificationChannel(msgChannelId, '消息推送', importance)
                    channel.enableLights(true)
                    channel.enableVibration(true)
                    channel.setShowBadge(true)
                    nm.createNotificationChannel(channel)
                    console.log('消息推送通知渠道已创建')
                }
            } catch (e) {
                console.error('创建通知渠道失败', e)
            }
            // #endif
        },
        showNotification(title, content) {
            // #ifdef APP-PLUS
            try {
                const main = plus.android.runtimeMainActivity()
                const Context = plus.android.importClass('android.content.Context')
                const Intent = plus.android.importClass('android.content.Intent')
                const NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
                const PendingIntent = plus.android.importClass('android.app.PendingIntent')
                const Build = plus.android.importClass('android.os.Build')
                const NotificationManager = plus.android.importClass('android.app.NotificationManager')
                const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')

                const channelId = 'push_messages'
                const notificationId = Math.floor(Math.random() * 100000) + 1

                // 确保通知渠道存在（Android 8.0+）
                if (Build.VERSION.SDK_INT >= 26) {
                    const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                    const channel = nm.getNotificationChannel(channelId)
                    if (channel === null || channel === undefined) {
                        const importance = NotificationManager.IMPORTANCE_HIGH
                        const mChannel = new NotificationChannel(channelId, '消息推送', importance)
                        mChannel.enableLights(true)
                        mChannel.enableVibration(true)
                        mChannel.setShowBadge(true)
                        nm.createNotificationChannel(mChannel)
                        console.log('消息推送通知渠道已创建')
                    }
                }

                // 创建点击跳转到 APP 的 Intent
                const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
                launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
                const contentIntent = PendingIntent.getActivity(
                    main, notificationId, launchIntent,
                    Build.VERSION.SDK_INT >= 31 ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE : PendingIntent.FLAG_UPDATE_CURRENT
                )

                // 构建通知
                const builder = new NotificationCompat.Builder(main, channelId)
                builder.setContentTitle(title || '新消息')
                builder.setContentText(content || '')
                builder.setSmallIcon(main.getApplicationInfo().icon)
                builder.setContentIntent(contentIntent)
                builder.setAutoCancel(true)
                builder.setPriority(NotificationCompat.PRIORITY_HIGH)
                builder.setDefaults(NotificationCompat.DEFAULT_SOUND | NotificationCompat.DEFAULT_VIBRATE | NotificationCompat.DEFAULT_LIGHTS)

                // 如果内容较长，使用大文本样式
                if (content && content.length > 50) {
                    const BigTextStyle = plus.android.importClass('androidx.core.app.NotificationCompat$BigTextStyle')
                    const bigText = new BigTextStyle()
                    bigText.bigText(content)
                    bigText.setBigContentTitle(title)
                    builder.setStyle(bigText)
                }

                const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                nm.notify(notificationId, builder.build())

                return true
            } catch (e) {
                console.error('显示通知失败', e)
                // 降级到 uni.showNotification
                if (uni.showNotification) {
                    uni.showNotification({ title, content })
                }
                return false
            }
            // #endif
            // #ifndef APP-PLUS
            if (uni.showNotification) {
                uni.showNotification({ title, content })
            }
            return true
            // #endif
        },
        checkAutoLogin() {
            const savedKey = uni.getStorageSync('push_key')
            const savedServer = uni.getStorageSync('push_server')

            if (savedKey && savedServer) {
                this.form.key = savedKey
                this.form.serverUrl = savedServer
                const savedWs = uni.getStorageSync('push_ws')
                this.wsUrl = savedWs || this.deriveWsUrl(savedServer)
                this.form.wsUrl = savedWs || ''
                this.isLoggedIn = true
                // 延迟 500ms 再连接，确保页面和网络都已准备好
                setTimeout(() => {
                    if (this.isLoggedIn) {
                        this.connectWebSocket()
                    }
                }, 500)
            }
        },
        deriveWsUrl(serverUrl) {
            let protocol = 'ws://'
            let hostPart = serverUrl
            if (serverUrl.startsWith('https://')) {
                protocol = 'wss://'
                hostPart = serverUrl.substring(8)
            } else if (serverUrl.startsWith('http://')) {
                protocol = 'ws://'
                hostPart = serverUrl.substring(7)
            }
            const colonIndex = hostPart.indexOf(':')
            const slashIndex = hostPart.indexOf('/')
            let host = hostPart
            let port = ''
            let path = ''
            if (slashIndex !== -1) {
                path = hostPart.substring(slashIndex)
                host = hostPart.substring(0, slashIndex)
            }
            if (colonIndex !== -1 && (slashIndex === -1 || colonIndex < slashIndex)) {
                host = hostPart.substring(0, colonIndex)
                port = hostPart.substring(colonIndex + 1, slashIndex !== -1 ? slashIndex : undefined)
            }
            const wsPortMap = {
                '80': '80',
                '443': '443',
                '9501': '9502',
                '6999': '7000'
            }
            if (port && wsPortMap[port]) {
                port = wsPortMap[port]
            } else if (port) {
                port = String(parseInt(port) + 1)
            }
            return protocol + host + (port ? ':' + port : '') + path
        },
        handleLogin() {
            if (!this.form.key.trim()) {
                uni.showToast({ title: '请输入推送 Key', icon: 'none' })
                return
            }
            if (!this.form.serverUrl.trim()) {
                uni.showToast({ title: '请输入服务器地址', icon: 'none' })
                return
            }

            const key = this.form.key.trim()
            const serverUrl = this.form.serverUrl.trim()
            const inputWs = (this.form.wsUrl || '').trim()
            const wsUrl = inputWs || this.deriveWsUrl(serverUrl)

            uni.setStorageSync('push_key', key)
            uni.setStorageSync('push_server', serverUrl)
            uni.setStorageSync('push_ws', wsUrl)

            this.wsUrl = wsUrl
            this.form.wsUrl = inputWs
            this.isLoggedIn = true
            this.connectWebSocket()
        },
        handleLogout() {
            uni.showModal({
                title: '提示',
                content: '确定要退出登录吗？',
                success: (res) => {
                    if (res.confirm) {
                        this.closeSocket()
                        this.stopForegroundService()
                        this.isLoggedIn = false
                        this.showSettings = false
                        uni.removeStorageSync('push_key')
                        uni.removeStorageSync('push_server')
                        uni.removeStorageSync('push_ws')
                    }
                }
            })
        },
        handleChangeWsUrl() {
            const inputWs = (this.wsUrl || '').trim()
            if (!inputWs) {
                uni.showToast({ title: 'WebSocket 地址不能为空', icon: 'none' })
                return
            }
            if (!/^wss?:\/\/.+/.test(inputWs)) {
                uni.showToast({ title: '地址需以 ws:// 或 wss:// 开头', icon: 'none' })
                return
            }
            uni.setStorageSync('push_ws', inputWs)
            this.wsUrl = inputWs
            this.form.wsUrl = inputWs
            this.showSettings = false
            uni.showToast({ title: '已应用，正在重连...', icon: 'none' })
            // 关闭旧连接并重连
            this.closeSocket()
            this.reconnectDelay = 3000
            this.connectWebSocket()
        },
        openPermission(type) {
            // #ifdef APP-PLUS
            try {
                const Intent = plus.android.importClass('android.content.Intent')
                const Settings = plus.android.importClass('android.provider.Settings')
                const Uri = plus.android.importClass('android.net.Uri')
                const main = plus.android.runtimeMainActivity()
                const packageName = main.getPackageName()
                const Build = plus.android.importClass('android.os.Build')

                if (type === 'notification') {
                    let launched = false
                    const notificationIntents = [
                        {
                            action: 'android.settings.APP_NOTIFICATION_SETTINGS',
                            extras: {
                                'android.provider.extra.APP_PACKAGE': packageName,
                                'app_package': packageName,
                                'pkg': packageName
                            }
                        },
                        {
                            action: Settings.ACTION_APP_NOTIFICATION_SETTINGS,
                            extras: {
                                'android.provider.extra.APP_PACKAGE': packageName
                            }
                        },
                        {
                            action: Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                            data: Uri.fromParts('package', packageName, null)
                        }
                    ]
                    for (const cfg of notificationIntents) {
                        try {
                            const intent = new Intent()
                            intent.setAction(cfg.action)
                            intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                            if (cfg.data) {
                                intent.setData(cfg.data)
                            }
                            if (cfg.extras) {
                                for (const key in cfg.extras) {
                                    intent.putExtra(key, cfg.extras[key])
                                }
                            }
                            main.startActivity(intent)
                            launched = true
                            break
                        } catch (e) {
                            // 继续尝试下一个
                        }
                    }
                    if (!launched) {
                        uni.showToast({ title: '无法打开通知设置，请手动前往系统设置', icon: 'none' })
                    }
                    return
                }

                const intent = new Intent()
                let action = null
                let data = null
                switch (type) {
                    case 'app':
                        action = Settings.ACTION_APPLICATION_DETAILS_SETTINGS
                        data = Uri.fromParts('package', packageName, null)
                        break
                    case 'battery':
                        action = Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS
                        break
                    case 'autostart':
                        // 自启动没有统一标准，尝试常见厂商 Intent
                        const brand = this.getDeviceBrand()
                        const autostartActions = {
                            'xiaomi': ['miui.intent.action.APP_AUTO_START'],
                            'huawei': ['huawei.intent.action.HSM_BOOTAPP_MANAGER'],
                            'honor': ['huawei.intent.action.HSM_BOOTAPP_MANAGER'],
                            'oppo': ['com.coloros.safecenter.permission.permission.PermissionAllAppsActivity'],
                            'vivo': ['com.iqoo.secure.MainActivity'],
                            'meizu': ['com.meizu.safe.security.SHOW_APPSEC'],
                            'samsung': ['samsung.intent.action.AUTOSTART_APP'],
                            'oneplus': ['com.android.settings.action.IGNORE_BATTERY_OPTIMIZATION_SETTINGS']
                        }
                        const actions = autostartActions[brand] || []
                        let launched = false
                        for (const act of actions) {
                            try {
                                const testIntent = new Intent()
                                testIntent.setAction(act)
                                testIntent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                main.startActivity(testIntent)
                                launched = true
                                break
                            } catch (e) {
                                // 继续尝试下一个
                            }
                        }
                        if (!launched) {
                            // 回退到应用详情页
                            uni.showToast({ title: '未找到自启动设置页，已跳转应用详情', icon: 'none' })
                            const fallback = new Intent()
                            fallback.setAction(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
                            fallback.setData(Uri.fromParts('package', packageName, null))
                            fallback.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                            main.startActivity(fallback)
                        }
                        return
                }
                if (action) {
                    intent.setAction(action)
                    if (data) {
                        intent.setData(data)
                    }
                    intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    main.startActivity(intent)
                }
            } catch (e) {
                console.error('打开权限设置失败', e)
                uni.showToast({ title: '打开设置失败：' + e.message, icon: 'none' })
            }
            // #endif
            // #ifndef APP-PLUS
            uni.showToast({ title: '此功能仅在 APP 端可用', icon: 'none' })
            // #endif
        },
        getDeviceBrand() {
            // #ifdef APP-PLUS
            try {
                const Build = plus.android.importClass('android.os.Build')
                return (Build.BRAND || '').toLowerCase()
            } catch (e) {
                return ''
            }
            // #endif
            return ''
        },
        checkXiaomiDevice() {
            const brand = this.getDeviceBrand()
            // 小米 / Redmi / POCO 都属于小米系
            this.isXiaomiDevice = brand === 'xiaomi' || brand === 'redmi' || brand === 'poco'
        },
        openXiaomiPermission(type) {
            // #ifdef APP-PLUS
            try {
                const Intent = plus.android.importClass('android.content.Intent')
                const Uri = plus.android.importClass('android.net.Uri')
                const main = plus.android.runtimeMainActivity()
                const packageName = main.getPackageName()

                // 小米 MIUI / HyperOS 各权限页面对应的 Intent 配置
                // 注意：不同 MIUI 版本页面路径可能不同，按优先级尝试
                const configs = {
                    autostart: {
                        title: '自启动',
                        actions: [
                            'miui.intent.action.APP_AUTO_START',
                            'com.miui.securitycenter.action.AUTO_START_MANAGER'
                        ],
                        // 部分版本需要直接跳转到应用详情自启动页
                        fallbackComponent: 'com.miui.securitycenter/com.miui.permcenter.autostart.AutoStartManagementActivity'
                    },
                    battery_saver: {
                        title: '省电策略',
                        actions: [
                            'miui.intent.action.POWER_HIDE_MODE_APP_LIST'
                        ],
                        fallbackComponent: 'com.miui.powerkeeper/com.miui.powerkeeper.ui.HiddenAppsConfigActivity'
                    },
                    background_popup: {
                        title: '后台弹出界面',
                        // 通过应用详情-权限管理-后台弹出界面
                        actions: [
                            'miui.intent.action.APP_PERM_EDITOR'
                        ],
                        extras: {
                            'extra_pkgname': packageName,
                            'extra_power_mode': 'background_popup'
                        }
                    },
                    lockscreen_show: {
                        title: '锁屏显示',
                        actions: [
                            'miui.intent.action.APP_PERM_EDITOR'
                        ],
                        extras: {
                            'extra_pkgname': packageName,
                            'extra_permission_type': 'lockscreen_show'
                        }
                    },
                    floating_window: {
                        title: '悬浮窗',
                        actions: [
                            'miui.intent.action.APP_PERM_EDITOR'
                        ],
                        extras: {
                            'extra_pkgname': packageName,
                            'extra_permission_type': 'floating_window'
                        }
                    },
                    notification_service: {
                        title: '通知使用权',
                        actions: [
                            'android.settings.ACTION_NOTIFICATION_LISTENER_SETTINGS',
                            'android.settings.NOTIFICATION_LISTENER_SETTINGS'
                        ]
                    },
                    notification_channel: {
                        title: '通知设置',
                        actions: [
                            'android.settings.APP_NOTIFICATION_SETTINGS',
                            'miui.intent.action.APP_NOTIFICATION_SETTINGS'
                        ],
                        extras: {
                            'android.provider.extra.APP_PACKAGE': packageName,
                            'pkg': packageName
                        },
                        tip: '请确保「允许通知」已开启，并打开「消息推送」渠道的通知权限'
                    },
                    developer_keep_alive: {
                        title: '开发者选项',
                        actions: [
                            'android.settings.APPLICATION_DEVELOPMENT_SETTINGS',
                            'com.android.settings.DevelopmentSettings'
                        ],
                        tip: '请找到「后台进程限制」并设为「标准限制」，或开启「不保留活动」时注意白名单'
                    }
                }

                const cfg = configs[type]
                if (!cfg) {
                    uni.showToast({ title: '未知权限类型：' + type, icon: 'none' })
                    return
                }

                let launched = false

                // 1. 优先尝试 Action 方式
                const actions = cfg.actions || []
                for (const action of actions) {
                    try {
                        const intent = new Intent()
                        intent.setAction(action)
                        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        // 添加 extra 参数
                        if (cfg.extras) {
                            for (const key in cfg.extras) {
                                intent.putExtra(key, cfg.extras[key])
                            }
                        }
                        // 部分页面需要指定包名
                        if (action.indexOf('miui.intent.action') === 0) {
                            intent.putExtra('extra_pkgname', packageName)
                        }
                        main.startActivity(intent)
                        launched = true
                        break
                    } catch (e) {
                        // 继续尝试下一个
                    }
                }

                // 2. 尝试 ComponentName 方式
                if (!launched && cfg.fallbackComponent) {
                    try {
                        const ComponentName = plus.android.importClass('android.content.ComponentName')
                        const intent = new Intent()
                        const parts = cfg.fallbackComponent.split('/')
                        intent.setComponent(new ComponentName(parts[0], parts[1]))
                        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        intent.putExtra('package_name', packageName)
                        main.startActivity(intent)
                        launched = true
                    } catch (e) {
                        // 继续回退
                    }
                }

                // 3. 最终回退到应用详情页
                if (!launched) {
                    const Settings = plus.android.importClass('android.provider.Settings')
                    uni.showToast({ title: '未找到' + cfg.title + '设置页，已跳转应用详情', icon: 'none' })
                    const fallback = new Intent()
                    fallback.setAction(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
                    fallback.setData(Uri.fromParts('package', packageName, null))
                    fallback.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    main.startActivity(fallback)
                }

                // 如果有额外提示
                if (cfg.tip) {
                    setTimeout(() => {
                        uni.showModal({
                            title: cfg.title + '提示',
                            content: cfg.tip,
                            showCancel: false,
                            confirmText: '我知道了'
                        })
                    }, 500)
                }
            } catch (e) {
                console.error('打开小米权限设置失败', e)
                uni.showToast({ title: '打开设置失败：' + e.message, icon: 'none' })
            }
            // #endif
            // #ifndef APP-PLUS
            uni.showToast({ title: '此功能仅在 APP 端可用', icon: 'none' })
            // #endif
        },
        registerNetworkListener() {
            // #ifdef APP-PLUS
            this.networkStatusChange = (res) => {
                console.log('网络状态变化:', res)
                if (res.isConnected) {
                    // 网络恢复，主动重连
                    console.log('网络已恢复，尝试重连 WebSocket')
                    if (this.isLoggedIn) {
                        if (!this.connected) {
                            // 已断开或正在连接但卡住，都强制重连
                            this.cleanupAndReconnect()
                        }
                    }
                } else {
                    // 网络断开，标记连接状态
                    console.warn('网络已断开')
                    this.connected = false
                    this.stopHeartbeat()
                }
            }
            uni.onNetworkStatusChange(this.networkStatusChange)
            // #endif
        },
        connectWebSocket() {
            // 如果 socketTask 还在但已断开（connected=false），先清理
            if (this.socketTask && !this.connected) {
                try { this.socketTask.close() } catch (e) {}
                this.socketTask = null
            }
            if (this.socketTask) {
                return
            }

            this.connecting = true
            this._connectStartTime = Date.now()

            const url = this.wsUrl + '/ws/client?key=' + encodeURIComponent(this.form.key) + '&device_id=' + encodeURIComponent(this.deviceId)

            // BUG修复：添加连接超时定时器，防止 connecting 状态卡死
            // 弱网下 TCP 握手可能无响应，onOpen 和 onError 都不触发
            if (this.connectTimeoutTimer) {
                clearTimeout(this.connectTimeoutTimer)
            }
            this.connectTimeoutTimer = setTimeout(() => {
                if (this.connecting && !this.connected) {
                    console.warn('WebSocket 连接超时（10秒无响应），主动触发重连')
                    this.connecting = false
                    if (this.socketTask) {
                        try { this.socketTask.close() } catch (e) {}
                        this.socketTask = null
                    }
                    this.scheduleReconnect()
                }
            }, 10000)

            this.socketTask = uni.connectSocket({
                url: url,
                success: () => {
                    console.log('WebSocket 连接中...')
                },
                fail: (err) => {
                    console.error('WebSocket 连接失败', err)
                    this.connecting = false
                    if (this.connectTimeoutTimer) {
                        clearTimeout(this.connectTimeoutTimer)
                        this.connectTimeoutTimer = null
                    }
                    this.scheduleReconnect()
                }
            })

            this.socketTask.onOpen(() => {
                console.log('WebSocket 连接已建立，等待鉴权确认...')
                // 注意：onOpen 只是 TCP 连接建立，不算真正可用
                // 需要等待 auth_result 鉴权成功消息才算真正连接成功
                // 设置鉴权超时（5秒内没收到鉴权结果则认为连接失败）
                this._authTimer = setTimeout(() => {
                    console.warn('鉴权超时（5秒未收到 auth_result），按连接成功处理（兼容旧服务端）')
                    this._confirmConnection()
                }, 5000)
            })

            this.socketTask.onMessage((res) => {
                // 收到任何消息都重置心跳超时（证明连接存活）
                this.resetHeartbeatTimeout()
                try {
                    const data = JSON.parse(res.data)
                    // 鉴权结果
                    if (data.type === 'auth_result' || data.message === '连接成功' || (data.code === 0 && data.data && data.data.device_id)) {
                        if (data.code === 0 || data.type === 'auth_result' && data.code === undefined) {
                            console.log('鉴权成功，连接已就绪')
                            this._confirmConnection()
                        } else {
                            console.warn('鉴权失败:', data.message)
                        }
                        return
                    }
                    // 服务端 ping
                    if (data.type === 'ping') {
                        this.socketTask.send({
                            data: JSON.stringify({ type: 'pong' })
                        })
                        return
                    }
                    // 服务端 pong（心跳响应）
                    if (data.type === 'pong') {
                        return
                    }
                    // 推送消息（多种格式兼容）
                    let title = ''
                    let content = ''
                    let isPush = false
                    // 格式1: type=push/message/offline_message，title/content 在顶层
                    if (data.type === 'push' || data.type === 'message' || data.type === 'offline_message') {
                        isPush = true
                        title = data.title || ''
                        content = data.content || ''
                        // 如果顶层没有，尝试从 data 字段取
                        if ((!title || !content) && data.data && typeof data.data === 'object') {
                            title = title || data.data.title || ''
                            content = content || data.data.content || ''
                        }
                    }
                    // 格式2: code/message/data 包装，message 字段标识类型
                    if (!isPush && data.code !== undefined && data.data && typeof data.data === 'object') {
                        if (data.message === 'message' || data.message === 'offline_message') {
                            isPush = true
                        } else if (data.data.title || data.data.content) {
                            // 可能是推送，尝试解析
                            isPush = true
                        }
                        if (isPush) {
                            title = data.data.title || data.title || ''
                            content = data.data.content || data.content || ''
                        }
                    }
                    // 格式3: 直接就是消息体（不带 type/code）
                    if (!isPush && (data.title || data.content)) {
                        isPush = true
                        title = data.title || ''
                        content = data.content || ''
                    }
                    if (isPush) {
                        this.addMessage(title || '消息推送', content || '')
                    } else {
                        console.log('收到未知类型消息:', data)
                    }
                } catch (e) {
                    console.error('消息解析失败', e, '原始数据:', res.data)
                }
            })

            this.socketTask.onClose((res) => {
                console.log('WebSocket 已断开, code:', res.code, 'reason:', res.reason)
                this.connecting = false
                this.connected = false
                this.stopHeartbeat()
                this.socketTask = null
                if (this.connectTimeoutTimer) {
                    clearTimeout(this.connectTimeoutTimer)
                    this.connectTimeoutTimer = null
                }
                const code = res.code
                const reason = res.reason || ''
                if (code === 4001 || reason === 'auth failed' || reason === 'auth timeout') {
                    console.warn('鉴权失败，停止重连，请检查推送 Key')
                    uni.showToast({ title: '鉴权失败：' + (reason || code), icon: 'none' })
                    this.isLoggedIn = false
                    this.stopForegroundService()
                    return
                }
                if (code === 4003 || reason === 'blacklisted') {
                    console.warn('设备已被拉黑，停止重连')
                    uni.showToast({ title: '设备已被拉黑', icon: 'none' })
                    this.isLoggedIn = false
                    this.stopForegroundService()
                    return
                }
                this.scheduleReconnect()
            })

            this.socketTask.onError((err) => {
                console.error('WebSocket 错误', err)
                this.connecting = false
                this.connected = false
                // BUG 修复：onError 触发 close()，由 onClose 统一处理 scheduleReconnect
                // 不要在这里调用 scheduleReconnect，避免重复触发
                if (this.socketTask) {
                    try { this.socketTask.close() } catch (e) {}
                    // socketTask 置空由 onClose 负责；若 onClose 不触发，下面的兜底定时器会处理
                }
                // 兜底：如果 2 秒后 socketTask 还在或还没触发重连，强制清理重连
                setTimeout(() => {
                    if (!this.connected && this.socketTask) {
                        console.warn('onError 后 onClose 未触发，强制清理重连')
                        try { this.socketTask.close() } catch (e) {}
                        this.socketTask = null
                        if (!this.reconnectTimer) {
                            this.scheduleReconnect()
                        }
                    }
                }, 2000)
            })
        },
        _confirmConnection() {
            if (this.connected) {
                return
            }
            if (this._authTimer) {
                clearTimeout(this._authTimer)
                this._authTimer = null
            }
            console.log('WebSocket 连接已就绪')
            this.connecting = false
            this.connected = true
            this.reconnectDelay = 3000
            this._reconnectCount = 0
            if (this.connectTimeoutTimer) {
                clearTimeout(this.connectTimeoutTimer)
                this.connectTimeoutTimer = null
            }
            this.startHeartbeat()
            // 连接成功后启动前台服务保活（防止后台被系统杀掉）
            this.startForegroundService()
            // 创建通知渠道（Android 8.0+ 必须，否则通知不显示）
            this.createNotificationChannel()
            // 连接成功后立即发送一个测试 ping，验证连接双向可用
            try {
                if (this.socketTask) {
                    this.socketTask.send({
                        data: JSON.stringify({ type: 'ping' })
                    })
                }
            } catch (e) {
                console.warn('连接验证 ping 发送失败', e)
            }
        },
        closeSocket() {
            if (this._authTimer) {
                clearTimeout(this._authTimer)
                this._authTimer = null
            }
            if (this.socketTask) {
                this.socketTask.close()
                this.socketTask = null
            }
            this.connected = false
            this.connecting = false
            this.stopHeartbeat()
            if (this.reconnectTimer) {
                clearTimeout(this.reconnectTimer)
                this.reconnectTimer = null
            }
            if (this.connectTimeoutTimer) {
                clearTimeout(this.connectTimeoutTimer)
                this.connectTimeoutTimer = null
            }
            this.reconnectDelay = 3000
            this._reconnectCount = 0
        },
        startHeartbeat() {
            this.stopHeartbeat()
            this.resetHeartbeatTimeout()
            this.heartbeatTimer = setInterval(() => {
                if (!this.socketTask || !this.connected) {
                    this.stopHeartbeat()
                    return
                }
                try {
                    this.socketTask.send({
                        data: JSON.stringify({ type: 'ping' }),
                        success: () => {
                        },
                        fail: (err) => {
                            console.error('心跳 ping 发送失败，触发重连', err)
                            this.connected = false
                            if (this.socketTask) {
                                try { this.socketTask.close() } catch (e) {}
                            }
                            this.stopHeartbeat()
                            if (!this.reconnectTimer) {
                                this.scheduleReconnect()
                            }
                        }
                    })
                } catch (e) {
                    console.error('心跳 ping 异常，触发重连', e)
                    this.connected = false
                    if (this.socketTask) {
                        try { this.socketTask.close() } catch (e2) {}
                    }
                    this.stopHeartbeat()
                    if (!this.reconnectTimer) {
                        this.scheduleReconnect()
                    }
                }
            }, 15000)
        },
        stopHeartbeat() {
            if (this.heartbeatTimer) {
                clearInterval(this.heartbeatTimer)
                this.heartbeatTimer = null
            }
            if (this.heartbeatTimeoutTimer) {
                clearTimeout(this.heartbeatTimeoutTimer)
                this.heartbeatTimeoutTimer = null
            }
        },
        resetHeartbeatTimeout() {
            if (!this.connected) {
                return
            }
            if (this.heartbeatTimeoutTimer) {
                clearTimeout(this.heartbeatTimeoutTimer)
            }
            this.heartbeatTimeoutTimer = setTimeout(() => {
                console.warn('心跳超时（30秒未收到任何消息），主动断开重连')
                this.connected = false
                this.stopHeartbeat()
                if (this.socketTask) {
                    try { this.socketTask.close() } catch (e) {}
                }
                setTimeout(() => {
                    if (!this.connected && this.socketTask) {
                        console.warn('心跳超时后 onClose 未触发，强制清理重连')
                        try { this.socketTask.close() } catch (e) {}
                        this.socketTask = null
                        if (!this.reconnectTimer) {
                            this.scheduleReconnect()
                        }
                    }
                }, 3000)
                if (!this.reconnectTimer) {
                    this.scheduleReconnect()
                }
            }, 30000)
        },
        scheduleReconnect() {
            if (!this.isLoggedIn) {
                return
            }
            if (this.reconnectTimer) {
                return
            }
            // 前 3 次重连使用更短的间隔，加快恢复速度
            // 第 1 次: 2s, 第 2 次: 5s, 第 3 次: 10s, 之后按指数退避
            let delay = this.reconnectDelay
            if (this._reconnectCount === undefined) {
                this._reconnectCount = 0
            }
            this._reconnectCount++
            if (this._reconnectCount <= 3) {
                const quickDelays = [2000, 5000, 10000]
                delay = quickDelays[this._reconnectCount - 1]
            }
            console.log('第 ' + this._reconnectCount + ' 次重连，' + delay / 1000 + '秒后重试...')
            this.reconnectTimer = setTimeout(() => {
                this.reconnectTimer = null
                if (this.isLoggedIn) {
                    this.connectWebSocket()
                }
            }, delay)
            // 超过 3 次后按指数退避
            if (this._reconnectCount >= 3) {
                this.reconnectDelay = Math.min(this.reconnectDelay * 2, this.maxReconnectDelay)
            }
        },
        cleanupAndReconnect() {
            // 清理所有可能残留的连接状态，立即重连
            if (this.socketTask) {
                try { this.socketTask.close() } catch (e) {}
                this.socketTask = null
            }
            if (this.reconnectTimer) {
                clearTimeout(this.reconnectTimer)
                this.reconnectTimer = null
            }
            if (this.connectTimeoutTimer) {
                clearTimeout(this.connectTimeoutTimer)
                this.connectTimeoutTimer = null
            }
            this.connecting = false
            this.connected = false
            this.stopHeartbeat()
            this.reconnectDelay = 3000
            this._connectStartTime = null
            if (this.isLoggedIn) {
                this.connectWebSocket()
            }
        },
        addMessage(title, content) {
            this.messages.unshift({
                title: title,
                content: content,
                time: Date.now()
            })

            if (this.messages.length > 100) {
                this.messages = this.messages.slice(0, 100)
            }

            this.totalCount++

            const today = new Date().toDateString()
            const lastToday = uni.getStorageSync('push_today_date')
            if (lastToday !== today) {
                uni.setStorageSync('push_today_date', today)
                this.todayCount = 1
                uni.setStorageSync('push_today_count', '1')
            } else {
                const savedToday = parseInt(uni.getStorageSync('push_today_count') || '0')
                this.todayCount = savedToday + 1
                uni.setStorageSync('push_today_count', (savedToday + 1).toString())
            }

            this.saveMessages()
            this.updateStats()

            // 显示系统通知（无论 APP 在前台还是后台都显示）
            this.showNotification(title, content)

            // 前台时同时显示 toast 提示（更直观）
            uni.showToast({
                title: title,
                icon: 'none',
                duration: 2000
            })
        },
        updateStats() {
            const savedTotal = uni.getStorageSync('push_total_count') || 0
            if (this.totalCount < this.messages.length) {
                this.totalCount = this.messages.length
            }
        },
        clearMessages() {
            uni.showModal({
                title: '提示',
                content: '确定要清空所有消息吗？',
                success: (res) => {
                    if (res.confirm) {
                        this.messages = []
                        this.saveMessages()
                        uni.showToast({ title: '已清空', icon: 'success' })
                    }
                }
            })
        },
        formatTime(timestamp) {
            const date = new Date(timestamp)
            const now = new Date()
            const diff = now.getTime() - timestamp

            if (diff < 60000) {
                return '刚刚'
            } else if (diff < 3600000) {
                return Math.floor(diff / 60000) + '分钟前'
            } else if (now.toDateString() === date.toDateString()) {
                return this.padZero(date.getHours()) + ':' + this.padZero(date.getMinutes())
            } else {
                return (date.getMonth() + 1) + '-' + date.getDate() + ' ' + this.padZero(date.getHours()) + ':' + this.padZero(date.getMinutes())
            }
        },
        padZero(num) {
            return num < 10 ? '0' + num : '' + num
        }
    }
}
</script>

<style scoped>
.container {
    min-height: 100vh;
    background-color: #f5f7fa;
}

/* 登录页面 */
.login-page {
    min-height: 100vh;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 24px;
    box-sizing: border-box;
}

.login-container {
    width: 100%;
    max-width: 400px;
}

.logo-section {
    text-align: center;
    margin-bottom: 48px;
}

.logo-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.logo-text {
    font-size: 60px;
}

.app-title {
    font-size: 28px;
    font-weight: 600;
    color: #ffffff;
    display: block;
    margin-bottom: 8px;
}

.app-subtitle {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.8);
    display: block;
}

.login-form {
    background: white;
    border-radius: 16px;
    padding: 32px 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 13px;
    color: #666;
    margin-bottom: 8px;
    font-weight: 500;
}

.form-input {
    width: 100%;
    height: 44px;
    padding: 0 16px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    color: #333;
    background: #f9f9f9;
    box-sizing: border-box;
}

.btn-primary {
    width: 100%;
    height: 48px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 500;
    margin-top: 10px;
}

/* 主页面 */
.main-page {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: white;
    border-bottom: 1px solid #eee;
    position: sticky;
    top: 0;
    z-index: 100;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.header-title {
    font-size: 18px;
    font-weight: 600;
    color: #333;
}

.status-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
}

.status-dot.connected {
    background: #52c41a;
    box-shadow: 0 0 8px rgba(82, 196, 26, 0.5);
}

.status-dot.disconnected {
    background: #ff4d4f;
    box-shadow: 0 0 8px rgba(255, 77, 79, 0.5);
}

.setting-icon {
    font-size: 20px;
    padding: 4px;
}

/* 统计卡片 */
.stats-section {
    display: flex;
    gap: 12px;
    padding: 16px 20px;
}

.stat-card {
    flex: 1;
    background: white;
    border-radius: 12px;
    padding: 16px 12px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.stat-value {
    font-size: 20px;
    font-weight: 600;
    color: #667eea;
    display: block;
    margin-bottom: 4px;
}

.stat-label {
    font-size: 12px;
    color: #999;
    display: block;
}

/* 消息列表 */
.message-section {
    flex: 1;
    padding: 0 20px 20px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.section-title {
    font-size: 16px;
    font-weight: 600;
    color: #333;
}

.clear-btn {
    font-size: 13px;
    color: #667eea;
    padding: 4px 8px;
}

.message-list {
    flex: 1;
    height: 500px;
}

.message-item {
    background: white;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}

.message-title {
    font-size: 15px;
    font-weight: 600;
    color: #333;
    display: block;
    margin-bottom: 8px;
}

.message-content {
    font-size: 14px;
    color: #666;
    line-height: 1.6;
    display: block;
    margin-bottom: 12px;
}

.message-time {
    font-size: 12px;
    color: #999;
    display: block;
}

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 60px 20px;
}

.empty-icon {
    font-size: 48px;
    margin-bottom: 16px;
}

.empty-text {
    font-size: 15px;
    color: #999;
    margin-bottom: 8px;
    display: block;
}

.empty-desc {
    font-size: 13px;
    color: #bbb;
    display: block;
}

/* 设置弹窗 */
.settings-mask {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px;
    box-sizing: border-box;
}

.settings-dialog {
    width: 100%;
    max-width: 400px;
    max-height: 80vh;
    background: white;
    border-radius: 16px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.settings-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #eee;
}

.settings-title {
    font-size: 18px;
    font-weight: 600;
    color: #333;
}

.close-btn {
    font-size: 20px;
    color: #999;
    padding: 4px;
}

.settings-content {
    flex: 1;
    height: 0;
    min-height: 0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 20px;
}

.setting-item {
    background: #f9f9f9;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
}

.setting-label {
    font-size: 13px;
    color: #999;
    display: block;
    margin-bottom: 8px;
}

.setting-value {
    font-size: 14px;
    color: #333;
    font-weight: 500;
    display: block;
    word-break: break-all;
}

.setting-item-column {
    display: flex;
    flex-direction: column;
}

.setting-input {
    width: 100%;
    height: 40px;
    padding: 0 12px;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    font-size: 13px;
    color: #333;
    background: white;
    box-sizing: border-box;
    margin-bottom: 8px;
}

.btn-sm {
    align-self: flex-start;
    height: 32px;
    padding: 0 16px;
    background: #667eea;
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    line-height: 32px;
    margin-bottom: 6px;
}

.setting-tip {
    font-size: 12px;
    color: #999;
    line-height: 1.4;
    margin-bottom: 10px;
}

.btn-danger {
    width: 100%;
    height: 48px;
    background: #ff4d4f;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 500;
    margin-top: 0;
}

.settings-footer {
    padding: 12px 20px 20px;
    border-top: 1px solid #f0f0f0;
    background: white;
    flex-shrink: 0;
}

.btn-xiaomi {
    background: linear-gradient(135deg, #ff6900, #ff8a00) !important;
    color: white !important;
    border: none !important;
}

.setting-tip-warn {
    color: #e6a23c !important;
    font-weight: 500;
}
</style>
