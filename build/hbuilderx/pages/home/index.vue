<template>
    <view class="container">
        <!-- 顶部状态栏 -->
        <view class="header">
            <view class="header-left">
                <text class="header-title">{{ currentTab === 'message' ? '消息推送' : '音频播放器' }}</text>
                <view v-if="currentTab === 'message'" :class="['status-dot', connected ? 'connected' : 'disconnected']"></view>
            </view>
            <view class="header-right">
                <text class="setting-icon" @click="showSettings = true">⚙️</text>
            </view>
        </view>

        <!-- ========== Tab 1: 消息推送 ========== -->
        <view v-show="currentTab === 'message'" class="tab-page">
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
                <scroll-view
                    scroll-y
                    class="message-list"
                    v-if="messages.length > 0"
                    :scroll-top="scrollTop"
                    :key="'msg-list-' + messages.length"
                    enhanced
                    show-scrollbar
                >
                    <view v-for="(msg) in messages" :key="msg.id" class="message-item">
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

        <!-- ========== Tab 2: 音频播放器 ========== -->
        <view v-show="currentTab === 'player'" class="tab-page player-page">
            <!-- 播放器主区域 -->
            <view class="player-main">
                <view class="player-disc-wrap">
                    <view :class="['player-disc', isPlaying ? 'rotating' : '']">
                        <text class="player-disc-icon">🎵</text>
                    </view>
                </view>
                <text class="player-song-name">{{ currentAudioName }}</text>
                <text class="player-song-status">{{ isPlaying ? '播放中' : (audioList.length > 0 ? '已暂停' : '未加载') }}</text>

                <!-- 播放控制按钮 -->
                <view class="player-controls-bar">
                    <text class="ctrl-btn" @click="playPrev">⏮</text>
                    <text class="ctrl-btn ctrl-btn-play" @click="togglePlay">{{ isPlaying ? '⏸' : '▶' }}</text>
                    <text class="ctrl-btn" @click="playNext">⏭</text>
                </view>

                <!-- 播放模式 -->
                <view class="play-mode-row">
                    <text class="play-mode-label">播放模式：</text>
                    <view class="play-mode-options">
                        <text
                            :class="['mode-option', playMode === 'list_loop' ? 'active' : '']"
                            @click="setPlayMode('list_loop')"
                        >列表循环</text>
                        <text
                            :class="['mode-option', playMode === 'single_loop' ? 'active' : '']"
                            @click="setPlayMode('single_loop')"
                        >单曲循环</text>
                        <text
                            :class="['mode-option', playMode === 'single' ? 'active' : '']"
                            @click="setPlayMode('single')"
                        >播放一次</text>
                    </view>
                </view>
            </view>

            <!-- 播放列表 -->
            <view class="player-playlist">
                <view class="section-header">
                    <text class="section-title">播放列表</text>
                    <text v-if="audioList.length > 0" class="clear-btn" @click="clearAudioList">清空</text>
                </view>
                <scroll-view scroll-y class="audio-list">
                    <view v-if="audioList.length === 0" class="empty-state">
                        <text class="empty-icon">🎵</text>
                        <text class="empty-text">暂无音频</text>
                        <text class="empty-desc">点击右上角 ⚙️ 添加音频地址</text>
                    </view>
                    <view
                        v-for="(item, idx) in audioList"
                        :key="idx"
                        :class="['audio-item', idx === currentAudioIndex ? 'audio-item-active' : '']"
                        @click="playAudioByIndex(idx)"
                    >
                        <text class="audio-index">{{ idx + 1 }}</text>
                        <text class="audio-name">{{ item.name }}</text>
                        <text v-if="idx === currentAudioIndex && isPlaying" class="audio-playing-icon">🔊</text>
                        <text class="audio-del" @click.stop="removeAudio(idx)">删除</text>
                    </view>
                </scroll-view>
            </view>
        </view>

        <!-- ========== 底部导航 Tab Bar ========== -->
        <view class="bottom-tab-bar">
            <view
                :class="['tab-item', currentTab === 'message' ? 'tab-active' : '']"
                @click="switchTab('message')"
            >
                <text class="tab-icon">📨</text>
                <text class="tab-text">消息推送</text>
            </view>
            <view
                :class="['tab-item', currentTab === 'player' ? 'tab-active' : '']"
                @click="switchTab('player')"
            >
                <text class="tab-icon">🎵</text>
                <text class="tab-text">音频播放</text>
            </view>
        </view>

        <!-- ========== 设置弹窗 ========== -->
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
                    <!-- 音频播放器设置 -->
                    <view class="setting-item setting-item-column">
                        <text class="setting-label">音频播放器</text>
                        <text class="setting-tip">添加网络或本地音频，后台循环播放，提升进程保活能力</text>
                        <view class="audio-switch-row">
                            <text class="setting-label-sm">启用音频播放</text>
                            <switch :checked="audioEnabled" @change="onAudioToggle" color="#409EFF" />
                        </view>
                        <view v-if="audioEnabled" class="audio-input-row">
                            <input class="setting-input" v-model="newAudioUrl" placeholder="输入音频地址（支持网络URL或本地路径）" />
                            <button class="btn-sm btn-add" @click="addAudioUrl">添加</button>
                        </view>
                        <view v-if="audioEnabled && audioList.length > 0" class="audio-list-settings">
                            <view class="audio-item" v-for="(item, idx) in audioList" :key="idx">
                                <text class="audio-name">{{ item.name }}</text>
                                <text class="audio-del" @click="removeAudio(idx)">删除</text>
                            </view>
                        </view>
                        <view v-if="audioEnabled && audioList.length === 0" class="setting-tip">
                            暂无音频，请添加音频地址
                        </view>
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
            currentTab: 'message',
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
            scrollTop: 0,
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
            isXiaomiDevice: false,
            // 音频播放器
            audioEnabled: false,
            audioList: [],
            newAudioUrl: '',
            currentAudioIndex: 0,
            isPlaying: false,
            audioContext: null,
            // 播放模式：list_loop=列表循环 / single_loop=单曲循环 / single=播放一次
            playMode: 'list_loop'
        }
    },
    computed: {
        deviceIdShort() {
            return this.deviceId ? this.deviceId.substring(0, 8) : '--'
        },
        currentAudioName() {
            if (this.audioList.length === 0) return '暂无音频'
            const item = this.audioList[this.currentAudioIndex]
            if (!item) return '暂无音频'
            return item.name
        }
    },
    onLoad() {
        this.initDeviceId()
        this.checkXiaomiDevice()
        this.loadConfig()
        this.loadMessages()
        this.loadAudioConfig()
        // 验证登录状态，未登录则返回登录页
        const savedKey = uni.getStorageSync('push_key')
        const savedServer = uni.getStorageSync('push_server')
        if (!savedKey || !savedServer) {
            uni.redirectTo({ url: '/pages/index/index' })
            return
        }
        this.registerNetworkListener()
        // 延迟连接，确保页面渲染完成
        setTimeout(() => {
            this.connectWebSocket()
            if (this.audioEnabled && this.audioList.length > 0) {
                this.initAudioPlayer()
            }
        }, 300)
    },
    onShow() {
        // APP 从后台切回前台 / 页面重新显示时，主动检测并重连断开的 WebSocket
        if (this.form.key) {
            if (!this.connected && !this.connecting) {
                console.log('页面 onShow 检测到连接已断开，主动重连')
                this.cleanupAndReconnect()
                return
            }
            if (this.connecting && this._connectStartTime) {
                const elapsed = Date.now() - this._connectStartTime
                if (elapsed > 15000) {
                    console.warn('页面 onShow 检测到 connecting 状态卡住，强制重连')
                    this.cleanupAndReconnect()
                    return
                }
            }
            if (this.connected && !this.socketTask) {
                console.warn('页面 onShow 检测到状态不一致（connected=true 但无 socketTask），重置重连')
                this.connected = false
                this.cleanupAndReconnect()
                return
            }
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
        switchTab(tab) {
            this.currentTab = tab
        },
        // ============== 音频播放器 ==============
        loadAudioConfig() {
            const enabled = uni.getStorageSync('audio_enabled')
            const list = uni.getStorageSync('audio_list')
            const mode = uni.getStorageSync('audio_play_mode')
            this.audioEnabled = enabled === true || enabled === 'true'
            if (list && Array.isArray(list)) {
                this.audioList = list
            }
            if (mode) {
                this.playMode = mode
            }
            if (this.audioEnabled && this.audioList.length > 0) {
                this.initAudioPlayer()
            }
        },
        saveAudioConfig() {
            uni.setStorageSync('audio_enabled', this.audioEnabled)
            uni.setStorageSync('audio_list', this.audioList)
            uni.setStorageSync('audio_play_mode', this.playMode)
        },
        setPlayMode(mode) {
            this.playMode = mode
            this.saveAudioConfig()
            // 单曲循环模式：将当前音频设为循环
            if (this.audioContext) {
                this.audioContext.loop = (mode === 'single_loop')
            }
            const modeText = {
                'list_loop': '列表循环',
                'single_loop': '单曲循环',
                'single': '播放一次'
            }
            uni.showToast({ title: '已切换为：' + modeText[mode], icon: 'none' })
        },
        onAudioToggle(e) {
            this.audioEnabled = e.detail.value
            this.saveAudioConfig()
            if (this.audioEnabled && this.audioList.length > 0) {
                this.initAudioPlayer()
                this.startAudioPlay()
            } else {
                this.stopAudioPlay()
                this.destroyAudioPlayer()
            }
        },
        addAudioUrl() {
            const url = this.newAudioUrl.trim()
            if (!url) {
                uni.showToast({ title: '请输入音频地址', icon: 'none' })
                return
            }
            // 从 URL 中提取文件名作为名称
            let name = url.substring(url.lastIndexOf('/') + 1)
            if (name.indexOf('?') > 0) {
                name = name.substring(0, name.indexOf('?'))
            }
            if (!name) name = '音频' + (this.audioList.length + 1)
            this.audioList.push({ url, name })
            this.saveAudioConfig()
            this.newAudioUrl = ''
            uni.showToast({ title: '添加成功', icon: 'success' })
            // 如果是第一首，初始化播放器
            if (this.audioList.length === 1 && this.audioEnabled) {
                this.initAudioPlayer()
            }
        },
        removeAudio(index) {
            this.audioList.splice(index, 1)
            this.saveAudioConfig()
            // 如果删除的是当前播放的，重新设置
            if (index === this.currentAudioIndex && this.audioList.length > 0) {
                this.currentAudioIndex = 0
                this.stopAudioPlay()
                if (this.audioEnabled) {
                    this.startAudioPlay()
                }
            } else if (this.audioList.length === 0) {
                this.stopAudioPlay()
                this.destroyAudioPlayer()
                this.currentAudioIndex = 0
                this.isPlaying = false
            } else if (index < this.currentAudioIndex) {
                this.currentAudioIndex--
            }
        },
        clearAudioList() {
            uni.showModal({
                title: '提示',
                content: '确定要清空播放列表吗？',
                success: (res) => {
                    if (res.confirm) {
                        this.stopAudioPlay()
                        this.destroyAudioPlayer()
                        this.audioList = []
                        this.currentAudioIndex = 0
                        this.isPlaying = false
                        this.saveAudioConfig()
                        uni.showToast({ title: '已清空', icon: 'success' })
                    }
                }
            })
        },
        initAudioPlayer() {
            if (this.audioContext) {
                return
            }
            this.audioContext = uni.createInnerAudioContext()
            this.audioContext.autoplay = false
            // 根据播放模式设置 loop
            this.audioContext.loop = (this.playMode === 'single_loop')
            // 播放结束处理
            this.audioContext.onEnded(() => {
                console.log('音频播放结束，当前模式：' + this.playMode)
                // 单曲循环由 innerAudioContext.loop=true 自动处理，不会触发 onEnded 后再播
                // 这里处理列表循环和播放一次
                if (this.playMode === 'list_loop') {
                    this.playNext()
                } else if (this.playMode === 'single') {
                    // 播放一次：停止不继续
                    this.isPlaying = false
                    this.updateAudioNotification()
                }
                // single_loop 模式由 loop=true 自动循环，不会触发 onEnded
            })
            this.audioContext.onError((err) => {
                console.error('音频播放错误', err)
                uni.showToast({ title: '播放失败：' + (err.errMsg || '未知错误'), icon: 'none' })
                // 播放失败时，列表循环模式跳下一首
                if (this.playMode === 'list_loop') {
                    setTimeout(() => {
                        this.playNext()
                    }, 2000)
                }
            })
            this.audioContext.onPlay(() => {
                console.log('音频开始播放')
                this.isPlaying = true
                this.updateAudioNotification()
            })
            this.audioContext.onPause(() => {
                console.log('音频暂停')
                this.isPlaying = false
                this.updateAudioNotification()
            })
            this.audioContext.onStop(() => {
                console.log('音频停止')
                this.isPlaying = false
            })
            console.log('音频播放器已初始化，播放模式：' + this.playMode)
        },
        destroyAudioPlayer() {
            if (this.audioContext) {
                try {
                    this.audioContext.destroy()
                } catch (e) {}
                this.audioContext = null
            }
            this.isPlaying = false
        },
        startAudioPlay() {
            if (!this.audioContext) {
                this.initAudioPlayer()
            }
            if (!this.audioContext || this.audioList.length === 0) {
                return
            }
            const item = this.audioList[this.currentAudioIndex]
            if (!item) return
            console.log('开始播放音频:', item.name, item.url)
            this.audioContext.src = item.url
            // 设置 loop（单曲循环）
            this.audioContext.loop = (this.playMode === 'single_loop')
            this.audioContext.play()
        },
        stopAudioPlay() {
            if (this.audioContext) {
                this.audioContext.stop()
                this.isPlaying = false
            }
        },
        togglePlay() {
            if (!this.audioContext) {
                this.initAudioPlayer()
                this.startAudioPlay()
                return
            }
            if (this.isPlaying) {
                this.audioContext.pause()
            } else {
                if (!this.audioContext.src && this.audioList.length > 0) {
                    this.startAudioPlay()
                } else {
                    this.audioContext.play()
                }
            }
        },
        playPrev() {
            if (this.audioList.length === 0) return
            this.currentAudioIndex = (this.currentAudioIndex - 1 + this.audioList.length) % this.audioList.length
            this.stopAudioPlay()
            this.startAudioPlay()
        },
        playNext() {
            if (this.audioList.length === 0) return
            this.currentAudioIndex = (this.currentAudioIndex + 1) % this.audioList.length
            this.stopAudioPlay()
            this.startAudioPlay()
        },
        playAudioByIndex(idx) {
            if (idx < 0 || idx >= this.audioList.length) return
            this.currentAudioIndex = idx
            this.stopAudioPlay()
            if (this.audioEnabled) {
                this.startAudioPlay()
            } else {
                // 未启用音频时，自动启用
                this.audioEnabled = true
                this.saveAudioConfig()
                this.initAudioPlayer()
                this.startAudioPlay()
            }
        },
        updateAudioNotification() {
            this.startForegroundService()
        },
        // ============== 设备与配置 ==============
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

            this.form.key = savedKey || APP_CONFIG.default_key
            this.form.serverUrl = savedServer || APP_CONFIG.server_url

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
                    const list = JSON.parse(saved)
                    // 确保每条消息都有唯一 id（兼容旧数据）
                    this.messages = list.map((msg, idx) => ({
                        ...msg,
                        id: msg.id || (msg.time || Date.now()) + '_' + idx
                    }))
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
        // ============== 前台服务保活 ==============
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

                const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
                const contentIntent = PendingIntent.getActivity(
                    main, 0, launchIntent,
                    Build.VERSION.SDK_INT >= 31 ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE : PendingIntent.FLAG_UPDATE_CURRENT
                )

                const builder = new NotificationCompat.Builder(main, channelId)

                if (this.audioEnabled && this.isPlaying && this.audioList.length > 0) {
                    const audioItem = this.audioList[this.currentAudioIndex]
                    const audioName = audioItem ? audioItem.name : '音乐播放中'
                    const modeText = this.playMode === 'single_loop' ? '单曲循环' : (this.playMode === 'list_loop' ? '列表循环' : '播放一次')
                    builder.setContentTitle('♪ ' + audioName)
                    builder.setContentText('推送服务运行中 · ' + modeText + ' · 点击返回应用')
                    builder.setPriority(NotificationCompat.PRIORITY_LOW)
                } else {
                    builder.setContentTitle('推送服务运行中')
                    builder.setContentText('保持后台连接，实时接收推送消息')
                    builder.setPriority(NotificationCompat.PRIORITY_LOW)
                }

                builder.setSmallIcon(main.getApplicationInfo().icon)
                builder.setContentIntent(contentIntent)
                builder.setOngoing(true)
                builder.setAutoCancel(false)

                const notification = builder.build()

                const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                nm.notify(notificationId, notification)

                // WakeLock 保持 CPU 唤醒
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

                // WifiLock 保持 Wi-Fi 连接
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

                if (this._wakeLock) {
                    try {
                        if (this._wakeLock.isHeld()) {
                            this._wakeLock.release()
                        }
                    } catch (e) {}
                    this._wakeLock = null
                }

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

                const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
                launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
                const contentIntent = PendingIntent.getActivity(
                    main, notificationId, launchIntent,
                    Build.VERSION.SDK_INT >= 31 ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE : PendingIntent.FLAG_UPDATE_CURRENT
                )

                const builder = new NotificationCompat.Builder(main, channelId)
                builder.setContentTitle(title || '新消息')
                builder.setContentText(content || '')
                builder.setSmallIcon(main.getApplicationInfo().icon)
                builder.setContentIntent(contentIntent)
                builder.setAutoCancel(true)
                builder.setPriority(NotificationCompat.PRIORITY_HIGH)
                builder.setDefaults(NotificationCompat.DEFAULT_SOUND | NotificationCompat.DEFAULT_VIBRATE | NotificationCompat.DEFAULT_LIGHTS)

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
        // ============== 登录与重连 ==============
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
            this.closeSocket()
            this.reconnectDelay = 3000
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
                        this.stopAudioPlay()
                        this.destroyAudioPlayer()
                        this.showSettings = false
                        uni.removeStorageSync('push_key')
                        uni.removeStorageSync('push_server')
                        uni.removeStorageSync('push_ws')
                        uni.redirectTo({ url: '/pages/index/index' })
                    }
                }
            })
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
                        } catch (e) {}
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
                            } catch (e) {}
                        }
                        if (!launched) {
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
            this.isXiaomiDevice = brand === 'xiaomi' || brand === 'redmi' || brand === 'poco'
        },
        openXiaomiPermission(type) {
            // #ifdef APP-PLUS
            try {
                const Intent = plus.android.importClass('android.content.Intent')
                const Uri = plus.android.importClass('android.net.Uri')
                const main = plus.android.runtimeMainActivity()
                const packageName = main.getPackageName()

                const configs = {
                    autostart: {
                        title: '自启动',
                        actions: [
                            'miui.intent.action.APP_AUTO_START',
                            'com.miui.securitycenter.action.AUTO_START_MANAGER'
                        ],
                        fallbackComponent: 'com.miui.securitycenter/com.miui.permcenter.autostart.AutoStartManagementActivity'
                    },
                    battery_saver: {
                        title: '省电策略',
                        actions: ['miui.intent.action.POWER_HIDE_MODE_APP_LIST'],
                        fallbackComponent: 'com.miui.powerkeeper/com.miui.powerkeeper.ui.HiddenAppsConfigActivity'
                    },
                    background_popup: {
                        title: '后台弹出界面',
                        actions: ['miui.intent.action.APP_PERM_EDITOR'],
                        extras: { 'extra_pkgname': packageName, 'extra_power_mode': 'background_popup' }
                    },
                    lockscreen_show: {
                        title: '锁屏显示',
                        actions: ['miui.intent.action.APP_PERM_EDITOR'],
                        extras: { 'extra_pkgname': packageName, 'extra_permission_type': 'lockscreen_show' }
                    },
                    floating_window: {
                        title: '悬浮窗',
                        actions: ['miui.intent.action.APP_PERM_EDITOR'],
                        extras: { 'extra_pkgname': packageName, 'extra_permission_type': 'floating_window' }
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
                        extras: { 'android.provider.extra.APP_PACKAGE': packageName, 'pkg': packageName },
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
                const actions = cfg.actions || []
                for (const action of actions) {
                    try {
                        const intent = new Intent()
                        intent.setAction(action)
                        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        if (cfg.extras) {
                            for (const key in cfg.extras) {
                                intent.putExtra(key, cfg.extras[key])
                            }
                        }
                        if (action.indexOf('miui.intent.action') === 0) {
                            intent.putExtra('extra_pkgname', packageName)
                        }
                        main.startActivity(intent)
                        launched = true
                        break
                    } catch (e) {}
                }

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
                    } catch (e) {}
                }

                if (!launched) {
                    const Settings = plus.android.importClass('android.provider.Settings')
                    uni.showToast({ title: '未找到' + cfg.title + '设置页，已跳转应用详情', icon: 'none' })
                    const fallback = new Intent()
                    fallback.setAction(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
                    fallback.setData(Uri.fromParts('package', packageName, null))
                    fallback.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    main.startActivity(fallback)
                }

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
                    console.log('网络已恢复，尝试重连 WebSocket')
                    if (this.form.key) {
                        if (!this.connected) {
                            this.cleanupAndReconnect()
                        }
                    }
                } else {
                    console.warn('网络已断开')
                    this.connected = false
                    this.stopHeartbeat()
                }
            }
            uni.onNetworkStatusChange(this.networkStatusChange)
            // #endif
        },
        connectWebSocket() {
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
                this._authTimer = setTimeout(() => {
                    console.warn('鉴权超时（5秒未收到 auth_result），按连接成功处理（兼容旧服务端）')
                    this._confirmConnection()
                }, 5000)
            })

            this.socketTask.onMessage((res) => {
                this.resetHeartbeatTimeout()
                try {
                    const data = JSON.parse(res.data)
                    console.log('[WS] 收到消息:', data.type || data.message || 'unknown', data.title || '')

                    // 1. 鉴权结果（严格匹配，避免误判推送消息）
                    const isAuthResult =
                        data.type === 'auth_result' ||
                        data.message === '连接成功' ||
                        (data.type === undefined && data.code === 0 && data.data && data.data.device_id)

                    if (isAuthResult) {
                        const authSuccess =
                            (data.type === 'auth_result' && (data.code === 0 || data.code === undefined)) ||
                            data.message === '连接成功' ||
                            (data.code === 0 && data.data && data.data.device_id)
                        if (authSuccess) {
                            console.log('[WS] 鉴权成功，连接已就绪')
                            this._confirmConnection()
                        } else {
                            console.warn('[WS] 鉴权失败:', data.message)
                        }
                        return
                    }

                    // 2. 心跳 ping/pong
                    if (data.type === 'ping') {
                        this.socketTask.send({ data: JSON.stringify({ type: 'pong' }) })
                        return
                    }
                    if (data.type === 'pong') {
                        return
                    }

                    // 3. 推送消息解析（按优先级匹配）
                    let title = ''
                    let content = ''
                    let isPush = false

                    // 标准新格式：type=push/message/offline_message
                    if (data.type === 'push' || data.type === 'message' || data.type === 'offline_message') {
                        isPush = true
                        title = data.title || ''
                        content = data.content || ''
                        if ((!title || !content) && data.data && typeof data.data === 'object') {
                            title = title || data.data.title || ''
                            content = content || data.data.content || ''
                        }
                    }

                    // 兼容旧格式：code=0 + message='message' + data
                    if (!isPush && data.code === 0 && data.data && typeof data.data === 'object') {
                        if (data.message === 'message' || data.message === 'offline_message') {
                            isPush = true
                        } else if (data.data.title || data.data.content) {
                            isPush = true
                        }
                        if (isPush) {
                            title = data.data.title || data.title || ''
                            content = data.data.content || data.content || ''
                        }
                    }

                    // 兜底：只要有 title 或 content 就当作推送
                    if (!isPush && (data.title || data.content)) {
                        isPush = true
                        title = data.title || ''
                        content = data.content || ''
                    }

                    if (isPush) {
                        console.log('[WS] 解析为推送消息，title=', title || '(空)', 'content=', content ? content.substring(0, 50) : '(空)')
                        this.addMessage(title || '消息推送', content || '')
                    } else {
                        console.log('[WS] 收到未知类型消息:', data)
                    }
                } catch (e) {
                    console.error('[WS] 消息解析失败', e, '原始数据:', res.data)
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
                    this.stopForegroundService()
                    uni.removeStorageSync('push_key')
                    uni.removeStorageSync('push_server')
                    uni.removeStorageSync('push_ws')
                    setTimeout(() => {
                        uni.redirectTo({ url: '/pages/index/index' })
                    }, 1500)
                    return
                }
                if (code === 4003 || reason === 'blacklisted') {
                    console.warn('设备已被拉黑，停止重连')
                    uni.showToast({ title: '设备已被拉黑', icon: 'none' })
                    this.stopForegroundService()
                    return
                }
                this.scheduleReconnect()
            })

            this.socketTask.onError((err) => {
                console.error('WebSocket 错误', err)
                this.connecting = false
                this.connected = false
                if (this.socketTask) {
                    try { this.socketTask.close() } catch (e) {}
                }
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
            this.startForegroundService()
            this.createNotificationChannel()
            try {
                if (this.socketTask) {
                    this.socketTask.send({ data: JSON.stringify({ type: 'ping' }) })
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
                        success: () => {},
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
            if (!this.form.key) {
                return
            }
            if (this.reconnectTimer) {
                return
            }
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
                if (this.form.key) {
                    this.connectWebSocket()
                }
            }, delay)
            if (this._reconnectCount >= 3) {
                this.reconnectDelay = Math.min(this.reconnectDelay * 2, this.maxReconnectDelay)
            }
        },
        cleanupAndReconnect() {
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
            if (this.form.key) {
                this.connectWebSocket()
            }
        },
        addMessage(title, content) {
            console.log('[UI] addMessage 被调用, title=', title, '当前消息数=', this.messages.length)

            // 1. 使用 Vue.set 确保响应式（兼容 uni-app 各种环境）
            const newMsg = {
                title: title,
                content: content,
                time: Date.now(),
                id: Date.now() + '_' + Math.random().toString(36).substr(2, 9)
            }

            // 2. 创建新数组引用，强制触发视图更新（解决 scroll-view 不刷新问题）
            this.messages = [newMsg, ...this.messages]

            // 3. 限制最多 100 条
            if (this.messages.length > 100) {
                this.messages = this.messages.slice(0, 100)
            }

            // 4. 更新统计
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

            // 5. 持久化存储
            this.saveMessages()
            this.updateStats()

            // 6. 强制刷新视图（uni-app APP 端 scroll-view 有时需要手动触发）
            this.$nextTick(() => {
                try {
                    if (this.$forceUpdate) {
                        this.$forceUpdate()
                    }
                } catch (e) {
                    console.warn('[UI] forceUpdate 失败', e)
                }
                console.log('[UI] 消息已添加到列表，当前总数=', this.messages.length)
            })

            // 7. 显示系统通知
            this.showNotification(title, content)

            // 8. 仅在消息推送 tab 时显示 toast，避免干扰播放器使用
            if (this.currentTab === 'message') {
                uni.showToast({
                    title: title,
                    icon: 'none',
                    duration: 2000
                })
            }
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
    padding-bottom: 70px;
    box-sizing: border-box;
}

/* 顶部状态栏 */
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

/* Tab 页面 */
.tab-page {
    min-height: calc(100vh - 70px);
    display: flex;
    flex-direction: column;
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

/* ============== 播放器页面 ============== */
.player-page {
    padding: 0 20px;
}

.player-main {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 30px 20px 20px;
}

.player-disc-wrap {
    margin-bottom: 20px;
}

.player-disc {
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 12px 32px rgba(102, 126, 234, 0.3);
}

.player-disc.rotating {
    animation: rotate 8s linear infinite;
}

@keyframes rotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.player-disc-icon {
    font-size: 72px;
    color: white;
}

.player-song-name {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin-bottom: 6px;
    text-align: center;
    max-width: 100%;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.player-song-status {
    font-size: 13px;
    color: #999;
    margin-bottom: 24px;
}

.player-controls-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 40px;
    margin-bottom: 24px;
}

.ctrl-btn {
    font-size: 28px;
    color: #667eea;
    padding: 8px 12px;
}

.ctrl-btn-play {
    font-size: 56px;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
}

.play-mode-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
    margin-bottom: 8px;
}

.play-mode-label {
    font-size: 13px;
    color: #999;
}

.play-mode-options {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.mode-option {
    font-size: 12px;
    padding: 4px 12px;
    background: #f5f7fa;
    color: #666;
    border-radius: 12px;
}

.mode-option.active {
    background: #667eea;
    color: white;
}

/* 播放列表 */
.player-playlist {
    margin-top: 12px;
}

.audio-list {
    max-height: 400px;
}

.audio-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    background: white;
    border-radius: 10px;
    margin-bottom: 8px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}

.audio-item-active {
    background: linear-gradient(135deg, #f0f4ff 0%, #f8f0ff 100%);
    border: 1px solid #667eea;
}

.audio-index {
    width: 28px;
    height: 28px;
    line-height: 28px;
    text-align: center;
    background: #f0f0f0;
    color: #666;
    border-radius: 50%;
    font-size: 12px;
    margin-right: 12px;
    flex-shrink: 0;
}

.audio-item-active .audio-index {
    background: #667eea;
    color: white;
}

.audio-name {
    flex: 1;
    font-size: 14px;
    color: #333;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    margin-right: 8px;
}

.audio-playing-icon {
    font-size: 14px;
    margin-right: 8px;
}

.audio-del {
    font-size: 12px;
    color: #f56c6c;
    flex-shrink: 0;
    padding: 4px 8px;
}

/* ============== 底部 Tab Bar ============== */
.bottom-tab-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    height: 60px;
    background: white;
    border-top: 1px solid #eee;
    display: flex;
    z-index: 999;
    box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.04);
}

.tab-item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 2px;
    color: #999;
    transition: color 0.2s;
}

.tab-item.tab-active {
    color: #667eea;
}

.tab-icon {
    font-size: 22px;
}

.tab-text {
    font-size: 11px;
}

/* ============== 设置弹窗 ============== */
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

/* 音频设置 */
.audio-switch-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    margin-top: 8px;
}

.setting-label-sm {
    font-size: 14px;
    color: #606266;
}

.audio-input-row {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    width: 100%;
}

.audio-input-row .setting-input {
    flex: 1;
}

.btn-add {
    background-color: #67c23a;
    color: #ffffff;
    font-size: 13px;
    padding: 0 16px;
    height: 36px;
    line-height: 36px;
}

.audio-list-settings {
    margin-top: 12px;
    width: 100%;
    max-height: 200px;
    overflow-y: auto;
}

.audio-list-settings .audio-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 12px;
    background-color: #f5f7fa;
    border-radius: 6px;
    margin-bottom: 8px;
}
</style>
