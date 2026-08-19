import { addMessage, PUSH_HEARTBEAT, PUSH_AUTO_RECONNECT, PUSH_WIFI_ONLY } from './storage.js'
import * as _cfg from '../config.js'
import { getDeviceId } from './device-id.js'
import { setAlarmHandler, setScreenPingCallback } from './keepalive.js'
// 🔴 关键修复：静态 import notify 模块（命名空间导入，模块文件存在即安全，顶层无副作用）
//   之前用 require('./notify.js') 在 vue3/vite 编译的 APP 端不存在 require 函数（CommonJS 不可用）
//   → 每次收推送都抛 "require is not defined" → 通知栏永远只走 Toast 兜底，弹不出系统通知！
import * as _notifyLib from './notify.js'

// 通知显示：优先静态 import 的模块 → 再试 require（兼容老的 webpack 编译）→ 最后 Toast 兜底
function _showPushNotification(title, content, priority) {
    // 路径1：ESM 静态导入（vue3/vite APP 端唯一可靠路径）
    try {
        if (_notifyLib && typeof _notifyLib.showNotification === 'function') {
            _notifyLib.showNotification(title, content, { priority: priority || 'default' })
            return
        }
        if (_notifyLib && typeof _notifyLib.notify === 'function') {
            _notifyLib.notify(title, content, priority || 'default')
            return
        }
    } catch (e) {
        console.warn('[Ws] 静态导入 notify 显示失败，尝试 require', e)
    }
    // 路径2：require（老版 webpack/vue2 编译环境才存在）
    try {
        if (typeof require === 'function') {
            const notifyMod = require('./notify.js')
            if (notifyMod && typeof notifyMod.showNotification === 'function') {
                notifyMod.showNotification(title, content, { priority: priority || 'default' })
                return
            }
            if (notifyMod && typeof notifyMod.notify === 'function') {
                notifyMod.notify(title, content, priority || 'default')
                return
            }
        }
    } catch (e) {
        console.warn('[Ws] require notify.js 失败', e)
    }
    // 路径3：终极兜底 H5/通用 uni API
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

// 🔴 假连接检测加速：心跳 30s → 15s，丢失容忍 3次 → 2次
//   原来 30s×3=90s 才发现假连接，自测 4 秒超时已经判失败，用户永远等不到自动重连
//   现在 15s×2=30s，比 Nginx 默认 60s idle timeout 更敏感，用户感知大幅提升
const MAX_MISSED_PONG = 2
const RECONNECT_BASE = 1000
const RECONNECT_MAX = 60000
const AUTH_TIMEOUT_MS = 10000
const PROBE_TIMEOUT_MS = 2000
const CONNECT_TIMEOUT_MS = 8000  // 🔴 TCP 连接级超时：弱网/代理下 connectSocket 可能无限卡，8秒没回调就主动放弃+重连

let state = 'disconnected'
let socketTask = null
let heartbeatTimer = null
let reconnectTimer = null
let authTimeoutTimer = null
let reconnectAttempts = 0
let pendingPongs = 0
let latency = -1
let pendingPingAt = 0
// 🔴 同步通道探测：resolve 收到 pong 后立即兑现，超时则 reject（判定为假连接 + 自动重连）
let probeResolver = null
let probeTimer = null
let connectTimer = null  // 🔴 TCP 连接级超时定时器（connectSocket 回调前启动，任何回调首件事清掉）
let currentUrl = ''
let currentKey = ''
let heartbeatInterval = 15
let autoReconnect = true
let wifiOnly = false
let shouldReconnect = false
let listenersRegistered = false
let lostProcessing = false
let _connSeq = 0   // 🔴 B2修复：每次_connect分配递增序列号，旧连接的回调全部忽略（事件竞争隔离）

function registerListeners() {
    if (listenersRegistered) return
    listenersRegistered = true

    uni.onSocketOpen(() => {
        if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
        // 🔴 B2修复：全局回调也用_connSeq隔离；如果 state 是 disconnected 说明连接已被 reconnect() 等函数重置，
        //   这个 onSocketOpen 是**上一轮老连接**的迟到回调，丢弃不处理（否则会给一个已经断开的连接发 auth，然后卡死）
        if (state !== 'connecting' && state !== 'reconnecting') {
            console.warn('[Ws] onSocketOpen 丢弃：state=' + state + '（老连接迟到回调）')
            return
        }
        console.log('[Ws] onSocketOpen (seq=' + _connSeq + ') → 发送鉴权')
        const auth = _buildAuth()
        try { uni.sendSocketMessage({ data: JSON.stringify(auth) }) } catch(e) {
            console.error('[Ws] 发送 auth 失败', e)
            _onSocketLost()
            return
        }
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
        if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
        // 🔴 B2修复：state已被重置到disconnected时老连接的error，忽略（不会再触发_onSocketLost导致重连竞争）
        if (state === 'disconnected' || state === 'error') {
            console.warn('[Ws] onSocketError 丢弃：state=' + state + '（老连接迟到回调）err=', JSON.stringify(err))
            return
        }
        console.error('[Ws] onSocketError', JSON.stringify(err))
        if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
        _onSocketLost()
    })

    uni.onSocketClose((res) => {
        if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
        if (state === 'disconnected' || state === 'error') {
            // 🔴 reconnect()/disconnect() 已经先把 state 置成 disconnected，这个 close 事件是预期的，不触发 _onSocketLost
            //   之前的 bug：reconnect() 先 state=disconnected + closeSocket → close 事件 → _onSocketLost →
            //     _scheduleReconnect 又排一个 delay，和 reconnect() 自己 300ms 后立即的 _doConnect 竞争！
            return
        }
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

    // 🔴 TCP 连接级超时：弱网/代理异常时 uni.connectSocket 可能完全不回调（无限卡 connecting）
    //   8 秒内没收到 onSocketOpen/onSocketError/onSocketClose 就主动放弃 + 重连
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
    var thisConnSeq = ++_connSeq   // 🔴 B2修复：每次连接分配递增序列号，避免"老连接的回调污染新连接"
    connectTimer = setTimeout(function() {
        if (_connSeq !== thisConnSeq) return   // 序列号已推进，说明当前超时的是老连接，忽略
        connectTimer = null
        console.warn('[Ws] ⏰ TCP 连接超时（8秒connectSocket无回调）→ 主动断开并重连（seq=' + thisConnSeq + '）')
        events.emit('error', { type: 'connect_timeout', message: '连接超时（8秒无响应），正在自动重试' })
        try { _closeSocket() } catch (_) {}
        socketTask = null
        _onSocketLost()
    }, CONNECT_TIMEOUT_MS)

    try {
        socketTask = uni.connectSocket({
            url: currentUrl,
            success: () => {
                if (_connSeq !== thisConnSeq) return
                console.log('[Ws] connectSocket success callback (seq=' + thisConnSeq + ')')
            },
            fail: (err) => {
                if (_connSeq !== thisConnSeq) return   // 🔴 B2修复：连接被新连接取代后不再处理
                console.error('[Ws] connectSocket fail', JSON.stringify(err))
                if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
                socketTask = null
                _onSocketLost()
            }
        })
    } catch(e) {
        if (_connSeq !== thisConnSeq) return
        console.error('[Ws] connectSocket exception', e)
        if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
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
            console.log('[Ws] ✅ auth ok (type=auth_result)')
            reconnectAttempts = 0
            state = 'connected'
            events.emit('state', state)
            _startHeartbeat()
            // 🔴 鉴权成功还会从 data 里同步心跳间隔（后端返回的 heartbeat_interval）
            try {
                if (env.data && typeof env.data.heartbeat_interval === 'number' && env.data.heartbeat_interval > 0) {
                    var hb = parseInt(env.data.heartbeat_interval)
                    if (hb > 0 && hb <= 3600) { heartbeatInterval = hb; _startHeartbeat() }
                }
            } catch (_) {}
        } else {
            const failMsg = env.message || env.msg || '鉴权失败'
            console.warn('[Ws] ❌ auth failed:', failMsg)
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
            if (!isPermanentFail) {
                reconnectAttempts = 0
                _scheduleReconnect()
            }
        }
        return
    }

    // 🔴 【根治卡正在连接】兜底鉴权：连接中收到任何 code=0 且带 data/heartbeat/server_time 的消息
    //   等同于 auth_result（不同后端版本 pack 时 type 可能缺失或被 message 字段覆盖）
    //   触发条件：state=connecting/reconnecting + code=0 + (有data或message含"成功/连接")
    if ((state === 'connecting' || state === 'reconnecting')
        && env.code === 0
        && (env.data || /成功|连接|auth/i.test(env.message || ''))) {
        if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
        console.log('[Ws] ✅ auth ok (fallback: code=0, type=', t, 'msg=', env.message, ')')
        reconnectAttempts = 0
        state = 'connected'
        events.emit('state', state)
        try {
            if (env.data && typeof env.data.heartbeat_interval === 'number' && env.data.heartbeat_interval > 0) {
                var hb2 = parseInt(env.data.heartbeat_interval)
                if (hb2 > 0 && hb2 <= 3600) { heartbeatInterval = hb2 }
            }
        } catch (_) {}
        _startHeartbeat()
        // 注意：如果 type 不是 auth_result 但带了 push 内容，仍需交给后续 push 分支处理
        // 所以这里不 return，让消息继续匹配
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
        // 🔴 同步通道探测：收到任何 pong 都兑现探针（区分正常心跳 ping 和主动 probe ping）
        if (probeResolver) {
            const r = probeResolver
            probeResolver = null
            if (probeTimer) { clearTimeout(probeTimer); probeTimer = null }
            try { r({ ok: true, latency: latency }) } catch (_) {}
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
        // 🔴 修复 B3：只有真正 connected 才发心跳 ping
        //   之前 state==='connecting' 也允许发 ping → 鉴权未完成时后端还没把 fd 注册到 device，
        //   发的 ping 虽能到服务器但 pong 不回，pendingPongs 递增 → 达到 MAX_MISSED_PONG=2 后主动断开！
        //   直接表现就是"连到一半自己断了，一直卡正在连接"
        if (state !== 'connected') return
        try {
            pendingPingAt = Date.now()
            uni.sendSocketMessage({ data: JSON.stringify({ type: 'ping', ts: Date.now() }) })
            pendingPongs++
            if (pendingPongs >= MAX_MISSED_PONG) {
                console.warn('[Ws] ❤️ heartbeat timeout (missed ' + pendingPongs + ' pongs) → 判定假连接，重连')
                pendingPongs = 0
                _closeSocket()
                _onSocketLost()
            }
        } catch(e) {
            console.warn('[Ws] heartbeat send fail → 重连', e)
            _closeSocket()
            _onSocketLost()
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
    if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
    _closeSocket()
    latency = -1
    state = 'disconnected'
    events.emit('state', state)
}

export function reconnect() {
    shouldReconnect = true
    autoReconnect = true
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null }
    if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
    // 🔴 先置 disconnected 再关 socket：_onSocketLost 检查 state === 'disconnected' 会直接 return，
    //   避免旧连接的 close 事件再排一个重连定时器，和下面的立即连接竞争（uni APP 端单连接限制）
    if (state === 'connected' || state === 'connecting' || state === 'reconnecting') {
        state = 'disconnected'
        _closeSocket()
    }
    reconnectAttempts = 0
    // 🔴 手动重连立即执行，不走退避延迟
    //   之前 _scheduleReconnect() 至少等 1 秒（RECONNECT_BASE=1000）才开始连接 → 用户感觉"卡"
    //   现在只留 300ms 缓冲：让上一个 socket 的 close 事件先派发完，避免新旧连接事件竞争
    state = 'connecting'
    events.emit('state', state)
    console.log('[Ws] 手动重连 → 300ms 后立即连接')
    setTimeout(function() {
        if (shouldReconnect && autoReconnect) {
            _doConnect()
        }
    }, 300)
}

export function applySettings() {
    _loadSettings()
    if (state === 'connected' || state === 'connecting') {
        _startHeartbeat()
        const auth = _buildAuth()
        try { uni.sendSocketMessage({ data: JSON.stringify(auth) }) } catch(e) {}
    }
}

// 🔴 同步通道探测：点"测试推送"前先 ping，2 秒没收到 pong = 假连接
//   返回 Promise<{ok:boolean, latency?:number, reason?:string}>
//   假连接时会主动触发一次 reconnect()（带 autoReconnect 标记）
export function probeChannel() {
    return new Promise(function(resolve) {
        // ① 真正"未连接"：直接返回（不用等，等也没用）
        if (state === 'disconnected') {
            resolve({ ok: false, reason: 'disconnected', needReconnect: true })
            return
        }
        // ② "正在连接中"：给连接+鉴权留足时间（TCP8s + 鉴权10s = 最多18s），
        //    连好立刻跳正常探测流程；超时后再判（此时大概率是真卡主了）
        if (state === 'connecting' || state === 'reconnecting') {
            var waitStart = Date.now()
            var waitTimer = null
            var waitDone = false
            var waitMax = CONNECT_TIMEOUT_MS + AUTH_TIMEOUT_MS
            var stateHandler = function(s) {
                if (waitDone) return
                if (s === 'connected') {
                    // 连上了 → 取消等待，走下面的正常ping探测
                    waitDone = true
                    if (waitTimer) { clearTimeout(waitTimer); waitTimer = null }
                    try { events.off('state', stateHandler) } catch (_) {}
                    console.log('[Ws] probeChannel：等待连接耗时 ' + (Date.now() - waitStart) + 'ms，已连接 → 开始ping探测')
                    doProbe(resolve)
                } else if (s === 'disconnected') {
                    // 连接失败了 → 不等了
                    waitDone = true
                    if (waitTimer) { clearTimeout(waitTimer); waitTimer = null }
                    try { events.off('state', stateHandler) } catch (_) {}
                    resolve({ ok: false, reason: 'connect_failed', needReconnect: true })
                }
            }
            try { events.on('state', stateHandler) } catch (_) {}
            waitTimer = setTimeout(function() {
                if (waitDone) return
                waitDone = true
                waitTimer = null
                try { events.off('state', stateHandler) } catch (_) {}
                console.warn('[Ws] probeChannel：等待连接超时 ' + waitMax + 'ms，当前state=' + state + ' → 判定卡连接')
                // 卡连接太久 → 判失败，并告知上层应重连
                resolve({ ok: false, reason: 'wait_connect_timeout', needReconnect: true })
            }, waitMax)
            return
        }
        // ③ state === 'connected'：直接 ping 探测
        doProbe(resolve)
    })
}

// 抽出实际 ping 探测逻辑（state=connected 时调用；从 state=connecting 等过来也复用）
function doProbe(resolve) {
    // 取消上一个探测（理论上不会并发，保险）
    if (probeTimer) { clearTimeout(probeTimer); probeTimer = null }
    if (probeResolver) { try { probeResolver({ ok: false, reason: 'cancelled' }) } catch (_) {}; probeResolver = null }

    var done = false
    probeResolver = function(result) {
        if (done) return
        done = true
        resolve(result)
    }
    probeTimer = setTimeout(function() {
        if (done) return
        done = true
        probeResolver = null
        probeTimer = null
        console.warn('[Ws] ⚠️ 通道探测超时（2秒无pong）→ 判定假连接，触发重连')
        events.emit('error', { type: 'zombie_probe', message: 'WS假连接检测：通道探测2秒无响应，正在自动重连' })
        try { reconnect() } catch (_) {}
        resolve({ ok: false, reason: 'probe_timeout', needReconnect: true })
    }, PROBE_TIMEOUT_MS)

    try {
        pendingPingAt = Date.now()
        uni.sendSocketMessage({ data: JSON.stringify({ type: 'ping', ts: pendingPingAt, probe: true }) })
    } catch (e) {
        console.error('[Ws] probeChannel send ping fail', e)
        if (probeTimer) { clearTimeout(probeTimer); probeTimer = null }
        probeResolver = null
        resolve({ ok: false, reason: 'send_fail:' + (e && e.message ? e.message : String(e)), needReconnect: true })
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
