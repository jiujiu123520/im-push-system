var storage = require('./storage.js')
var events = {
    _handlers: {},
    on: function(evt, cb) {
        if (!this._handlers[evt]) this._handlers[evt] = []
        this._handlers[evt].push(cb)
    },
    emit: function(evt, data) {
        var arr = this._handlers[evt] || []
        for (var i = 0; i < arr.length; i++) {
            try { arr[i](data) } catch(e) { console.error('[Ws] handler error', e) }
        }
    },
    off: function(evt, cb) {
        if (!this._handlers[evt]) return
        if (!cb) { delete this._handlers[evt]; return }
        this._handlers[evt] = this._handlers[evt].filter(function(h){ return h !== cb })
    }
}

var MAX_MISSED_PONG = 3
var RECONNECT_BASE = 1000
var RECONNECT_MAX = 60000
var state = 'disconnected'
var connectTimer = null
var heartbeatTimer = null
var reconnectAttempts = 0
var pendingPongs = 0
var lastPongTime = 0
var currentUrl = ''
var currentKey = ''
var heartbeatInterval = 30
var autoReconnect = true
var shouldReconnect = false

function connect(url, key) {
    if (!url || !key) {
        console.warn('[Ws] url or key empty, skip')
        return
    }
    currentUrl = url
    currentKey = key
    shouldReconnect = true
    reconnectAttempts = 0
    currentKey = key
    try {
        var cfg = storage.loadBootConfig()
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
        success: function() { console.log('[Ws] socket opened, sending auth') },
        fail: function(err) {
            console.error('[Ws] connect fail', err)
            _onSocketLost()
        }
    })

    uni.onSocketOpen(function() {
        console.log('[Ws] onSocketOpen')
        var auth = { type: 'auth', key: currentKey, device_id: _deviceId(), heartbeat_interval: heartbeatInterval }
        uni.sendSocketMessage({ data: JSON.stringify(auth) })
        _startHeartbeat()
    })

    uni.onSocketMessage(function(res) {
        _handleMessage(res.data)
    })

    uni.onSocketError(function(err) {
        console.error('[Ws] onSocketError', err)
        _onSocketLost()
    })

    uni.onSocketClose(function() {
        console.log('[Ws] onSocketClose')
        _onSocketLost()
    })
}

function _handleMessage(text) {
    var env
    try { env = JSON.parse(text) } catch(e) { return }

    var t = env.type
    if (!t) t = env.message === 'pong' ? 'pong' : null

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
        lastPongTime = Date.now()
        pendingPongs = 0
    } else if (t === 'ping') {
        uni.sendSocketMessage({ data: JSON.stringify({ type: 'pong' }) })
    } else if (t === 'push') {
        var msg = {
            id: env.id || _uuid(),
            title: env.title || '',
            content: env.content || '',
            priority: env.priority || 'default',
            timestamp: env.timestamp || Date.now()
        }
        storage.addMessage(msg)
        events.emit('message', msg)
    } else {
        if (env.code === 0 || env.code === -1) {
            var title = (env.data && env.data.title) || env.title || ''
            var content = (env.data && env.data.content) || env.content || ''
            if (title || content) {
                var m = {
                    id: (env.data && env.data.message_id) || _uuid(),
                    title: title,
                    content: content,
                    priority: (env.data && env.data.priority) || 'default',
                    timestamp: (env.data && env.data.timestamp) || Date.now()
                }
                storage.addMessage(m)
                events.emit('message', m)
            } else if (env.message === 'pong') {
                lastPongTime = Date.now()
                pendingPongs = 0
            }
        }
    }
}

function _startHeartbeat() {
    _stopHeartbeat()
    pendingPongs = 0
    lastPongTime = Date.now()
    var intervalMs = heartbeatInterval * 1000
    if (intervalMs < 5000) intervalMs = 5000
    heartbeatTimer = setInterval(function() {
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
    var shift = Math.min(reconnectAttempts - 1, 5)
    var delay = RECONNECT_BASE * (1 << shift)
    delay = Math.max(RECONNECT_BASE, Math.min(RECONNECT_MAX, delay))
    var jitter = Math.round(delay * 0.2 * (Math.random() * 2 - 1))
    delay = Math.max(500, delay + jitter)
    state = 'reconnecting'
    events.emit('state', state)
    console.log('[Ws] reconnect attempt=' + reconnectAttempts + ' delay=' + delay + 'ms')
    if (connectTimer) clearTimeout(connectTimer)
    connectTimer = setTimeout(function() {
        if (shouldReconnect && autoReconnect) _doConnect()
    }, delay)
}

function disconnect() {
    shouldReconnect = false
    autoReconnect = false
    _stopHeartbeat()
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
    try { uni.closeSocket() } catch(e) {}
    state = 'disconnected'
    events.emit('state', state)
}

function reconnect() {
    shouldReconnect = true
    autoReconnect = true
    if (connectTimer) clearTimeout(connectTimer)
    if (state === 'disconnected') _scheduleReconnect()
}

function isConnected() { return state === 'connected' }
function getState() { return state }

function _uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        var r = Math.random() * 16 | 0
        var v = c === 'x' ? r : (r & 0x3 | 0x8)
        return v.toString(16)
    })
}

function _deviceId() {
    var id = uni.getStorageSync('push_device_id')
    if (!id) {
        id = _uuid()
        uni.setStorageSync('push_device_id', id)
    }
    return id
}

module.exports = {
    connect: connect,
    disconnect: disconnect,
    reconnect: reconnect,
    isConnected: isConnected,
    getState: getState,
    on: events.on,
    off: events.off
}
