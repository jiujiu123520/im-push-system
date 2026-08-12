<template>
    <view class="glass-bg">
        <view class="top-bar">
            <view class="row-between" style="margin-top:60rpx;">
                <view>
                    <view class="text-secondary" style="font-size:26rpx;">你好，Push 用户</view>
                    <view class="top-bar-title" style="margin-top:4rpx;">{{ appName }}</view>
                </view>
                <view class="icon-btn" @click="goSettings">⚙</view>
            </view>
        </view>

        <view class="glass-card" style="margin-top:20rpx;">
            <view class="row-between">
                <view class="text-secondary" style="font-size:26rpx;">连接状态</view>
                <view :class="['status-chip', stateClass]">{{ stateLabel }}</view>
            </view>
            <view class="text-primary" style="font-size:34rpx;font-weight:600;margin-top:12rpx;">{{ wsState }}</view>
            <view class="text-muted" style="font-size:24rpx;margin-top:6rpx;">{{ wsUrl }}</view>
            <view class="row" style="margin-top:24rpx;">
                <button class="btn-primary" style="flex:1;margin-right:16rpx;" @click="testPush">测试推送</button>
                <button class="btn-ghost" style="flex:1;" @click="reconnect">重新连接</button>
            </view>
        </view>

        <view class="section-title">最近消息</view>
        <view class="glass-card" v-if="recentMessages.length === 0">
            <view class="text-muted" style="text-align:center;padding:20rpx 0;">暂无消息，点击上方"测试推送"发送一条试试</view>
        </view>
        <view class="glass-card" v-for="(m, i) in recentMessages" :key="m.id" style="padding:24rpx 30rpx;margin-top:8rpx;" @click="openMessages">
            <view class="row-between">
                <view style="font-size:28rpx;font-weight:600;color:rgba(255,255,255,0.9);">{{ m.title || '推送消息' }}</view>
                <view :class="['status-chip', m.priority === 'high' ? 'status-bad' : 'status-ok']" v-if="m.priority">{{ m.priority === 'high' ? '高优先' : '普通' }}</view>
            </view>
            <view class="text-secondary" style="font-size:26rpx;margin-top:6rpx;">{{ truncate(m.content, 60) }}</view>
            <view class="text-muted" style="font-size:22rpx;margin-top:10rpx;">{{ timeAgo(m.timestamp) }}</view>
        </view>

        <view class="section-title">快捷操作</view>
        <view class="row" style="padding:0 24rpx;gap:16rpx;">
            <button class="btn-ghost" style="flex:1;" @click="goMessages">📄 消息列表</button>
            <button class="btn-ghost" style="flex:1;" @click="goKeyConfig">🔑 服务器配置</button>
        </view>
    </view>
</template>

<script>
import { connect, disconnect, reconnect, isConnected, on, off } from '../../js/ws.js'
import { loadBootConfig, PUSH_KEY, PUSH_WS_URL, PUSH_SERVER_URL, getMessages } from '../../js/storage.js'
import { notify } from '../../js/notify.js'
import { testPush as apiTestPush } from '../../js/api.js'

export default {
    data() {
        return {
            appName: 'PushApp',
            wsState: '未连接',
            stateLabel: '离线',
            stateClass: 'status-bad',
            wsUrl: '',
            recentMessages: []
        }
    },
    onShow: function() {
        this.appName = loadBootConfig().app_name || 'PushApp'
        var cfg = loadBootConfig()
        var key = uni.getStorageSync(PUSH_KEY) || cfg.default_key
        this.wsUrl = uni.getStorageSync(PUSH_WS_URL) || cfg.ws_url || ''
        this.recentMessages = getMessages().slice(0, 3)

        if (!key) {
            uni.navigateTo({ url: '/pages/key-input/index' })
            return
        }

        on('state', this._onState)
        on('message', this._onMessage)
        if (this.wsUrl && !isConnected()) {
            connect(this.wsUrl, key)
        }
    },
    onHide: function() {
        off('state', this._onState)
        off('message', this._onMessage)
    },
    methods: {
        _onState: function(s) {
            this.wsState = s === 'connected' ? 'WebSocket 已连接' : (s === 'connecting' || s === 'reconnecting' ? '正在连接…' : '未连接')
            if (s === 'connected') {
                this.stateLabel = '在线'; this.stateClass = 'status-ok'
            } else if (s === 'connecting' || s === 'reconnecting') {
                this.stateLabel = '连接中'; this.stateClass = 'status-warn'
            } else {
                this.stateLabel = '离线'; this.stateClass = 'status-bad'
            }
        },
        _onMessage: function(msg) {
            this.recentMessages = getMessages().slice(0, 3)
            notify(msg.title, msg.content, msg.priority)
        },
        testPush: function() {
            var cfg = loadBootConfig()
            var key = uni.getStorageSync(PUSH_KEY) || cfg.default_key
            var base = uni.getStorageSync(PUSH_SERVER_URL) || cfg.server_url
            if (!key) { uni.showToast({ title: '请先配置 Key', icon: 'none' }); return }
            var self = this
            apiTestPush(base, key).then(function(r) {
                uni.showToast({ title: r && r.message ? r.message : '测试推送已发送', icon: 'success' })
            }).catch(function() {
                uni.showToast({ title: '测试推送已发送（无响应）', icon: 'none' })
                self._onMessage({ title: '测试推送', content: '这是一条测试推送消息', priority: 'default', timestamp: Date.now() })
            })
        },
        reconnect: function() { reconnect() },
        goMessages: function() { uni.switchTab({ url: '/pages/messages/index' }) },
        goSettings: function() { uni.navigateTo({ url: '/pages/settings/index' }) },
        goKeyConfig: function() { uni.navigateTo({ url: '/pages/key-input/index' }) },
        openMessages: function() { uni.switchTab({ url: '/pages/messages/index' }) },
        truncate: function(s, n) { return (s || '').length > n ? (s || '').slice(0, n) + '…' : (s || '') },
        timeAgo: function(ts) {
            if (!ts) return ''
            var diff = Date.now() - ts
            if (diff < 60000) return '刚刚'
            if (diff < 3600000) return Math.floor(diff / 60000) + '分钟前'
            if (diff < 86400000) return Math.floor(diff / 3600000) + '小时前'
            return Math.floor(diff / 86400000) + '天前'
        }
    }
}
</script>
