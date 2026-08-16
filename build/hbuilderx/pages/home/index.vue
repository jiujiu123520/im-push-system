<template>
    <view :class="['glass-bg', themeClass]">
        <view class="top-bar">
            <view class="row-between" >
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
            <view v-if="wsErrorMsg" class="text-error" style="font-size:24rpx;margin-top:8rpx;">⚠ {{ wsErrorMsg }}</view>
            <view class="row-between" style="margin-top:6rpx;">
                <view class="text-muted" style="font-size:24rpx;max-width:60%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ wsUrl || '未配置服务器地址' }}</view>
                <view class="text-muted" style="font-size:24rpx;">延迟 <text v-if="latency >= 0" style="color:var(--text-accent);font-weight:600;">{{ latency }}ms</text><text v-else>—</text></view>
            </view>
            <view class="row" style="margin-top:24rpx;">
                <button class="btn-primary" style="flex:1;margin-right:16rpx;" @click="testPush" :disabled="!canTest">测试推送</button>
                <button class="btn-ghost" style="flex:1;" @click="reconnect">重新连接</button>
            </view>
        </view>

        <view class="section-title">最近消息</view>
        <view class="glass-card" v-if="recentMessages.length === 0">
            <view class="text-muted" style="text-align:center;padding:20rpx 0;">暂无消息，点击上方"测试推送"发送一条试试</view>
        </view>
        <view class="glass-card" v-for="(m, i) in recentMessages" :key="m.id" style="padding:24rpx 30rpx;margin-top:8rpx;" @click="openMessages">
            <view class="row-between">
                <view style="font-size:28rpx;font-weight:600;color:var(--text-primary);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ m.title || '推送消息' }}</view>
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
import { loadBootConfig, PUSH_KEY, PUSH_WS_URL, PUSH_SERVER_URL, getMessages } from '../../js/storage.js'
import { connect, reconnect, isConnected, getState, getLatency, on, off } from '../../js/ws.js'
import { notify } from '../../js/notify.js'
import { testPush as apiTestPush } from '../../js/api.js'
import { getTheme, applyTheme, onThemeChange, offThemeChange } from '../../js/theme.js'
import { applySafeArea } from '../../js/safe-area.js'

const _DEFAULT_CONFIG = {
    app_name: 'PushApp',
    default_key: 'sQhrgtacqssANoklLtQsKwEOda0es8E7',
    server_url: 'https://api1.98dyy.cn',
    ws_url: 'wss://api1.98dyy.cn/ws/client',
    version_name: '1.0.0',
    build_time: ''
}
let APP_CONFIG = _DEFAULT_CONFIG
try {
    const _m = require('../../config.js')
    if (_m && _m.APP_CONFIG && typeof _m.APP_CONFIG === 'object') {
        APP_CONFIG = Object.assign({}, _DEFAULT_CONFIG, _m.APP_CONFIG)
    }
} catch(e) {}

function _v(v, fb) {
    if (!v || typeof v !== 'string' || v.length < 2) return fb
    if (/example\.com|default_key|placeholder/i.test(v)) return fb
    return v
}

export default {
    data() {
        return {
            themeClass: 'dark',
            appName: APP_CONFIG.app_name,
            wsState: '未连接',
            stateLabel: '离线',
            stateClass: 'status-bad',
            wsUrl: APP_CONFIG.ws_url,
            wsErrorMsg: '',
            keyValue: APP_CONFIG.default_key,
            latency: -1,
            recentMessages: []
        }
    },
    computed: {
        canTest: function() { return !!this.keyValue }
    },
    onShow: function() {
        applySafeArea()
        var self = this
        self.themeClass = getTheme()
        self._themeListener = function(t) { self.themeClass = t }
        onThemeChange(self._themeListener)
        applyTheme()
        try {
            var cfg = loadBootConfig() || {}
            self.appName = _v(cfg.app_name, APP_CONFIG.app_name)
            self.keyValue = _v(uni.getStorageSync(PUSH_KEY), _v(cfg.default_key, APP_CONFIG.default_key))
            self.wsUrl = _v(uni.getStorageSync(PUSH_WS_URL), _v(cfg.ws_url, APP_CONFIG.ws_url))
        } catch(e) {
            self.appName = APP_CONFIG.app_name
            self.keyValue = APP_CONFIG.default_key
            self.wsUrl = APP_CONFIG.ws_url
        }
        self.recentMessages = getMessages().slice(0, 3)

        if (!self.keyValue) {
            uni.navigateTo({ url: '/pages/key-input/index' })
            return
        }
        if (!self.wsUrl) {
            self.wsErrorMsg = '请先在"服务器配置"里填写服务器地址'
            self.wsState = '未配置服务器'
            self.stateLabel = '错误'
            self.stateClass = 'status-bad'
            return
        }

        self.wsErrorMsg = ''
        on('state', self._onState)
        on('message', self._onMessage)
        on('error', self._onError)
        on('latency', self._onLatency)
        var st = getState()
        self._onState(st)
        self.latency = getLatency()
        if (!isConnected()) {
            connect(self.wsUrl, self.keyValue)
        }
    },
    onHide: function() {
        off('state', this._onState)
        off('message', this._onMessage)
        off('error', this._onError)
        off('latency', this._onLatency)
    },
    onUnload: function() {
        off('state', this._onState)
        off('message', this._onMessage)
        off('error', this._onError)
        off('latency', this._onLatency)
        if (this._themeListener) { offThemeChange(this._themeListener); this._themeListener = null }
    },
    methods: {
        _onState: function(s) {
            if (s === 'error') {
                this.wsState = '连接错误'
                this.stateLabel = '错误'
                this.stateClass = 'status-bad'
                this.latency = -1
            } else if (s === 'connected') {
                this.wsState = 'WebSocket 已连接'
                this.stateLabel = '在线'; this.stateClass = 'status-ok'
                this.wsErrorMsg = ''
            } else if (s === 'connecting' || s === 'reconnecting') {
                this.wsState = s === 'reconnecting' ? '正在自动重连…' : '正在连接…'
                this.stateLabel = '连接中'; this.stateClass = 'status-warn'
                this.wsErrorMsg = ''
                this.latency = -1
            } else if (s === 'disconnected') {
                this.wsState = '连接已断开'
                this.stateLabel = '离线'; this.stateClass = 'status-bad'
                this.latency = -1
            } else {
                this.wsState = s
                this.stateLabel = s; this.stateClass = 'status-bad'
                this.latency = -1
            }
        },
        _onLatency: function(v) { this.latency = v },
        _onError: function(err) {
            var msg = (err && err.message) || '连接错误'
            this.wsErrorMsg = msg
            uni.showToast({ title: msg, icon: 'none', duration: 2000 })
        },
        _onMessage: function(msg) {
            this.recentMessages = getMessages().slice(0, 3)
            notify(msg.title, msg.content, msg.priority)
        },
        testPush: function() {
            var cfg = loadBootConfig()
            var base = uni.getStorageSync(PUSH_SERVER_URL) || cfg.server_url
            if (!this.keyValue) { uni.showToast({ title: '请先配置 Key', icon: 'none' }); return }
            var deviceId = uni.getStorageSync('push_device_id') || ''
            var self = this
            apiTestPush(base, this.keyValue, deviceId).then(function(r) {
                uni.showToast({ title: (r && r.message) || '测试推送已发送', icon: 'success' })
            }).catch(function() {
                uni.showToast({ title: '测试推送已发送（无响应）', icon: 'none' })
                self._onMessage({ title: '测试推送', content: '这是一条测试推送消息', priority: 'default', timestamp: Date.now() })
            })
        },
        reconnect: function() {
            if (!this.wsUrl) { uni.showToast({ title: '请先配置服务器地址', icon: 'none' }); return }
            if (!this.keyValue) { uni.showToast({ title: '请先配置 Key', icon: 'none' }); return }
            reconnect()
        },
        goMessages: function() { uni.switchTab({ url: '/pages/messages/index' }) },
        goSettings: function() { uni.navigateTo({ url: '/pages/settings/index' }) },
        goKeyConfig: function() { uni.navigateTo({ url: '/pages/key-input/index' }) },
        openMessages: function() { uni.switchTab({ url: '/pages/messages/index' }) },
        truncate: function(s, n) { return (s || '').length > n ? (s || '').slice(0, n) + '…' : (s || '') },
        timeAgo: function(ts) {
            if (!ts) return ''
            var n = Number(ts)
            if (!n || n <= 0) return ''
            if (n < 1e12) n = n * 1000
            var diff = Date.now() - n
            if (diff < 0) diff = 0
            if (diff < 60000) return '刚刚'
            if (diff < 3600000) return Math.floor(diff / 60000) + '分钟前'
            if (diff < 86400000) return Math.floor(diff / 3600000) + '小时前'
            return Math.floor(diff / 86400000) + '天前'
        }
    }
}
</script>

<style>
.text-error { color: #ff7875; }
</style>
