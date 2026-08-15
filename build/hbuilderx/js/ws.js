import { addMessage, PUSH_HEARTBEAT, PUSH_AUTO_RECONNECT, PUSH_WIFI_ONLY } from './storage.js'

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
let state = 'disconnected'
let socketTask = null
let heartbeatTimer = null
let reconnectTimer = null
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
        console.log('[Ws] onSocketOpen')
        const auth = { type: 'auth', key: currentKey, device_id: _deviceId(), heartbeat_interval: heartbeatInterval }
        try { uni.sendSocketMessage({ data: JSON.stringify(auth) }) } catch(e) {}
        _startHeartbeat()
    })

    uni.onSocketMessage((res) => { _handleMessage(res.data) })

    uni.onSocketError((err) => {
        console.error('[Ws] onSocketError', JSON.stringify(err))
        _onSocketLost()
    })

    uni.onSocketClose((res) => {
        console.log('[Ws] onSocketClose', JSON.stringify(res || {}))
        _onSocketLost()
    })
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
    currentUrl = url
    currentKey = key
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

function _handleMessage(text) {
    let env
    try { env = JSON.parse(text) } catch(e) {
        console.log('[Ws] non-JSON message:', text ? String(text).slice(0, 80) : '(empty)')
        return
    }

    const t = env.type || (env.message === 'pong' ? 'pong' : null)

    if (t === 'auth_result') {
        if (env.success || env.code === 0) {
            console.log('[Ws] ✅ auth ok')
            reconnectAttempts = 0
            state = 'connected'
            events.emit('state', state)
        } else {
            const failMsg = env.message || env.msg || '鉴权失败'
            console.warn('[Ws] ❌ auth failed:', failMsg)
            shouldReconnect = false
            _closeSocket()
            state = 'error'
            events.emit('state', state)
            events.emit('error', { type: 'auth_fail', message: failMsg })
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
            timestamp: env.timestamp || Date.now()
        }
        addMessage(msg)
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
                timestamp: (env.data && env.data.timestamp) || Date.now()
            }
            addMessage(m)
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
    if (reconnectTimer) clearTimeout(reconnectTimer)
    if (state === 'disconnected') {
        reconnectAttempts = 0
        _scheduleReconnect()
    }
}

export function applySettings() {
    _loadSettings()
    if (state === 'connected' || state === 'connecting') {
        _startHeartbeat()
        const auth = { type: 'auth', key: currentKey, device_id: _deviceId(), heartbeat_interval: heartbeatInterval }
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
    let id = uni.getStorageSync('push_device_id')
    if (!id) {
        id = _uuid()
        uni.setStorageSync('push_device_id', id)
    }
    return id
}

function _networkLabel(type) {
    const map = { wifi: 'Wi-Fi', '4g': '4G', '5g': '5G', '3g': '3G', '2g': '2G', ethernet: '有线', unknown: '未知', none: '无网络' }
    return map[type] || String(type || '未知')
}
