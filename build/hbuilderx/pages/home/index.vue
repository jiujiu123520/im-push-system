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
                <button class="btn-ghost" style="flex:1;margin-right:16rpx;" @click="reconnect">重新连接</button>
                <button class="btn-ghost" style="flex:1;" @click="refreshData" :disabled="refreshing">{{ refreshing ? '刷新中…' : '刷新消息' }}</button>
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
import { loadBootConfig, PUSH_KEY, PUSH_WS_URL, PUSH_SERVER_URL, getMessages, addMessage } from '../../js/storage.js'
import { connect, reconnect, isConnected, getState, getLatency, on, off, probeChannel } from '../../js/ws.js'
import { notify } from '../../js/notify.js'
import { testPush as apiTestPush } from '../../js/api.js'
import { getTheme, applyTheme, onThemeChange, offThemeChange } from '../../js/theme.js'
import { applySafeArea } from '../../js/safe-area.js'
import { requestNotificationPerm } from '../../js/permissions.js'
import { getDeviceId } from '../../js/device-id.js'
import { updateKeepAliveStatus } from '../../js/keepalive.js'
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
// config.js 字段为空时保留硬编码兜底 + 剥离首尾反引号/引号/空白
Object.keys(_DEFAULT_CONFIG).forEach(function(k) {
    var v = APP_CONFIG[k]
    if (typeof v === 'string') v = v.replace(/^[\s`'"]+|[\s`'"]+$/g, '').trim()
    if (!v || v.length < 2 || /example\.com|placeholder/i.test(v)) {
        APP_CONFIG[k] = _DEFAULT_CONFIG[k]
    } else {
        APP_CONFIG[k] = v
    }
})

function _v(v, fb) {
    if (!v || typeof v !== 'string') return fb
    v = v.replace(/^[\s`'"]+|[\s`'"]+$/g, '').trim()  // 剥离反引号/引号/空白
    if (v.length < 2) return fb
    if (/example\.com|default_key|placeholder/i.test(v)) return fb
    return v
}

export default {
    data() {
        return {
            themeClass: 'theme-dark',
            appName: APP_CONFIG.app_name,
            wsState: '未连接',
            stateLabel: '离线',
            stateClass: 'status-bad',
            wsUrl: APP_CONFIG.ws_url,
            wsErrorMsg: '',
            keyValue: APP_CONFIG.default_key,
            latency: -1,
            recentMessages: [],
            // 🔴 新增：刷新功能状态
            refreshing: false,
            refreshTimer: null,
            lastRefreshTs: 0  // onShow 节流 5 秒
        }
    },
    computed: {
        canTest: function() { return !!this.keyValue }
    },
    onShow: function() {
        applySafeArea()
        var self = this
        self.themeClass = 'theme-' + getTheme()
        self._themeListener = function(t) { self.themeClass = 'theme-' + t }
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

        // 🔴 新增：60 秒自动刷新定时器（每次 onShow 重置，避免定时器漂移/堆积）
        //   老版本逻辑：每分钟补拉一次 HTTP 历史消息，应对 WS 假死/Doze 期间丢失的推送
        if (self.refreshTimer) { try { clearInterval(self.refreshTimer) } catch(_) {} }
        self.refreshTimer = setInterval(function() {
            try { self.refreshData() } catch(e) { console.warn('[Home] 定时刷新失败', e) }
        }, 60000)

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

        // 🔴 新增：onShow 节流调用 refreshData（5 秒内不重复）
        //   应对：杀进程/切后台再回来，先 HTTP 拉历史消息看有没有 WS 掉线时漏掉的推送
        var now = Date.now()
        if (!self.lastRefreshTs || now - self.lastRefreshTs > 5000) {
            self.lastRefreshTs = now
            try { self.refreshData() } catch(e) { console.warn('[Home] onShow 刷新失败', e) }
        }

        // 通知权限请求（老版时机：页面渲染完延迟 1 秒，此时 Activity 已 resumed，
        // ActivityCompat.requestPermissions 弹框才可靠；App.vue onShow 冷启动时太早会静默失败）
        setTimeout(function() {
            try { requestNotificationPerm() } catch(e) {}
        }, 1000)
    },
    onHide: function() {
        off('state', this._onState)
        off('message', this._onMessage)
        off('error', this._onError)
        off('latency', this._onLatency)
        // 🔴 新增：页面隐藏时清定时器（避免持续 HTTP 轮询耗电）
        if (this.refreshTimer) { try { clearInterval(this.refreshTimer) } catch(_) {}; this.refreshTimer = null }
    },
    onUnload: function() {
        off('state', this._onState)
        off('message', this._onMessage)
        off('error', this._onError)
        off('latency', this._onLatency)
        if (this._themeListener) { offThemeChange(this._themeListener); this._themeListener = null }
        // 🔴 新增：卸载时清定时器
        if (this.refreshTimer) { try { clearInterval(this.refreshTimer) } catch(_) {}; this.refreshTimer = null }
    },
    methods: {
        // 🔴 新增：把 WS URL 反转为 HTTP 基础地址（用户 99% 填的是面板 HTTP/HTTPS 地址）
        //   ws://host:port/ws/client   → http://host:port
        //   wss://host:port/ws/client  → https://host:port
        //   http://host 或 https://host → 原样
        _httpBaseFromWs: function(wsUrl) {
            if (!wsUrl) return ''
            var s = String(wsUrl).replace(/^[\s`'"]+|[\s`'"]+$/g, '').trim()
            s = s.replace(/\/+$/, '')
            // 先去掉 /ws/client 结尾或 /ws 结尾
            s = s.replace(/\/ws\/client$/i, '').replace(/\/ws$/i, '')
            // 协议反转
            if (/^wss:\/\//i.test(s)) {
                return 'https://' + s.substring(6)
            } else if (/^ws:\/\//i.test(s)) {
                return 'http://' + s.substring(5)
            }
            // 已经是 http/https 原样返回
            return s
        },

        // 🔴 新增：HTTP 拉取历史消息（补拉 WS 假死/Doze 期间丢失的推送）
        //   接口：GET /api/device/messages?push_key=xxx&device_id=xxx&limit=50
        refreshData: function() {
            var self = this
            if (self.refreshing) return Promise.resolve()
            var pushKey = self.keyValue
            var deviceId = getDeviceId()
            if (!pushKey || !deviceId) return Promise.resolve()

            // HTTP base：优先用 PUSH_SERVER_URL（用户在配置页填的 HTTP 面板地址），没有就用 WS 反推
            var httpBase = ''
            try {
                var boot = loadBootConfig() || {}
                httpBase = uni.getStorageSync(PUSH_SERVER_URL) || boot.server_url || ''
            } catch(_) {}
            if (!httpBase) httpBase = self._httpBaseFromWs(self.wsUrl)
            if (!httpBase) return Promise.resolve()

            self.refreshing = true
            var url = httpBase + '/api/device/messages'
                  + '?push_key=' + encodeURIComponent(pushKey)
                  + '&device_id=' + encodeURIComponent(deviceId)
                  + '&limit=50'
            return new Promise(function(resolve) {
                uni.request({
                    url: url,
                    method: 'GET',
                    timeout: 15000,
                    success: function(res) {
                        try {
                            var body = res.data || {}
                            if (body.code === 0 && body.data && Array.isArray(body.data.list)) {
                                var list = body.data.list || []
                                if (list.length > 0) {
                                    var newCnt = 0
                                    for (var i = 0; i < list.length; i++) {
                                        var raw = list[i]
                                        if (!raw || (!raw.title && !raw.content)) continue
                                        var ok = addMessage({
                                            id: String(raw.id || raw.message_id || ('msg-hist-' + Date.now() + '-' + i)),
                                            title: raw.title || '',
                                            content: raw.content || '',
                                            priority: raw.priority || 'default',
                                            timestamp: (function() {
                                                try {
                                                    if (raw.created_at) {
                                                        var t = new Date(String(raw.created_at).replace(/-/g, '/')).getTime()
                                                        if (t && t > 0) return t
                                                    }
                                                } catch(_) {}
                                                if (raw.timestamp) {
                                                    var n = Number(raw.timestamp)
                                                    return n > 1e12 ? n : n * 1000
                                                }
                                                return Date.now()
                                            })(),
                                            payload: raw.payload || null,
                                            read: raw.is_read === 1 || !!raw.read
                                        })
                                        if (ok) newCnt++
                                    }
                                    // 刷新列表
                                    self.recentMessages = getMessages().slice(0, 3)
                                    uni.showToast({
                                        title: '刷新完成，共' + list.length + '条（新增' + newCnt + '条）',
                                        icon: 'none',
                                        duration: 1800
                                    })
                                } else {
                                    uni.showToast({ title: '暂无新消息', icon: 'none', duration: 1500 })
                                }
                            } else {
                                var failMsg = body.message || body.msg || '刷新失败（code=' + body.code + '）'
                                uni.showToast({ title: failMsg, icon: 'none', duration: 2500 })
                            }
                        } catch(e) {
                            console.error('[Home] refreshData parse fail', e)
                            uni.showToast({ title: '刷新失败：数据解析异常', icon: 'none', duration: 2000 })
                        }
                    },
                    fail: function(err) {
                        console.warn('[Home] refreshData fail', JSON.stringify(err))
                        var hint = (err && err.errMsg) || '网络错误'
                        if (/timeout/i.test(hint)) hint = '请求超时，请检查服务器'
                        uni.showToast({ title: '刷新失败：' + hint, icon: 'none', duration: 2500 })
                    },
                    complete: function() {
                        self.refreshing = false
                        resolve()
                    }
                })
            })
        },

        _onState: function(s) {
            updateKeepAliveStatus(s === 'connected')
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
            var type = (err && err.type) || ''
            this.wsErrorMsg = msg
            // 🔴 降噪：自动重试类的临时错误（connect_timeout/auth_timeout/zombie_probe）
            //   不再弹 Toast——重连循环里会反复触发，之前每次都弹"连接超时"轰炸用户。
            //   状态栏本来就显示"重连中"，这类信息走 wsErrorMsg 即可；
            //   只有需要用户行动的错误（网络不可用/鉴权失败/放弃重连等）才弹 Toast
            if (type === 'connect_timeout' || type === 'auth_timeout' || type === 'zombie_probe') {
                console.warn('[Home] 自动重试类错误（不弹Toast）：', type, msg)
                return
            }
            // 同一条错误 5 秒内只弹一次（切页面回来/多入口重复触发时防轰炸）
            var now = Date.now()
            if (!this._lastErrToastTs || now - this._lastErrToastTs > 5000 || this._lastErrToastMsg !== msg) {
                this._lastErrToastTs = now
                this._lastErrToastMsg = msg
                uni.showToast({ title: msg, icon: 'none', duration: 2000 })
            }
        },
        _onMessage: function(msg) {
            // 关键修复：ws.js 收到 push 已经调了 showNotification()，这里不能再重复调 notify()
            // 否则会每条推送显示 2 次通知栏！
            this.recentMessages = getMessages().slice(0, 3)
        },
        testPush: function() {
            var cfg = loadBootConfig()
            var base = uni.getStorageSync(PUSH_SERVER_URL) || cfg.server_url
            if (!this.keyValue) { uni.showToast({ title: '请先配置 Key', icon: 'none' }); return }
            var deviceId = getDeviceId()
            var self = this

            // 🔴 第一步：先做同步通道探测（2秒），假连接直接弹具体提示+自动重连
            //   之前是直接 HTTP 发 → 等 4 秒 WS 回推，假连接时前后端各说各话，用户永远不知道为什么
            var beforeCount = getMessages().length
            probeChannel().then(function(probe) {
                if (!probe.ok) {
                    // 针对每种原因给出对应的、用户能读懂的中文提示 + 需要重连时自动触发
                    var reason = probe.reason || 'unknown'
                    var probeTitle = '⚠️ 通道未就绪'
                    var probeMsg = '通道未就绪，请稍等几秒后再试'
                    var autoTriggerReconnect = !!probe.needReconnect
                    switch (reason) {
                        case 'disconnected':
                            probeTitle = '⚠️ 未连接'
                            probeMsg = '当前未连接服务器，正在自动重连，约 10 秒后再测'
                            break
                        case 'connect_failed':
                            probeTitle = '⚠️ 连接失败'
                            probeMsg = '服务器连接失败，已触发自动重连，请 10 秒后再测'
                            break
                        case 'wait_connect_timeout':
                            probeTitle = '⚠️ 连接卡顿'
                            probeMsg = '连接服务器耗时过久（≥22s），已触发自动重连；若多次失败请检查服务器地址或网络'
                            break
                        case 'probe_timeout':
                            probeTitle = '⚠️ 通道探测失败'
                            probeMsg = 'WS假连接已检测（2秒无响应），已自动触发重连，请稍等10秒后再测'
                            break
                        default:
                            if (typeof reason === 'string' && reason.indexOf('send_fail') === 0) {
                                probeTitle = '⚠️ 探测失败'
                                probeMsg = '发送探测失败：已自动触发重连，请稍等再测'
                            }
                    }
                    // 如果 probeChannel 自己没触发重连（如 disconnected 场景只是没调用），在这里补触发
                    if (autoTriggerReconnect) {
                        try { reconnect() } catch (_) {}
                    }
                    try {
                        addMessage({
                            id: 'probe-fail-' + Date.now(),
                            title: probeTitle,
                            content: probeMsg,
                            priority: 'high',
                            timestamp: Date.now(),
                            read: false
                        })
                        try { notify(probeTitle, probeMsg, 'high') } catch (e) {}
                        self.recentMessages = getMessages().slice(0, 3)
                    } catch (_) {}
                    uni.showToast({ title: probeMsg.length > 18 ? probeMsg.slice(0, 16) + '…' : probeMsg, icon: 'none', duration: 2800 })
                    return
                }
                // 通道探测 OK → 再发 HTTP 自测推送
                return apiTestPush(base, self.keyValue, deviceId).then(function(r) {
                    // 🔴 第二步：立刻读 HTTP 返回的 online / success 字段，不再傻等 4 秒
                    //   selfTest 返回：{ online:bool, success:bool, message, elapsed_ms }
                    //   - online=false  → Redis 没有 fd（服务端视角离线），直接提示 + 建议重连
                    //   - online=true, success=false → 推送 dispatch 失败（fd 写失败等）
                    var isOnline = !!(r && r.online)
                    var isSuccess = !!(r && r.success)
                    var elapsed = (r && r.elapsed_ms) || 0

                    if (!isOnline) {
                        var offlineMsg = '⚠️ 服务端视角：设备离线（Redis无fd记录）。WS可能是假连接，已自动触发重连，请10秒后再测'
                        uni.showToast({ title: '服务端视角：设备离线', icon: 'none', duration: 2500 })
                        try {
                            addMessage({
                                id: 'self-offline-' + Date.now(),
                                title: '⚠️ 服务端视角：设备离线',
                                content: offlineMsg + '（接口耗时：' + elapsed + 'ms）',
                                priority: 'high',
                                timestamp: Date.now(),
                                read: false
                            })
                            try { notify('⚠️ 服务端视角：设备离线', offlineMsg, 'high') } catch (e) {}
                            self.recentMessages = getMessages().slice(0, 3)
                        } catch (_) {}
                        // 服务端视角离线 = 100% 假连接，立即重连
                        try { reconnect() } catch (_) {}
                        return
                    }

                    if (isOnline && !isSuccess) {
                        var pushFailMsg = '⚠️ 服务端在线但推送失败（fd写入失败），已触发自动重连，请10秒后再测'
                        uni.showToast({ title: '推送失败，正在重连', icon: 'none', duration: 2500 })
                        try {
                            addMessage({
                                id: 'self-pushfail-' + Date.now(),
                                title: '⚠️ 在线但推送失败',
                                content: pushFailMsg + '（接口耗时：' + elapsed + 'ms）',
                                priority: 'high',
                                timestamp: Date.now(),
                                read: false
                            })
                            try { notify('⚠️ 推送失败', pushFailMsg, 'high') } catch (e) {}
                            self.recentMessages = getMessages().slice(0, 3)
                        } catch (_) {}
                        try { reconnect() } catch (_) {}
                        return
                    }

                    // 🔴 online=true + success=true → 服务端成功推送，正常 Toast + 4秒兜底（极端慢网场景）
                    uni.showToast({ title: (r && r.message) || '测试推送已发送，请留意通知栏', icon: 'success' })
                    setTimeout(function() {
                        var afterCount = getMessages().length
                        if (afterCount <= beforeCount) {
                            try {
                                addMessage({
                                    id: 'test-timeout-' + Date.now(),
                                    title: '⚠️ 通道测试超时',
                                    content: '服务器已收到请求且返回成功，但 4 秒内未收到推送回包。可能是网络极慢或代理缓存，建议稍后再测。',
                                    priority: 'high',
                                    timestamp: Date.now(),
                                    read: false
                                })
                                try { notify('⚠️ 通道测试超时', '服务器返回成功但4秒内未收到回包，网络可能极慢或代理缓存', 'high') } catch (e) {}
                                self.recentMessages = getMessages().slice(0, 3)
                            } catch (e2) {}
                        }
                    }, 4000)
                })
            }).catch(function() {
                uni.showToast({ title: '测试推送已发送（无响应），本地模拟一条', icon: 'none' })
                try {
                    var testMsg = {
                        id: 'local-test-' + Date.now(),
                        title: '📣 测试推送',
                        content: '这是一条本地测试消息，如果通知栏能看到说明链路正常 ✅',
                        priority: 'high',
                        timestamp: Date.now(),
                        read: false
                    }
                    addMessage(testMsg)
                    self.recentMessages = getMessages().slice(0, 3)
                    try { notify(testMsg.title, testMsg.content, testMsg.priority) } catch (e) {}
                } catch (e2) {}
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
