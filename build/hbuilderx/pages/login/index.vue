<template>
    <view :class="['glass-bg', themeClass]">
        <view class="top-bar">
            <view class="text-primary" style="font-size:56rpx;font-weight:800;letter-spacing:-1rpx;margin-top:40rpx;">PushApp</view>
            <view class="text-secondary" style="margin-top:8rpx;font-size:28rpx;">玻璃拟态版 · 即时推送</view>
        </view>

        <view style="margin-top:120rpx;">
            <view class="glass-card" style="padding-top:48rpx;padding-bottom:48rpx;">
                <view style="font-size:34rpx;font-weight:600;margin-bottom:32rpx;">登录账号</view>
                <view style="margin-bottom:24rpx;">
                    <input class="glass-input" type="text" placeholder="邮箱" placeholder-style="color:rgba(255,255,255,0.4)" v-model="email" />
                </view>
                <view style="margin-bottom:32rpx;">
                    <input class="glass-input" type="password" placeholder="密码" placeholder-style="color:rgba(255,255,255,0.4)" v-model="password" />
                </view>
                <button class="btn-primary" style="width:100%;margin-top:16rpx;" @click="doLogin">登 录</button>
                <view style="text-align:center;margin-top:24rpx;font-size:26rpx;">
                    <text class="text-muted">还没有账号？</text>
                    <text class="text-accent" @click="doRegister">立即注册</text>
                </view>
            </view>

            <view class="text-muted" style="text-align:center;margin-top:60rpx;font-size:24rpx;">
                或使用 Push Key 快速开始 →
            </view>
            <button class="btn-ghost" style="display:block;width:calc(100% - 48rpx);margin:20rpx 24rpx;" @click="goKeyInput">使用 Key 进入</button>
        </view>
    </view>
</template>

<script>
import { loadBootConfig, PUSH_KEY, PUSH_USER_TOKEN, PUSH_USER_ID } from '../../js/storage.js'
import { login as apiLogin } from '../../js/api.js'
import { getTheme, onThemeChange, offThemeChange } from '../../js/theme.js'

export default {
    data() {
        return {
            themeClass: 'dark',
            email: '',
            password: '',
            loading: false
        }
    },
    onShow: function() {
        var self = this
        self.themeClass = getTheme()
        onThemeChange(function(t) { self.themeClass = t })
        var key = uni.getStorageSync(PUSH_KEY)
        if (key) {
            uni.switchTab({ url: '/pages/home/index' })
        }
    },
    onUnload: function() {
        offThemeChange()
    },
    methods: {
        doLogin: function() {
            var self = this
            if (!self.email || !self.password) {
                uni.showToast({ title: '请填写完整', icon: 'none' }); return
            }
            self.loading = true
            var cfg = loadBootConfig()
            apiLogin(cfg.server_url, self.email, self.password).then(function(res) {
                self.loading = false
                if (res && res.code === 0) {
                    uni.setStorageSync(PUSH_USER_TOKEN, (res.data && res.data.token) || res.token || '')
                    var uid = (res.data && (res.data.user_id || res.data.id)) || res.user_id || res.id || ''
                    if (uid) uni.setStorageSync(PUSH_USER_ID, String(uid))
                    if (res.data && res.data.push_key) uni.setStorageSync(PUSH_KEY, res.data.push_key)
                    uni.showToast({ title: '登录成功', icon: 'success' })
                    setTimeout(function(){ uni.switchTab({ url: '/pages/home/index' }) }, 600)
                } else {
                    uni.showToast({ title: (res && res.message) || '登录失败', icon: 'none' })
                }
            }).catch(function(err) {
                self.loading = false
                uni.showToast({ title: (err && err.message) || '网络错误', icon: 'none' })
            })
        },
        goKeyInput: function() {
            uni.navigateTo({ url: '/pages/key-input/index' })
        },
        doRegister: function() {
            uni.showToast({ title: '注册功能请在网页端完成', icon: 'none' })
        }
    }
}
</script>
