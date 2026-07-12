package com.push.app.data

import android.content.Context
import android.util.Log
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import java.io.File
import java.util.UUID

/**
 * 数据仓库：统一管理 WebSocket 连接、消息存储、偏好配置。
 *
 * 作为单例存在（应用级），Service / ViewModel / Screen 均通过 [get] 获取同一实例。
 * 消息到达后由本类负责：① 持久化到 [MessageStore]；② 触发通知栏展示（[NotificationHelper]）。
 */
class PushRepository private constructor(private val appContext: Context) {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    val preferencesManager = PreferencesManager(appContext)

    private val messageStore = MessageStore(File(appContext.filesDir, "messages"))
    private val okHttpClient = PushWebSocket.Factory.createOkHttpClient()

    // WebSocket 客户端，消息回调交由本类处理
    private val webSocket = PushWebSocket(
        client = okHttpClient,
        scope = scope,
        onPushMessage = { msg -> onMessageReceived(msg) },
    )

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

    suspend fun clearMessages() = messageStore.clear()

    /** 导出全部消息，返回导出文件 */
    suspend fun exportMessages(format: MessageStore.ExportFormat): MessageStore.ExportResult =
        messageStore.export(format)

    // ========== 内部 ==========

    /** 收到推送消息：存储并展示通知 */
    private fun onMessageReceived(msg: PushMessage) {
        scope.launch {
            messageStore.add(msg)
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

        @Volatile
        private var instance: PushRepository? = null

        /** 获取单例仓库 */
        fun get(context: Context): PushRepository =
            instance ?: synchronized(this) {
                instance ?: PushRepository(context.applicationContext).also { instance = it }
            }
    }
}
