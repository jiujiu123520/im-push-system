package com.push.app.data

import android.util.Log
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.WebSocket
import okhttp3.WebSocketListener
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger

// ========== WebSocket 协议消息 ==========

/** 客户端 → 服务端：鉴权 */
@Serializable
private data class AuthMessage(
    val type: String = "auth",
    val key: String,
    val device_id: String,
    val heartbeat_interval: Int,
)

/** 客户端 → 服务端：心跳 ping */
@Serializable
private data class PingMessage(val type: String = "ping")

/** 客户端 → 服务端：响应服务端 ping */
@Serializable
private data class PongMessage(val type: String = "pong")

/** 服务端消息统一外壳，用于按 type 分发解析 */
@Serializable
private data class ServerEnvelope(
    val type: String? = null,
    val success: Boolean = false,
    val message: String? = null,
    // 推送字段（平铺在顶层）
    val id: String? = null,
    val title: String? = null,
    val content: String? = null,
    val priority: String? = null,
    val timestamp: Long = 0L,
    // 兼容旧格式（data 嵌套）
    val code: Int = -1,
    val data: kotlinx.serialization.json.JsonElement? = null,
)

/** 连接参数 */
data class ConnectConfig(
    val url: String,
    val key: String,
    val deviceId: String,
    val heartbeatInterval: Int, // 秒
)

/**
 * WebSocket 客户端封装。
 *
 * 核心能力：
 * 1. 建立连接并发送鉴权
 * 2. 应用层心跳（按间隔发送 ping，统计未响应次数，连续 3 次无 pong 触发重连）
 * 3. 自动重连（指数退避 + 抖动，最大间隔 60s，连接成功后重置计数）
 *
 * 线程模型：心跳与重连协程运行在传入的 [scope] 中；OkHttp 回调运行在 OkHttp 调度器，
 * 通过 MutableStateFlow 与挂起函数安全地与协程层交互。
 */
class PushWebSocket(
    private val client: OkHttpClient,
    private val scope: CoroutineScope,
    private val onPushMessage: (PushMessage) -> Unit,
) {

    companion object {
        private const val TAG = "PushWebSocket"
        // 重连退避参数
        private const val RECONNECT_BASE_MS = 1_000L
        private const val RECONNECT_MAX_MS = 60_000L
        // 连续未收到 pong 的容忍次数，达到即判定连接已死
        private const val MAX_MISSED_PONG = 3
    }

    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        encodeDefaults = true // 确保带默认值的 type 字段被序列化（auth/ping/pong 消息依赖此字段）
    }

    private val _state = MutableStateFlow(ConnectionState.DISCONNECTED)
    val state: StateFlow<ConnectionState> = _state.asStateFlow()

    // 当前 WebSocket 实例（可能为 null）
    @Volatile
    private var socket: WebSocket? = null

    // 当前连接参数（用于重连）
    @Volatile
    private var config: ConnectConfig? = null

    // 是否允许自动重连（手动断开时置为 false）
    @Volatile
    private var shouldReconnect = false

    // 重连尝试次数
    private var reconnectAttempts = 0

    // 心跳协程
    private var heartbeatJob: Job? = null
    // 重连协程
    private var reconnectJob: Job? = null

    // 上一次收到 pong 的时间戳
    @Volatile
    private var lastPongTime: Long = 0L
    // 已发送但未收到响应的 ping 数量（原子操作，避免心跳协程与 pong 回调竞态）
    private val pendingPongs = AtomicInteger(0)

    /**
     * 发起连接。
     * 会先取消已有连接，再创建新的 WebSocket。
     */
    fun connect(config: ConnectConfig) {
        Log.i(TAG, "connect -> ${config.url}, key=${config.key}, hb=${config.heartbeatInterval}s")
        this.config = config
        this.shouldReconnect = true
        this.reconnectAttempts = 0
        openConnection()
    }

    /**
     * 主动断开，不再自动重连。
     */
    fun disconnect() {
        Log.i(TAG, "disconnect (manual)")
        shouldReconnect = false
        cancelJobs()
        socket?.close(1000, "client closed")
        socket = null
        _state.value = ConnectionState.DISCONNECTED
    }

    /** 真正建立底层 WebSocket */
    private fun openConnection() {
        val cfg = config ?: return
        cancelJobs()
        // 关闭旧 socket，避免重复连接导致连接泄漏
        socket?.close(1000, "reconnect")
        socket = null
        _state.value = if (reconnectAttempts > 0) ConnectionState.RECONNECTING
        else ConnectionState.CONNECTING

        val request = Request.Builder()
            .url(cfg.url)
            .build()

        socket = client.newWebSocket(request, object : WebSocketListener() {
            override fun onOpen(webSocket: WebSocket, response: Response) {
                Log.i(TAG, "onOpen, sending auth")
                // 发送鉴权消息
                val auth = AuthMessage(
                    key = cfg.key,
                    device_id = cfg.deviceId,
                    heartbeat_interval = cfg.heartbeatInterval,
                )
                webSocket.send(json.encodeToString(AuthMessage.serializer(), auth))
                // 启动心跳
                startHeartbeat(cfg.heartbeatInterval)
            }

            override fun onMessage(webSocket: WebSocket, text: String) {
                handleServerMessage(text)
            }

            override fun onClosing(webSocket: WebSocket, code: Int, reason: String) {
                Log.i(TAG, "onClosing: $code / $reason")
                webSocket.close(code, reason)
            }

            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) {
                Log.i(TAG, "onClosed: $code / $reason")
                onSocketLost()
            }

            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) {
                Log.e(TAG, "onFailure: ${t.message}")
                onSocketLost()
            }
        })
    }

    /** 处理服务端消息，按 type 分发 */
    private fun handleServerMessage(text: String) {
        val env = runCatching { json.decodeFromString(ServerEnvelope.serializer(), text) }.getOrNull()
            ?: return

        when (env.type.takeUnless { it.isNullOrBlank() }) {
            "auth_result" -> {
                if (env.success || env.code == 0) {
                    Log.i(TAG, "auth success")
                    reconnectAttempts = 0
                    _state.value = ConnectionState.CONNECTED
                } else {
                    Log.w(TAG, "auth failed: ${env.message}")
                    // 鉴权失败不重连，避免无效循环
                    shouldReconnect = false
                    // 主动关闭 socket，避免连接泄漏
                    socket?.close(1008, "auth failed")
                    socket = null
                    heartbeatJob?.cancel()
                    heartbeatJob = null
                    _state.value = ConnectionState.DISCONNECTED
                }
            }
            "pong" -> {
                // 收到对心跳 ping 的响应
                lastPongTime = System.currentTimeMillis()
                pendingPongs.set(0)
            }
            "ping" -> {
                // 响应服务端主动 ping（服务端心跳检测）
                socket?.send(json.encodeToString(PongMessage.serializer(), PongMessage()))
            }
            "push" -> {
                val msg = PushMessage(
                    id = env.id ?: java.util.UUID.randomUUID().toString(),
                    title = env.title ?: "",
                    content = env.content ?: "",
                    priority = env.priority ?: "default",
                    timestamp = if (env.timestamp > 0) env.timestamp else System.currentTimeMillis(),
                )
                onPushMessage(msg)
            }
            else -> {
                // 兼容旧格式：type 为空/空白但 code==0 或 -1（默认值）时，尝试从 data 提取推送消息
                if (env.type.isNullOrBlank() && (env.code == 0 || env.code == -1)) {
                    val msgId = extractStringFromData(env.data, "message_id")
                        ?: extractStringFromData(env.data, "id")
                    val title = extractStringFromData(env.data, "title") ?: ""
                    val content = extractStringFromData(env.data, "content") ?: ""
                    if (title.isNotBlank() || content.isNotBlank()) {
                        val msg = PushMessage(
                            id = msgId ?: java.util.UUID.randomUUID().toString(),
                            title = title,
                            content = content,
                            priority = extractStringFromData(env.data, "priority") ?: "default",
                            timestamp = extractLongFromData(env.data, "timestamp") ?: System.currentTimeMillis(),
                        )
                        onPushMessage(msg)
                    } else if (env.message == "pong") {
                        // 旧格式的 pong 响应
                        lastPongTime = System.currentTimeMillis()
                        pendingPongs.set(0)
                    }
                } else {
                    Log.d(TAG, "unknown message type: ${env.type}")
                }
            }
        }
    }

    /** 从 JsonElement 中提取字符串字段 */
    private fun extractStringFromData(element: kotlinx.serialization.json.JsonElement?, key: String): String? {
        if (element == null || element !is JsonObject) return null
        val obj = element.jsonObject
        return obj[key]?.let { if (it is JsonPrimitive) it.content else null }
    }

    /** 从 JsonElement 中提取 Long 字段 */
    private fun extractLongFromData(element: kotlinx.serialization.json.JsonElement?, key: String): Long? {
        if (element == null || element !is JsonObject) return null
        val obj = element.jsonObject
        return obj[key]?.let { runCatching { it.jsonPrimitive.content.toLong() }.getOrNull() }
    }

    /** 启动心跳协程 */
    private fun startHeartbeat(intervalSeconds: Int) {
        heartbeatJob?.cancel()
        pendingPongs.set(0)
        lastPongTime = System.currentTimeMillis()
        heartbeatJob = scope.launch {
            // 首次延迟一个间隔
            delay(intervalSeconds * 1000L)
            while (isActive) {
                val sock = socket
                if (sock == null) break
                // 发送心跳 ping
                val sent = sock.send(json.encodeToString(PingMessage.serializer(), PingMessage()))
                if (sent) {
                    pendingPongs.incrementAndGet()
                } else {
                    // send 失败说明 socket 已关闭，直接触发重连（避免空转）
                    Log.w(TAG, "heartbeat send failed, socket may be closed, reconnecting")
                    onSocketLost()
                    break
                }
                // 连续 MAX_MISSED_PONG 次未响应则判定连接死亡，触发重连
                if (pendingPongs.get() >= MAX_MISSED_PONG) {
                    Log.w(TAG, "heartbeat timeout (${pendingPongs.get()} missed), reconnecting")
                    // 仅调用 cancel，让 onFailure 统一处理 onSocketLost，避免双重触发
                    socket?.cancel()
                    break
                }
                delay(intervalSeconds * 1000L)
            }
        }
    }

    /** 底层 socket 丢失（关闭/异常），决定是否重连 */
    private fun onSocketLost() {
        heartbeatJob?.cancel()
        socket = null
        if (!shouldReconnect) {
            _state.value = ConnectionState.DISCONNECTED
            return
        }
        scheduleReconnect()
    }

    /** 安排一次重连（指数退避 + 抖动） */
    private fun scheduleReconnect() {
        reconnectJob?.cancel()
        reconnectAttempts = (reconnectAttempts + 1).coerceAtMost(20)
        // 限制 shift 位数防止溢出：最多移位 5 位（2^5=32倍），超出后维持最大退避
        val shiftBits = (reconnectAttempts - 1).coerceAtMost(5)
        val delayMs = (RECONNECT_BASE_MS * (1L shl shiftBits))
            .coerceIn(RECONNECT_BASE_MS, RECONNECT_MAX_MS)
        // 加入 ±20% 抖动，避免雷同重连风暴
        val jitter = (delayMs * 0.2 * (Math.random() * 2 - 1)).toLong()
        val actualDelay = (delayMs + jitter).coerceIn(500L, RECONNECT_MAX_MS)
        Log.i(TAG, "scheduleReconnect attempt=$reconnectAttempts delay=${actualDelay}ms")
        _state.value = ConnectionState.RECONNECTING
        reconnectJob = scope.launch {
            delay(actualDelay)
            if (shouldReconnect) openConnection()
        }
    }

    private fun cancelJobs() {
        heartbeatJob?.cancel()
        heartbeatJob = null
        reconnectJob?.cancel()
        reconnectJob = null
    }

    /** 伴随 WebSocket 工厂，便于复用 OkHttpClient */
    object Factory {
        fun createOkHttpClient(): OkHttpClient = OkHttpClient.Builder()
            .pingInterval(0, TimeUnit.SECONDS) // 关闭 OkHttp 内建 ping，使用应用层心跳
            .readTimeout(0, TimeUnit.MILLISECONDS) // 长连接不超时
            .connectTimeout(15, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .build()
    }
}
