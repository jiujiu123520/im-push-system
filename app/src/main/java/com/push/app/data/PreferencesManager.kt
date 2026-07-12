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
import kotlinx.coroutines.flow.map

// 顶层扩展，保证全局唯一 DataStore 实例
private val Context.pushDataStore: DataStore<Preferences> by preferencesDataStore(name = "push_settings")

/**
 * DataStore 偏好管理：保存推送 Key、服务器地址、心跳间隔。
 *
 * 默认服务器地址为本地开发占位，心跳间隔默认 30 秒（范围 10-300）。
 */
class PreferencesManager(private val context: Context) {

    private object Keys {
        val PUSH_KEY = stringPreferencesKey("push_key")
        val SERVER_URL = stringPreferencesKey("server_url")
        val HEARTBEAT_INTERVAL = intPreferencesKey("heartbeat_interval")
    }

    /** 推送 Key */
    val keyFlow: Flow<String> = context.pushDataStore.data.map { it[Keys.PUSH_KEY] ?: "" }

    /** 服务器 WebSocket 地址（如 ws://192.168.1.100:9502） */
    val serverUrlFlow: Flow<String> =
        context.pushDataStore.data.map { it[Keys.SERVER_URL] ?: DEFAULT_SERVER_URL }

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

    /** 保存心跳间隔，自动夹取到 [10, 300] 区间 */
    suspend fun saveHeartbeatInterval(seconds: Int) {
        val clamped = seconds.coerceIn(MIN_HEARTBEAT, MAX_HEARTBEAT)
        context.pushDataStore.edit { it[Keys.HEARTBEAT_INTERVAL] = clamped }
    }

    companion object {
        const val DEFAULT_SERVER_URL = "ws://192.168.1.100:9502"
        const val DEFAULT_HEARTBEAT = 30
        const val MIN_HEARTBEAT = 10
        const val MAX_HEARTBEAT = 300
    }
}
