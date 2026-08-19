import { addMessage, PUSH_HEARTBEAT, PUSH_AUTO_RECONNECT, PUSH_WIFI_ONLY } from './storage.js'
import * as _cfg from '../config.js'
import { getDeviceId } from './device-id.js'
import { setAlarmHandler, setScreenPingCallback } from './keepalive.js'

// 通知显示：使用 require + try/catch 内联 fallback，避免 ESM 静态 import 在 APP 端静默失败
// （项目 memory 明确指出：uni-app APP-PLUS ESM 静态 import 失败时不会报错，直接整段脚本挂）
function _showPushNotification(title, content, priority) {
    try {
        const notifyMod = require('./notify.js')
        if (notifyMod && typeof notifyMod.showNotification === 'function') {
            notifyMod.showNotification(title, content, { priority: priority || 'default' })
            return
        }
        if (notifyMod && typeof notifyMod.notify === 'function') {
            notifyMod.notify(title, content, priority || 'default')
            return
        }
    } catch (e) {
        console.warn('[Ws] require notify.js 失败，尝试 uni.showNotification', e)
    }
    // 终极兜底：H5/通用 uni API
    try {
        if (uni && uni.showNotification) {
            uni.showNotification({ title: title || '新消息', content: content || '' })
            return
        }
    } catch (_) {}
    try {
        uni.showToast({
            title: (title || '新消息') + (content ? '：' + (content.length > 20 ? content.slice(0, 20) + '…' : content) : ''),
            icon: 'none',
            duration: 2500
        })
    } catch (_) {}
    try { if (uni.vibrateShort) uni.vibrateShort({ type: 'heavy' }) } catch (_) {}
}

// ============================================================
// 保活回调 1：AlarmManager 15 秒闹钟唤醒时回调（移植老版策略）
//   已连接 → 立即发 ping 保活；断开 → 触发重连
// ============================================================
setAlarmHandler(function() {
    if (state === 'connected') {
        try {
            uni.sendSocketMessage({ data: JSON.stringify({ type: 'ping', ts: Date.now() }) })
            console.log('[Ws] ⏰ alarm heartbeat sent')
        } catch(e) {
            console.warn('[Ws] alarm heartbeat send fail', e)
            _onSocketLost()
        }
    } else if (shouldReconnect && currentUrl && currentKey) {
        console.log('[Ws] ⏰ alarm trigger reconnect')
        if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null }
        reconnectAttempts = 0
        _doConnect()
    }
})

// ============================================================
// 保活回调 2：SCREEN_ON 亮屏后验证 WS 连接（移植老版策略）
// keepalive.js 中 SCREEN_ON 广播接收器会调用这 3 个回调：
//   sendPing()    → 发送一个 ping 看服务端是否还活着
//   isConnected() → 当前是否在 connected 状态
//   reconnect()   → 清理 socket 并重新连接
// 如果 5 秒内没收到 pong，也会触发 reconnect
// ============================================================
try {
    setScreenPingCallback(
        // sendPing 回调
        function sendPingCb() {
            if (state !== 'connected') return
            try {
                pendingPingAt = Date.now()
                uni.sendSocketMessage({ data: JSON.stringify({ type: 'ping', ts: Date.now() }) })
            } catch (e) {
                console.warn('[Ws] screen ping send fail, will reconnect', e)
                throw e  // 抛出会触发 keepalive 直接 reconnect
            }
        },
        // isConnected 回调
        function isConnectedCb() {
            return state === 'connected'
        },
        // cleanupAndReconnect 回调
        function reconnectCb() {
            console.log('[Ws] 🔌 screen reconnect triggered')
            pendingPongs = 0
            if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null }
            reconnectAttempts = 0
            shouldReconnect = true
            autoReconnect = true
            _closeSocket()
            state = 'disconnected'
            events.emit('state', state)
            _doConnect()
        }
    )
    console.log('[Ws] setScreenPingCallback 已注册（亮屏验证 WS 连接）')
} catch (e) {
    console.warn('[Ws] setScreenPingCallback 注册失败', e)
}

const _appVersion = (_cfg && _cfg.APP_CONFIG && _cfg.APP_CONFIG.version_name) || '1.0.0'

const events = {
    _handlers: {},
    on(evt, cb) {
        if (!this._handlers[evt]) this._handlers[evt] = []
        this._handlers[evt].push(cb)
    },
    emit(evt, data) {
        const arr = this._handlers[evt] || []
        for (let i = 0; i < arr.length; i++) {
            try { arr[i](data) } catch(e) { console.error('[Ws] handler error', e) }
        }
    },
    off(evt, cb) {
        if (!this._handlers[evt]) return
        if (!cb) { delete this._handlers[evt]; return }
        this._handlers[evt] = this._handlers[evt].filter(h => h !== cb)
    }
}

const MAX_MISSED_PONG = 3
const RECONNECT_BASE = 1000
const RECONNECT_MAX = 60000
const AUTH_TIMEOUT_MS = 10000  // 🔴 新增：客户端 auth 主动超时（10秒），不用等服务器30秒

let state = 'disconnected'
let socketTask = null
let heartbeatTimer = null
let reconnectTimer = null
let authTimeoutTimer = null  // 🔴 新增：鉴权超时定时器
let reconnectAttempts = 0
let pendingPongs = 0
let latency = -1
let pendingPingAt = 0
let currentUrl = ''
let currentKey = ''
let heartbeatInterval = 30
let autoReconnect = true
let wifiOnly = false
let shouldReconnect = false
let listenersRegistered = false
let lostProcessing = false

function registerListeners() {
    if (listenersRegistered) return
    listenersRegistered = true

    uni.onSocketOpen(() => {
        console.log('[Ws] onSocketOpen → 发送鉴权')
        const auth = _buildAuth()
        try { uni.sendSocketMessage({ data: JSON.stringify(auth) }) } catch(e) {
            console.error('[Ws] 发送 auth 失败', e)
            _onSocketLost()
            return
        }
        // 🔴 关键修复4：客户端主动鉴权超时（10秒）
        //   原服务器 pendingAuthTable 定时器是 30 秒，客户端等不了这么久 → 用户看到"一直卡连接中"
        //   现在客户端 10 秒没收到 auth_result 就主动关闭 + 重连
        if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
        authTimeoutTimer = setTimeout(() => {
            console.warn('[Ws] ⏰ 鉴权超时（10秒未收到 auth_result）→ 主动断开并重连')
            authTimeoutTimer = null
            events.emit('error', { type: 'auth_timeout', message: '鉴权超时：服务器10秒未响应，正在重试' })
            try { _closeSocket() } catch (_) {}
            _onSocketLost()
        }, AUTH_TIMEOUT_MS)
    })

    uni.onSocketMessage((res) => { _handleMessage(res.data) })

    uni.onSocketError((err) => {
        console.error('[Ws] onSocketError', JSON.stringify(err))
        if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
        _onSocketLost()
    })

    uni.onSocketClose((res) => {
        console.log('[Ws] onSocketClose', JSON.stringify(res || {}))
        if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
        _onSocketLost()
    })
}

function _normalizeWsUrl(url) {
    if (!url) return url
    // 关键修复1：先剥离首尾反引号/引号/空白（用户从文档复制地址时 markdown 装饰字符会混入）
    var cleaned = String(url).replace(/^[\s`'"]+|[\s`'"]+$/g, '').trim()
    cleaned = cleaned.replace(/\/+$/, '')

    // 🔴 关键修复2：HTTPS 支持！协议自动转换（99% 用户填的是面板 HTTP/HTTPS 地址，不会写 ws/wss）
    //   http://host   → ws://host
    //   https://host  → wss://host  (SSL/TLS 加密)
    //   ws://host     → 不变
    //   wss://host    → 不变
    if (/^https:\/\//i.test(cleaned)) {
        cleaned = 'wss://' + cleaned.substring(8)
        console.log('[Ws] 协议自动转换：HTTPS → WSS（加密 WebSocket）')
    } else if (/^http:\/\//i.test(cleaned)) {
        cleaned = 'ws://' + cleaned.substring(7)
        console.log('[Ws] 协议自动转换：HTTP → WS（明文 WebSocket）')
    }

    // 如果已经明确带 /ws 或 /ws/client 结尾，直接返回
    if (/\/(ws|ws\/client)$/i.test(cleaned)) return cleaned

    // 没有带 path 的情况下（只有 host[:port]），默认补上 /ws/client
    var afterProto = cleaned.replace(/^wss?:\/\//, '')
    if (afterProto.indexOf('/') === -1) return cleaned + '/ws/client'

    return cleaned
}

function _cleanAuthKey(key) {
    if (!key) return key
    return String(key).replace(/^[\s`'"]+|[\s`'"]+$/g, '').trim()
}

export function connect(url, key) {
    if (!url || !key) {
        const reason = !url ? 'no_server' : 'no_key'
        console.warn('[Ws] url or key empty, emit error', reason)
        state = 'error'
        events.emit('state', state)
        events.emit('error', { type: reason, message: !url ? '未配置服务器地址' : '未配置推送 Key' })
        return
    }
    currentUrl = _normalizeWsUrl(url)
    if (currentUrl !== url) console.log('[Ws] normalized ws url:', url, '→', currentUrl)
    currentKey = _cleanAuthKey(key)
    shouldReconnect = true
    reconnectAttempts = 0
    _loadSettings()

    registerListeners()
    _closeSocket()
    _doConnect()
}

function _loadSettings() {
    try {
        const hb = parseInt(uni.getStorageSync(PUSH_HEARTBEAT))
        heartbeatInterval = (hb > 0 && hb <= 3600) ? hb : 30
        const ar = uni.getStorageSync(PUSH_AUTO_RECONNECT)
        autoReconnect = ar === '' || ar === null || ar === undefined ? true : (ar !== false && ar !== 0)
        wifiOnly = uni.getStorageSync(PUSH_WIFI_ONLY) === true
    } catch(e) {
        heartbeatInterval = 30
        autoReconnect = true
        wifiOnly = false
    }
}

function _doConnect() {
    if (wifiOnly) {
        uni.getNetworkType({
            success: function(res) {
                if (res.networkType !== 'wifi') {
                    console.warn('[Ws] wifiOnly=true but network is', res.networkType, '→ block connect')
                    state = 'error'
                    events.emit('state', state)
                    events.emit('error', {
                        type: 'wifi_only',
                        message: '仅 Wi-Fi 模式已开启，当前使用的是 ' + _networkLabel(res.networkType) + '，请切到 Wi-Fi 或关闭该选项'
                    })
                } else {
                    _actuallyConnect()
                }
            },
            fail: function() { _actuallyConnect() }
        })
        return
    }
    _actuallyConnect()
}

function _actuallyConnect() {
    state = 'connecting'
    events.emit('state', state)
    console.log('[Ws] connecting →', currentUrl)

    try {
        socketTask = uni.connectSocket({
            url: currentUrl,
            success: () => console.log('[Ws] connectSocket success callback'),
            fail: (err) => {
                console.error('[Ws] connectSocket fail', JSON.stringify(err))
                socketTask = null
                _onSocketLost()
            }
        })
    } catch(e) {
        console.error('[Ws] connectSocket exception', e)
        socketTask = null
        _onSocketLost()
    }
}

function _closeSocket() {
    _stopHeartbeat()
    if (socketTask) {
        try { socketTask.close({}) } catch(e) {}
        socketTask = null
    } else {
        try { uni.closeSocket() } catch(e) {}
    }
}

function _normalizeTs(ts) {
    if (!ts) return Date.now()
    var n = Number(ts)
    if (!n || n <= 0) return Date.now()
    if (n < 1e12) return n * 1000
    return n
}

function _handleMessage(text) {
    let env
    try { env = JSON.parse(text) } catch(e) {
        console.log('[Ws] non-JSON message:', text ? String(text).slice(0, 80) : '(empty)')
        return
    }

    const t = env.type || (env.message === 'pong' ? 'pong' : null)

    if (t === 'auth_result') {
        // 收到任何 auth_result → 清除客户端鉴权超时定时器
        if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
        if (env.success || env.code === 0) {
            console.log('[Ws] ✅ auth ok')
            reconnectAttempts = 0
            state = 'connected'
            events.emit('state', state)
            _startHeartbeat()
        } else {
            const failMsg = env.message || env.msg || '鉴权失败'
            console.warn('[Ws] ❌ auth failed:', failMsg)

            // 🔴 关键修复3：只有永久失败（Key 无效/拉黑/设备拉黑/IP 拉黑/指纹拉黑）才关重连
            //   临时错误（数据库异常/服务器内部错/数量上限）仍然允许重连（用户删设备后自动恢复）
            //   避免用户必须杀进程重启才能再次尝试连接
            const isPermanentFail =
                /推送 Key 无效|Key 无效|已禁用|已被拉黑|设备数量已达上限|缺少 key 或 device_id|凭证无效/i.test(failMsg)

            if (isPermanentFail) {
                shouldReconnect = false
                console.warn('[Ws] 永久失败，不再自动重连：', failMsg)
            } else {
                console.warn('[Ws] 临时失败，保留重连开关（30秒后重试）')
            }

            _closeSocket()
            state = 'error'
            events.emit('state', state)
            events.emit('error', { type: 'auth_fail', message: failMsg, permanent: isPermanentFail })

            // 临时失败 → 延迟 30 秒后尝试重连（指数退避也在重连流程内）
            if (!isPermanentFail) {
                reconnectAttempts = 0
                _scheduleReconnect()
            }
        }
        return
    }

    if (t === 'pong') {
        pendingPongs = 0
        const recvTs = Date.now()
        const remoteTs = typeof env.ts === 'number' ? env.ts : null
        if (remoteTs && remoteTs > 0 && recvTs >= remoteTs) {
            latency = recvTs - remoteTs
            events.emit('latency', latency)
        } else if (pendingPingAt > 0) {
            latency = recvTs - pendingPingAt
            pendingPingAt = 0
            events.emit('latency', latency)
        }
        return
    }

    if (t === 'ping') {
        try { uni.sendSocketMessage({ data: JSON.stringify({ type: 'pong' }) }) } catch(e) {}
        return
    }

    if (t === 'push') {
        const msg = {
            id: env.id || _uuid(),
            title: env.title || '',
            content: env.content || '',
            priority: env.priority || 'default',
            timestamp: _normalizeTs(env.timestamp)
        }
        addMessage(msg)
        // 关键修复：**收到推送立刻弹通知栏**，不依赖首页监听是否激活
        // 旧版逻辑：home.vue on('message') → notify()，但 APP 后台时首页 off 了所有监听，
        // 导致推送只存 storage 永远不显示在通知栏/锁屏！
        try { _showPushNotification(msg.title, msg.content, msg.priority) } catch (e) {
            console.error('[Ws] 显示推送通知失败', e)
        }
        events.emit('message', msg)
        return
    }

    if (env.code === 0 || env.code === -1) {
        const title = (env.data && env.data.title) || env.title || ''
        const content = (env.data && env.data.content) || env.content || ''
        if (title || content) {
            const m = {
                id: (env.data && env.data.message_id) || _uuid(),
                title, content,
                priority: (env.data && env.data.priority) || 'default',
                timestamp: _normalizeTs(env.data && env.data.timestamp)
            }
            addMessage(m)
            // 同样：通用格式推送也立刻弹通知栏
            try { _showPushNotification(m.title, m.content, m.priority) } catch (e) {
                console.error('[Ws] 显示通用格式推送通知失败', e)
            }
            events.emit('message', m)
        }
    }
}

function _startHeartbeat() {
    _stopHeartbeat()
    pendingPongs = 0
    const intervalMs = Math.max(5000, heartbeatInterval * 1000)
    heartbeatTimer = setInterval(() => {
        if (state !== 'connected' && state !== 'connecting') return
        try {
            pendingPingAt = Date.now()
            uni.sendSocketMessage({ data: JSON.stringify({ type: 'ping', ts: Date.now() }) })
            pendingPongs++
            if (pendingPongs >= MAX_MISSED_PONG) {
                console.warn('[Ws] ❤️ heartbeat timeout, close and reconnect')
                pendingPongs = 0
                _closeSocket()
            }
        } catch(e) {
            console.warn('[Ws] heartbeat send fail', e)
            _closeSocket()
        }
    }, intervalMs)
}

function _stopHeartbeat() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null }
}

function _onSocketLost() {
    if (lostProcessing) return
    lostProcessing = true
    _stopHeartbeat()
    socketTask = null
    if (state === 'disconnected' || state === 'error') {
        lostProcessing = false
        return
    }
    state = 'disconnected'
    events.emit('state', state)
    console.log('[Ws] socket lost, shouldReconnect=', shouldReconnect, 'autoReconnect=', autoReconnect, 'attempt=', reconnectAttempts)
    lostProcessing = false
    if (!shouldReconnect || !autoReconnect) return
    if (reconnectAttempts >= 10) {
        console.warn('[Ws] too many reconnect attempts (' + reconnectAttempts + '), giving up')
        shouldReconnect = false
        state = 'error'
        events.emit('state', state)
        events.emit('error', { type: 'max_reconnect', message: '连接失败次数过多，请检查网络或手动重连' })
        return
    }
    _scheduleReconnect()
}

function _scheduleReconnect() {
    if (!shouldReconnect || !autoReconnect) return
    reconnectAttempts = Math.min(reconnectAttempts + 1, 10)
    const shift = Math.min(reconnectAttempts - 1, 5)
    let delay = RECONNECT_BASE * (1 << shift)
    delay = Math.max(RECONNECT_BASE, Math.min(RECONNECT_MAX, delay))
    const jitter = Math.round(delay * 0.2 * (Math.random() * 2 - 1))
    delay = Math.max(500, delay + jitter)
    state = 'reconnecting'
    events.emit('state', state)
    console.log('[Ws] 🔄 reconnect attempt=' + reconnectAttempts + ' delay=' + delay + 'ms')
    if (reconnectTimer) clearTimeout(reconnectTimer)
    reconnectTimer = setTimeout(() => {
        if (shouldReconnect && autoReconnect) _doConnect()
    }, delay)
}

export function disconnect() {
    shouldReconnect = false
    autoReconnect = false
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null }
    _closeSocket()
    latency = -1
    state = 'disconnected'
    events.emit('state', state)
}

export function reconnect() {
    shouldReconnect = true
    autoReconnect = true
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null }
    if (state === 'connected' || state === 'connecting' || state === 'reconnecting') {
        _closeSocket()
        state = 'disconnected'
    }
    reconnectAttempts = 0
    _scheduleReconnect()
}

export function applySettings() {
    _loadSettings()
    if (state === 'connected' || state === 'connecting') {
        _startHeartbeat()
        const auth = _buildAuth()
        try { uni.sendSocketMessage({ data: JSON.stringify(auth) }) } catch(e) {}
    }
}

export function getHeartbeatInterval() { return heartbeatInterval }

export function isConnected() { return state === 'connected' }
export function getState() { return state }
export function getLatency() { return latency }
export const on = events.on.bind(events)
export const off = events.off.bind(events)

function _uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = Math.random() * 16 | 0
        const v = c === 'x' ? r : (r & 0x3 | 0x8)
        return v.toString(16)
    })
}

function _deviceId() {
    return getDeviceId()
}

function _deviceInfo() {
    var info = {
        platform: '',
        model: '',
        os_version: '',
        device_name: '',
        app_version: _appVersion || ''
    }
    try {
        var sys = uni.getSystemInfoSync()
        info.platform = (sys.platform || '').toLowerCase() || ''
        if (info.platform === 'devtools') info.platform = 'web'
        info.model = sys.model || ''
        info.os_version = sys.system || ''
        var brand = sys.brand || sys.brandModel || ''
        var dn = sys.deviceName || sys.device || ''
        info.device_name = (brand && brand !== 'unknown' ? brand + ' ' : '') + (dn || info.model || '')
    } catch(e) {}
    return info
}

function _buildAuth() {
    var dev = _deviceInfo()
    return {
        type: 'auth',
        key: currentKey,
        device_id: _deviceId(),
        device_name: dev.device_name,
        model: dev.model,
        os_version: dev.os_version,
        platform: dev.platform,
        app_version: dev.app_version,
        heartbeat_interval: heartbeatInterval
    }
}

function _networkLabel(type) {
    const map = { wifi: 'Wi-Fi', '4g': '4G', '5g': '5G', '3g': '3G', '2g': '2G', ethernet: '有线', unknown: '未知', none: '无网络' }
    return map[type] || String(type || '未知')
}
