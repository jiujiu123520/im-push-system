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
                <scroll-view scroll-y class="settings-content">
                    <view class="setting-item">
                        <text class="setting-label">推送 Key</text>
                        <text class="setting-value">{{ form.key }}</text>
                    </view>
                    <view class="setting-item">
                        <text class="setting-label">服务器地址</text>
                        <text class="setting-value">{{ form.serverUrl }}</text>
                    </view>
                    <view class="setting-item">
                        <text class="setting-label">WebSocket 地址</text>
                        <text class="setting-value">{{ wsUrl }}</text>
                    </view>
                    <view class="setting-item">
                        <text class="setting-label">应用版本</text>
                        <text class="setting-value">{{ versionName }}</text>
                    </view>
                    <button class="btn-danger" @click="handleLogout">退出登录</button>
                </scroll-view>
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
                serverUrl: ''
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
            reconnectDelay: 3000,
            maxReconnectDelay: 60000
        }
    },
    computed: {
        deviceIdShort() {
            return this.deviceId ? this.deviceId.substring(0, 8) : '--'
        }
    },
    onLoad() {
        this.initDeviceId()
        this.loadConfig()
        this.loadMessages()
        this.checkAutoLogin()
    },
    onUnload() {
        this.closeSocket()
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer)
        }
        if (this.reconnectTimer) {
            clearTimeout(this.reconnectTimer)
        }
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
            } else {
                this.wsUrl = APP_CONFIG.ws_url
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
        checkAutoLogin() {
            const savedKey = uni.getStorageSync('push_key')
            const savedServer = uni.getStorageSync('push_server')

            if (savedKey && savedServer) {
                this.form.key = savedKey
                this.form.serverUrl = savedServer
                this.wsUrl = uni.getStorageSync('push_ws') || this.deriveWsUrl(savedServer)
                this.isLoggedIn = true
                this.connectWebSocket()
            }
        },
        deriveWsUrl(serverUrl) {
            if (serverUrl.startsWith('https://')) {
                return 'wss://' + serverUrl.substring(8)
            } else if (serverUrl.startsWith('http://')) {
                return 'ws://' + serverUrl.substring(7)
            } else {
                return 'ws://' + serverUrl
            }
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
            const wsUrl = this.deriveWsUrl(serverUrl)

            uni.setStorageSync('push_key', key)
            uni.setStorageSync('push_server', serverUrl)
            uni.setStorageSync('push_ws', wsUrl)

            this.wsUrl = wsUrl
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
                        this.isLoggedIn = false
                        this.showSettings = false
                        uni.removeStorageSync('push_key')
                        uni.removeStorageSync('push_server')
                        uni.removeStorageSync('push_ws')
                    }
                }
            })
        },
        connectWebSocket() {
            if (this.socketTask) {
                return
            }

            this.connecting = true
            this.reconnectDelay = 3000

            const url = this.wsUrl + '/ws/client?key=' + encodeURIComponent(this.form.key) + '&device_id=' + encodeURIComponent(this.deviceId)

            this.socketTask = uni.connectSocket({
                url: url,
                success: () => {
                    console.log('WebSocket 连接中...')
                },
                fail: (err) => {
                    console.error('WebSocket 连接失败', err)
                    this.connecting = false
                    this.scheduleReconnect()
                }
            })

            this.socketTask.onOpen(() => {
                console.log('WebSocket 已连接')
                this.connecting = false
                this.connected = true
                this.reconnectDelay = 3000
                this.startHeartbeat()
            })

            this.socketTask.onMessage((res) => {
                try {
                    const data = JSON.parse(res.data)
                    if (data.type === 'ping') {
                        this.socketTask.send({
                            data: JSON.stringify({ type: 'pong' })
                        })
                    } else if (data.type === 'pong') {
                        this.resetHeartbeatTimeout()
                    } else if (data.type === 'message' || data.type === 'push' || data.type === 'offline_message') {
                        const msg = data.data || data
                        this.addMessage(msg.title || '消息推送', msg.content || '')
                    }
                } catch (e) {
                    console.error('消息解析失败', e)
                }
            })

            this.socketTask.onClose((res) => {
                console.log('WebSocket 已断开, code:', res.code, 'reason:', res.reason)
                this.connecting = false
                this.connected = false
                this.stopHeartbeat()
                this.socketTask = null
                this.scheduleReconnect()
            })

            this.socketTask.onError((err) => {
                console.error('WebSocket 错误', err)
                this.connected = false
                if (this.socketTask) {
                    this.socketTask.close()
                    this.socketTask = null
                }
                this.scheduleReconnect()
            })
        },
        closeSocket() {
            if (this.socketTask) {
                this.socketTask.close()
                this.socketTask = null
            }
            this.connected = false
            this.stopHeartbeat()
            if (this.reconnectTimer) {
                clearTimeout(this.reconnectTimer)
                this.reconnectTimer = null
            }
            this.reconnectDelay = 3000
        },
        startHeartbeat() {
            this.stopHeartbeat()
            this.heartbeatTimer = setInterval(() => {
                if (this.socketTask && this.connected) {
                    this.socketTask.send({
                        data: JSON.stringify({ type: 'ping' })
                    })
                    this.startHeartbeatTimeout()
                }
            }, 30000)
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
        startHeartbeatTimeout() {
            if (this.heartbeatTimeoutTimer) {
                clearTimeout(this.heartbeatTimeoutTimer)
            }
            this.heartbeatTimeoutTimer = setTimeout(() => {
                console.warn('心跳超时，主动断开重连')
                if (this.socketTask) {
                    this.socketTask.close()
                    this.socketTask = null
                }
                this.connected = false
                this.scheduleReconnect()
            }, 15000)
        },
        resetHeartbeatTimeout() {
            if (this.heartbeatTimeoutTimer) {
                clearTimeout(this.heartbeatTimeoutTimer)
                this.heartbeatTimeoutTimer = null
            }
        },
        scheduleReconnect() {
            if (!this.isLoggedIn) {
                return
            }
            if (this.reconnectTimer) {
                return
            }
            console.log(this.reconnectDelay / 1000 + '秒后重连...')
            this.reconnectTimer = setTimeout(() => {
                this.reconnectTimer = null
                if (this.isLoggedIn) {
                    this.connectWebSocket()
                }
            }, this.reconnectDelay)
            this.reconnectDelay = Math.min(this.reconnectDelay * 2, this.maxReconnectDelay)
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

            uni.showNotification ? uni.showNotification({
                title: title,
                content: content
            }) : uni.showToast({
                title: title,
                icon: 'none',
                duration: 3000
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

.btn-danger {
    width: 100%;
    height: 48px;
    background: #ff4d4f;
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 500;
    margin-top: 24px;
}
</style>
