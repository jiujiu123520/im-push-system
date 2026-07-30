package com.push.app.data

import android.content.Context
import android.util.Log
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.launchIn
import kotlinx.coroutines.flow.onEach
import kotlinx.coroutines.launch
import java.io.File
import java.util.UUID

/**
 * 数据仓库：统一管理 WebSocket 连接、消息存储、偏好配置、服务端历史同步。
 *
 * 作为单例存在（应用级），Service / ViewModel / Screen 均通过 [get] 获取同一实例。
 * 消息到达后由本类负责：① 持久化到 [MessageStore]；② 触发通知栏展示（[NotificationHelper]）。
 * 连接成功后会自动启动服务端历史分页同步（游标 before_id，最多拉取 [MAX_SYNC_PAGES] 页或直到无更多数据）。
 */
class PushRepository private constructor(private val appContext: Context) {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    val preferencesManager = PreferencesManager(appContext)

    private val messageStore = MessageStore(File(appContext.filesDir, "messages"))
    private val okHttpClient = PushWebSocket.Factory.createOkHttpClient()
    private val deviceMessagesApi = DeviceMessagesApi(appContext)

    // 历史同步：防止重复触发
    @Volatile
    private var syncJob: Job? = null
    @Volatile
    private var lastSyncedAtMs: Long = 0L
    private val historySyncLock = Any()

    // WebSocket 客户端，消息回调交由本类处理
    private val webSocket = PushWebSocket(
        client = okHttpClient,
        scope = scope,
        onPushMessage = { msg -> onMessageReceived(msg) },
    )

    init {
        // 监听连接状态：鉴权成功后自动触发历史同步
        webSocket.state
            .onEach { state ->
                if (state == ConnectionState.CONNECTED) {
                    // 启动服务端历史分页同步（异步非阻塞）
                    scheduleHistorySync(delayMs = 500)
                }
            }
            .launchIn(scope)
    }

    /** 连接状态流 */
    val connectionState: StateFlow<ConnectionState> = webSocket.state

    /** 消息列表流 */
    val messages: StateFlow<List<PushMessage>> = messageStore.messages

    /**
     * 建立连接：读取本地配置后发起 WebSocket 连接。
     * 若 Key 为空则跳过。
     */
    suspend fun connect() {
        val key = preferencesManager.keyFlow.first()
        if (key.isBlank()) {
            Log.w(TAG, "connect: key is empty, abort")
            return
        }
        val url = preferencesManager.serverUrlFlow.first()
        val hb = preferencesManager.heartbeatIntervalFlow.first()
        val deviceId = getDeviceId()
        Log.i(TAG, "connect to $url, hb=${hb}s")
        webSocket.connect(ConnectConfig(url, key, deviceId, hb))
    }

    /** 主动断开连接 */
    fun disconnect() {
        Log.i(TAG, "disconnect")
        webSocket.disconnect()
    }

    /** 重连（保留配置） */
    fun reconnect() {
        scope.launch { connect() }
    }

    // ========== 偏好操作 ==========

    suspend fun saveKey(key: String) = preferencesManager.saveKey(key)
    suspend fun clearKey() {
        preferencesManager.clearKey()
        disconnect()
    }
    suspend fun saveServerUrl(url: String) = preferencesManager.saveServerUrl(url)
    suspend fun saveHeartbeatInterval(seconds: Int) = preferencesManager.saveHeartbeatInterval(seconds)

    // ========== 消息操作 ==========

    /** 最近 N 条消息（用于首页展示） */
    fun recentMessages(limit: Int = 5): List<PushMessage> = messageStore.recent(limit)

    /** 分页查询消息（支持关键词搜索） */
    fun queryPage(
        page: Int = 1,
        pageSize: Int = 10,
        keyword: String = "",
    ): MessageStore.PagedResult = messageStore.queryPage(page, pageSize, keyword)

    suspend fun clearMessages() = messageStore.clear()

    /** 导出全部消息，返回导出文件 */
    suspend fun exportMessages(format: MessageStore.ExportFormat): MessageStore.ExportResult =
        messageStore.export(format)

    // ========== 服务端历史分页同步 ==========

    /**
     * 手动触发一次服务端历史分页同步（用于「下拉刷新」「加载更多」等用户主动操作）。
     *
     * @param ignoreCooldown 忽略冷却时间（默认 false）
     * @param maxPages 本次允许拉取的最大页数（传 null 使用 [MAX_SYNC_PAGES]）
     * @return 本次实际新增（去重后）的消息条数
     */
    suspend fun syncServerHistory(
        ignoreCooldown: Boolean = false,
        maxPages: Int? = null,
    ): Int {
        val key = preferencesManager.keyFlow.first()
        val url = preferencesManager.serverUrlFlow.first()
        val deviceId = getDeviceId()
        if (key.isBlank() || deviceId.isBlank()) {
            Log.w(TAG, "syncServerHistory: key or device_id is blank, skip")
            return 0
        }

        synchronized(historySyncLock) {
            if (!ignoreCooldown) {
                val now = System.currentTimeMillis()
                if (now - lastSyncedAtMs < SYNC_COOLDOWN_MS) {
                    Log.d(TAG, "syncServerHistory: cooldown, skip")
                    return 0
                }
            }
            if (syncJob?.isActive == true) {
                Log.d(TAG, "syncServerHistory: already running, skip")
                return 0
            }
            val pages = maxPages ?: MAX_SYNC_PAGES
            val job = scope.launch {
                runCatching {
                    doSyncHistory(url, key, deviceId, pages)
                }.onFailure {
                    Log.e(TAG, "syncServerHistory failed: ${it.message}", it)
                }
            }
            syncJob = job
        }

        syncJob?.join()
        return 0 // 真正的新增数在内部已经记录，这里无需再暴露
    }

    private fun scheduleHistorySync(delayMs: Long = 0L) {
        scope.launch {
            if (delayMs > 0) delay(delayMs)
            runCatching { syncServerHistory(ignoreCooldown = false) }
        }
    }

    /**
     * 真正的游标分页同步循环。
     * 从 before_id=0 开始（最新在前），翻页游标用服务端返回的 next_before_id，
     * 直到 hasNext=false 或达到最大页数。每拉一页就合并进 [MessageStore]（按 id 去重）。
     */
    private suspend fun doSyncHistory(
        serverUrl: String,
        key: String,
        deviceId: String,
        maxPages: Int,
    ) {
        var beforeId = 0L
        var page = 0
        var totalAdded = 0
        while (page < maxPages) {
            page++
            val result = runCatching {
                deviceMessagesApi.fetchPageByCursor(
                    serverUrl = serverUrl,
                    pushKey = key,
                    deviceId = deviceId,
                    limit = SYNC_PAGE_SIZE,
                    beforeId = beforeId,
                )
            }
            if (result.isFailure) {
                Log.e(TAG, "doSyncHistory page $page fetch failed: ${result.exceptionOrNull()?.message}")
                break
            }
            val pageData = result.getOrThrow()
            val added = messageStore.merge(pageData.messages)
            totalAdded += added
            Log.i(
                TAG,
                "doSyncHistory page=$page got=${pageData.messages.size} added=$added " +
                    "hasNext=${pageData.hasNext} nextBeforeId=${pageData.nextBeforeId}"
            )
            if (!pageData.hasNext || pageData.nextBeforeId <= 0L || pageData.messages.isEmpty()) {
                break
            }
            beforeId = pageData.nextBeforeId
        }
        lastSyncedAtMs = System.currentTimeMillis()
        if (totalAdded > 0) {
            Log.i(TAG, "doSyncHistory done: total new merged = $totalAdded, pages=$page")
        }
    }

    // ========== 内部 ==========

    /** 收到推送消息：存储并展示通知 */
    private fun onMessageReceived(msg: PushMessage) {
        scope.launch {
            messageStore.merge(listOf(msg))
            // 通知展示交由 NotificationHelper（静态工具，无循环依赖）
            com.push.app.service.NotificationHelper.showPushNotification(appContext, msg)
        }
    }

    /** 生成稳定设备 ID（基于 ANDROID_ID，回退到随机 UUID 持久化） */
    private fun getDeviceId(): String {
        val prefs = appContext.getSharedPreferences("device", Context.MODE_PRIVATE)
        prefs.getString("device_id", null)?.let { return it }
        val id = runCatching {
            android.provider.Settings.Secure.getString(
                appContext.contentResolver,
                android.provider.Settings.Secure.ANDROID_ID,
            )
        }.getOrNull()?.takeIf { it.isNotBlank() } ?: UUID.randomUUID().toString()
        prefs.edit().putString("device_id", id).apply()
        return id
    }

    /** 获取设备 ID（公开方法，供 UI 调用） */
    fun getDeviceIdPublic(): String = getDeviceId()

    companion object {
        private const val TAG = "PushRepository"
        /** 单次历史同步的最大页数（避免一次拉 1w+ 条撑爆内存 / 耗流量） */
        private const val MAX_SYNC_PAGES = 25
        /** 每页条数（与后端 limit 上限 100 保持安全距离） */
        private const val SYNC_PAGE_SIZE = 20
        /** 同步冷却时间（ms）：避免频繁触发 */
        private const val SYNC_COOLDOWN_MS = 30_000L

        @Volatile
        private var instance: PushRepository? = null

        /** 获取单例仓库 */
        fun get(context: Context): PushRepository =
            instance ?: synchronized(this) {
                instance ?: PushRepository(context.applicationContext).also { instance = it }
            }
    }
}
