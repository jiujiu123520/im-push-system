<template>
    <view class="container">
        <!-- 登录页面 -->
        <view class="login-page">
            <view class="login-container">
                <view class="logo-section">
                    <view class="logo-icon">
                        <text class="logo-text">📨</text>
                    </view>
                    <text class="app-title">消息推送</text>
                    <text class="app-subtitle">即时消息推送客户端</text>
                </view>
                <view class="login-form">
                    <view class="form-group">
                        <text class="form-label">推送 Key</text>
                        <input class="form-input" v-model="form.key" placeholder="请输入推送 Key" />
                    </view>
                    <view class="form-group">
                        <text class="form-label">服务器地址</text>
                        <input class="form-input" v-model="form.serverUrl" placeholder="http://example.com:9501" />
                    </view>
                    <view class="form-group">
                        <text class="form-label">WebSocket 地址</text>
                        <input class="form-input" v-model="form.wsUrl" placeholder="留空则自动从服务器地址推导" />
                    </view>
                    <button class="btn-primary" @click="handleLogin">
                        进入应用
                    </button>
                </view>
            </view>
        </view>
    </view>
</template>

<script>
import { APP_CONFIG } from '@/config.js'

export default {
    data() {
        return {
            form: {
                key: '',
                serverUrl: '',
                wsUrl: ''
            }
        }
    },
    onLoad() {
        // 已登录则直接跳转到主页面
        const savedKey = uni.getStorageSync('push_key')
        const savedServer = uni.getStorageSync('push_server')
        if (savedKey && savedServer) {
            uni.redirectTo({ url: '/pages/home/index' })
            return
        }
        // 加载默认配置
        this.form.key = APP_CONFIG.default_key
        this.form.serverUrl = APP_CONFIG.server_url
        this.form.wsUrl = ''
    },
    methods: {
        deriveWsUrl(serverUrl) {
            let protocol = 'ws://'
            let hostPart = serverUrl
            if (serverUrl.startsWith('https://')) {
                protocol = 'wss://'
                hostPart = serverUrl.substring(8)
            } else if (serverUrl.startsWith('http://')) {
                protocol = 'ws://'
                hostPart = serverUrl.substring(7)
            }
            const colonIndex = hostPart.indexOf(':')
            const slashIndex = hostPart.indexOf('/')
            let host = hostPart
            let port = ''
            let path = ''
            if (slashIndex !== -1) {
                path = hostPart.substring(slashIndex)
                host = hostPart.substring(0, slashIndex)
            }
            if (colonIndex !== -1 && (slashIndex === -1 || colonIndex < slashIndex)) {
                host = hostPart.substring(0, colonIndex)
                port = hostPart.substring(colonIndex + 1, slashIndex !== -1 ? slashIndex : undefined)
            }
            const wsPortMap = {
                '80': '80',
                '443': '443',
                '9501': '9502',
                '6999': '7000'
            }
            if (port && wsPortMap[port]) {
                port = wsPortMap[port]
            } else if (port) {
                port = String(parseInt(port) + 1)
            }
            return protocol + host + (port ? ':' + port : '') + path
        },
        handleLogin() {
            if (!this.form.key.trim()) {
                uni.showToast({ title: '请输入推送 Key', icon: 'none' })
                return
            }
            if (!this.form.serverUrl.trim()) {
                uni.showToast({ title: '请输入服务器地址', icon: 'none' })
                return
            }

            const key = this.form.key.trim()
            const serverUrl = this.form.serverUrl.trim()
            const inputWs = (this.form.wsUrl || '').trim()
            const wsUrl = inputWs || this.deriveWsUrl(serverUrl)

            uni.setStorageSync('push_key', key)
            uni.setStorageSync('push_server', serverUrl)
            uni.setStorageSync('push_ws', wsUrl)

            // 跳转到主页面
            uni.redirectTo({ url: '/pages/home/index' })
        }
    }
}
</script>

<style scoped>
.container {
    min-height: 100vh;
    background-color: #f5f7fa;
}

/* 登录页面 */
.login-page {
    min-height: 100vh;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 24px;
    box-sizing: border-box;
}

.login-container {
    width: 100%;
    max-width: 400px;
}

.logo-section {
    text-align: center;
    margin-bottom: 48px;
}

.logo-icon {
    width: 80px;
    height: 80px;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.logo-text {
    font-size: 60px;
}

.app-title {
    font-size: 28px;
    font-weight: 600;
    color: #ffffff;
    display: block;
    margin-bottom: 8px;
}

.app-subtitle {
    font-size: 14px;
    color: rgba(255, 255, 255, 0.8);
    display: block;
}

.login-form {
    background: white;
    border-radius: 16px;
    padding: 32px 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-size: 13px;
    color: #666;
    margin-bottom: 8px;
    font-weight: 500;
}

.form-input {
    width: 100%;
    height: 44px;
    padding: 0 16px;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    font-size: 14px;
    color: #333;
    background: #f9f9f9;
    box-sizing: border-box;
}

.btn-primary {
    width: 100%;
    height: 48px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 8px;
    font-size: 16px;
    font-weight: 500;
    margin-top: 10px;
}
</style>
