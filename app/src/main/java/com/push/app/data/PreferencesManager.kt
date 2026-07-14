package com.push.app.data

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.map
import org.json.JSONObject

// 顶层扩展，保证全局唯一 DataStore 实例
private val Context.pushDataStore: DataStore<Preferences> by preferencesDataStore(name = "push_settings")

/**
 * DataStore 偏好管理：保存推送 Key、服务器地址、心跳间隔。
 *
 * 服务器地址拆分为：
 * - WebSocket 地址（[serverUrlFlow]，如 ws://192.168.1.100:9502，供 PushWebSocket 使用）
 * - HTTP API 地址（[httpServerUrlFlow]，如 http://192.168.1.100:9501，供 TestPushApi 使用）
 *
 * 初始化时读取 assets/build_config.json，若存在则用其中的值覆盖硬编码默认值。
 * 心跳间隔默认 30 秒（范围 10-300）。
 */
class PreferencesManager(private val context: Context) {

    private object Keys {
        val PUSH_KEY = stringPreferencesKey("push_key")
        val SERVER_URL = stringPreferencesKey("server_url")
        val HTTP_SERVER_URL = stringPreferencesKey("http_server_url")
        val HEARTBEAT_INTERVAL = intPreferencesKey("heartbeat_interval")
    }

    // 启动时一次性读取 assets/build_config.json，作为默认值回退（文件不存在或解析失败为 null）
    private val buildConfig: JSONObject? = loadBuildConfig(context)

    /** 推送 Key（用户输入） */
    val keyFlow: Flow<String> = context.pushDataStore.data.map { it[Keys.PUSH_KEY] ?: "" }

    /** 默认推送 Key（从 build_config.json 读取，用户未设置 Key 时使用） */
    val defaultKeyFlow: Flow<String> =
        flowOf(buildConfig?.optString("default_key")?.takeIf { it.isNotBlank() } ?: DEFAULT_KEY)

    /** 服务器 WebSocket 地址（如 ws://192.168.1.100:9502），用于 PushWebSocket */
    val serverUrlFlow: Flow<String> =
        context.pushDataStore.data.map {
            it[Keys.SERVER_URL] ?: (buildConfig?.optString("server_ws_url")?.takeIf { it.isNotBlank() }
                ?: DEFAULT_SERVER_URL)
        }

    /** 服务器 HTTP API 地址（如 http://192.168.1.100:9501），用于 TestPushApi */
    val httpServerUrlFlow: Flow<String> =
        context.pushDataStore.data.map {
            it[Keys.HTTP_SERVER_URL] ?: (buildConfig?.optString("server_url")?.takeIf { it.isNotBlank() }
                ?: DEFAULT_HTTP_SERVER_URL)
        }

    /** 心跳间隔（秒），范围 10-300 */
    val heartbeatIntervalFlow: Flow<Int> =
        context.pushDataStore.data.map { it[Keys.HEARTBEAT_INTERVAL] ?: DEFAULT_HEARTBEAT }

    /** 同步读取当前 Key（仅用于启动判断） */
    suspend fun getKeySync(): String =
        context.pushDataStore.data.map { it[Keys.PUSH_KEY] ?: "" }.first()

    suspend fun saveKey(key: String) {
        context.pushDataStore.edit { it[Keys.PUSH_KEY] = key.trim() }
    }

    suspend fun clearKey() {
        context.pushDataStore.edit { it.remove(Keys.PUSH_KEY) }
    }

    suspend fun saveServerUrl(url: String) {
        context.pushDataStore.edit { it[Keys.SERVER_URL] = url.trim() }
    }

    suspend fun saveHttpServerUrl(url: String) {
        context.pushDataStore.edit { it[Keys.HTTP_SERVER_URL] = url.trim() }
    }

    /** 保存心跳间隔，自动夹取到 [10, 300] 区间 */
    suspend fun saveHeartbeatInterval(seconds: Int) {
        val clamped = seconds.coerceIn(MIN_HEARTBEAT, MAX_HEARTBEAT)
        context.pushDataStore.edit { it[Keys.HEARTBEAT_INTERVAL] = clamped }
    }

    /** 读取 assets/build_config.json，不存在或解析失败返回 null */
    private fun loadBuildConfig(context: Context): JSONObject? {
        return try {
            val json = context.assets.open("build_config.json").bufferedReader().use { it.readText() }
            JSONObject(json)
        } catch (e: Exception) {
            null
        }
    }

    companion object {
        const val DEFAULT_SERVER_URL = "ws://192.168.1.100:9502"
        const val DEFAULT_HTTP_SERVER_URL = "http://192.168.1.100:9501"
        const val DEFAULT_KEY = ""
        const val DEFAULT_HEARTBEAT = 30
        const val MIN_HEARTBEAT = 10
        const val MAX_HEARTBEAT = 300
    }
}
