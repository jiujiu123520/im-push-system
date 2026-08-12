<template>
    <view class="glass-bg">
        <view class="top-bar">
            <view class="row" style="margin-top:60rpx;">
                <text class="icon-btn" @click="goBack" style="font-size:36rpx;width:72rpx;height:72rpx;">‹</text>
                <text class="top-bar-title" style="margin-left:20rpx;">输入 Push Key</text>
            </view>
            <view class="top-bar-subtitle" style="margin-top:12rpx;">从后台 APP 详情页复制 Key 粘贴在这里</view>
        </view>

        <view class="glass-card" style="margin-top:80rpx;">
            <view style="font-size:26rpx;color:rgba(255,255,255,0.6);margin-bottom:16rpx;">Push Key</view>
            <input class="glass-input" placeholder="粘贴你的 Push Key" placeholder-style="color:rgba(255,255,255,0.4)" v-model="key" />

            <view style="font-size:26rpx;color:rgba(255,255,255,0.6);margin-bottom:16rpx;margin-top:32rpx;">服务器地址（可选，已预填）</view>
            <input class="glass-input" placeholder="https://api.example.com" placeholder-style="color:rgba(255,255,255,0.4)" v-model="serverUrl" />

            <view style="font-size:26rpx;color:rgba(255,255,255,0.6);margin-bottom:16rpx;margin-top:32rpx;">WebSocket 地址（可选）</view>
            <input class="glass-input" placeholder="wss://api.example.com/ws" placeholder-style="color:rgba(255,255,255,0.4)" v-model="wsUrl" />

            <button class="btn-primary" style="width:100%;margin-top:40rpx;" @click="confirm">确认并连接</button>
        </view>
    </view>
</template>

<script>
import { loadBootConfig, PUSH_KEY, PUSH_SERVER_URL, PUSH_WS_URL } from '../../js/storage.js'

export default {
    data() {
        return { key: '', serverUrl: '', wsUrl: '' }
    },
    onLoad: function() {
        var cfg = loadBootConfig()
        this.serverUrl = uni.getStorageSync(PUSH_SERVER_URL) || cfg.server_url || ''
        this.wsUrl = uni.getStorageSync(PUSH_WS_URL) || cfg.ws_url || ''
    },
    methods: {
        goBack: function() { uni.navigateBack({ delta: 1 }) },
        confirm: function() {
            if (!this.key) { uni.showToast({ title: '请填写 Key', icon: 'none' }); return }
            var cfg = loadBootConfig()
            if (!this.serverUrl) this.serverUrl = cfg.server_url || ''
            if (!this.wsUrl) this.wsUrl = cfg.ws_url || ''
            uni.setStorageSync(PUSH_KEY, this.key)
            uni.setStorageSync(PUSH_SERVER_URL, this.serverUrl)
            uni.setStorageSync(PUSH_WS_URL, this.wsUrl)
            uni.showToast({ title: '已保存，正在连接…', icon: 'none' })
            setTimeout(function(){ uni.switchTab({ url: '/pages/home/index' }) }, 800)
        }
    }
}
</script>
