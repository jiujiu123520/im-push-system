<template>
    <view :class="['glass-bg', themeClass]">
        <view class="top-bar">
            <view class="row" >
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
                <view class="theme-swatch-row">
                    <view :class="['theme-swatch', theme==='light'?'active':'']" @click="setTheme('light')">
                        <view class="theme-swatch-swatch" style="background:linear-gradient(135deg,#f0f2fa,#dce0ee);"></view>
                        <text class="theme-swatch-label">浅色玻璃</text>
                    </view>
                    <view :class="['theme-swatch', theme==='dark'?'active':'']" @click="setTheme('dark')">
                        <view class="theme-swatch-swatch" style="background:linear-gradient(135deg,#0a0a1a,#2a1f55);"></view>
                        <text class="theme-swatch-label">深色玻璃</text>
                    </view>
                    <view :class="['theme-swatch', theme==='flat'?'active':'']" @click="setTheme('flat')">
                        <view class="theme-swatch-swatch" style="background:linear-gradient(135deg,#0f0c29,#24243e);"></view>
                        <text class="theme-swatch-label">扁平渐变</text>
                    </view>
                </view>
            </view>
            <view class="row-between" style="padding:24rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>📳 震动反馈</view>
                <switch :checked="vibrateOn" color="#6366f1" @change="toggleVibrate" />
            </view>
            <view class="row-between" style="padding:24rpx 0;">
                <view>🎵 通知铃声</view>
                <view class="seg-group">
                    <text :class="['seg-item', ringtone==='default'?'active':'']" @click="setRingtone('default')">默认</text>
                    <text :class="['seg-item', ringtone==='silent'?'active':'']" @click="setRingtone('silent')">静默</text>
                </view>
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
                <view class="seg-group">
                    <text v-for="v in [15,30,60]" :key="v" :class="['seg-item', heartbeat===v?'active':'']" @click="setHeartbeat(v)">{{ v }}s</text>
                </view>
            </view>
        </view>

        <view class="section-title">存储</view>
        <view class="glass-card" style="padding:16rpx 30rpx;">
            <view class="row-between" style="padding:20rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>💾 本地消息</view>
                <view class="text-secondary" style="font-size:24rpx;">{{ messagesCount }} 条 · {{ messagesSizeStr }}</view>
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
            <view class="row-between" style="padding:20rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>🏗 构建时间</view>
                <view class="text-secondary" style="font-size:22rpx;">{{ buildTime }}</view>
            </view>
            <view class="row-between" style="padding:24rpx 0;" @click="doCheckUpdate">
                <view>
                    <view>🔄 检查更新</view>
                    <view class="text-muted" style="font-size:22rpx;">{{ updateTip }}</view>
                </view>
                <view class="text-muted" style="font-size:26rpx;">›</view>
            </view>
            <view class="row-between" style="padding:24rpx 0;border-top:1px solid rgba(255,255,255,0.08);" @click="goHelp">
                <view>
                    <view>❓ 使用帮助</view>
                    <view class="text-muted" style="font-size:22rpx;">常见问题与功能说明</view>
                </view>
                <view class="text-muted" style="font-size:26rpx;">›</view>
            </view>
        </view>

        <view class="section-title">危险</view>
        <view class="glass-card">
            <button class="btn-ghost" style="color:#ef4444;border-color:rgba(239,68,68,0.4);" @click="logout">🚪 断开连接</button>
        </view>
    </view>
</template>

<script>

import { checkUpdate } from '../../js/api.js'
import { loadBootConfig, PUSH_VIBRATE, PUSH_WIFI_ONLY, PUSH_AUTO_RECONNECT, PUSH_HEARTBEAT, PUSH_RINGTONE, getMessages, clearMessages, getMessagesSize, formatBytes, PUSH_KEY } from '../../js/storage.js'
import { disconnect, applySettings } from '../../js/ws.js'
import { getDeviceInfo, checkNotificationPerm, checkBatteryOpt, openNotificationSetting, openBatteryOpt, openBrandSetting } from '../../js/permissions.js'
import { getTheme, setTheme as applyThemeFn, onThemeChange, offThemeChange } from '../../js/theme.js'
import { applySafeArea } from '../../js/safe-area.js'

export default {
    data() {
        return {
            themeClass: 'dark',
            theme: 'dark',
            ringtone: 'default',
            deviceInfo: { brand: '', model: '', os: '' },
            notifyOk: true,
            batteryOk: true,
            vibrateOn: true,
            wifiOnly: false,
            autoReconnect: true,
            heartbeat: 30,
            messagesCount: 0,
            messagesSizeStr: '0 B',
            versionName: '1.0.0',
            updateTip: '点击检查最新版本',
            buildTime: ''
        }
    },
    onShow: function() {
        applySafeArea()
            var self = this; self.themeClass = getTheme(); self.theme = self.themeClass; onThemeChange(function(t){ self.themeClass = t; self.theme = t })
        var cfg = loadBootConfig()
        this.deviceInfo = getDeviceInfo()
        this.notifyOk = checkNotificationPerm()
        this.batteryOk = checkBatteryOpt()
        this.vibrateOn = uni.getStorageSync(PUSH_VIBRATE) !== false
        this.wifiOnly = uni.getStorageSync(PUSH_WIFI_ONLY) === true
        this.autoReconnect = uni.getStorageSync(PUSH_AUTO_RECONNECT) !== false
        this.heartbeat = parseInt(uni.getStorageSync(PUSH_HEARTBEAT)) || 30
        this.ringtone = uni.getStorageSync(PUSH_RINGTONE) || 'default'
        this.messagesCount = getMessages().length
        this.messagesSizeStr = formatBytes(getMessagesSize())
        this.versionName = cfg.version_name || '1.0.0'
        this.buildTime = cfg.build_time || '—'
    },
    methods: {
        goBack: function() { uni.navigateBack({ delta: 1 }) },
        goHelp: function() { uni.navigateTo({ url: '/pages/help/index' }) },
        setTheme: function(v) {
            applyThemeFn(v)
            uni.showToast({ title: '主题已切换', icon: 'none' })
        },
        setRingtone: function(v) {
            this.ringtone = v
            uni.setStorageSync(PUSH_RINGTONE, v)
            uni.showToast({ title: v === 'silent' ? '通知将静默推送' : '使用系统默认铃声', icon: 'none' })
        },
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
            applySettings()
        },
        setHeartbeat: function(v) {
            this.heartbeat = v
            uni.setStorageSync(PUSH_HEARTBEAT, v)
            applySettings()
            uni.showToast({ title: '心跳间隔已更新为 ' + v + 's', icon: 'none' })
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
                        self.messagesSizeStr = '0 B'
                        uni.showToast({ title: '已清除', icon: 'success' })
                    }
                }
            })
        },
                doCheckUpdate: function() {
            var self = this
            var cfg = loadBootConfig()
            var baseUrl = uni.getStorageSync('push_server_url') || cfg.server_url
            var platform = uni.getSystemInfoSync().platform || 'android'
            if (!baseUrl) { uni.showToast({ title: '未配置服务器地址', icon: 'none' }); return }
            self.updateTip = '检查中…'
            checkUpdate(baseUrl, self.versionName, platform).then(function(info) {
                if (info && info.has_update) {
                    var msg = '最新版本：v' + info.latest_version + '\n当前版本：v' + self.versionName
                    if (info.update_log) msg += '\n\n更新说明：\n' + info.update_log
                    if (info.download_url) msg += '\n\n下载地址：' + info.download_url
                    self.updateTip = '发现新版本 v' + info.latest_version
                    uni.showModal({
                        title: '🎉 发现新版本',
                        content: msg,
                        confirmText: info.download_url ? '立即下载' : '知道了',
                        success: function(r) {
                            if (r.confirm && info.download_url) {
                                // #ifdef APP-PLUS
                                plus.runtime.openURL(info.download_url)
                                // #endif
                                // #ifndef APP-PLUS
                                uni.setClipboardData({ data: info.download_url, success: function() {
                                    uni.showToast({ title: '下载链接已复制', icon: 'success' })
                                }})
                                // #endif
                            }
                        }
                    })
                } else {
                    self.updateTip = '已是最新版本'
                    uni.showToast({ title: '已是最新版本 v' + self.versionName, icon: 'none' })
                }
            }).catch(function() {
                self.updateTip = '检查失败，请稍后重试'
                uni.showToast({ title: '网络错误', icon: 'none' })
            })
        },
        logout: function() {
            var self = this
            uni.showModal({
                title: '断开连接',
                content: '断开 WebSocket 连接并清除 Key？',
                success: function(r) {
                    if (r.confirm) {
                        disconnect()
                        uni.removeStorageSync(PUSH_KEY)
                        self.onShow()
                        uni.showToast({ title: '已断开', icon: 'success' })
                    }
                }
            })
        }
    },
    onUnload: function() { offThemeChange() }
}

</script>
