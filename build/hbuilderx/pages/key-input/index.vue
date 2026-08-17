<template>
    <view :class="['glass-bg', themeClass]">
        <view class="top-bar">
            <view class="row">
                <text class="icon-btn" @click="goBack" style="font-size:36rpx;width:72rpx;height:72rpx;">‹</text>
                <text class="top-bar-title" style="margin-left:20rpx;">服务器配置</text>
            </view>
            <view class="top-bar-subtitle" style="margin-top:12rpx;">填写后端连接信息以接收推送</view>
        </view>

        <!-- 当前生效配置一览 -->
        <view class="glass-card" style="margin-top:80rpx;">
            <view class="row-between" style="margin-bottom:16rpx;">
                <view style="font-size:28rpx;font-weight:600;">📋 当前生效配置</view>
                <text style="font-size:24rpx;color:#6366f1;" @click="resetDefault">恢复默认</text>
            </view>
            <view class="cfg-row">
                <text class="cfg-label">Key</text>
                <text class="cfg-value">{{ currentKey || '—' }}</text>
            </view>
            <view class="cfg-row">
                <text class="cfg-label">HTTP</text>
                <text class="cfg-value">{{ currentServerUrl || '—' }}</text>
            </view>
            <view class="cfg-row" style="border-bottom:none;">
                <text class="cfg-label">WS</text>
                <text class="cfg-value">{{ currentWsUrl || '—' }}</text>
            </view>
        </view>

        <!-- 编辑表单 -->
        <view class="glass-card" style="margin-top:32rpx;">
            <view style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20rpx;">
                <view style="font-size:28rpx;font-weight:600;">🔑 Push Key</view>
                <text v-if="keyState !== 'empty'" :style="{ fontSize: '22rpx', color: keyState === 'ok' ? '#22c55e' : '#ef4444' }">{{ keyState === 'ok' ? '✓ 格式正确' : '✗ 长度不足' }}</text>
            </view>
            <input class="glass-input" placeholder="粘贴你的 Push Key" placeholder-style="color:rgba(150,150,160,0.6);"
                   v-model="key" />

            <view style="display:flex;align-items:center;justify-content:space-between;margin:32rpx 0 20rpx;">
                <view style="font-size:28rpx;font-weight:600;">🌐 HTTP 服务器地址</view>
                <text v-if="serverUrl" :style="{ fontSize: '22rpx', color: urlState.server ? '#22c55e' : '#ef4444' }">{{ urlState.server ? '✓ 地址有效' : '✗ 格式错误' }}</text>
            </view>
            <input class="glass-input" placeholder="https://push.example.com" placeholder-style="color:rgba(150,150,160,0.6);"
                   v-model="serverUrl" @blur="syncWsFromServer" />

            <view style="display:flex;align-items:center;justify-content:space-between;margin:32rpx 0 20rpx;">
                <view style="font-size:28rpx;font-weight:600;">🔗 WebSocket 地址</view>
                <text v-if="wsUrl" :style="{ fontSize: '22rpx', color: urlState.ws ? '#22c55e' : '#ef4444' }">{{ urlState.ws ? '✓ 地址有效' : '✗ 格式错误' }}</text>
            </view>
            <input class="glass-input" placeholder="wss://push.example.com/ws（留空自动生成）" placeholder-style="color:rgba(150,150,160,0.6);"
                   v-model="wsUrl" />
            <view style="font-size:22rpx;opacity:0.5;margin-top:12rpx;">留空将根据 HTTP 地址自动生成（https→wss + /ws/client）</view>

            <button class="btn-primary" style="width:100%;margin-top:48rpx;" @click="confirm">保存并连接</button>
        </view>

        <!-- 测试连接 -->
        <view class="glass-card" style="margin-top:32rpx;">
            <view style="font-size:28rpx;font-weight:600;margin-bottom:8rpx;">🧪 测试服务器连接</view>
            <view style="font-size:24rpx;opacity:0.6;margin-bottom:20rpx;">使用表单当前填写的地址和 Key 验证（无需先保存）</view>
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
import { getDeviceId } from '../../js/device-id.js'
import * as _cfg from '../../config.js'

const _DEFAULT_CONFIG = {
    app_name: 'PushApp',
    default_key: 'sQhrgtacqssANoklLtQsKwEOda0es8E7',
    server_url: 'https://api1.98dyy.cn',
    ws_url: 'wss://api1.98dyy.cn/ws/client',
    version_name: '1.0.0',
    build_time: ''
}
const APP_CONFIG = Object.assign({}, _DEFAULT_CONFIG, (_cfg && _cfg.APP_CONFIG) || {})
Object.keys(_DEFAULT_CONFIG).forEach(function(k) {
    var v = _clean(APP_CONFIG[k])
    if (!v || v.length < 2 || /example\.com|placeholder/i.test(v)) v = _DEFAULT_CONFIG[k]
    APP_CONFIG[k] = v
})

function _clean(v) {
    if (!v || typeof v !== 'string') return ''
    return v.replace(/^[\s`'"]+|[\s`'"]+$/g, '').trim()
}

function _readClean(storageKey, fallback) {
    var raw = ''
    try { raw = uni.getStorageSync(storageKey) } catch(e) {}
    var v = _clean(raw)
    if (!v || v.length < 2 || /example\.com|placeholder/i.test(v)) v = fallback
    if (v && v !== raw) {
        try { uni.setStorageSync(storageKey, v) } catch(e) {}
        console.log('[KeyInput] 已自动修复存储脏值:', storageKey)
    }
    return v
}

export default {
    data() {
        return {
            themeClass: 'theme-dark',
            key: '',
            serverUrl: '',
            wsUrl: '',
            currentKey: '',
            currentServerUrl: '',
            currentWsUrl: '',
            testing: false,
            testResult: null
        }
    },
    computed: {
        keyState: function() {
            if (!this.key) return 'empty'
            return this.key.trim().length >= 16 ? 'ok' : 'bad'
        },
        urlState: function() {
            return {
                server: /^https?:\/\/[a-z0-9.-]+(:\d+)?(\/.*)?$/i.test((this.serverUrl || '').trim()),
                ws: /^wss?:\/\/[a-z0-9.-]+(:\d+)?(\/.*)?$/i.test((this.wsUrl || '').trim())
            }
        }
    },
    watch: {
        // uni-app APP-PLUS 原生组件只认 v-model，不认 :value
        // 所以用 v-model + watch 做实时清洗（剥离反引号/引号/空白）
        key: function(v) { var c = _clean(v); if (c !== v) this.key = c },
        serverUrl: function(v) { var c = _clean(v); if (c !== v) this.serverUrl = c },
        wsUrl: function(v) { var c = _clean(v); if (c !== v) this.wsUrl = c }
    },
    onShow: function() {
        applySafeArea()
        var self = this
        var t = getTheme()
        self.themeClass = 'theme-' + t
        self._themeListener = function(nt) { self.themeClass = 'theme-' + nt }
        onThemeChange(self._themeListener)
        applyTheme()

        var boot = {}
        try { boot = loadBootConfig() || {} } catch(e) {}

        // 读取三级配置：本地存储（自动清洗）→ boot 配置（清洗）→ config.js 硬编码
        var finalKey = _readClean(PUSH_KEY, _clean(boot.default_key) || APP_CONFIG.default_key)
        var finalServer = _readClean(PUSH_SERVER_URL, _clean(boot.server_url) || APP_CONFIG.server_url)
        var finalWs = _readClean(PUSH_WS_URL, _clean(boot.ws_url) || APP_CONFIG.ws_url)

        // 顶部"当前生效配置"
        self.currentKey = finalKey
        self.currentServerUrl = finalServer
        self.currentWsUrl = finalWs

        // APP-PLUS 原生 input 组件只认 v-model 的 setter
        // 直接 this.key = xxx 就会触发 setter，原生组件自动刷新
        // （之前用 :value 单向绑定被原生组件忽略了！）
        self.key = finalKey
        self.serverUrl = finalServer
        self.wsUrl = finalWs
    },
    onUnload: function() {
        if (this._themeListener) { offThemeChange(this._themeListener); this._themeListener = null }
    },
    methods: {
        goBack: function() {
            uni.navigateBack({ delta: 1 })
        },
        syncWsFromServer: function() {
            var s = _clean(this.serverUrl)
            if (!/^https?:\/\//i.test(s)) return
            var ws = _clean(this.wsUrl)
            if (ws && /^wss?:\/\//i.test(ws)) return  // 用户已填，不覆盖
            this.wsUrl = s.replace(/^http/i, 'ws') + '/ws/client'
        },
        resetDefault: function() {
            var self = this
            uni.showModal({
                title: '恢复默认配置',
                content: '将 Key 和服务器地址重置为构建时内置的默认值？',
                success: function(r) {
                    if (!r.confirm) return
                    self.key = APP_CONFIG.default_key
                    self.serverUrl = APP_CONFIG.server_url
                    self.wsUrl = APP_CONFIG.ws_url
                    uni.setStorageSync(PUSH_KEY, self.key)
                    uni.setStorageSync(PUSH_SERVER_URL, self.serverUrl)
                    uni.setStorageSync(PUSH_WS_URL, self.wsUrl)
                    self.currentKey = self.key
                    self.currentServerUrl = self.serverUrl
                    self.currentWsUrl = self.wsUrl
                    uni.showToast({ title: '已恢复默认', icon: 'success' })
                }
            })
        },
        confirm: function() {
            var self = this
            var key = _clean(self.key)
            var serverUrl = _clean(self.serverUrl)
            var wsUrl = _clean(self.wsUrl)

            if (!key) { uni.showToast({ title: '请填写 Push Key', icon: 'none' }); return }
            if (key.length < 16) { uni.showToast({ title: 'Key 长度不足，请检查', icon: 'none' }); return }
            if (!/^https?:\/\//i.test(serverUrl)) { uni.showToast({ title: 'HTTP 地址需以 http(s):// 开头', icon: 'none' }); return }
            if (!wsUrl) {
                wsUrl = serverUrl.replace(/^http/i, 'ws') + '/ws/client'
                self.wsUrl = wsUrl
            }
            if (!/^wss?:\/\//i.test(wsUrl)) { uni.showToast({ title: 'WS 地址需以 ws(s):// 开头', icon: 'none' }); return }

            uni.setStorageSync(PUSH_KEY, key)
            uni.setStorageSync(PUSH_SERVER_URL, serverUrl)
            uni.setStorageSync(PUSH_WS_URL, wsUrl)
            uni.showToast({ title: '已保存，正在连接…', icon: 'none' })
            setTimeout(function() { uni.switchTab({ url: '/pages/home/index' }) }, 700)
        },
        testConnection: function() {
            var self = this
            var serverUrl = _clean(self.serverUrl)
            var key = _clean(self.key)
            if (!serverUrl || !key) {
                self.testResult = { ok: false, message: '请先填写服务器地址和 Push Key' }
                return
            }
            if (!/^https?:\/\//i.test(serverUrl)) {
                self.testResult = { ok: false, message: '❌ HTTP 地址需以 http(s):// 开头' }
                return
            }
            self.testing = true
            self.testResult = null
            var deviceId = getDeviceId()
            testPush(serverUrl, key, deviceId).then(function() {
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

<style scoped>
.cfg-row {
    display: flex;
    align-items: flex-start;
    padding: 14rpx 0;
    border-bottom: 1px solid rgba(255,255,255,0.08);
}
.cfg-label {
    width: 100rpx;
    font-size: 24rpx;
    opacity: 0.6;
    flex-shrink: 0;
}
.cfg-value {
    flex: 1;
    font-size: 24rpx;
    word-break: break-all;
    opacity: 0.9;
}
</style>
