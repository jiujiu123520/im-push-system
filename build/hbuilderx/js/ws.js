import { addMessage, PUSH_HEARTBEAT, PUSH_AUTO_RECONNECT, PUSH_WIFI_ONLY } from './storage.js'
import * as _cfg from '../config.js'
import { getDeviceId } from './device-id.js'
import { setAlarmHandler, setScreenPingCallback, markScreenPongOk } from './keepalive.js'
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
            if (_wsSend({ type: 'ping', ts: Date.now() })) {
                console.log('[Ws] ⏰ alarm heartbeat sent')
            } else {
                console.warn('[Ws] alarm heartbeat send fail → 重连')
                _onSocketLost()
            }
        } catch(e) {
            console.warn('[Ws] alarm heartbeat send fail', e)
            _onSocketLost()
        }
    } else if (shouldReconnect && currentUrl && currentKey) {
        // 🔴 状态守卫：connecting/reconnecting 说明 TCP 握手或鉴权正在进行——
        //   alarm（15秒周期，相位随机）此刻强制 _doConnect 会打断鉴权中的连接：
        //   老连接 seq 作废、auth_result 被丢弃、泄漏的 authTimeoutTimer 再杀死新连接 → 死循环。
        //   连接要么成功（下次 alarm 发心跳）要么自己失败排重连，alarm 不需要抢跑。
        if (state === 'connecting' || state === 'reconnecting') {
            console.log('[Ws] ⏰ alarm 跳过：连接进行中（state=' + state + '）')
            return
        }
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
                if (!_wsSend({ type: 'ping', ts: Date.now() })) {
                    throw new Error('send fail')
                }
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
            // 🔴 正在连接中不打断：亮屏时 keepalive 只看 isConnected()（connected 才算），
            //   state=connecting 也会触发本回调 → 之前无条件 close+connect 会把正在握手的
            //   连接杀掉，制造又一轮并发建连。已连接/已断开才允许清理重连。
            if (state === 'connecting' || state === 'reconnecting') {
                console.log('[Ws] screen reconnect 跳过：连接进行中（state=' + state + '）')
                return
            }
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
// 🔴 1000→1500：close() 是异步操作，Android 底层释放 socket 需要时间。
//   1s 后立即 connectSocket 可能撞上"老 socket 未释放完"→ uni 单连接排队 → 又超时（死循环）。
//   手动重连的 1.5s 缓冲已验证有效，自动重连对齐。
const RECONNECT_BASE = 1500
const RECONNECT_MAX = 60000
const AUTH_TIMEOUT_MS = 10000
const PROBE_TIMEOUT_MS = 2000
const CONNECT_TIMEOUT_MS = 12000  // 🔴 TCP 连接级超时：弱网/代理下 connectSocket 可能无限卡。
                                   //   8s→12s：WSS+TLS 握手慢网络可能超 8 秒被误杀（误杀→超时提示→重试循环）

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
let manualReconnectTimer = null  // 🔴 手动重连的排程定时器句柄（reconnect 连点防叠加）
let usingTaskListeners = false   // 🔴 SocketTask 实例监听是否已生效（生效后禁用全局监听，防双份事件）
let authSentAt = 0 // 🔴 本连接发送鉴权的时间戳；0=本连接还没 onSocketOpen/没发过鉴权。
                   //   uni 的全局 onSocketMessage 不区分连接，老连接的迟到消息会派发到新连接上：
                   //   - 老 pong 带 code=0+data 被兜底鉴权误判成 auth ok
                   //   - 老 auth_result(失败) 把没发过鉴权的新连接直接"鉴权超时"误杀
                   //   门控：authSentAt===0 时收到的一切消息都是老连接残留，直接丢弃

// 🔴 统一发送出口：SocketTask.send 优先（发到"当前这一代"连接上），全局 send 兜底
//   【为什么必须 task.send】uni 的全局 uni.sendSocketMessage 在 App 端是单槽资源，
//   重连换代后它可能把数据发到老的/已死的 socket 上 → 服务端从未收到 auth →
//   服务端 30s（部分版本更短）auth timeout 后 close(4001 "auth timeout") →
//   客户端 onSocketClose → 重连 → 再发错 socket → 死循环。
//   日志实锤：seq=17/18/19 从未 onSocketOpen 却收到"❌ auth failed: 鉴权超时"+close 4001
function _wsSend(payload) {
    const data = typeof payload === 'string' ? payload : JSON.stringify(payload)
    try {
        if (socketTask && typeof socketTask.send === 'function') {
            socketTask.send({ data: data })
            return true
        }
    } catch (e) {
        console.warn('[Ws] task.send 失败，回退全局 send', e)
    }
    try {
        uni.sendSocketMessage({ data: data })
        return true
    } catch (e2) {
        console.error('[Ws] 发送失败（task+全局均失败）', e2)
        return false
    }
}

// onSocketOpen 公共逻辑（task 实例监听和全局兜底监听共用）
function _onSocketOpen(thisConnSeq) {
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
    if (state !== 'connecting' && state !== 'reconnecting') {
        console.warn('[Ws] onSocketOpen 丢弃：state=' + state + '（老连接迟到回调）')
        return
    }
    console.log('[Ws] onSocketOpen (seq=' + thisConnSeq + ') → 发送鉴权')
    authSentAt = Date.now()
    const auth = _buildAuth()
    if (!_wsSend(auth)) {
        console.error('[Ws] 发送 auth 失败')
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
}

// 🔴 全局监听【降级为兜底】：仅当 SocketTask 不支持实例监听（极老运行时）才注册。
//   全局 uni.onSocket* 不区分连接代际，是历史 bug 的温床（串台消息/串台close）。
function registerListeners() {
    if (listenersRegistered) return
    if (usingTaskListeners) return   // task 实例监听已生效，绝不能再注册全局（会双份事件）
    listenersRegistered = true
    console.warn('[Ws] 使用全局 socket 监听兜底（当前运行时不支持 SocketTask 实例监听）')

    uni.onSocketOpen(() => { _onSocketOpen(_connSeq) })
    uni.onSocketMessage((res) => { _handleMessage(res.data) })

    uni.onSocketError((err) => {
        if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
        if (state === 'disconnected' || state === 'error') {
            console.warn('[Ws] onSocketError 丢弃：state=' + state + '（老连接迟到回调）')
            return
        }
        console.error('[Ws] onSocketError', JSON.stringify(err))
        if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
        _onSocketLost()
    })

    uni.onSocketClose((res) => {
        if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
        if (state === 'disconnected' || state === 'error') {
            // reconnect()/disconnect() 已先把 state 置 disconnected，这个 close 是预期的
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
    const normUrl = _normalizeWsUrl(url)
    const cleanKey = _cleanAuthKey(key)
    // 🔴 幂等保护（根治并发建连循环）：
    //   App.vue onShow 和 home onShow 都只判断 isConnected()，
    //   connecting/reconnecting 被当成"未连接" → 权限弹窗/切前台每次触发 onShow
    //   都会再次 connect() → _closeSocket() 把正在握手的连接杀掉 →
    //   被杀连接的 close 事件又排重连 → 无限循环
    //   （日志实锤：900ms 内 3 条并发 connecting，auth ok 后 1ms 内被 onSocketClose 1000）
    if ((state === 'connected' || state === 'connecting' || state === 'reconnecting')
        && currentUrl === normUrl && currentKey === cleanKey) {
        console.log('[Ws] connect 幂等跳过：同参数连接进行中（state=' + state + '）')
        return
    }
    currentUrl = normUrl
    if (currentUrl !== url) console.log('[Ws] normalized ws url:', url, '→', currentUrl)
    currentKey = cleanKey
    shouldReconnect = true
    reconnectAttempts = 0
    _loadSettings()

    // 🔴 不再预注册全局监听：_actuallyConnect 里优先绑定 SocketTask 实例监听，
    //   仅当运行时不支持 task.onX 时才由 registerListeners() 兜底注册全局
    // 🔴 清老代际定时器：_closeSocket 只清 connectTimer/心跳，不清 authTimeoutTimer——
    //   老连接的鉴权定时器若不清，10秒后到期会 _closeSocket() 杀死刚建的新连接
    if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null }
    _closeSocket()
    _doConnect()
}

function _loadSettings() {
    try {
        const hb = parseInt(uni.getStorageSync(PUSH_HEARTBEAT))
        // 🔴 默认 15（与顶层 heartbeatInterval=15 和"假连接检测加速"设计一致）：
        //   之前 fallback 是 30 → 30s×2次容忍=60s 才发现假连接，恰好贴着 Nginx 60s idle 超时边缘，
        //   检测永远慢半拍。15s×2=30s 检测窗口，比 Nginx 掐连接更早发现假连接。
        heartbeatInterval = (hb > 0 && hb <= 3600) ? hb : 15
        const ar = uni.getStorageSync(PUSH_AUTO_RECONNECT)
        autoReconnect = ar === '' || ar === null || ar === undefined ? true : (ar !== false && ar !== 0)
        wifiOnly = uni.getStorageSync(PUSH_WIFI_ONLY) === true
    } catch(e) {
        heartbeatInterval = 15
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
    // 🔴🔴 并发守卫V2：必须覆盖"整个连接生命周期"（TCP阶段 + 鉴权阶段），不能只看 connectTimer！
    //   之前的致命漏洞：onSocketOpen 时 connectTimer 被清除，但 state 仍是 connecting（鉴权中）——
    //   此时 alarm 回调（15秒周期，相位随机）/ 残留 reconnectTimer 触发 _doConnect：
    //     1. 守卫只查 connectTimer → 已被清 → 守卫失效 → 又 ++_connSeq 建新连接
    //     2. 老连接的 auth_result 到达 → seq 不匹配被丢弃 → 老连接白鉴权
    //     3. 更毒的：老连接排的 authTimeoutTimer（10秒）从不清除 → 到期执行 _closeSocket()
    //        把正在鉴权的新连接杀掉 → 新连接 close 又排重连 → 无限"连接超时"循环
    //   authTimeoutTimer 活跃 = onSocketOpen 已发生且未收到 auth_result = 鉴权进行中，精确可靠。
    if (connectTimer || authTimeoutTimer) {
        console.warn('[Ws] _actuallyConnect 跳过：连接生命周期进行中（TCP=' + (connectTimer ? '是' : '否') +
            ' 鉴权=' + (authTimeoutTimer ? '是' : '否') + '，seq=' + _connSeq + '）')
        return
    }
    // 🔴 连接换代收尾：清掉老代际泄漏的鉴权超时定时器（防止它到期杀死本代新连接）
    if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
    state = 'connecting'
    authSentAt = 0   // 🔴 新连接开始：重置"已发鉴权"标记（未发鉴权前收到的消息=老连接残留，丢弃）
    events.emit('state', state)

    var thisConnSeq = ++_connSeq   // 🔴 B2修复：每次连接分配递增序列号，避免"老连接的回调污染新连接"
    console.log('[Ws] connecting →', currentUrl, '(seq=' + thisConnSeq + ')')

    // 🔴 TCP 连接级超时：弱网/代理异常时 uni.connectSocket 可能完全不回调（无限卡 connecting）
    connectTimer = setTimeout(function() {
        if (_connSeq !== thisConnSeq) return
        connectTimer = null
        console.warn('[Ws] ⏰ TCP 连接超时（' + (CONNECT_TIMEOUT_MS / 1000) + '秒connectSocket无回调）→ 主动断开并重连（seq=' + thisConnSeq + '）')
        events.emit('error', { type: 'connect_timeout', message: '连接超时，正在自动重试' })
        try { _closeSocket() } catch (_) {}
        socketTask = null
        _onSocketLost()
    }, CONNECT_TIMEOUT_MS)

    var task = null
    try {
        task = uni.connectSocket({
            url: currentUrl,
            success: () => {
                if (_connSeq !== thisConnSeq) return
                console.log('[Ws] connectSocket success callback (seq=' + thisConnSeq + ')')
            },
            fail: (err) => {
                if (_connSeq !== thisConnSeq) return
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
        return
    }
    socketTask = task

    // 🔴🔴 核心重构：SocketTask 实例化监听（根治全局 API 串台）
    //   之前用全局 uni.onSocket* + uni.sendSocketMessage：多代连接共存时事件/发送全部串台——
    //   老 socket 的 pong 被新连接误读、新连接的 auth 发到老 socket 上（服务端从没收到 →
    //   close 4001 "auth timeout"）、老 socket 被踢的 close(1000) 把新连接判死。
    //   task.onX 是每代连接私有的，天然按 seq 隔离，上述三类串台全部消失。
    var bound = false
    try {
        if (task && typeof task.onOpen === 'function' && typeof task.onMessage === 'function') {
            task.onOpen(function() {
                if (_connSeq !== thisConnSeq) return   // 本代已被新一代取代，丢弃
                _onSocketOpen(thisConnSeq)
            })
            task.onMessage(function(res) {
                if (_connSeq !== thisConnSeq) return
                _handleMessage(res.data)
            })
            task.onError(function(err) {
                if (_connSeq !== thisConnSeq) return
                if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
                if (state === 'disconnected' || state === 'error') return
                console.error('[Ws] onSocketError (seq=' + thisConnSeq + ')', JSON.stringify(err || {}))
                if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
                _onSocketLost()
            })
            task.onClose(function(res) {
                if (_connSeq !== thisConnSeq) return
                if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
                if (state === 'disconnected' || state === 'error') return
                console.log('[Ws] onSocketClose (seq=' + thisConnSeq + ')', JSON.stringify(res || {}))
                if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
                _onSocketLost()
            })
            bound = true
            usingTaskListeners = true
            console.log('[Ws] ✅ SocketTask 实例监听已绑定 (seq=' + thisConnSeq + ')')
        }
    } catch (e) {
        console.warn('[Ws] SocketTask 监听绑定异常，回退全局监听', e)
    }
    if (!bound) {
        registerListeners()   // 极老运行时兜底：全局监听
    }
}

function _closeSocket() {
    _stopHeartbeat()
    // 🔴 关 socket 时同步清掉 TCP 超时定时器（避免 close 后定时器仍触发误判超时）
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
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

    // 🔴 丢弃老连接的迟到消息：本连接还没发过鉴权（authSentAt=0，onSocketOpen 未发生），
    //   这条消息必然来自上一轮被关闭的连接（uni 全局 onSocketMessage 不区分连接代际）
    //   日志实锤：seq=16 连接从未 onSocketOpen，却收到老连接的 auth_result(鉴权超时) 被误杀
    if (authSentAt === 0) {
        console.warn('[Ws] 丢弃迟到消息（本连接未发鉴权，老连接残留）type=' + t)
        return
    }

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
    //   ⚠️ 排除 pong/ping：后端 pong 响应带 code=0+data，曾被误判成鉴权成功
    //     （日志实锤：'auth ok (fallback: code=0, type=pong, msg=pong)'）
    if ((state === 'connecting' || state === 'reconnecting')
        && env.code === 0
        && t !== 'pong' && t !== 'ping'
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
        // 🔴 回写亮屏 ping 验证标记：亮屏后 keepalive 发 ping 等 pong，5 秒没等到就强制重连。
        //   之前没人置 _screenPongOk → 每次亮屏必误判掉线重连（杀掉健康连接）
        try { markScreenPongOk() } catch (_) {}
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
        try { _wsSend({ type: 'pong' }) } catch(e) {}
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
            if (!_wsSend({ type: 'ping', ts: Date.now() })) {
                throw new Error('send fail')
            }
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
    // 🔴 断连汇聚点统一清理：所有路径（close/error/心跳超时/TCP超时/alarm失败）都经过这里，
    //   任何活跃的 connectTimer/authTimeoutTimer 泄漏到下一轮连接都会杀死新连接（血泪教训）
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
    if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
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
        // 🔴 双保险：排程到期时其他路径（手动重连/alarm）可能已经连上或正在连——
        //   connected → 无需再连；connecting/reconnecting → _actuallyConnect 守卫也会拦，这里提前挡掉少打日志
        if (!shouldReconnect || !autoReconnect) return
        if (state === 'connected' || state === 'connecting' || state === 'reconnecting') return
        _doConnect()
    }, delay)
}

export function disconnect() {
    shouldReconnect = false
    autoReconnect = false
    if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null }
    if (authTimeoutTimer) { clearTimeout(authTimeoutTimer); authTimeoutTimer = null }
    if (connectTimer) { clearTimeout(connectTimer); connectTimer = null }
    if (manualReconnectTimer) { clearTimeout(manualReconnectTimer); manualReconnectTimer = null }
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
    // 🔴 防叠加：清掉上一次手动重连的排程定时器。
    //   日志实锤：用户连点 3 次"重新连接"→ 之前每次都裸 setTimeout 排一个 _doConnect
    //   → 1.5s 后连续 3 次 connectSocket → uni Android 单连接排队 → TCP 超时
    if (manualReconnectTimer) { clearTimeout(manualReconnectTimer); manualReconnectTimer = null }
    // 🔴 先置 disconnected 再关 socket：_onSocketLost 检查 state === 'disconnected' 会直接 return，
    //   避免旧连接的 close 事件再排一个重连定时器，和下面的立即连接竞争（uni APP 端单连接限制）
    if (state === 'connected' || state === 'connecting' || state === 'reconnecting') {
        state = 'disconnected'
        _closeSocket()
    }
    reconnectAttempts = 0
    // 🔴 手动重连缓冲 1.5s：旧 socket 释放是异步的（close 回调还没派发完），
    //   立即 connectSocket 会排队等不到 onSocketOpen → TCP 超时
    state = 'connecting'
    events.emit('state', state)
    console.log('[Ws] 手动重连 → 1.5s 后连接（等待旧 socket 释放）')
    manualReconnectTimer = setTimeout(function() {
        manualReconnectTimer = null
        if (shouldReconnect && autoReconnect) {
            _doConnect()
        }
    }, 1500)
}

export function applySettings() {
    _loadSettings()
    if (state === 'connected' || state === 'connecting') {
        _startHeartbeat()
        const auth = _buildAuth()
        try { _wsSend(auth) } catch(e) {}
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
        // ② "正在连接中"：给连接+鉴权留足时间（TCP12s + 鉴权10s = 最多22s），
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
        if (!_wsSend({ type: 'ping', ts: pendingPingAt, probe: true })) {
            throw new Error('send fail')
        }
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
