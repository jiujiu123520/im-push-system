<template>
    <view :class="['glass-bg', themeClass]">
        <view class="top-bar">
            <view class="row" >
                <text class="icon-btn" @click="goBack" style="font-size:36rpx;width:72rpx;height:72rpx;">‹</text>
                <text class="top-bar-title" style="margin-left:20rpx;">服务器配置</text>
            </view>
            <view class="top-bar-subtitle" style="margin-top:12rpx;">填写后端连接信息以接收推送</view>
        </view>

        <view class="glass-card" style="margin-top:80rpx;">
            <view style="font-size:28rpx;font-weight:600;margin-bottom:20rpx;">🔑 Push Key</view>
            <input class="glass-input" placeholder="粘贴你的 Push Key" placeholder-style="" v-model="key"
                   cursor-spacing="20" adjust-position="true" confirm-type="done"
 />

            <view style="font-size:28rpx;font-weight:600;margin-bottom:20rpx;">🌐 HTTP 服务器地址</view>
            <input class="glass-input" placeholder="https://push.example.com" placeholder-style="" v-model="serverUrl"
                   cursor-spacing="20" adjust-position="true" confirm-type="done"
 />

            <view style="font-size:28rpx;font-weight:600;margin-bottom:20rpx;">🔗 WebSocket 地址</view>
            <input class="glass-input" placeholder="wss://push.example.com/ws" placeholder-style="" v-model="wsUrl"
                   cursor-spacing="20" adjust-position="true" confirm-type="done"
 />

            <button class="btn-primary" style="width:100%;margin-top:48rpx;" @click="confirm">确认并连接</button>
        </view>

        <view class="glass-card" style="margin-top:32rpx;">
            <view style="font-size:28rpx;font-weight:600;margin-bottom:8rpx;">🧪 测试服务器连接</view>
            <view style="font-size:24rpx;opacity:0.6;margin-bottom:20rpx;">一键验证 Key 和地址是否正确</view>
            <button class="btn-ghost" style="width:100%;" @click="testConnection" :disabled="testing">
                {{ testing ? '测试中…' : '测试连接' }}
            </button>
            <view v-if="testResult" style="margin-top:16rpx;font-size:24rpx;padding:16rpx;border-radius:12rpx;"
                  :style="{ background: testResult.ok ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.15)', color: testResult.ok ? '#22c55e' : '#ef4444' }">
                {{ testResult.message }}
            </view>
        </view>
    </view>
</template>

<script>
import { loadBootConfig, PUSH_KEY, PUSH_SERVER_URL, PUSH_WS_URL } from '../../js/storage.js'
import { testPush } from '../../js/api.js'
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

function _v(v, fallback) {
    if (!v) return fallback
    if (typeof v !== 'string') return fallback
    if (v.length < 2) return fallback
    if (/example\.com|default_key|placeholder/i.test(v)) return fallback
    return v
}

export default {
    data() {
        return {
            themeClass: 'theme-dark',
            key: APP_CONFIG.default_key,
            serverUrl: APP_CONFIG.server_url,
            wsUrl: APP_CONFIG.ws_url,
            testing: false,
            testResult: null
        }
    },
    onShow: function() {
        applySafeArea()
        var self = this
        self.themeClass = 'theme-' + getTheme()
        self._themeListener = function(t) { self.themeClass = 'theme-' + t }
        onThemeChange(self._themeListener)
        applyTheme()
        try {
            var boot = loadBootConfig() || {}
            self.key = _v(uni.getStorageSync(PUSH_KEY), _v(boot.default_key, APP_CONFIG.default_key))
            self.serverUrl = _v(uni.getStorageSync(PUSH_SERVER_URL), _v(boot.server_url, APP_CONFIG.server_url))
            self.wsUrl = _v(uni.getStorageSync(PUSH_WS_URL), _v(boot.ws_url, APP_CONFIG.ws_url))
        } catch(e) {
            self.key = APP_CONFIG.default_key
            self.serverUrl = APP_CONFIG.server_url
            self.wsUrl = APP_CONFIG.ws_url
        }
    },
    onUnload: function() {
        if (this._themeListener) { offThemeChange(this._themeListener); this._themeListener = null }
    },
    methods: {
        goBack: function() {
            uni.navigateBack({ delta: 1 })
        },
        confirm: function() {
            var self = this
            if (!self.key) { uni.showToast({ title: '请填写 Push Key', icon: 'none' }); return }
            if (!self.serverUrl) { uni.showToast({ title: '请填写服务器地址', icon: 'none' }); return }
            if (!self.wsUrl) {
                self.wsUrl = self.serverUrl.replace(/^http/, 'ws') + '/ws'
            }
            uni.setStorageSync(PUSH_KEY, self.key)
            uni.setStorageSync(PUSH_SERVER_URL, self.serverUrl)
            uni.setStorageSync(PUSH_WS_URL, self.wsUrl)
            uni.showToast({ title: '已保存，正在连接…', icon: 'none' })
            setTimeout(function() { uni.switchTab({ url: '/pages/home/index' }) }, 700)
        },
        testConnection: function() {
            var self = this
            if (!self.serverUrl || !self.key) {
                self.testResult = { ok: false, message: '请先填写服务器地址和 Push Key' }
                return
            }
            self.testing = true
            self.testResult = null
            var deviceId = uni.getStorageSync('push_device_id') || ''
            testPush(self.serverUrl, self.key, deviceId).then(function(res) {
                self.testing = false
                self.testResult = { ok: true, message: '✅ 连接成功！服务器响应正常。' }
            }).catch(function(err) {
                self.testing = false
                var msg = (err && err.message) || (typeof err === 'string' ? err : '连接失败，请检查地址和 Key')
                self.testResult = { ok: false, message: '❌ ' + msg }
            })
        }
    }
}
</script>
