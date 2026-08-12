import { addMessage, loadBootConfig } from './storage.js'

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
let connectTimer = null
let heartbeatTimer = null
let reconnectAttempts = 0
let pendingPongs = 0
let currentUrl = ''
let currentKey = ''
let heartbeatInterval = 30
let autoReconnect = true
let shouldReconnect = false

export function connect(url, key) {
    if (!url || !key) {
        console.warn('[Ws] url or key empty, skip')
        return
    }
    currentUrl = url
    currentKey = key
    shouldReconnect = true
    reconnectAttempts = 0
    try {
        const cfg = loadBootConfig()
        heartbeatInterval = (cfg.heartbeat_interval || 30)
        autoReconnect = cfg.auto_reconnect !== false
    } catch(e) {}
    _doConnect()
}

function _doConnect() {
    if (connectTimer) { try { uni.closeSocket() } catch(e){} }
    state = 'connecting'
    events.emit('state', state)

    uni.connectSocket({
        url: currentUrl,
        success: () => console.log('[Ws] socket opened, sending auth'),
        fail: (err) => {
            console.error('[Ws] connect fail', err)
            _onSocketLost()
        }
    })

    uni.onSocketOpen(() => {
        console.log('[Ws] onSocketOpen')
        const auth = { type: 'auth', key: currentKey, device_id: _deviceId(), heartbeat_interval: heartbeatInterval }
        uni.sendSocketMessage({ data: JSON.stringify(auth) })
        _startHeartbeat()
    })

    uni.onSocketMessage((res) => { _handleMessage(res.data) })
    uni.onSocketError((err) => {
        console.error('[Ws] onSocketError', err)
        _onSocketLost()
    })
    uni.onSocketClose(() => {
        console.log('[Ws] onSocketClose')
        _onSocketLost()
    })
}

function _handleMessage(text) {
    let env
    try { env = JSON.parse(text) } catch(e) { return }

    const t = env.type || (env.message === 'pong' ? 'pong' : null)

    if (t === 'auth_result') {
        if (env.success || env.code === 0) {
            console.log('[Ws] auth ok')
            reconnectAttempts = 0
            state = 'connected'
            events.emit('state', state)
        } else {
            console.warn('[Ws] auth failed', env.message)
            shouldReconnect = false
            _stopHeartbeat()
            uni.closeSocket()
            state = 'disconnected'
            events.emit('state', state)
        }
    } else if (t === 'pong') {
        pendingPongs = 0
    } else if (t === 'ping') {
        uni.sendSocketMessage({ data: JSON.stringify({ type: 'pong' }) })
    } else if (t === 'push') {
        const msg = {
            id: env.id || _uuid(),
            title: env.title || '',
            content: env.content || '',
            priority: env.priority || 'default',
            timestamp: env.timestamp || Date.now()
        }
        addMessage(msg)
        events.emit('message', msg)
    } else {
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
            } else if (env.message === 'pong') {
                pendingPongs = 0
            }
        }
    }
}

function _startHeartbeat() {
    _stopHeartbeat()
    pendingPongs = 0
    const intervalMs = heartbeatInterval * 1000
    if (intervalMs < 5000) intervalMs = 5000
    heartbeatTimer = setInterval(() => {
        if (state !== 'connected' && state !== 'connecting') return
        try {
            uni.sendSocketMessage({ data: JSON.stringify({ type: 'ping' }) })
            pendingPongs++
            if (pendingPongs >= MAX_MISSED_PONG) {
                console.warn('[Ws] heartbeat timeout, reconnect')
                pendingPongs = 0
                uni.closeSocket()
            }
        } catch(e) {
            console.warn('[Ws] heartbeat send fail', e)
            uni.closeSocket()
        }
    }, intervalMs)
}

function _stopHeartbeat() {
    if (heartbeatTimer) { clearInterval(heartbeatTimer); heartbeatTimer = null }
}

function _onSocketLost() {
    _stopHeartbeat()
    state = 'disconnected'
    events.emit('state', state)
    if (!shouldReconnect || !autoReconnect) return
    _scheduleReconnect()
}

function _scheduleReconnect() {
    if (!shouldReconnect || !autoReconnect) return
    reconnectAttempts = Math.min(reconnectAttempts + 1, 20)
    const shift = Math.min(reconnectAttempts - 1, 5)
    let delay = RECONNECT_BASE * (1 << shift)
    delay = Math.max(RECONNECT_BASE, Math.min(RECONNECT_MAX, delay))
    const jitter = Math.round(delay * 0.2 * (Math.random() * 2 - 1))
    delay = Math.max(500, delay + jitter)
    state = 'reconnecting'
    events.emit('state', state)
    console.log('[Ws] reconnect attempt=' + reconnectAttempts + ' delay=' + delay + 'ms')
    if (connectTimer) clearTimeout(connectTimer)
    connectTimer = setTimeout(() => {
        if (shouldReconnect && autoReconnect) _doConnect()
    }, delay)
}

export function disconnect() {
    shouldReconnect = false
    autoReconnect = false
    _stopHeartbeat()
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
    try { uni.closeSocket() } catch(e) {}
    state = 'disconnected'
    events.emit('state', state)
}

export function reconnect() {
    shouldReconnect = true
    autoReconnect = true
    if (connectTimer) clearTimeout(connectTimer)
    if (state === 'disconnected') _scheduleReconnect()
}

export function isConnected() { return state === 'connected' }
export function getState() { return state }
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
