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
            <input class="glass-input" placeholder="粘贴你的 Push Key" placeholder-style="color:rgba(255,255,255,0.4)" v-model="key" />

            <view style="font-size:28rpx;font-weight:600;margin-bottom:20rpx;">🌐 HTTP 服务器地址</view>
            <input class="glass-input" placeholder="https://push.example.com" placeholder-style="color:rgba(255,255,255,0.4)" v-model="serverUrl" />

            <view style="font-size:28rpx;font-weight:600;margin-bottom:20rpx;">🔗 WebSocket 地址</view>
            <input class="glass-input" placeholder="wss://push.example.com/ws" placeholder-style="color:rgba(255,255,255,0.4)" v-model="wsUrl" />

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

        <view class="glass-card" style="margin-top:32rpx;margin-bottom:80rpx;">
            <view style="font-size:28rpx;font-weight:600;margin-bottom:8rpx;">👤 账号登录</view>
            <view style="font-size:24rpx;opacity:0.6;margin-bottom:20rpx;">邮箱/密码登录（可选，用于消息管理）</view>
            <input class="glass-input" placeholder="邮箱" placeholder-style="color:rgba(255,255,255,0.4)" v-model="email" type="email" />
            <input class="glass-input" placeholder="密码" placeholder-style="color:rgba(255,255,255,0.4)" v-model="password" password style="margin-top:20rpx;" />
            <button class="btn-ghost" style="width:100%;margin-top:28rpx;" @click="doLogin" :disabled="loggingIn">
                {{ loggingIn ? '登录中…' : '登录' }}
            </button>
            <view v-if="loginResult" style="margin-top:16rpx;font-size:24rpx;padding:16rpx;border-radius:12rpx;"
                  :style="{ background: loginResult.ok ? 'rgba(34,197,94,0.15)' : 'rgba(239,68,68,0.15)', color: loginResult.ok ? '#22c55e' : '#ef4444' }">
                {{ loginResult.message }}
            </view>
            <view v-if="isLoggedIn" style="margin-top:16rpx;font-size:22rpx;opacity:0.7;">已登录：{{ userId }}</view>
        </view>
    </view>
</template>

<script>
import { loadBootConfig, PUSH_KEY, PUSH_SERVER_URL, PUSH_WS_URL, PUSH_USER_ID, PUSH_USER_TOKEN } from '../../js/storage.js'
import { login, testPush } from '../../js/api.js'
import { getTheme, onThemeChange, offThemeChange } from '../../js/theme.js'
import { applySafeArea } from '../../js/safe-area.js'

export default {
    data() {
        return {
            themeClass: 'dark',
            key: '',
            serverUrl: '',
            wsUrl: '',
            email: '',
            password: '',
            testing: false,
            testResult: null,
            loggingIn: false,
            loginResult: null,
            isLoggedIn: false,
            userId: ''
        }
    },
    onShow: function() {
        applySafeArea()
        var self = this
        self.themeClass = getTheme()
        onThemeChange(function(t) { self.themeClass = t })
        var cfg = loadBootConfig()
        self.key = uni.getStorageSync(PUSH_KEY) || ''
        self.serverUrl = uni.getStorageSync(PUSH_SERVER_URL) || cfg.server_url || ''
        self.wsUrl = uni.getStorageSync(PUSH_WS_URL) || cfg.ws_url || ''
        var token = uni.getStorageSync(PUSH_USER_TOKEN)
        var uid = uni.getStorageSync(PUSH_USER_ID)
        self.isLoggedIn = !!(token && uid)
        self.userId = uid || ''
    },
    onUnload: function() {
        offThemeChange()
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
            testPush(self.serverUrl, self.key).then(function(res) {
                self.testing = false
                self.testResult = { ok: true, message: '✅ 连接成功！服务器响应正常。' }
            }).catch(function(err) {
                self.testing = false
                var msg = (err && err.data && err.data.message) || (err && err.message) || '连接失败，请检查地址和 Key'
                self.testResult = { ok: false, message: '❌ ' + msg }
            })
        },
        doLogin: function() {
            var self = this
            if (!self.serverUrl) {
                self.loginResult = { ok: false, message: '请先填写服务器地址' }
                return
            }
            if (!self.email || !self.password) {
                self.loginResult = { ok: false, message: '请填写邮箱和密码' }
                return
            }
            self.loggingIn = true
            self.loginResult = null
            login(self.serverUrl, self.email, self.password).then(function(res) {
                self.loggingIn = false
                var token = (res && res.data && res.data.token) || (res && res.token) || ''
                var uid = (res && res.data && (res.data.user_id || res.data.id)) || (res && res.user_id) || (res && res.id) || ''
                if (!token) {
                    self.loginResult = { ok: false, message: '服务器未返回 token' }
                    return
                }
                uni.setStorageSync(PUSH_USER_TOKEN, token)
                if (uid) uni.setStorageSync(PUSH_USER_ID, String(uid))
                self.isLoggedIn = true
                self.userId = String(uid || self.email)
                self.loginResult = { ok: true, message: '✅ 登录成功' }
            }).catch(function(err) {
                self.loggingIn = false
                var msg = (err && err.data && err.data.message) || (err && err.message) || '登录失败'
                self.loginResult = { ok: false, message: '❌ ' + msg }
            })
        }
    }
}
</script>
