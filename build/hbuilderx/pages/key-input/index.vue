<template>
    <view :class="['glass-bg', themeClass]">
        <view class="top-bar">
            <view class="row">
                <text class="icon-btn" @click="goBack" style="font-size:36rpx;width:72rpx;height:72rpx;">‹</text>
                <text class="top-bar-title" style="margin-left:20rpx;">服务器配置</text>
            </view>
            <view class="top-bar-subtitle" style="margin-top:12rpx;">填写后端连接信息以接收推送</view>
        </view>

        <!-- 当前生效配置（仅展示用，不用 input 避免原生组件干扰） -->
        <view class="glass-card" style="margin-top:80rpx;">
            <view class="row-between" style="margin-bottom:16rpx;">
                <view style="font-size:28rpx;font-weight:600;">📋 当前生效配置</view>
                <text style="font-size:24rpx;color:#6366f1;" @click="resetDefault">恢复默认</text>
            </view>
            <view class="cfg-row">
                <text class="cfg-label">Key</text>
                <text class="cfg-value">{{ displayKey }}</text>
            </view>
            <view class="cfg-row">
                <text class="cfg-label">HTTP</text>
                <text class="cfg-value">{{ displayServer }}</text>
            </view>
            <view class="cfg-row" style="border-bottom:none;">
                <text class="cfg-label">WS</text>
                <text class="cfg-value">{{ displayWs }}</text>
            </view>
        </view>

        <!-- 编辑表单：3 个原生 input，必须用 v-model 双向绑定（APP-PLUS :value 单向绑定被完全忽略！） -->
        <view class="glass-card" style="margin-top:32rpx;">
            <view style="font-size:28rpx;font-weight:600;margin-bottom:20rpx;">🔑 Push Key</view>
            <input class="input-key" placeholder="粘贴你的 Push Key" placeholder-class="ph-style"
                   v-model="formKey" />

            <view style="font-size:28rpx;font-weight:600;margin:32rpx 0 20rpx;">🌐 HTTP 服务器地址</view>
            <input class="input-url" placeholder="https://push.example.com" placeholder-class="ph-style"
                   v-model="formServer" @blur="autoFillWs" />

            <view style="font-size:28rpx;font-weight:600;margin:32rpx 0 20rpx;">🔗 WebSocket 地址</view>
            <input class="input-url" placeholder="wss://push.example.com/ws/client（留空自动生成）" placeholder-class="ph-style"
                   v-model="formWs" />
            <view style="font-size:22rpx;opacity:0.75;margin-top:12rpx;">留空将根据 HTTP 地址自动生成（https→wss + /ws/client）</view>

            <button class="btn-primary" style="width:100%;margin-top:48rpx;" @click="confirm">保存并连接</button>
        </view>

        <!-- 测试连接 -->
        <view class="glass-card" style="margin-top:32rpx;">
            <view style="font-size:28rpx;font-weight:600;margin-bottom:8rpx;">🧪 测试服务器连接</view>
            <view style="font-size:24rpx;opacity:0.6;margin-bottom:20rpx;">使用当前填写的地址和 Key 验证（无需先保存）</view>
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

// 剥离首尾的反引号/引号/空白字符（从文档复制地址时 markdown 装饰字符会混入）
function _clean(v) {
    if (!v || typeof v !== 'string') return ''
    return String(v).replace(/^[\s`'"]+|[\s`'"]+$/g, '').trim()
}

function _readClean(storageKey, fallback) {
    var raw = ''
    try { raw = uni.getStorageSync(storageKey) } catch(e) {}
    var v = _clean(raw)
    if (!v || v.length < 2 || /example\.com|placeholder/i.test(v)) v = fallback
    // 自动修复存储里的脏值
    if (v && v !== raw) { try { uni.setStorageSync(storageKey, v) } catch(e) {} }
    return v
}

export default {
    data() {
        return {
            themeClass: 'theme-dark',
            // v-model 绑定的表单值（APP-PLUS 原生 input 只认 v-model 的 setter）
            formKey: '',
            formServer: '',
            formWs: '',
            // 展示区用的值
            displayKey: '',
            displayServer: '',
            displayWs: '',
            testing: false,
            testResult: null
        }
    },
    watch: {
        // v-model 实时清洗：APP-PLUS 原生组件只认 v-model，不认 :value
        // 所以 watch 里把反引号/引号/空白剥掉后再赋回给 formKey，形成清洗循环
        formKey: function(v) {
            var c = _clean(v)
            if (c !== v) { this.formKey = c }
        },
        formServer: function(v) {
            var c = _clean(v)
            if (c !== v) { this.formServer = c }
        },
        formWs: function(v) {
            var c = _clean(v)
            if (c !== v) { this.formWs = c }
        }
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

        var finalKey = _readClean(PUSH_KEY, _clean(boot.default_key) || APP_CONFIG.default_key)
        var finalServer = _readClean(PUSH_SERVER_URL, _clean(boot.server_url) || APP_CONFIG.server_url)
        var finalWs = _readClean(PUSH_WS_URL, _clean(boot.ws_url) || APP_CONFIG.ws_url)

        // 顶部展示区
        self.displayKey = finalKey
        self.displayServer = finalServer
        self.displayWs = finalWs

        // 表单：APP-PLUS 原生 input 只有 v-model 能驱动显示
        // 直接 this.formXxx = value 会触发 Vue setter → 原生组件更新
        self.formKey = finalKey
        self.formServer = finalServer
        self.formWs = finalWs

        console.log('[KeyInput] 已加载：key=' + finalKey.substring(0,6) + '..., server=' + finalServer)
    },
    onUnload: function() {
        if (this._themeListener) { offThemeChange(this._themeListener); this._themeListener = null }
    },
    methods: {
        goBack: function() {
            uni.navigateBack({ delta: 1 })
        },
        autoFillWs: function() {
            var s = _clean(this.formServer)
            if (!/^https?:\/\//i.test(s)) return
            var ws = _clean(this.formWs)
            if (ws && /^wss?:\/\//i.test(ws)) return  // 用户已填就不覆盖
            this.formWs = s.replace(/^http/i, 'ws') + '/ws/client'
        },
        resetDefault: function() {
            var self = this
            uni.showModal({
                title: '恢复默认配置',
                content: '将 Key 和服务器地址重置为构建时内置的默认值？',
                success: function(r) {
                    if (!r.confirm) return
                    self.formKey = APP_CONFIG.default_key
                    self.formServer = APP_CONFIG.server_url
                    self.formWs = APP_CONFIG.ws_url
                    uni.setStorageSync(PUSH_KEY, self.formKey)
                    uni.setStorageSync(PUSH_SERVER_URL, self.formServer)
                    uni.setStorageSync(PUSH_WS_URL, self.formWs)
                    self.displayKey = self.formKey
                    self.displayServer = self.formServer
                    self.displayWs = self.formWs
                    uni.showToast({ title: '已恢复默认', icon: 'success' })
                }
            })
        },
        confirm: function() {
            var key = _clean(this.formKey)
            var serverUrl = _clean(this.formServer)
            var wsUrl = _clean(this.formWs)

            if (!key) { uni.showToast({ title: '请填写 Push Key', icon: 'none' }); return }
            if (key.length < 16) { uni.showToast({ title: 'Key 长度不足，请检查', icon: 'none' }); return }
            if (!/^https?:\/\//i.test(serverUrl)) {
                uni.showToast({ title: 'HTTP 地址需以 http(s):// 开头', icon: 'none' }); return
            }
            if (!wsUrl) {
                wsUrl = serverUrl.replace(/^http/i, 'ws') + '/ws/client'
                this.formWs = wsUrl
            }
            if (!/^wss?:\/\//i.test(wsUrl)) {
                uni.showToast({ title: 'WS 地址需以 ws(s):// 开头', icon: 'none' }); return
            }

            uni.setStorageSync(PUSH_KEY, key)
            uni.setStorageSync(PUSH_SERVER_URL, serverUrl)
            uni.setStorageSync(PUSH_WS_URL, wsUrl)
            this.displayKey = key
            this.displayServer = serverUrl
            this.displayWs = wsUrl
            uni.showToast({ title: '已保存，正在连接…', icon: 'none' })
            setTimeout(function() {
                try { uni.switchTab({ url: '/pages/home/index' }) }
                catch(e) { uni.navigateBack({ delta: 1 }) }
            }, 700)
        },
        testConnection: function() {
            var self = this
            var serverUrl = _clean(self.formServer)
            var key = _clean(self.formKey)
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

/* ================================================================
   APP-PLUS 原生 <input> 组件：
   1. 完全不认 CSS 变量（color: var(--xxx) = 透明！）→ 必须写具体色值
   2. 内联 style 也经常失效 → 必须用 class 样式
   3. 深色/浅色都写具体颜色，不依赖任何 var()
   ================================================================ */

/* 浅色主题（默认）：具体颜色值 */
.input-key,
.input-url {
    background: rgba(0, 0, 0, 0.04) !important;
    border: 1px solid rgba(0, 0, 0, 0.1) !important;
    border-radius: 14rpx !important;
    padding: 24rpx 30rpx !important;
    color: rgba(15, 23, 42, 0.95) !important;
    -webkit-text-fill-color: rgba(15, 23, 42, 0.95) !important;
    font-size: 28rpx !important;
    width: 100% !important;
    box-sizing: border-box !important;
    /* 关键：原生 input 高度 */
    min-height: 80rpx !important;
    line-height: 1.4 !important;
}

/* 深色主题覆盖（theme-dark class）：硬编码具体颜色 */
.theme-dark .input-key,
.theme-dark .input-url {
    background: rgba(255, 255, 255, 0.1) !important;
    border: 1px solid rgba(255, 255, 255, 0.2) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

/* flat 主题覆盖 */
.theme-flat .input-key,
.theme-flat .input-url {
    background: rgba(255, 255, 255, 0.1) !important;
    border: 1px solid rgba(255, 255, 255, 0.18) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

/* 占位符：同样具体色值，class 方式适配主题 */
.ph-style {
    color: rgba(15, 23, 42, 0.45) !important;
}
.theme-dark .ph-style,
.theme-flat .ph-style {
    color: rgba(255, 255, 255, 0.5) !important;
}
</style>
