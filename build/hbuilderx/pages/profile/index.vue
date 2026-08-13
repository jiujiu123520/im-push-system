<template>
    <view :class="['glass-bg', themeClass]">
        <view class="top-bar">
            <view class="top-bar-title" >个人中心</view>
            <view class="top-bar-subtitle">你的 Push 账户信息</view>
        </view>

        <view class="glass-card-highlight" style="margin-top:20rpx;">
            <view class="row-between">
                <view class="row">
                    <view style="width:100rpx;height:100rpx;border-radius:50%;background:linear-gradient(135deg,#6366f1,#ec4899);display:flex;align-items:center;justify-content:center;font-size:40rpx;font-weight:700;">P</view>
                    <view style="margin-left:24rpx;">
                        <view style="font-size:32rpx;font-weight:600;">Push 用户</view>
                        <view class="text-muted" style="font-size:24rpx;margin-top:4rpx;">ID: {{ userId || '未登录' }}</view>
                    </view>
                </view>
                <view :class="['status-chip', wsStateClass]">{{ wsStateLabel }}</view>
            </view>
        </view>

        <view class="glass-card">
            <view class="row-between" style="padding:16rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>
                    <view style="font-size:26rpx;font-weight:600;">当前 Key</view>
                    <view class="text-muted" style="font-size:22rpx;">用于 WebSocket 认证</view>
                </view>
                <view class="row">
                    <view class="text-accent" style="font-size:24rpx;margin-right:16rpx;max-width:300rpx;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ displayKey }}</view>
                    <text class="text-muted" style="font-size:24rpx;" @click="copyKey">📋 复制</text>
                </view>
            </view>
            <view class="row-between" style="padding:16rpx 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>
                    <view style="font-size:26rpx;font-weight:600;">HTTP 服务器</view>
                </view>
                <view class="text-secondary" style="font-size:24rpx;max-width:400rpx;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ serverUrl }}</view>
            </view>
            <view class="row-between" style="padding:16rpx 0;">
                <view>
                    <view style="font-size:26rpx;font-weight:600;">WebSocket</view>
                </view>
                <view class="text-secondary" style="font-size:24rpx;max-width:400rpx;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ wsUrl }}</view>
            </view>
        </view>

        <view class="section-title">快捷入口</view>
        <view class="glass-card" style="padding:0;">
            <view class="row-between" @click="goMessages" style="padding:30rpx;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>📄 消息列表</view>
                <view class="text-muted">›</view>
            </view>
            <view class="row-between" @click="goSettings" style="padding:30rpx;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>⚙ 设置</view>
                <view class="text-muted">›</view>
            </view>
            <view class="row-between" @click="goKeyConfig" style="padding:30rpx;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>🔑 服务器配置</view>
                <view class="text-muted">›</view>
            </view>
            <view class="row-between" @click="goHelp" style="padding:30rpx;border-bottom:1px solid rgba(255,255,255,0.08);">
                <view>❓ 使用帮助</view>
                <view class="text-muted">›</view>
            </view>
            <view class="row-between" @click="clearMsgs" style="padding:30rpx;">
                <view style="color:#ef4444;">🗑 清空消息记录</view>
                <view class="text-muted">›</view>
            </view>
        </view>
    </view>
</template>

<script>
import { loadBootConfig, PUSH_KEY, PUSH_SERVER_URL, PUSH_WS_URL, PUSH_USER_ID, clearMessages } from '../../js/storage.js'
import { on, off, getState } from '../../js/ws.js'
import { getTheme, onThemeChange, offThemeChange } from '../../js/theme.js'
import { applySafeArea } from '../../js/safe-area.js'

export default {
    data() {
        return {
            themeClass: 'light',
            userId: '',
            key: '',
            serverUrl: '',
            wsUrl: '',
            wsStateLabel: '未连接',
            wsStateClass: 'status-bad'
        }
    },
    computed: {
        displayKey: function() {
            if (!this.key) return '—'
            return this.key.length > 24 ? this.key.slice(0, 12) + '……' + this.key.slice(-8) : this.key
        }
    },
    onShow: function() {
        applySafeArea()
        var self = this
        self.themeClass = getTheme()
        onThemeChange(function(t) { self.themeClass = t })
        var cfg = loadBootConfig()
        self.userId = uni.getStorageSync(PUSH_USER_ID) || ''
        self.key = uni.getStorageSync(PUSH_KEY) || cfg.default_key || ''
        self.serverUrl = uni.getStorageSync(PUSH_SERVER_URL) || cfg.server_url || ''
        self.wsUrl = uni.getStorageSync(PUSH_WS_URL) || cfg.ws_url || ''
        self._updateState()
        on('state', self._updateState)
    },
    onHide: function() {
        off('state', this._updateState)
    },
    onUnload: function() {
        off('state', this._updateState)
        offThemeChange()
    },
    methods: {
        _updateState: function() {
            var s = getState()
            if (s === 'connected') { this.wsStateLabel = '在线'; this.wsStateClass = 'status-ok' }
            else if (s === 'connecting' || s === 'reconnecting') { this.wsStateLabel = '连接中'; this.wsStateClass = 'status-warn' }
            else { this.wsStateLabel = '离线'; this.wsStateClass = 'status-bad' }
        },
        copyKey: function() {
            uni.setClipboardData({ data: this.key, success: function(){ uni.showToast({ title: '已复制 Key', icon: 'success' }) } })
        },
        goMessages: function() { uni.switchTab({ url: '/pages/messages/index' }) },
        goSettings: function() { uni.navigateTo({ url: '/pages/settings/index' }) },
        goKeyConfig: function() { uni.navigateTo({ url: '/pages/key-input/index' }) },
        goHelp: function() { uni.navigateTo({ url: '/pages/help/index' }) },
        clearMsgs: function() {
            uni.showModal({
                title: '清空消息',
                content: '确定清空所有本地消息记录？',
                success: function(r) {
                    if (r.confirm) { clearMessages(); uni.showToast({ title: '已清空', icon: 'success' }) }
                }
            })
        }
    }
}
</script>
