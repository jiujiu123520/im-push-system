<template>
    <view class="glass-bg">
        <view class="top-bar">
            <view class="row" style="margin-top:60rpx;">
                <text class="icon-btn" @click="goBack" style="font-size:36rpx;width:72rpx;height:72rpx;">‹</text>
                <text class="top-bar-title" style="margin-left:20rpx;">设置</text>
            </view>
        </view>

        <view class="section-title">权限引导</view>
        <view class="glass-card" style="padding:16rpx 30rpx;">
            <view class="row-between" style="padding:20rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>
                    <view style="font-size:28rpx;font-weight:600;">📱 设备识别</view>
                    <view class="text-muted" style="font-size:22rpx;">{{ deviceInfo.brand || '未知' }} · {{ deviceInfo.model || '—' }} · Android {{ deviceInfo.os || '—' }}</view>
                </view>
            </view>
            <view class="row-between" style="padding:24rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>
                    <view>🔔 通知权限</view>
                    <view class="text-muted" style="font-size:22rpx;">允许接收推送消息</view>
                </view>
                <view class="row">
                    <text :class="['status-chip', notifyOk ? 'status-ok' : 'status-bad']">{{ notifyOk ? '已开启' : '未开启' }}</text>
                    <text class="text-muted" style="margin-left:16rpx;font-size:26rpx;" @click="openNotify">›</text>
                </view>
            </view>
            <view class="row-between" style="padding:24rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>
                    <view>🔋 电池优化白名单</view>
                    <view class="text-muted" style="font-size:22rpx;">防止系统杀后台进程</view>
                </view>
                <view class="row">
                    <text :class="['status-chip', batteryOk ? 'status-ok' : 'status-bad']">{{ batteryOk ? '已加入' : '未加入' }}</text>
                    <text class="text-muted" style="margin-left:16rpx;font-size:26rpx;" @click="openBattery">›</text>
                </view>
            </view>
            <view class="row-between" style="padding:24rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>
                    <view>🛡️ 自启动</view>
                    <view class="text-muted" style="font-size:22rpx;">允许 APP 开机自启</view>
                </view>
                <text class="text-muted" style="font-size:26rpx;" @click="openAutoStart">›</text>
            </view>
            <view class="row-between" style="padding:24rpx 0;">
                <view>
                    <view>🔒 后台弹窗</view>
                    <view class="text-muted" style="font-size:22rpx;">允许后台通知弹窗</view>
                </view>
                <text class="text-muted" style="font-size:26rpx;" @click="openBrandPerm">›</text>
            </view>
        </view>

        <view class="section-title">通用</view>
        <view class="glass-card" style="padding:16rpx 30rpx;">
            <view class="row-between" style="padding:20rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>🎨 主题</view>
                <view class="text-secondary" style="font-size:26rpx;">深色（默认）</view>
            </view>
            <view class="row-between" style="padding:24rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>📳 震动反馈</view>
                <switch :checked="vibrateOn" color="#6366f1" @change="toggleVibrate" />
            </view>
            <view class="row-between" style="padding:24rpx 0;">
                <view>🎵 通知铃声</view>
                <view class="text-secondary" style="font-size:24rpx;">默认</view>
            </view>
        </view>

        <view class="section-title">网络</view>
        <view class="glass-card" style="padding:16rpx 30rpx;">
            <view class="row-between" style="padding:24rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>📶 仅 Wi-Fi 连接</view>
                <switch :checked="wifiOnly" color="#6366f1" @change="toggleWifiOnly" />
            </view>
            <view class="row-between" style="padding:24rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>🔄 自动重连</view>
                <switch :checked="autoReconnect" color="#6366f1" @change="toggleReconnect" />
            </view>
            <view class="row-between" style="padding:24rpx 0;">
                <view>⚡ 心跳间隔</view>
                <view class="row" style="gap:12rpx;">
                    <text v-for="v in [15,30,60]" :key="v" :class="['status-chip', heartbeat===v?'status-ok':'']" @click="setHeartbeat(v)">{{ v }}s</text>
                </view>
            </view>
        </view>

        <view class="section-title">存储</view>
        <view class="glass-card" style="padding:16rpx 30rpx;">
            <view class="row-between" style="padding:20rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>💾 本地消息</view>
                <view class="text-secondary" style="font-size:24rpx;">{{ messagesCount }} 条</view>
            </view>
            <view class="row-between" style="padding:24rpx 0;">
                <view>🗑 清除缓存</view>
                <text class="text-muted" style="font-size:26rpx;" @click="clearCache">›</text>
            </view>
        </view>

        <view class="section-title">关于</view>
        <view class="glass-card" style="padding:16rpx 30rpx;">
            <view class="row-between" style="padding:20rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>ℹ️ 版本信息</view>
                <view class="text-secondary" style="font-size:24rpx;">v{{ versionName }}</view>
            </view>
            <view class="row-between" style="padding:20rpx 0;">
                <view>🏗 构建时间</view>
                <view class="text-secondary" style="font-size:22rpx;">{{ buildTime }}</view>
            </view>
        </view>

        <view class="section-title">危险</view>
        <view class="glass-card">
            <button class="btn-ghost" style="color:#ef4444;border-color:rgba(239,68,68,0.4);" @click="logout">🚪 退出登录</button>
        </view>
    </view>
</template>

<script>
import { loadBootConfig, PUSH_VIBRATE, PUSH_WIFI_ONLY, PUSH_AUTO_RECONNECT, PUSH_HEARTBEAT, getMessages, clearMessages, PUSH_KEY, PUSH_USER_ID, PUSH_USER_TOKEN } from '../../js/storage.js'
import { disconnect } from '../../js/ws.js'
import { getDeviceInfo, checkNotificationPerm, checkBatteryOpt, openNotificationSetting, openBatteryOpt, openBrandSetting } from '../../js/permissions.js'

export default {
    data() {
        return {
            deviceInfo: { brand: '', model: '', os: '' },
            notifyOk: true,
            batteryOk: true,
            vibrateOn: true,
            wifiOnly: false,
            autoReconnect: true,
            heartbeat: 30,
            messagesCount: 0,
            versionName: '1.0.0',
            buildTime: ''
        }
    },
    onShow: function() {
        var cfg = loadBootConfig()
        this.deviceInfo = getDeviceInfo()
        this.notifyOk = checkNotificationPerm()
        this.batteryOk = checkBatteryOpt()
        this.vibrateOn = uni.getStorageSync(PUSH_VIBRATE) !== false
        this.wifiOnly = uni.getStorageSync(PUSH_WIFI_ONLY) === true
        this.autoReconnect = uni.getStorageSync(PUSH_AUTO_RECONNECT) !== false
        this.heartbeat = parseInt(uni.getStorageSync(PUSH_HEARTBEAT)) || 30
        this.messagesCount = getMessages().length
        this.versionName = cfg.version_name || '1.0.0'
        this.buildTime = cfg.build_time || '—'
    },
    methods: {
        goBack: function() { uni.navigateBack({ delta: 1 }) },
        openNotify: function() { openNotificationSetting() },
        openBattery: function() { openBatteryOpt() },
        openAutoStart: function() { openBrandSetting('autoStart') },
        openBrandPerm: function() { openBrandSetting('permissionCenter') },
        toggleVibrate: function(e) {
            this.vibrateOn = e.detail.value
            uni.setStorageSync(PUSH_VIBRATE, this.vibrateOn)
        },
        toggleWifiOnly: function(e) {
            this.wifiOnly = e.detail.value
            uni.setStorageSync(PUSH_WIFI_ONLY, this.wifiOnly)
        },
        toggleReconnect: function(e) {
            this.autoReconnect = e.detail.value
            uni.setStorageSync(PUSH_AUTO_RECONNECT, this.autoReconnect)
        },
        setHeartbeat: function(v) {
            this.heartbeat = v
            uni.setStorageSync(PUSH_HEARTBEAT, v)
        },
        clearCache: function() {
            var self = this
            uni.showModal({
                title: '清除缓存',
                content: '清除所有本地消息记录？',
                success: function(r) {
                    if (r.confirm) {
                        clearMessages()
                        self.messagesCount = 0
                        uni.showToast({ title: '已清除', icon: 'success' })
                    }
                }
            })
        },
        logout: function() {
            uni.showModal({
                title: '退出登录',
                content: '断开 WebSocket 连接并清除登录信息？',
                success: function(r) {
                    if (r.confirm) {
                        disconnect()
                        uni.removeStorageSync(PUSH_KEY)
                        uni.removeStorageSync(PUSH_USER_ID)
                        uni.removeStorageSync(PUSH_USER_TOKEN)
                        uni.navigateTo({ url: '/pages/login/index', success: function() { uni.switchTab({ url: '/pages/home/index' }) } })
                    }
                }
            })
        }
    }
}
</script>
