<template>
    <view class="container">
        <!-- 顶部状态栏 -->
        <view class="header">
            <view class="header-left">
                <text class="header-title">{{ currentTab === 'message' ? '消息推送' : (currentTab === 'player' ? '音频播放器' : '用户中心') }}</text>
                <view v-if="currentTab === 'message'" :class="['status-dot', connected ? 'connected' : 'disconnected']"></view>
            </view>
            <view class="header-right">
                <text
                    :class="['refresh-icon', refreshing ? 'refreshing' : '']"
                    @click="handleManualRefresh"
                >{{ refreshing ? '⏳' : '🔄' }}</text>
                <text class="setting-icon" @click="showSettings = true">⚙️</text>
            </view>
        </view>

        <!-- 掉线提醒条 -->
        <view v-if="showDisconnectBanner && !connected" class="disconnect-banner">
            <view class="disconnect-banner-content">
                <text class="disconnect-icon">⚠️</text>
                <view class="disconnect-text-wrap">
                    <text class="disconnect-title">{{ reconnectingTip || '连接已断开' }}</text>
                    <text v-if="lastDisconnectTimeStr" class="disconnect-time">上次掉线：{{ lastDisconnectTimeStr }}</text>
                </view>
                <text class="disconnect-retry-btn" @click="cleanupAndReconnect">立即重连</text>
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
                    <view class="section-header-right">
                        <text v-if="refreshTimeAgo" class="last-refresh">已更新 {{ refreshTimeAgo }}</text>
                        <text class="refresh-btn" @click="handleManualRefresh">{{ refreshing ? '刷新中...' : '刷新' }}</text>
                        <text class="clear-btn" @click="clearMessages">清空</text>
                    </view>
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
                <view class="message-header">
                    <text class="message-title">{{ msg.title }}</text>
                    <text class="message-copy-btn" @click="copyMessage(msg)">复制</text>
                </view>
                <text class="message-content">{{ msg.content }}</text>
                <view class="message-footer">
                    <text class="message-time">{{ formatTime(msg.time) }}</text>
                    <text v-if="msg.is_synced" class="message-synced-tag">已同步</text>
                </view>
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
                <text class="player-song-status">{{ isPlaying ? '播放中' : ((currentAudioSource === 'server' ? serverAudioList.length : audioList.length) > 0 ? '已暂停' : '未加载') }}</text>

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
                    <text v-if="audioListTab === 'local' && audioList.length > 0" class="clear-btn" @click="clearAudioList">清空</text>
                    <text v-if="audioListTab === 'server'" class="clear-btn" @click="fetchServerAudioList">刷新</text>
                </view>
                <!-- 播放列表 Tab 切换 -->
                <view class="audio-tab-bar">
                    <text
                        :class="['audio-tab-item', audioListTab === 'server' ? 'active' : '']"
                        @click="audioListTab = 'server'"
                    >云端音频</text>
                    <text
                        :class="['audio-tab-item', audioListTab === 'local' ? 'active' : '']"
                        @click="audioListTab = 'local'"
                    >本地音频</text>
                </view>
                <!-- 云端音频列表 -->
                <scroll-view v-if="audioListTab === 'server'" scroll-y class="audio-list">
                    <view v-if="loadingAudio" class="empty-state">
                        <text class="empty-icon">⏳</text>
                        <text class="empty-text">加载中...</text>
                    </view>
                    <view v-else-if="serverAudioList.length === 0" class="empty-state">
                        <text class="empty-icon">🎵</text>
                        <text class="empty-text">暂无云端音频</text>
                        <text class="empty-desc">请在服务器端上传音频文件</text>
                    </view>
                    <view
                        v-for="(item, idx) in serverAudioList"
                        :key="item.id || idx"
                        :class="['audio-item', (currentAudioSource === 'server' && idx === currentAudioIndex) ? 'audio-item-active' : '']"
                        @click="playServerAudioByIndex(idx)"
                    >
                        <text class="audio-index">{{ idx + 1 }}</text>
                        <view class="audio-info">
                            <text class="audio-name">{{ item.title }}</text>
                            <text class="audio-artist">{{ item.artist || '未知歌手' }} · {{ item.duration_text || '' }}</text>
                        </view>
                        <text v-if="currentAudioSource === 'server' && idx === currentAudioIndex && isPlaying" class="audio-playing-icon">🔊</text>
                    </view>
                </scroll-view>
                <!-- 本地音频列表 -->
                <scroll-view v-else scroll-y class="audio-list">
                    <view v-if="audioList.length === 0" class="empty-state">
                        <text class="empty-icon">🎵</text>
                        <text class="empty-text">暂无音频</text>
                        <text class="empty-desc">点击右上角 ⚙️ 添加音频地址</text>
                    </view>
                    <view
                        v-for="(item, idx) in audioList"
                        :key="idx"
                        :class="['audio-item', (currentAudioSource === 'local' && idx === currentAudioIndex) ? 'audio-item-active' : '']"
                        @click="playLocalAudioByIndex(idx)"
                    >
                        <text class="audio-index">{{ idx + 1 }}</text>
                        <text class="audio-name">{{ item.name }}</text>
                        <text v-if="currentAudioSource === 'local' && idx === currentAudioIndex && isPlaying" class="audio-playing-icon">🔊</text>
                        <text class="audio-del" @click.stop="removeAudio(idx)">删除</text>
                    </view>
                </scroll-view>
            </view>
        </view>

        <!-- ========== Tab 3: 用户中心 ========== -->
        <view v-show="currentTab === 'profile'" class="tab-page profile-page">
            <!-- 用户信息卡片 -->
            <view class="profile-header-card">
                <view class="profile-avatar">
                    <image v-if="logoUrl" :src="logoUrl" class="avatar-img" mode="aspectFill" />
                    <text v-else class="avatar-text">P</text>
                </view>
                <view class="profile-user-info">
                    <text class="profile-username">PushApp 用户</text>
                    <text class="profile-device-id">设备ID：{{ deviceIdShort }}</text>
                    <view class="profile-status-row">
                        <view :class="['profile-status-dot', connected ? 'online' : 'offline']"></view>
                        <text :class="['profile-status-text', connected ? 'online' : 'offline']">{{ connected ? '在线' : '离线' }}</text>
                    </view>
                </view>
            </view>

            <!-- 数据统计 -->
            <view class="profile-stats-row">
                <view class="profile-stat-item">
                    <text class="profile-stat-value">{{ todayCount }}</text>
                    <text class="profile-stat-label">今日</text>
                </view>
                <view class="profile-stat-divider"></view>
                <view class="profile-stat-item">
                    <text class="profile-stat-value">{{ totalCount }}</text>
                    <text class="profile-stat-label">累计</text>
                </view>
                <view class="profile-stat-divider"></view>
                <view class="profile-stat-item">
                    <text class="profile-stat-value">{{ messages.length }}</text>
                    <text class="profile-stat-label">已读</text>
                </view>
            </view>

            <!-- 刷新按钮（用户中心） -->
            <view class="profile-refresh-row">
                <text v-if="refreshTimeAgo" class="profile-last-refresh">最后更新：{{ refreshTimeAgo }}</text>
                <text class="profile-refresh-btn" @click="handleManualRefresh">{{ refreshing ? '刷新中...' : '🔄 刷新数据' }}</text>
            </view>

            <!-- 连接信息 -->
            <view class="profile-section">
                <view class="profile-section-title">连接信息</view>
                <view class="profile-cell">
                    <text class="profile-cell-label">推送 Key</text>
                    <text class="profile-cell-value">{{ form.key ? form.key.substring(0, 12) + '...' : '--' }}</text>
                </view>
                <view class="profile-cell">
                    <text class="profile-cell-label">服务器</text>
                    <text class="profile-cell-value">{{ form.serverUrl || '--' }}</text>
                </view>
                <view class="profile-cell">
                    <text class="profile-cell-label">WebSocket</text>
                    <text class="profile-cell-value">{{ wsUrl || form.wsUrl || '--' }}</text>
                </view>
                <view class="profile-cell" v-if="_lastRtt">
                    <text class="profile-cell-label">网络延迟</text>
                    <text class="profile-cell-value">{{ _lastRtt }}ms</text>
                </view>
                <view class="profile-cell">
                    <text class="profile-cell-label">连接状态</text>
                    <text :class="['profile-cell-value', connected ? 'text-success' : 'text-danger']">{{ connected ? '已连接' : '已断开' }}</text>
                </view>
            </view>

            <!-- 权限管理 -->
            <view class="profile-section">
                <view class="profile-section-title">权限管理</view>
                <view class="profile-cell profile-cell-tap" @click="openPermission('app')">
                    <text class="profile-cell-label">应用详情</text>
                    <text class="profile-cell-arrow">›</text>
                </view>
                <view class="profile-cell profile-cell-tap" @click="openPermission('notification')">
                    <text class="profile-cell-label">通知权限</text>
                    <text class="profile-cell-arrow">›</text>
                </view>
                <view class="profile-cell profile-cell-tap" @click="openPermission('battery')">
                    <text class="profile-cell-label">电池优化白名单</text>
                    <text class="profile-cell-arrow">›</text>
                </view>
                <view class="profile-cell profile-cell-tap" @click="openPermission('autostart')">
                    <text class="profile-cell-label">自启动管理</text>
                    <text class="profile-cell-arrow">›</text>
                </view>
                <template v-if="isXiaomiDevice">
                    <view class="profile-cell profile-cell-tap" @click="openXiaomiPermission('autostart')">
                        <text class="profile-cell-label">小米·自启动</text>
                        <text class="profile-cell-arrow">›</text>
                    </view>
                    <view class="profile-cell profile-cell-tap" @click="openXiaomiPermission('battery_saver')">
                        <text class="profile-cell-label">小米·省电策略</text>
                        <text class="profile-cell-arrow">›</text>
                    </view>
                    <view class="profile-cell profile-cell-tap" @click="openXiaomiPermission('background_popup')">
                        <text class="profile-cell-label">小米·后台弹出</text>
                        <text class="profile-cell-arrow">›</text>
                    </view>
                    <view class="profile-cell profile-cell-tap" @click="openXiaomiPermission('lockscreen_show')">
                        <text class="profile-cell-label">小米·锁屏显示</text>
                        <text class="profile-cell-arrow">›</text>
                    </view>
                </template>
            </view>

            <!-- 关于 -->
            <view class="profile-section">
                <view class="profile-section-title">关于</view>
                <view class="profile-cell">
                    <text class="profile-cell-label">应用版本</text>
                    <text class="profile-cell-value">{{ versionName }}</text>
                </view>
                <view class="profile-cell">
                    <text class="profile-cell-label">设备型号</text>
                    <text class="profile-cell-value">{{ deviceModel || '--' }}</text>
                </view>
                <view class="profile-cell">
                    <text class="profile-cell-label">系统版本</text>
                    <text class="profile-cell-value">{{ osVersion || '--' }}</text>
                </view>
                <view class="profile-cell">
                    <text class="profile-cell-label">设备品牌</text>
                    <text class="profile-cell-value">{{ deviceBrand || '--' }}</text>
                </view>
            </view>

            <!-- 其他操作 -->
            <view class="profile-section">
                <view class="profile-section-title">其他操作</view>
                <view class="profile-cell profile-cell-tap" @click="showSettings = true">
                    <text class="profile-cell-label">应用设置</text>
                    <text class="profile-cell-arrow">›</text>
                </view>
                <view class="profile-cell profile-cell-tap" @click="checkUpdate">
                    <text class="profile-cell-label">检查更新</text>
                    <text class="profile-cell-arrow">›</text>
                </view>
                <view class="profile-cell profile-cell-tap" @click="clearCache">
                    <text class="profile-cell-label">清除缓存</text>
                    <text class="profile-cell-arrow">›</text>
                </view>
                <view class="profile-cell profile-cell-tap" @click="openBindDeviceId">
                    <text class="profile-cell-label">绑定/修改设备 ID</text>
                    <text class="profile-cell-value profile-cell-value-sub">{{ deviceIdShort }}</text>
                    <text class="profile-cell-arrow">›</text>
                </view>
                <view class="profile-cell profile-cell-tap" @click="copyDeviceInfo">
                    <text class="profile-cell-label">复制设备信息</text>
                    <text class="profile-cell-arrow">›</text>
                </view>
            </view>

            <!-- 退出登录 -->
            <view class="profile-logout-wrap">
                <button class="profile-logout-btn" @click="handleLogout">退出登录</button>
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
            <view
                :class="['tab-item', currentTab === 'profile' ? 'tab-active' : '']"
                @click="switchTab('profile')"
            >
                <text class="tab-icon">👤</text>
                <text class="tab-text">用户中心</text>
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
                    <view class="setting-item setting-item-column">
                        <text class="setting-label">设备 ID 绑定</text>
                        <text class="setting-tip">从后台「设备管理」列表复制设备 ID（如 app-xxxx）粘贴到下方，即可绑定到该设备（消息将推送到此 ID）。绑定后会断开当前连接并以新设备 ID 重连。</text>
                        <input
                            class="setting-input"
                            v-model="bindDeviceIdInput"
                            placeholder="输入/粘贴后台设备 ID，例如 app-6yfple6sms4x1f93"
                        />
                        <view class="setting-btn-row">
                            <button class="btn-sm" @click="applyBoundDeviceId">应用并重连</button>
                            <button class="btn-sm btn-secondary" @click="pasteDeviceIdFromClipboard">粘贴剪贴板</button>
                            <button class="btn-sm btn-reset" @click="resetDeviceIdAuto">恢复自动生成</button>
                        </view>
                        <text class="setting-tip">当前设备 ID：{{ deviceId }}</text>
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
            bindDeviceIdInput: '',
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
            // 掉线提醒
            lastDisconnectTime: null,      // 上次掉线时间（Date 对象）
            lastDisconnectTimeStr: '',     // 上次掉线时间（格式化字符串）
            reconnectingTip: '',           // 重连中提示文案
            showDisconnectBanner: false,   // 是否显示掉线提醒条
            // 数据刷新机制
            refreshing: false,             // 是否正在刷新
            lastRefreshTime: null,         // 上次刷新时间（Date 对象）
            lastRefreshTimeStr: '',        // 上次刷新时间（格式化字符串）
            _autoRefreshTimer: null,       // 自动刷新定时器
            _lastRefreshAt: 0,             // 上次刷新时间戳（毫秒），用于节流
            // 音频播放器
            audioEnabled: false,
            audioList: [],
            newAudioUrl: '',
            currentAudioIndex: 0,
            isPlaying: false,
            audioContext: null,
            // 播放模式：list_loop=列表循环 / single_loop=单曲循环 / single=播放一次
            playMode: 'list_loop',
            // 云端音频列表
            serverAudioList: [],
            loadingAudio: false,
            // 当前播放音频来源：server=云端 / local=本地
            currentAudioSource: 'local',
            // 播放列表当前显示的 tab：server=云端音频 / local=本地音频
            audioListTab: 'server',
            // 用户中心
            logoUrl: '',
            deviceModel: '',
            deviceBrand: '',
            osVersion: ''
        }
    },
    computed: {
        deviceIdShort() {
            return this.deviceId ? this.deviceId.substring(0, 8) : '--'
        },
        // 上次刷新时间的友好显示（如"刚刚"、"2分钟前"）
        refreshTimeAgo() {
            if (!this.lastRefreshTime) return ''
            const diff = Date.now() - this.lastRefreshTime.getTime()
            if (diff < 60000) return '刚刚'
            if (diff < 3600000) return Math.floor(diff / 60000) + '分钟前'
            return this.lastRefreshTimeStr
        },
        currentAudioName() {
            if (this.currentAudioSource === 'server') {
                if (this.serverAudioList.length === 0) return '暂无音频'
                const item = this.serverAudioList[this.currentAudioIndex]
                if (!item) return '暂无音频'
                return item.title || '未知音频'
            } else {
                if (this.audioList.length === 0) return '暂无音频'
                const item = this.audioList[this.currentAudioIndex]
                if (!item) return '暂无音频'
                return item.name
            }
        }
    },
    onLoad() {
        this.initDeviceId()
        this.checkXiaomiDevice()
        this.loadConfig()
        this.loadMessages()
        this.loadAudioConfig()
        this.fetchServerAudioList()
        this.loadDeviceInfo()
        this.logoUrl = '/static/logo.png'
        // 验证登录状态，未登录则返回登录页
        const savedKey = uni.getStorageSync('push_key')
        const savedServer = uni.getStorageSync('push_server')
        if (!savedKey || !savedServer) {
            uni.redirectTo({ url: '/pages/index/index' })
            return
        }
        this.registerNetworkListener()
        // 请求通知权限 + 电池优化 + 小米自启动（延迟 1 秒等页面渲染完）
        setTimeout(() => {
            this.requestNotificationPermission()
            this.checkBatteryOptimization()
            this.checkXiaomiAutoStart()
        }, 1000)
        // 延迟连接，确保页面渲染完成
        setTimeout(() => {
            this.connectWebSocket()
            if (this.audioEnabled && (this.audioList.length > 0 || this.serverAudioList.length > 0)) {
                this.initAudioPlayer()
            }
        }, 300)
        // 启动自动刷新定时器（每 60 秒静默同步一次历史消息，保证数据最新）
        this.startAutoRefresh()
        // 首次加载延迟同步一次历史消息
        setTimeout(() => {
            this.refreshData(true)
        }, 2000)
    },
    // 下拉刷新：用户主动下拉触发
    onPullDownRefresh() {
        console.log('[Refresh] 用户下拉刷新')
        this.refreshData(false).finally(() => {
            uni.stopPullDownRefresh()
        })
    },
    onShow() {
        // APP 从后台切回前台 / 页面重新显示时，主动检测并重连断开的 WebSocket
        if (this.form.key) {
            if (!this.connected && !this.connecting) {
                console.log('页面 onShow 检测到连接已断开，主动重连')
                this.cleanupAndReconnect()
                // 切回前台时静默刷新一次数据
                this.refreshData(true)
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
                this._onShowPingTimer && clearTimeout(this._onShowPingTimer)
                this._onShowPongOk = false
                this._onShowPingTimer = setTimeout(() => {
                    if (!this._onShowPongOk) {
                        console.warn('onShow 验证 ping 超时（5秒无响应），连接已失效，触发重连')
                        this.cleanupAndReconnect()
                    }
                    this._onShowPingTimer = null
                }, 5000)
                try {
                    this.socketTask.send({
                        data: JSON.stringify({ type: 'ping' }),
                        fail: (err) => {
                            this._onShowPingTimer && clearTimeout(this._onShowPingTimer)
                            this._onShowPingTimer = null
                            console.warn('onShow 验证 ping 发送失败，连接已失效，触发重连', err)
                            this.cleanupAndReconnect()
                        }
                    })
                } catch (e) {
                    this._onShowPingTimer && clearTimeout(this._onShowPingTimer)
                    this._onShowPingTimer = null
                    console.warn('onShow 验证 ping 异常，触发重连', e)
                    this.cleanupAndReconnect()
                }
            }
            // 切回前台时静默刷新一次数据（节流：距离上次刷新超过 30 秒才触发）
            const now = Date.now()
            if (now - this._lastRefreshAt > 30000) {
                this.refreshData(true)
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
        // 清理自动刷新定时器
        this.stopAutoRefresh()
        // #ifdef APP-PLUS
        try {
            uni.offNetworkStatusChange(this.networkStatusChange)
        } catch (e) {}
        try {
            const main = plus.android.runtimeMainActivity()
            if (this._mediaReceiver && this._mediaReceiverRegistered) {
                main.unregisterReceiver(this._mediaReceiver)
                this._mediaReceiver = null
                this._mediaReceiverRegistered = false
            }
        } catch (e) {}
        // #endif
    },
    methods: {
        switchTab(tab) {
            this.currentTab = tab
        },
        // ============== 数据刷新机制 ==============
        // 启动自动刷新定时器（每 60 秒静默同步一次）
        startAutoRefresh() {
            this.stopAutoRefresh()
            this._autoRefreshTimer = setInterval(() => {
                // 仅在连接正常时静默刷新
                if (this.connected && this.form.key) {
                    console.log('[Refresh] 自动刷新触发')
                    this.refreshData(true)
                }
            }, 60000)
            console.log('[Refresh] 自动刷新已启动（60秒间隔）')
        },
        // 停止自动刷新定时器
        stopAutoRefresh() {
            if (this._autoRefreshTimer) {
                clearInterval(this._autoRefreshTimer)
                this._autoRefreshTimer = null
                console.log('[Refresh] 自动刷新已停止')
            }
        },
        // 手动刷新入口（带节流和 Toast 提示）
        handleManualRefresh() {
            // 节流：3 秒内只允许触发一次
            const now = Date.now()
            if (now - this._lastRefreshAt < 3000) {
                uni.showToast({ title: '刚刷新过，请稍候', icon: 'none', duration: 1000 })
                return
            }
            this.refreshData(false).then((newCount) => {
                if (newCount > 0) {
                    uni.showToast({ title: '新增 ' + newCount + ' 条消息', icon: 'success' })
                } else {
                    uni.showToast({ title: '已是最新', icon: 'none', duration: 1000 })
                }
            })
        },
        // 核心刷新方法：同步历史消息 + 更新统计
        // silent=true 静默刷新（不显示 loading），false 显示 loading
        refreshData(silent = false) {
            if (this.refreshing) {
                return Promise.resolve(0)
            }
            this.refreshing = true
            const pushKey = this.form.key || uni.getStorageSync('push_key') || ''
            const deviceId = this.deviceId || ''
            // 无 push_key 或 device_id 时仅更新本地统计
            if (!pushKey || !deviceId) {
                this.updateStats()
                this.markRefreshed()
                this.refreshing = false
                return Promise.resolve(0)
            }
            const serverUrl = (this.form.serverUrl || APP_CONFIG.server_url || '').replace(/\/+$/, '')
            const url = serverUrl + '/api/device/messages?push_key='
                + encodeURIComponent(pushKey)
                + '&device_id=' + encodeURIComponent(deviceId)
                + '&limit=50'
            return new Promise((resolve) => {
                uni.request({
                    url: url,
                    method: 'GET',
                    timeout: 8000,
                    success: (res) => {
                        if (res.statusCode !== 200 || !res.data || res.data.code !== 0) {
                            console.warn('[Refresh] 同步失败:', res.data?.message || res.statusCode)
                            resolve(0)
                            return
                        }
                        const data = res.data.data || {}
                        const list = data.list || []
                        // 合并去重
                        const existingIds = new Set(this.messages.map(m => m.message_id || m.id))
                        const newMsgs = []
                        list.forEach((item) => {
                            const msgId = item.message_id || ('db_' + item.id)
                            if (existingIds.has(msgId)) return
                            newMsgs.push({
                                id: msgId,
                                message_id: item.message_id,
                                title: item.title || '消息推送',
                                content: item.content || '',
                                time: new Date(item.created_at.replace(/-/g, '/')).getTime() || Date.now(),
                                is_synced: true
                            })
                        })
                        if (newMsgs.length > 0) {
                            this.messages = [...this.messages, ...newMsgs].sort((a, b) => (b.time || 0) - (a.time || 0))
                            if (this.messages.length > 100) {
                                this.messages = this.messages.slice(0, 100)
                            }
                            this.saveMessages()
                            this.updateStats()
                            console.log('[Refresh] 新增', newMsgs.length, '条消息')
                        } else {
                            console.log('[Refresh] 已是最新')
                        }
                        this.markRefreshed()
                        resolve(newMsgs.length)
                    },
                    fail: (err) => {
                        console.error('[Refresh] 请求失败', err)
                        // 失败时仍更新本地统计
                        this.updateStats()
                        this.markRefreshed()
                        resolve(0)
                    },
                    complete: () => {
                        this.refreshing = false
                    }
                })
            })
        },
        // 记录刷新时间
        markRefreshed() {
            this.lastRefreshTime = new Date()
            this.lastRefreshTimeStr = this.formatDateTime(this.lastRefreshTime)
            this._lastRefreshAt = Date.now()
        },
        // 格式化日期时间（用于刷新时间显示）
        formatDateTime(date) {
            if (!date) return ''
            const d = date instanceof Date ? date : new Date(date)
            const pad = (n) => (n < 10 ? '0' + n : '' + n)
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
        },
        // ============== 音频播放器 ==============
        fetchServerAudioList() {
            if (!this.form.serverUrl) {
                console.log('服务器地址为空，跳过获取云端音频列表')
                return
            }
            this.loadingAudio = true
            const url = this.form.serverUrl + '/api/audio/list'
            console.log('获取云端音频列表:', url)
            uni.request({
                url: url,
                method: 'GET',
                success: (res) => {
                    console.log('云端音频列表获取成功:', res.data)
                    // 后端响应格式: { code: 0, data: { list: [...], total: N } }
                    const resData = res.data || {}
                    const list = resData.data ? resData.data.list : resData.list
                    if (list && Array.isArray(list)) {
                        this.serverAudioList = list
                        // 查找默认音频
                        const defaultIndex = list.findIndex(item => item.is_default === 1 || item.is_default === '1')
                        if (defaultIndex >= 0) {
                            this.currentAudioSource = 'server'
                            this.currentAudioIndex = defaultIndex
                            if (this.audioEnabled) {
                                this.initAudioPlayer()
                                this.startAudioPlay()
                            }
                        }
                    }
                },
                fail: (err) => {
                    console.error('获取云端音频列表失败:', err)
                },
                complete: () => {
                    this.loadingAudio = false
                }
            })
        },
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
                this.currentAudioSource = 'local'
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
            const hasAudio = this.audioList.length > 0 || this.serverAudioList.length > 0
            if (this.audioEnabled && hasAudio) {
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
                this.currentAudioSource = 'local'
                this.currentAudioIndex = 0
                this.initAudioPlayer()
            }
        },
        removeAudio(index) {
            this.audioList.splice(index, 1)
            this.saveAudioConfig()
            // 只有当前播放的是本地音频时才处理
            if (this.currentAudioSource === 'local') {
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
                    // 如果有云端音频，切换到云端
                    if (this.serverAudioList.length > 0) {
                        this.currentAudioSource = 'server'
                        this.currentAudioIndex = 0
                    }
                } else if (index < this.currentAudioIndex) {
                    this.currentAudioIndex--
                }
            }
        },
        clearAudioList() {
            uni.showModal({
                title: '提示',
                content: '确定要清空播放列表吗？',
                success: (res) => {
                    if (res.confirm) {
                        // 如果当前播放的是本地音频，停止播放
                        if (this.currentAudioSource === 'local') {
                            this.stopAudioPlay()
                            this.destroyAudioPlayer()
                            this.isPlaying = false
                            // 如果有云端音频，切换到云端
                            if (this.serverAudioList.length > 0) {
                                this.currentAudioSource = 'server'
                                this.currentAudioIndex = 0
                            } else {
                                this.currentAudioIndex = 0
                            }
                        }
                        this.audioList = []
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
            const list = this.currentAudioSource === 'server' ? this.serverAudioList : this.audioList
            if (!this.audioContext || list.length === 0) {
                return
            }
            const item = list[this.currentAudioIndex]
            if (!item) return
            let audioUrl, audioName
            if (this.currentAudioSource === 'server') {
                // 云端音频：play_url 是相对路径，需要拼接服务器地址
                audioUrl = this.form.serverUrl + item.play_url
                audioName = item.title
                // 确保 URL 格式正确
                if (audioUrl.indexOf('http') !== 0) {
                    // 如果 serverUrl 没有协议头，补上
                    audioUrl = 'http://' + audioUrl
                }
            } else {
                audioUrl = item.url
                audioName = item.name
            }
            console.log('开始播放音频:', audioName, audioUrl)
            // Android 平台需要先设置 src 再 play
            this.audioContext.src = audioUrl
            // 设置 loop（单曲循环）
            this.audioContext.loop = (this.playMode === 'single_loop')
            // 延迟一帧再播放，确保 src 已生效
            setTimeout(() => {
                if (this.audioContext) {
                    this.audioContext.play()
                }
            }, 100)
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
                const list = this.currentAudioSource === 'server' ? this.serverAudioList : this.audioList
                if (!this.audioContext.src && list.length > 0) {
                    this.startAudioPlay()
                } else {
                    this.audioContext.play()
                }
            }
        },
        playPrev() {
            const list = this.currentAudioSource === 'server' ? this.serverAudioList : this.audioList
            if (list.length === 0) return
            this.currentAudioIndex = (this.currentAudioIndex - 1 + list.length) % list.length
            this.stopAudioPlay()
            this.startAudioPlay()
        },
        playNext() {
            const list = this.currentAudioSource === 'server' ? this.serverAudioList : this.audioList
            if (list.length === 0) return
            this.currentAudioIndex = (this.currentAudioIndex + 1) % list.length
            this.stopAudioPlay()
            this.startAudioPlay()
        },
        playServerAudioByIndex(idx) {
            if (idx < 0 || idx >= this.serverAudioList.length) return
            this.currentAudioSource = 'server'
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
        playLocalAudioByIndex(idx) {
            if (idx < 0 || idx >= this.audioList.length) return
            this.currentAudioSource = 'local'
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
        playAudioByIndex(idx) {
            this.playLocalAudioByIndex(idx)
        },
        updateAudioNotification() {
            this.startForegroundService()
        },
        // ============== 设备与配置 ==============
        initDeviceId() {
            let deviceId = uni.getStorageSync('push_device_id')
            if (!deviceId) {
                // 优先使用设备硬件标识生成稳定ID（同一手机重装应用也保持一致）
                let hardwareId = ''
                // #ifdef APP-PLUS
                try {
                    const info = uni.getSystemInfoSync()
                    // androidId 在 Android 8+ 仍可用，作为设备唯一标识
                    hardwareId = info.androidId || info.deviceId || ''
                } catch (e) {
                    console.warn('获取设备硬件标识失败', e)
                }
                // #endif
                if (hardwareId) {
                    // 基于硬件标识 + 应用包名生成稳定ID（同一手机唯一，不同应用不同）
                    deviceId = 'app-' + this.stableHash(hardwareId + (APP_CONFIG.package_name || 'pushapp'))
                } else {
                    // 回退：随机生成（仅在没有硬件标识时使用）
                    deviceId = 'app-' + Math.random().toString(36).substring(2, 10) + Date.now().toString(36)
                }
                uni.setStorageSync('push_device_id', deviceId)
            }
            this.deviceId = deviceId
        },
        // 稳定哈希函数：将字符串转为固定长度的16进制字符串（同一输入始终产生同一输出）
        stableHash(str) {
            let h1 = 0xdeadbeef ^ 0
            let h2 = 0x41c6ce57 ^ 0
            for (let i = 0; i < str.length; i++) {
                const ch = str.charCodeAt(i)
                h1 = Math.imul(h1 ^ ch, 2654435761)
                h2 = Math.imul(h2 ^ ch, 1597334677)
            }
            h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909)
            h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909)
            const hash = 4294967296 * (2097151 & h2) + (h1 >>> 0)
            return hash.toString(16).padStart(13, '0').substring(0, 12)
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
        getNotificationSmallIcon(main) {
            // #ifdef APP-PLUS
            try {
                // 尝试获取 APP 图标
                const appInfo = main.getApplicationInfo()
                const icon = appInfo.icon
                if (icon && icon > 0) {
                    return icon
                }
            } catch (e) {
                console.warn('获取 APP 图标失败', e)
            }
            // 回退到系统图标
            // android.R.drawable.ic_dialog_info = 17301651
            // android.R.drawable.stat_sys_download = 17301633
            return 17301651
            // #endif
        },
        startForegroundService() {
            // #ifdef APP-PLUS
            let main, Context, Build, NotificationManager, Intent, PendingIntent
            try {
                main = plus.android.runtimeMainActivity()
                Context = plus.android.importClass('android.content.Context')
                Build = plus.android.importClass('android.os.Build')
                NotificationManager = plus.android.importClass('android.app.NotificationManager')
                Intent = plus.android.importClass('android.content.Intent')
                PendingIntent = plus.android.importClass('android.app.PendingIntent')
            } catch (e) {
                console.error('导入通知基础类失败', e)
                return
            }

            const channelId = 'push_service_foreground'
            const notificationId = 1001

            // 检查通知权限
            try {
                const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                if (nm.areNotificationsEnabled() === false) {
                    console.warn('通知权限未开启，无法显示通知栏')
                    this.requestNotificationPermission()
                    return
                }
            } catch (e) {
                console.warn('检查通知权限失败', e)
            }

            // 创建通知渠道
            try {
                if (Build.VERSION.SDK_INT >= 26) {
                    const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                    const channel = nm.getNotificationChannel(channelId)
                    if (channel === null || channel === undefined) {
                        const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
                        const importance = NotificationManager.IMPORTANCE_DEFAULT
                        const mChannel = new NotificationChannel(channelId, '推送服务', importance)
                        mChannel.setShowBadge(false)
                        mChannel.setSound(null, null)
                        mChannel.enableVibration(false)
                        mChannel.setDescription('推送服务运行状态，保持后台连接')
                        // VISIBILITY_PUBLIC = 1
                        mChannel.setLockscreenVisibility(1)
                        nm.createNotificationChannel(mChannel)
                        console.log('推送服务通知渠道已创建')
                    }
                }
            } catch (e) {
                console.error('创建通知渠道失败', e)
            }

            // 构建 Intent 和 PendingIntent
            let contentIntent
            try {
                const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
                launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
                contentIntent = PendingIntent.getActivity(
                    main, 0, launchIntent,
                    Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
                )
            } catch (e) {
                console.error('创建 PendingIntent 失败', e)
            }

            // 构建通知 - 使用原生 Notification.Builder
            let notification
            try {
                const smallIcon = this.getNotificationSmallIcon(main)

                // 尝试 androidx.core.app.NotificationCompat.Builder，失败则回退到 android.app.Notification.Builder
                let builder
                let useCompat = false
                try {
                    const NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
                    builder = new NotificationCompat.Builder(main, channelId)
                    useCompat = true
                    console.log('使用 NotificationCompat.Builder')
                } catch (e) {
                    console.log('NotificationCompat 不可用，回退到原生 Notification.Builder')
                    const Notification = plus.android.importClass('android.app.Notification')
                    builder = new Notification.Builder(main, channelId)
                }

                let hasAudio = this.audioEnabled && (this.serverAudioList.length > 0 || this.audioList.length > 0)
                if (hasAudio) {
                    let audioName = '未知音频'
                    let audioArtist = ''
                    if (this.currentAudioSource === 'server' && this.serverAudioList.length > 0) {
                        const audioItem = this.serverAudioList[this.currentAudioIndex]
                        audioName = audioItem ? audioItem.title : '未知音频'
                        audioArtist = audioItem ? (audioItem.artist || '') : ''
                    } else if (this.currentAudioSource === 'local' && this.audioList.length > 0) {
                        const audioItem = this.audioList[this.currentAudioIndex]
                        audioName = audioItem ? audioItem.name : '未知音频'
                    }
                    const modeText = this.playMode === 'single_loop' ? '单曲循环' : (this.playMode === 'list_loop' ? '列表循环' : '播放一次')
                    builder.setContentTitle('♪ ' + audioName)
                    builder.setContentText(audioArtist || (this.isPlaying ? modeText : '已暂停'))
                    builder.setSubText('推送服务运行中')

                    // 媒体控制按钮
                    const pkgName = main.getPackageName()
                    const prevAction = 'com.push.app.ACTION_PREV'
                    const playAction = 'com.push.app.ACTION_PLAY'
                    const nextAction = 'com.push.app.ACTION_NEXT'

                    try {
                        const prevIntent = new Intent(prevAction)
                        prevIntent.setPackage(pkgName)
                        const prevPendingIntent = PendingIntent.getBroadcast(main, 1, prevIntent, Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000)
                        const playIntent = new Intent(playAction)
                        playIntent.setPackage(pkgName)
                        const playPendingIntent = PendingIntent.getBroadcast(main, 2, playIntent, Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000)
                        const nextIntent = new Intent(nextAction)
                        nextIntent.setPackage(pkgName)
                        const nextPendingIntent = PendingIntent.getBroadcast(main, 3, nextIntent, Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000)

                        builder.addAction(17301539, '上一曲', prevPendingIntent)  // android.R.drawable.ic_media_previous
                        builder.addAction(this.isPlaying ? 17301540 : 17301541, this.isPlaying ? '暂停' : '播放', playPendingIntent)
                        builder.addAction(17301542, '下一曲', nextPendingIntent)
                    } catch (e) {
                        console.warn('添加媒体按钮失败', e)
                    }
                } else {
                    const statusText = this.connected ? '已连接' : '正在连接...'
                    builder.setContentTitle('推送服务 · ' + statusText)
                    builder.setContentText('保持后台运行，实时接收推送消息')
                }

                builder.setSmallIcon(smallIcon)
                if (contentIntent) builder.setContentIntent(contentIntent)
                builder.setOngoing(true)
                builder.setAutoCancel(false)

                // 设置优先级和可见性
                try {
                    if (useCompat) {
                        builder.setPriority(0)  // PRIORITY_DEFAULT
                        builder.setVisibility(1)  // VISIBILITY_PUBLIC
                        builder.setCategory('service')
                    } else {
                        if (Build.VERSION.SDK_INT >= 16) {
                            builder.setPriority(0)
                        }
                    }
                } catch (e) {}

                notification = builder.build()
                console.log('通知构建成功')
            } catch (e) {
                console.error('构建通知失败', e)
                // 即使通知失败，也继续执行保活逻辑
            }

            // 显示通知
            if (notification) {
                try {
                    const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                    nm.notify(notificationId, notification)
                    console.log('通知已显示，id=' + notificationId)
                } catch (e) {
                    console.error('显示通知失败', e)
                }
            }

            // 注册媒体控制广播接收器
            if (!this._mediaReceiverRegistered) {
                try {
                    const pkgName = main.getPackageName()
                    const prevAction = 'com.push.app.ACTION_PREV'
                    const playAction = 'com.push.app.ACTION_PLAY'
                    const nextAction = 'com.push.app.ACTION_NEXT'
                    const BroadcastReceiver = plus.android.importClass('android.content.BroadcastReceiver')
                    const self = this
                    this._mediaReceiver = new BroadcastReceiver({
                        onReceive: function(context, intent) {
                            const action = intent.getAction()
                            if (action === prevAction) {
                                self.playPrev()
                            } else if (action === playAction) {
                                self.togglePlay()
                            } else if (action === nextAction) {
                                self.playNext()
                            }
                        }
                    })
                    const filter = plus.android.importClass('android.content.IntentFilter')
                    const intentFilter = new filter()
                    intentFilter.addAction(prevAction)
                    intentFilter.addAction(playAction)
                    intentFilter.addAction(nextAction)
                    main.registerReceiver(this._mediaReceiver, intentFilter)
                    this._mediaReceiverRegistered = true
                } catch (e) {
                    console.warn('注册媒体广播接收器失败', e)
                }
            }

            // WakeLock
            try {
                const PowerManager = plus.android.importClass('android.os.PowerManager')
                const pm = main.getSystemService(Context.POWER_SERVICE)
                if (!this._wakeLock) {
                    this._wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:WakeLock')
                    this._wakeLock.setReferenceCounted(false)
                }
                if (!this._wakeLock.isHeld()) {
                    this._wakeLock.acquire()
                }
            } catch (e) {
                console.warn('获取 WakeLock 失败', e)
            }

            // WifiLock
            try {
                const WifiManager = plus.android.importClass('android.net.wifi.WifiManager')
                const wm = main.getApplicationContext().getSystemService(Context.WIFI_SERVICE)
                if (!this._wifiLock) {
                    this._wifiLock = wm.createWifiLock(WifiManager.WIFI_MODE_FULL_HIGH_PERF, 'PushApp:WifiLock')
                    this._wifiLock.setReferenceCounted(false)
                }
                if (!this._wifiLock.isHeld()) {
                    this._wifiLock.acquire()
                }
            } catch (e) {
                console.warn('获取 WifiLock 失败', e)
            }

            // AlarmManager 定时心跳 - 锁屏后 JS 引擎被冻结时作为备用心跳
            this.setupAlarmHeartbeat(main, Context, Build)

            // 屏幕状态监听
            if (!this._screenReceiverRegistered) {
                try {
                    const self = this
                    this._screenReceiver = new BroadcastReceiver({
                        onReceive: function(context, intent) {
                            const action = intent.getAction()
                            if (action === 'android.intent.action.SCREEN_ON') {
                                if (self.form && self.form.key) {
                                    if (!self.connected && !self.connecting) {
                                        self.cleanupAndReconnect()
                                    } else if (self.connected && self.socketTask) {
                                        self._screenPingTimer && clearTimeout(self._screenPingTimer)
                                        self._screenPongOk = false
                                        self._screenPingTimer = setTimeout(() => {
                                            if (!self._screenPongOk) {
                                                self.cleanupAndReconnect()
                                            }
                                            self._screenPingTimer = null
                                        }, 5000)
                                        try {
                                            self.socketTask.send({
                                                data: JSON.stringify({ type: 'ping' }),
                                                fail: () => {
                                                    self._screenPingTimer && clearTimeout(self._screenPingTimer)
                                                    self._screenPingTimer = null
                                                    self.cleanupAndReconnect()
                                                }
                                            })
                                        } catch (e) {
                                            self._screenPingTimer && clearTimeout(self._screenPingTimer)
                                            self._screenPingTimer = null
                                            self.cleanupAndReconnect()
                                        }
                                    }
                                }
                            }
                        }
                    })
                    const filter = plus.android.importClass('android.content.IntentFilter')
                    const screenFilter = new filter()
                    screenFilter.addAction('android.intent.action.SCREEN_ON')
                    screenFilter.addAction('android.intent.action.SCREEN_OFF')
                    main.registerReceiver(this._screenReceiver, screenFilter)
                    this._screenReceiverRegistered = true
                } catch (e) {
                    console.warn('注册屏幕广播接收器失败', e)
                }
            }

            console.log('前台服务保活已启动')
            // #endif
        },
        setupAlarmHeartbeat(main, Context, Build) {
            // #ifdef APP-PLUS
            try {
                const AlarmManager = plus.android.importClass('android.app.AlarmManager')
                const PendingIntent = plus.android.importClass('android.app.PendingIntent')
                const Intent = plus.android.importClass('android.content.Intent')
                const System = plus.android.importClass('java.lang.System')

                const alarmAction = 'com.push.app.ALARM_HEARTBEAT'
                const interval = 25 * 1000  // 25 秒
                const triggerAt = System.currentTimeMillis() + interval

                const intent = new Intent(alarmAction)
                intent.setPackage(main.getPackageName())

                const flags = Build.VERSION.SDK_INT >= 31
                    ? 0x04000000 | 0x08000000  // FLAG_UPDATE_CURRENT | FLAG_IMMUTABLE
                    : 0x04000000  // FLAG_UPDATE_CURRENT

                if (this._alarmPendingIntent) {
                    try { this._alarmPendingIntent.cancel() } catch (e) {}
                }
                this._alarmPendingIntent = PendingIntent.getBroadcast(main, 200, intent, flags)

                const am = main.getSystemService(Context.ALARM_SERVICE)

                // RTC_WAKEUP = 0, 唤醒 CPU
                if (Build.VERSION.SDK_INT >= 23) {
                    // setExactAndAllowWhileIdle: 在 Doze 模式下也能精确触发
                    try {
                        am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, this._alarmPendingIntent)
                    } catch (e) {
                        // Android 12+ 可能需要 SCHEDULE_EXACT_ALARM 权限
                        console.warn('setExactAndAllowWhileIdle 失败，回退到 setAndAllowWhileIdle', e)
                        am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, this._alarmPendingIntent)
                    }
                } else {
                    am.setExact(AlarmManager.RTC_WAKEUP, triggerAt, this._alarmPendingIntent)
                }

                // 注册闹钟广播接收器
                if (!this._alarmReceiverRegistered) {
                    const BroadcastReceiver = plus.android.importClass('android.content.BroadcastReceiver')
                    const self = this
                    this._alarmReceiver = new BroadcastReceiver({
                        onReceive: function(context, intent) {
                            const action = intent.getAction()
                            if (action === alarmAction) {
                                // 关键：唤醒后立即获取短暂 WakeLock，防止 CPU 在心跳完成前再次休眠
                                let tmpWakeLock = null
                                try {
                                    const PowerManager = plus.android.importClass('android.os.PowerManager')
                                    const pm = context.getSystemService(Context.POWER_SERVICE)
                                    tmpWakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:AlarmWake')
                                    tmpWakeLock.setReferenceCounted(false)
                                    // 10 秒后自动释放，确保心跳/重连完成
                                    tmpWakeLock.acquire(10 * 1000)
                                } catch (e) {
                                    console.warn('[Alarm] 获取临时 WakeLock 失败', e)
                                }

                                // 唤醒后立即发送心跳
                                if (self.socketTask && self.connected) {
                                    try {
                                        self.socketTask.send({
                                            data: JSON.stringify({ type: 'ping' }),
                                            fail: () => {
                                                self.connected = false
                                                self.cleanupAndReconnect()
                                            }
                                        })
                                        console.log('[Alarm] 心跳已发送')
                                    } catch (e) {
                                        self.connected = false
                                        self.cleanupAndReconnect()
                                    }
                                } else if (self.form && self.form.key) {
                                    // 连接断开了，尝试重连
                                    console.log('[Alarm] 连接已断开，触发重连')
                                    self.cleanupAndReconnect()
                                }

                                // 重新设置下一次闹钟
                                self.setupAlarmHeartbeat(main, Context, Build)

                                // 释放临时 WakeLock（保留几秒让心跳完成）
                                if (tmpWakeLock) {
                                    try {
                                        // 不立即释放，让 10 秒超时自动释放
                                        // 但如果已持有则保留引用防止被 GC
                                        self._tmpAlarmWakeLock = tmpWakeLock
                                    } catch (e) {}
                                }
                            }
                        }
                    })
                    const filter = plus.android.importClass('android.content.IntentFilter')
                    const intentFilter = new filter()
                    intentFilter.addAction(alarmAction)
                    main.registerReceiver(this._alarmReceiver, intentFilter)
                    this._alarmReceiverRegistered = true
                    console.log('AlarmManager 心跳广播接收器已注册')
                }
            } catch (e) {
                console.error('设置 AlarmManager 心跳失败', e)
            }
            // #endif
        },
        stopAlarmHeartbeat() {
            // #ifdef APP-PLUS
            try {
                const main = plus.android.runtimeMainActivity()
                const Context = plus.android.importClass('android.content.Context')

                if (this._alarmPendingIntent) {
                    const AlarmManager = plus.android.importClass('android.app.AlarmManager')
                    const am = main.getSystemService(Context.ALARM_SERVICE)
                    am.cancel(this._alarmPendingIntent)
                    this._alarmPendingIntent.cancel()
                    this._alarmPendingIntent = null
                }

                if (this._alarmReceiver && this._alarmReceiverRegistered) {
                    try {
                        main.unregisterReceiver(this._alarmReceiver)
                        this._alarmReceiver = null
                        this._alarmReceiverRegistered = false
                    } catch (e) {}
                }
            } catch (e) {
                console.warn('停止 AlarmManager 心跳失败', e)
            }
            // #endif
        },
        checkBatteryOptimization() {
            // #ifdef APP-PLUS
            try {
                const main = plus.android.runtimeMainActivity()
                const Context = plus.android.importClass('android.content.Context')
                const Build = plus.android.importClass('android.os.Build')
                const PowerManager = plus.android.importClass('android.os.PowerManager')

                if (Build.VERSION.SDK_INT < 23) return true

                const pm = main.getSystemService(Context.POWER_SERVICE)
                const isIgnoring = pm.isIgnoringBatteryOptimizations(main.getPackageName())
                if (isIgnoring) {
                    console.log('已在电池优化白名单中')
                    return true
                }

                // 引导用户关闭电池优化
                uni.showModal({
                    title: '关闭电池优化',
                    content: '为了保持后台推送连接，需要将本应用加入电池优化白名单。请在弹出的设置中选择"不优化"。',
                    confirmText: '去设置',
                    cancelText: '稍后',
                    success: (res) => {
                        if (res.confirm) {
                            try {
                                const Intent = plus.android.importClass('android.content.Intent')
                                const intent = new Intent('android.settings.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS')
                                const Uri = plus.android.importClass('android.net.Uri')
                                intent.setData(Uri.fromParts('package', main.getPackageName(), null))
                                main.startActivity(intent)
                            } catch (e) {
                                console.warn('打开电池优化设置失败', e)
                            }
                        }
                    }
                })
                return false
            } catch (e) {
                console.warn('检查电池优化失败', e)
                return true
            }
            // #endif
            return true
        },
        checkXiaomiAutoStart() {
            // #ifdef APP-PLUS
            try {
                const main = plus.android.runtimeMainActivity()
                const Build = plus.android.importClass('android.os.Build')
                const manufacturer = Build.MANUFACTURER.toLowerCase()
                if (manufacturer !== 'xiaomi') return

                const key = 'xiaomi_autostart_checked'
                const checked = uni.getStorageSync(key)
                if (checked) return

                uni.showModal({
                    title: '开启后台保活权限',
                    content: '小米手机需要开启以下权限才能在后台保持推送连接：\n\n1. 自启动权限\n2. 后台弹出通知\n3. 锁屏显示通知\n\n请在设置中找到本应用并开启以上权限。',
                    confirmText: '去设置',
                    cancelText: '稍后',
                    success: (res) => {
                        if (res.confirm) {
                            try {
                                const Intent = plus.android.importClass('android.content.Intent')
                                const intent = new Intent()
                                intent.setComponent(new plus.android.invoke('android.content.ComponentName', 'init', 'com.miui.securitycenter', 'com.miui.permcenter.autostart.AutoStartManagementActivity'))
                                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                                main.startActivity(intent)
                            } catch (e) {
                                console.warn('打开小米自启动设置失败', e)
                                // 回退到应用详情页
                                try {
                                    const Intent2 = plus.android.importClass('android.content.Intent')
                                    const Uri2 = plus.android.importClass('android.net.Uri')
                                    const intent2 = new Intent2('android.settings.APPLICATION_DETAILS_SETTINGS')
                                    intent2.setData(Uri2.fromParts('package', main.getPackageName(), null))
                                    main.startActivity(intent2)
                                } catch (e2) {}
                            }
                        }
                        uni.setStorageSync(key, true)
                    }
                })
            } catch (e) {
                console.warn('检查小米自启动失败', e)
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

                if (this._mediaReceiver && this._mediaReceiverRegistered) {
                    try {
                        main.unregisterReceiver(this._mediaReceiver)
                        this._mediaReceiver = null
                        this._mediaReceiverRegistered = false
                        console.log('媒体控制广播接收器已注销')
                    } catch (e) {
                        console.warn('注销广播接收器失败', e)
                    }
                }

                if (this._screenReceiver && this._screenReceiverRegistered) {
                    try {
                        main.unregisterReceiver(this._screenReceiver)
                        this._screenReceiver = null
                        this._screenReceiverRegistered = false
                        console.log('屏幕状态广播接收器已注销')
                    } catch (e) {
                        console.warn('注销屏幕广播接收器失败', e)
                    }
                }

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
                    channel.setDescription('推送消息通知（锁屏可见）')
                    // 锁屏完全可见
                    channel.setLockscreenVisibility(1)  // VISIBILITY_PUBLIC
                    // 绕过勿扰模式
                    try { channel.setBypassDnd(true) } catch (e) {}
                    // 灯光颜色（绿色）
                    try { channel.setLightColor(0xFF00FF00) } catch (e) {}
                    // 振动模式
                    try { channel.setVibrationPattern([0, 200, 200, 200]) } catch (e) {}
                    nm.createNotificationChannel(channel)
                    console.log('消息推送通知渠道已创建（锁屏可见）')
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
                const PendingIntent = plus.android.importClass('android.app.PendingIntent')
                const Build = plus.android.importClass('android.os.Build')
                const NotificationManager = plus.android.importClass('android.app.NotificationManager')

                const channelId = 'push_messages'
                const notificationId = Math.floor(Math.random() * 100000) + 1

                // 检查通知权限
                const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                try {
                    if (nm.areNotificationsEnabled() === false) {
                        console.warn('通知权限未开启，推送消息无法显示通知栏')
                        this.requestNotificationPermission()
                        return false
                    }
                } catch (e) {}

                // 创建通知渠道
                if (Build.VERSION.SDK_INT >= 26) {
                    const channel = nm.getNotificationChannel(channelId)
                    if (channel === null || channel === undefined) {
                        const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
                        const importance = NotificationManager.IMPORTANCE_HIGH
                        const mChannel = new NotificationChannel(channelId, '消息推送', importance)
                        mChannel.enableLights(true)
                        mChannel.enableVibration(true)
                        mChannel.setShowBadge(true)
                        mChannel.setDescription('推送消息通知（锁屏可见）')
                        // 锁屏完全可见
                        mChannel.setLockscreenVisibility(1)  // VISIBILITY_PUBLIC
                        // 绕过勿扰模式
                        try { mChannel.setBypassDnd(true) } catch (e) {}
                        // 灯光颜色（绿色）
                        try { mChannel.setLightColor(0xFF00FF00) } catch (e) {}
                        // 振动模式：震动 200ms 停 200ms
                        try {
                            const VibratorHelper = plus.android.importClass('android.os.VibrationEffect')
                            // 简单设置振动模式
                            mChannel.setVibrationPattern([0, 200, 200, 200])
                        } catch (e) {}
                        nm.createNotificationChannel(mChannel)
                        console.log('消息推送通知渠道已创建（锁屏可见）')
                    }
                }

                const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
                launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
                const contentIntent = PendingIntent.getActivity(
                    main, notificationId, launchIntent,
                    Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
                )

                // 尝试 NotificationCompat，失败回退到原生 Notification.Builder
                let builder
                let useCompat = false
                try {
                    const NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
                    builder = new NotificationCompat.Builder(main, channelId)
                    useCompat = true
                } catch (e) {
                    console.log('NotificationCompat 不可用，使用原生 Notification.Builder')
                    const Notification = plus.android.importClass('android.app.Notification')
                    builder = new Notification.Builder(main, channelId)
                }

                builder.setContentTitle(title || '新消息')
                builder.setContentText(content || '')
                builder.setSmallIcon(this.getNotificationSmallIcon(main))
                builder.setContentIntent(contentIntent)
                builder.setAutoCancel(true)
                // Ticker：状态栏首次显示时的滚动文本
                try { builder.setTicker('收到推送：' + (title || '新消息')) } catch (e) {}
                // 时间戳
                try {
                    const JavaSystem = plus.android.importClass('java.lang.System')
                    builder.setWhen(JavaSystem.currentTimeMillis())
                    try { builder.setShowWhen(true) } catch (e) {}
                } catch (e) {}

                try {
                    if (useCompat) {
                        builder.setPriority(2)  // PRIORITY_MAX
                        builder.setDefaults(-1)  // DEFAULT_ALL
                        builder.setVisibility(1)  // VISIBILITY_PUBLIC
                        // 分类为消息，锁屏界面会优先显示
                        try { builder.setCategory('msg') } catch (e) {}  // CATEGORY_MESSAGE
                        // 锁屏可见性（再次强调）
                        try { builder.setVisibility(1) } catch (e) {}
                    } else {
                        if (Build.VERSION.SDK_INT >= 16) {
                            builder.setPriority(2)
                        }
                        if (Build.VERSION.SDK_INT < 21) {
                            builder.setDefaults(-1)
                        }
                        // 原生 Builder 也设置 category
                        if (Build.VERSION.SDK_INT >= 21) {
                            try { builder.setCategory('msg') } catch (e) {}
                            try { builder.setVisibility(1) } catch (e) {}
                        }
                    }
                } catch (e) {}

                // 设置全屏 Intent（Android 10 以下可弹出到锁屏上方，Android 10+ 受限但小米可能支持）
                // USE_FULL_SCREEN_INTENT 权限已申请
                if (Build.VERSION.SDK_INT >= 28) {
                    try {
                        const fullScreenPendingIntent = PendingIntent.getActivity(
                            main, notificationId + 10000, launchIntent,
                            Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
                        )
                        builder.setFullScreenIntent(fullScreenPendingIntent, true)
                    } catch (e) {
                        console.warn('设置全屏 Intent 失败', e)
                    }
                }

                // 大文本支持
                if (content && content.length > 50) {
                    try {
                        if (useCompat) {
                            const BigTextStyle = plus.android.importClass('androidx.core.app.NotificationCompat$BigTextStyle')
                            const bigText = new BigTextStyle()
                            bigText.bigText(content)
                            bigText.setBigContentTitle(title)
                            builder.setStyle(bigText)
                        } else {
                            const BigTextStyle = plus.android.importClass('android.app.Notification$BigTextStyle')
                            const bigText = new BigTextStyle()
                            bigText.bigText(content)
                            bigText.setBigContentTitle(title)
                            builder.setStyle(bigText)
                        }
                    } catch (e) {
                        console.warn('BigTextStyle 不支持', e)
                    }
                }

                const notification = builder.build()
                nm.notify(notificationId, notification)
                console.log('推送消息通知已显示，id=' + notificationId)

                return true
            } catch (e) {
                console.error('显示通知失败', e)
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
        requestNotificationPermission() {
            // #ifdef APP-PLUS
            try {
                const main = plus.android.runtimeMainActivity()
                const Context = plus.android.importClass('android.content.Context')
                const Build = plus.android.importClass('android.os.Build')
                const NotificationManager = plus.android.importClass('android.app.NotificationManager')

                const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                const areNotificationsEnabled = nm.areNotificationsEnabled()
                if (areNotificationsEnabled) {
                    console.log('通知权限已开启')
                    return true
                }

                if (Build.VERSION.SDK_INT >= 33) {
                    const Manifest = plus.android.importClass('android.Manifest')
                    const PermissionCompat = plus.android.importClass('androidx.core.content.ContextCompat')
                    const ActivityCompat = plus.android.importClass('androidx.core.app.ActivityCompat')
                    const hasPermission = PermissionCompat.checkSelfPermission(main, Manifest.permission.POST_NOTIFICATIONS)
                    const PackageManager = plus.android.importClass('android.content.pm.PackageManager')
                    if (hasPermission !== PackageManager.PERMISSION_GRANTED) {
                        ActivityCompat.requestPermissions(main, [Manifest.permission.POST_NOTIFICATIONS], 1001)
                        console.log('请求通知权限（Android 13+）')
                        return false
                    }
                }

                console.log('通知权限未开启，引导用户去设置')
                uni.showModal({
                    title: '开启通知权限',
                    content: '为了让您及时收到推送消息，请在设置中开启通知权限',
                    confirmText: '去设置',
                    cancelText: '稍后再说',
                    success: (res) => {
                        if (res.confirm) {
                            this.openNotificationSettings()
                        }
                    }
                })
                return false
            } catch (e) {
                console.warn('请求通知权限失败', e)
                return false
            }
            // #endif
        },
        isNotificationEnabled() {
            // #ifdef APP-PLUS
            try {
                const main = plus.android.runtimeMainActivity()
                const Context = plus.android.importClass('android.content.Context')
                const NotificationManager = plus.android.importClass('android.app.NotificationManager')
                const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                return nm.areNotificationsEnabled()
            } catch (e) {
                console.warn('检查通知权限失败', e)
                return true
            }
            // #endif
            return true
        },
        openNotificationSettings() {
            // #ifdef APP-PLUS
            try {
                const main = plus.android.runtimeMainActivity()
                const Intent = plus.android.importClass('android.content.Intent')
                const Uri = plus.android.importClass('android.net.Uri')
                const Build = plus.android.importClass('android.os.Build')

                const intent = new Intent()
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

                if (Build.VERSION.SDK_INT >= 26) {
                    intent.setAction('android.settings.APP_NOTIFICATION_SETTINGS')
                    intent.putExtra('android.provider.extra.APP_PACKAGE', main.getPackageName())
                } else if (Build.VERSION.SDK_INT >= 21) {
                    intent.setAction('android.settings.APP_NOTIFICATION_SETTINGS')
                    intent.putExtra('app_package', main.getPackageName())
                    intent.putExtra('app_uid', main.getApplicationInfo().uid)
                } else {
                    intent.setAction('android.settings.APPLICATION_DETAILS_SETTINGS')
                    intent.setData(Uri.fromParts('package', main.getPackageName(), null))
                }

                main.startActivity(intent)
            } catch (e) {
                console.warn('打开通知设置失败', e)
            }
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
        // ============== 用户中心 ==============
        loadDeviceInfo() {
            try {
                // #ifdef APP-PLUS
                const info = uni.getSystemInfoSync()
                this.deviceModel = info.model || ''
                this.deviceBrand = info.brand || ''
                this.osVersion = (info.system || '') + (info.platform ? ' (' + info.platform + ')' : '')
                // #endif
            } catch (e) {
                console.warn('获取设备信息失败', e)
            }
        },
        checkUpdate() {
            uni.showModal({
                title: '检查更新',
                content: '当前版本：' + this.versionName + '\n\n暂无可用的更新，请保持关注。',
                showCancel: false,
                confirmText: '知道了'
            })
        },
        clearCache() {
            uni.showModal({
                title: '清除缓存',
                content: '将清除本地消息记录和音频缓存（不影响推送 Key 和服务器配置）',
                success: (res) => {
                    if (res.confirm) {
                        uni.removeStorageSync('push_messages')
                        uni.removeStorageSync('push_today_count')
                        uni.removeStorageSync('push_total_count')
                        this.messages = []
                        this.todayCount = 0
                        uni.showToast({ title: '缓存已清除', icon: 'success' })
                    }
                }
            })
        },
        copyDeviceInfo() {
            const info = [
                '设备ID: ' + this.deviceId,
                '推送Key: ' + this.form.key,
                '服务器: ' + this.form.serverUrl,
                'WebSocket: ' + (this.wsUrl || this.form.wsUrl || ''),
                '应用版本: ' + this.versionName,
                '设备型号: ' + this.deviceModel,
                '设备品牌: ' + this.deviceBrand,
                '系统版本: ' + this.osVersion,
                '连接状态: ' + (this.connected ? '在线' : '离线'),
                '网络延迟: ' + (this._lastRtt ? this._lastRtt + 'ms' : '--')
            ].join('\n')
            uni.setClipboardData({
                data: info,
                success: () => {
                    uni.showToast({ title: '设备信息已复制', icon: 'success' })
                }
            })
        },
        // 跳转到设置中的「设备 ID 绑定」区域
        openBindDeviceId() {
            this.bindDeviceIdInput = this.deviceId || ''
            this.showSettings = true
            // 延迟一点滚动到对应区域（设置弹窗打开动画完成后）
            setTimeout(() => {
                uni.pageScrollTo({
                    selector: '.setting-item-column',
                    scrollTop: 400,
                    duration: 300
                })
            }, 300)
        },
        // 从剪贴板粘贴设备 ID 到输入框
        pasteDeviceIdFromClipboard() {
            uni.getClipboardData({
                success: (res) => {
                    let text = (res.data || '').trim()
                    // 兼容：剪贴板是"设备ID: app-xxx"格式时自动提取 ID
                    const match = text.match(/(app-[a-z0-9]+|[A-Z]{2,}[a-zA-Z0-9_-]{6,})/i)
                    if (match) {
                        text = match[1]
                    }
                    this.bindDeviceIdInput = text
                    if (text) {
                        uni.showToast({ title: '已粘贴', icon: 'success' })
                    } else {
                        uni.showToast({ title: '剪贴板为空', icon: 'none' })
                    }
                },
                fail: () => {
                    uni.showToast({ title: '读取剪贴板失败', icon: 'none' })
                }
            })
        },
        // 应用绑定的设备 ID 并重连
        applyBoundDeviceId() {
            const newDeviceId = (this.bindDeviceIdInput || '').trim()
            if (!newDeviceId) {
                uni.showToast({ title: '请输入设备 ID', icon: 'none' })
                return
            }
            if (newDeviceId === this.deviceId) {
                uni.showToast({ title: '与当前设备 ID 相同', icon: 'none' })
                return
            }
            // 基本校验：长度和字符（允许：字母数字下划线短横线）
            if (!/^[A-Za-z0-9_-]{4,64}$/.test(newDeviceId)) {
                uni.showModal({
                    title: '设备 ID 格式异常',
                    content: '您输入的设备 ID 格式可能不正确（长度 4-64，仅字母数字下划线短横线）。\n\n确认继续使用？',
                    success: (r) => {
                        if (r.confirm) {
                            this._confirmBindDeviceId(newDeviceId)
                        }
                    }
                })
                return
            }
            this._confirmBindDeviceId(newDeviceId)
        },
        _confirmBindDeviceId(newDeviceId) {
            uni.showModal({
                title: '绑定设备 ID',
                content:
                    '绑定后将：\n' +
                    '1. 断开当前 WebSocket 连接\n' +
                    '2. 使用新设备 ID 「' + newDeviceId + '」重新连接\n' +
                    '3. 同步该设备的历史推送消息\n' +
                    '4. 后台推送消息将发送到该设备 ID\n\n' +
                    '请确认后台「设备管理」中存在此设备 ID。',
                confirmText: '确认绑定',
                success: (r) => {
                    if (!r.confirm) return
                    // 保存到本地存储
                    uni.setStorageSync('push_device_id', newDeviceId)
                    this.deviceId = newDeviceId
                    // 先关闭当前连接
                    this.closeSocket()
                    uni.showToast({ title: '已绑定，正在同步消息...', icon: 'none' })
                    // 同步该设备的历史消息
                    this.syncDeviceHistoryMessages(newDeviceId)
                    // 稍等片刻后重连（让 closeSocket 完成）
                    setTimeout(() => {
                        this.connectWebSocket()
                    }, 800)
                }
            })
        },
        // 同步设备历史消息（绑定新设备 ID 后调用）
        // 从后端拉取该设备的历史推送消息，合并到本地消息列表
        syncDeviceHistoryMessages(deviceId) {
            const pushKey = this.form.key || uni.getStorageSync('push_key') || ''
            if (!pushKey || !deviceId) {
                console.warn('[Sync] push_key 或 device_id 为空，跳过同步')
                return
            }
            const serverUrl = (this.form.serverUrl || APP_CONFIG.server_url || '').replace(/\/+$/, '')
            const url = serverUrl + '/api/device/messages?push_key='
                + encodeURIComponent(pushKey)
                + '&device_id=' + encodeURIComponent(deviceId)
                + '&limit=50'

            console.log('[Sync] 开始同步设备历史消息:', deviceId)
            uni.request({
                url: url,
                method: 'GET',
                timeout: 10000,
                success: (res) => {
                    if (res.statusCode !== 200 || !res.data || res.data.code !== 0) {
                        console.warn('[Sync] 同步失败:', res.data?.message || res.statusCode)
                        uni.showToast({ title: '历史消息同步失败', icon: 'none' })
                        return
                    }
                    const data = res.data.data || {}
                    const list = data.list || []
                    if (list.length === 0) {
                        console.log('[Sync] 该设备暂无历史消息')
                        uni.showToast({ title: '已绑定，正在重连...', icon: 'none' })
                        return
                    }
                    // 将后端历史消息合并到本地消息列表
                    // 后端返回按 id DESC，本地 messages 也按时间倒序，直接合并去重
                    const existingIds = new Set(this.messages.map(m => m.message_id || m.id))
                    const newMsgs = []
                    list.forEach((item) => {
                        const msgId = item.message_id || ('db_' + item.id)
                        if (existingIds.has(msgId)) return
                        newMsgs.push({
                            id: msgId,
                            message_id: item.message_id,
                            title: item.title || '消息推送',
                            content: item.content || '',
                            time: new Date(item.created_at.replace(/-/g, '/')).getTime() || Date.now(),
                            is_synced: true
                        })
                    })
                    if (newMsgs.length > 0) {
                        // 合并并按时间倒序排序
                        this.messages = [...this.messages, ...newMsgs].sort((a, b) => (b.time || 0) - (a.time || 0))
                        // 限制最多 100 条
                        if (this.messages.length > 100) {
                            this.messages = this.messages.slice(0, 100)
                        }
                        this.saveMessages()
                        this.updateStats()
                        console.log('[Sync] 同步完成，新增', newMsgs.length, '条历史消息')
                        uni.showToast({
                            title: '已同步 ' + newMsgs.length + ' 条历史消息',
                            icon: 'none'
                        })
                    } else {
                        console.log('[Sync] 历史消息已全部存在，无需合并')
                        uni.showToast({ title: '已绑定，正在重连...', icon: 'none' })
                    }
                },
                fail: (err) => {
                    console.error('[Sync] 同步请求失败', err)
                    uni.showToast({ title: '历史消息同步失败', icon: 'none' })
                }
            })
        },
        // 恢复为自动生成的设备 ID（基于硬件标识生成稳定ID）
        resetDeviceIdAuto() {
            uni.showModal({
                title: '恢复自动生成',
                content: '将基于本机硬件标识重新生成稳定的设备 ID（同一手机始终生成相同 ID），并使用它重新连接。当前绑定的设备 ID 将被覆盖。',
                confirmText: '恢复',
                success: (r) => {
                    if (!r.confirm) return
                    // 清除本地存储，触发 initDeviceId 重新生成
                    uni.removeStorageSync('push_device_id')
                    this.initDeviceId()
                    this.bindDeviceIdInput = this.deviceId
                    this.closeSocket()
                    uni.showToast({ title: '已恢复，正在重连...', icon: 'none' })
                    setTimeout(() => {
                        this.connectWebSocket()
                    }, 800)
                }
            })
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

            // 收集设备信息上报到服务端（用于设备管理列表展示）
            let platform = ''
            let appVersion = ''
            let deviceModel = ''
            let osVersion = ''
            // #ifdef APP-PLUS
            try {
                const info = uni.getSystemInfoSync()
                platform = (info.platform || '').toLowerCase()  // android/ios
                appVersion = info.appVersion || info.appWgtVersion || ''
                deviceModel = info.model || ''
                osVersion = (info.system || '') + (info.platform ? ' (' + info.platform + ')' : '')
            } catch (e) {
                console.warn('获取设备信息失败', e)
            }
            // #endif

            // 构造 WebSocket URL，附加设备信息参数（服务端会写入 devices 表）
            let url = this.wsUrl + '/ws/client?key=' + encodeURIComponent(this.form.key)
                + '&device_id=' + encodeURIComponent(this.deviceId)
            if (deviceModel) {
                url += '&model=' + encodeURIComponent(deviceModel)
            }
            if (osVersion) {
                url += '&os_version=' + encodeURIComponent(osVersion)
            }
            if (platform) {
                url += '&platform=' + encodeURIComponent(platform)
            }
            if (appVersion) {
                url += '&app_version=' + encodeURIComponent(appVersion)
            }

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
                            // 标记鉴权失败原因，供 onClose 判断是否清除 Key
                            this._authFailReason = data.message || '鉴权失败'
                        }
                        return
                    }

                    // 2. 心跳 ping/pong
                    if (data.type === 'ping') {
                        // 回复服务端 ping，携带时间戳和设备状态
                        const pongData = {
                            type: 'pong',
                            timestamp: Date.now(),
                            server_ping_time: data.time || 0,
                            device_state: {
                                connected: this.connected,
                                is_playing: this.isPlaying,
                                audio_enabled: this.audioEnabled,
                                tab: this.currentTab
                            }
                        }
                        this.socketTask.send({ data: JSON.stringify(pongData) })
                        return
                    }
                    if (data.type === 'pong') {
                        // 收到服务端 pong 响应，计算网络延迟并记录
                        const serverTime = data.server_time || data.data?.server_time || 0
                        const now = Date.now()
                        // 如果有记录的 ping 发送时间，计算 RTT（往返时延）
                        if (this._lastPingSentTime) {
                            const rtt = now - this._lastPingSentTime
                            this._lastRtt = rtt
                            // 每隔几次打印一次延迟日志，避免刷屏
                            if (!this._pongLogCounter) this._pongLogCounter = 0
                            this._pongLogCounter++
                            if (this._pongLogCounter >= 3) {
                                console.log('[WS] 收到 pong, RTT=' + rtt + 'ms, server_time=' + serverTime)
                                this._pongLogCounter = 0
                            }
                            this._lastPingSentTime = null
                        }
                        // 记录服务器时间偏移（秒级），可用于时间显示校准
                        if (serverTime) {
                            this._serverTimeOffset = (serverTime * 1000) - now
                        }
                        // onShow 验证 ping 成功
                        if (this._onShowPingTimer) {
                            this._onShowPongOk = true
                            clearTimeout(this._onShowPingTimer)
                            this._onShowPingTimer = null
                            console.log('onShow 验证 ping 成功，连接存活')
                        }
                        // 屏幕亮屏验证 ping 成功
                        if (this._screenPingTimer) {
                            this._screenPongOk = true
                            clearTimeout(this._screenPingTimer)
                            this._screenPingTimer = null
                            console.log('[屏幕] 亮屏 ping 验证成功，连接存活')
                        }
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
                // 记录掉线时间
                this.lastDisconnectTime = new Date()
                this.lastDisconnectTimeStr = this.formatDateTime(this.lastDisconnectTime)
                const code = res.code
                const reason = res.reason || ''
                const authFailReason = this._authFailReason
                this._authFailReason = null

                // 仅当服务端明确返回"Key 无效/已禁用"时才清除 Key 并跳转
                // 其他 4001 场景（鉴权超时、连接被服务端重启断开等）应允许重连
                if (authFailReason && authFailReason.indexOf('推送 Key 无效或已禁用') !== -1) {
                    console.warn('推送 Key 无效或已禁用，停止重连并清除本地 Key')
                    this.showDisconnectBanner = false
                    uni.showToast({ title: '推送 Key 无效或已禁用', icon: 'none' })
                    this.stopForegroundService()
                    uni.removeStorageSync('push_key')
                    uni.removeStorageSync('push_server')
                    uni.removeStorageSync('push_ws')
                    setTimeout(() => {
                        uni.redirectTo({ url: '/pages/index/index' })
                    }, 1500)
                    return
                }
                if (code === 4003 || reason === 'blacklisted' || (authFailReason && authFailReason.indexOf('拉黑') !== -1)) {
                    console.warn('设备已被拉黑，停止重连')
                    this.showDisconnectBanner = false
                    uni.showToast({ title: '设备已被拉黑', icon: 'none' })
                    this.stopForegroundService()
                    return
                }
                // 设备数量超限：提示用户但不清除 Key（用户可去管理后台删除多余设备）
                if (authFailReason && authFailReason.indexOf('设备数量已达上限') !== -1) {
                    console.warn('设备数量已达上限，停止重连')
                    this.showDisconnectBanner = false
                    uni.showToast({ title: authFailReason, icon: 'none', duration: 3000 })
                    this.stopForegroundService()
                    return
                }
                // 显示掉线提醒条
                this.showDisconnectBanner = true
                this.reconnectingTip = '连接已断开，正在重连...'
                // 掉线 toast 提醒（仅在非主动关闭情况下提示）
                uni.showToast({
                    title: '连接已断开 ' + this.lastDisconnectTimeStr,
                    icon: 'none',
                    duration: 2500
                })
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
            // 如果之前有掉线，显示恢复连接提示
            if (this.showDisconnectBanner) {
                const offlineDuration = this.lastDisconnectTime
                    ? Math.round((Date.now() - this.lastDisconnectTime.getTime()) / 1000)
                    : 0
                this.showDisconnectBanner = false
                this.reconnectingTip = ''
                uni.showToast({
                    title: '已恢复连接（离线 ' + offlineDuration + ' 秒）',
                    icon: 'success',
                    duration: 2500
                })
            }
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
            // 鉴权成功后自动拉取历史消息，确保离线期间的消息不丢失
            // 延迟 500ms 执行，避免与 WS 离线消息回放同时进行造成 UI 抖动
            setTimeout(() => {
                this.refreshData(true).then((newCount) => {
                    if (newCount > 0) {
                        console.log('[Refresh] 鉴权后同步到 ' + newCount + ' 条历史消息')
                    }
                }).catch((e) => {
                    console.warn('[Refresh] 鉴权后同步失败', e)
                })
            }, 500)
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
            // 心跳间隔缩短到 10 秒，锁屏后 JS 被冻结前多发几次心跳
            this.heartbeatTimer = setInterval(() => {
                if (!this.socketTask || !this.connected) {
                    this.stopHeartbeat()
                    return
                }
                try {
                    // 记录 ping 发送时间，用于收到 pong 时计算 RTT
                    this._lastPingSentTime = Date.now()
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
            }, 10000)
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
                console.warn('心跳超时（20秒未收到任何消息），主动断开重连')
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
            }, 20000)
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
            // 更新掉线提醒条的重连提示
            this.reconnectingTip = '第 ' + this._reconnectCount + ' 次重连，' + (delay / 1000) + '秒后重试（掉线于 ' + this.lastDisconnectTimeStr + '）'
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
        // 复制单条推送消息（标题 + 内容）
        copyMessage(msg) {
            const text = (msg.title || '消息推送') + '\n' + (msg.content || '')
            uni.setClipboardData({
                data: text,
                success: () => {
                    uni.showToast({ title: '已复制消息', icon: 'success', duration: 1500 })
                },
                fail: () => {
                    uni.showToast({ title: '复制失败', icon: 'none', duration: 1500 })
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
        formatDateTime(date) {
            if (!date) return ''
            const d = date instanceof Date ? date : new Date(date)
            const year = d.getFullYear()
            const month = this.padZero(d.getMonth() + 1)
            const day = this.padZero(d.getDate())
            const hour = this.padZero(d.getHours())
            const minute = this.padZero(d.getMinutes())
            const second = this.padZero(d.getSeconds())
            return year + '-' + month + '-' + day + ' ' + hour + ':' + minute + ':' + second
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

/* ============== 掉线提醒条 ============== */
.disconnect-banner {
    background: #fff7e6;
    border-bottom: 1px solid #ffd591;
    padding: 10px 16px;
}

.disconnect-banner-content {
    display: flex;
    align-items: center;
    gap: 10px;
}

.disconnect-icon {
    font-size: 18px;
    flex-shrink: 0;
}

.disconnect-text-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    overflow: hidden;
}

.disconnect-title {
    font-size: 13px;
    color: #d46b08;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.disconnect-time {
    font-size: 11px;
    color: #fa8c16;
}

.disconnect-retry-btn {
    flex-shrink: 0;
    font-size: 12px;
    color: #fff;
    background: #fa8c16;
    padding: 5px 12px;
    border-radius: 4px;
}

.disconnect-retry-btn:active {
    background: #d46b08;
}

.header-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

.setting-icon {
    font-size: 20px;
    padding: 4px;
}

.refresh-icon {
    font-size: 20px;
    padding: 4px;
}

.refresh-icon.refreshing {
    animation: spin 1s linear infinite;
    display: inline-block;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.section-header-right {
    display: flex;
    align-items: center;
    gap: 8px;
}

.last-refresh {
    font-size: 11px;
    color: #999;
}

.refresh-btn {
    font-size: 13px;
    color: #667eea;
    padding: 4px 8px;
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
    flex: 1;
}

.message-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.message-copy-btn {
    font-size: 12px;
    color: #667eea;
    padding: 2px 10px;
    border: 1px solid #667eea;
    border-radius: 10px;
    flex-shrink: 0;
}

.message-copy-btn:active {
    background: #667eea;
    color: #fff;
}

.message-content {
    font-size: 14px;
    color: #666;
    line-height: 1.6;
    display: block;
    margin-bottom: 12px;
}

.message-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.message-time {
    font-size: 12px;
    color: #999;
}

.message-synced-tag {
    font-size: 10px;
    color: #00C896;
    background: rgba(0, 200, 150, 0.1);
    padding: 1px 6px;
    border-radius: 8px;
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

.audio-tab-bar {
    display: flex;
    background: #f0f2f5;
    border-radius: 8px;
    padding: 4px;
    margin-bottom: 12px;
}

.audio-tab-item {
    flex: 1;
    text-align: center;
    padding: 8px 0;
    font-size: 13px;
    color: #666;
    border-radius: 6px;
    transition: all 0.2s;
}

.audio-tab-item.active {
    background: white;
    color: #667eea;
    font-weight: 500;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
}

.audio-list {
    max-height: 400px;
}

.audio-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    margin-right: 8px;
    overflow: hidden;
}

.audio-artist {
    font-size: 12px;
    color: #999;
    margin-top: 2px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
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

/* ============== 用户中心 ============== */
.profile-page {
    padding-bottom: 80px;
}

.profile-header-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 24px 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.profile-avatar {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    flex-shrink: 0;
    border: 2px solid rgba(255, 255, 255, 0.3);
}

.avatar-img {
    width: 100%;
    height: 100%;
}

.avatar-text {
    font-size: 28px;
    font-weight: bold;
    color: white;
}

.profile-user-info {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.profile-username {
    font-size: 18px;
    font-weight: 600;
}

.profile-device-id {
    font-size: 12px;
    opacity: 0.85;
}

.profile-status-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 4px;
}

.profile-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.profile-status-dot.online {
    background: #52c41a;
    box-shadow: 0 0 6px rgba(82, 196, 26, 0.6);
}

.profile-status-dot.offline {
    background: #ff4d4f;
}

.profile-status-text {
    font-size: 12px;
}

.profile-status-text.online {
    color: #b7eb8f;
}

.profile-status-text.offline {
    color: #ffccc7;
}

.profile-stats-row {
    display: flex;
    align-items: center;
    background: white;
    padding: 16px 0;
    margin-bottom: 10px;
}

.profile-stat-item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
}

.profile-stat-value {
    font-size: 22px;
    font-weight: 600;
    color: #333;
}

.profile-stat-label {
    font-size: 12px;
    color: #999;
}

.profile-stat-divider {
    width: 1px;
    height: 30px;
    background: #eee;
}

.profile-refresh-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 20px 4px;
}

.profile-last-refresh {
    font-size: 11px;
    color: #999;
}

.profile-refresh-btn {
    font-size: 12px;
    color: #667eea;
    background: #fff;
    border: 1px solid #667eea;
    padding: 4px 12px;
    border-radius: 12px;
}

.profile-refresh-btn:active {
    background: #667eea;
    color: #fff;
}

.profile-section {
    background: white;
    margin: 0 12px 10px;
    border-radius: 8px;
    overflow: hidden;
}

.profile-section-title {
    font-size: 13px;
    color: #999;
    padding: 12px 16px 6px;
}

.profile-cell {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    border-top: 1px solid #f5f5f5;
}

.profile-cell-tap {
    transition: background 0.2s;
}

.profile-cell-tap:active {
    background: #f5f5f5;
}

.profile-cell-label {
    font-size: 14px;
    color: #333;
}

.profile-cell-value {
    font-size: 13px;
    color: #999;
    max-width: 200px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.profile-cell-arrow {
    font-size: 18px;
    color: #ccc;
}

.text-success {
    color: #52c41a !important;
}

.text-danger {
    color: #ff4d4f !important;
}

.profile-logout-wrap {
    padding: 20px 16px;
}

.profile-logout-btn {
    background: white;
    color: #ff4d4f;
    border: 1px solid #ffccc7;
    border-radius: 8px;
    font-size: 15px;
    height: 44px;
    line-height: 44px;
}

.profile-logout-btn:active {
    background: #fff1f0;
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
    margin-right: 6px;
}

.btn-sm.btn-secondary {
    background: #f0f2f7;
    color: #555;
    border: 1px solid #e4e7ed;
}

.btn-sm.btn-reset {
    background: #fff5f5;
    color: #ff4d4f;
    border: 1px solid #ffccc7;
}

.setting-btn-row {
    display: flex;
    flex-wrap: wrap;
    margin-bottom: 6px;
}

.setting-tip {
    font-size: 12px;
    color: #999;
    line-height: 1.4;
    margin-bottom: 10px;
}

.profile-cell-value-sub {
    font-size: 12px;
    color: #999;
    flex-shrink: 0;
    max-width: 40%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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
