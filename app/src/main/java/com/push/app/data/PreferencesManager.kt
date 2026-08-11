package com.push.app.data

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.pushDataStore: DataStore<Preferences> by preferencesDataStore(name = "push_settings")

object PreferencesManager {

    private val KEY = stringPreferencesKey("key")
    private val SERVER_URL = stringPreferencesKey("server_url")
    private val WS_URL = stringPreferencesKey("ws_url")
    private val USER_TOKEN = stringPreferencesKey("user_token")
    private val USER_ID = stringPreferencesKey("user_id")
    private val HEARTBEAT_INTERVAL = intPreferencesKey("heartbeat_interval")
    private val VIBRATE = booleanPreferencesKey("vibrate")
    private val WIFI_ONLY = booleanPreferencesKey("wifi_only")
    private val AUTO_RECONNECT = booleanPreferencesKey("auto_reconnect")
    private val THEME = stringPreferencesKey("theme")

    private lateinit var appContext: Context

    fun init(context: Context) {
        appContext = context.applicationContext
    }

    val keyFlow: Flow<String>
        get() = appContext.pushDataStore.data.map { it[KEY] ?: "" }

    val serverUrlFlow: Flow<String>
        get() = appContext.pushDataStore.data.map { it[SERVER_URL] ?: "" }

    val wsUrlFlow: Flow<String>
        get() = appContext.pushDataStore.data.map { it[WS_URL] ?: "" }

    val userTokenFlow: Flow<String>
        get() = appContext.pushDataStore.data.map { it[USER_TOKEN] ?: "" }

    val userIdFlow: Flow<String>
        get() = appContext.pushDataStore.data.map { it[USER_ID] ?: "" }

    val heartbeatIntervalFlow: Flow<Int>
        get() = appContext.pushDataStore.data.map { it[HEARTBEAT_INTERVAL] ?: 30 }

    val vibrateFlow: Flow<Boolean>
        get() = appContext.pushDataStore.data.map { it[VIBRATE] ?: true }

    val wifiOnlyFlow: Flow<Boolean>
        get() = appContext.pushDataStore.data.map { it[WIFI_ONLY] ?: false }

    val autoReconnectFlow: Flow<Boolean>
        get() = appContext.pushDataStore.data.map { it[AUTO_RECONNECT] ?: true }

    val themeFlow: Flow<String>
        get() = appContext.pushDataStore.data.map { it[THEME] ?: "dark" }

    suspend fun getKey(): String = appContext.pushDataStore.data.map { it[KEY] ?: "" }.first()
    suspend fun setKey(value: String) {
        appContext.pushDataStore.edit { it[KEY] = value }
    }

    suspend fun getServerUrl(): String = appContext.pushDataStore.data.map { it[SERVER_URL] ?: "" }.first()
    suspend fun setServerUrl(value: String) {
        appContext.pushDataStore.edit { it[SERVER_URL] = value }
    }

    suspend fun getWsUrl(): String = appContext.pushDataStore.data.map { it[WS_URL] ?: "" }.first()
    suspend fun setWsUrl(value: String) {
        appContext.pushDataStore.edit { it[WS_URL] = value }
    }

    suspend fun getUserToken(): String = appContext.pushDataStore.data.map { it[USER_TOKEN] ?: "" }.first()
    suspend fun setUserToken(value: String) {
        appContext.pushDataStore.edit { it[USER_TOKEN] = value }
    }

    suspend fun getUserId(): String = appContext.pushDataStore.data.map { it[USER_ID] ?: "" }.first()
    suspend fun setUserId(value: String) {
        appContext.pushDataStore.edit { it[USER_ID] = value }
    }

    suspend fun getHeartbeatInterval(): Int = appContext.pushDataStore.data.map { it[HEARTBEAT_INTERVAL] ?: 30 }.first()
    suspend fun setHeartbeatInterval(value: Int) {
        appContext.pushDataStore.edit { it[HEARTBEAT_INTERVAL] = value }
    }

    suspend fun isVibrate(): Boolean = appContext.pushDataStore.data.map { it[VIBRATE] ?: true }.first()
    suspend fun setVibrate(value: Boolean) {
        appContext.pushDataStore.edit { it[VIBRATE] = value }
    }

    suspend fun isWifiOnly(): Boolean = appContext.pushDataStore.data.map { it[WIFI_ONLY] ?: false }.first()
    suspend fun setWifiOnly(value: Boolean) {
        appContext.pushDataStore.edit { it[WIFI_ONLY] = value }
    }

    suspend fun isAutoReconnect(): Boolean = appContext.pushDataStore.data.map { it[AUTO_RECONNECT] ?: true }.first()
    suspend fun setAutoReconnect(value: Boolean) {
        appContext.pushDataStore.edit { it[AUTO_RECONNECT] = value }
    }

    suspend fun getTheme(): String = appContext.pushDataStore.data.map { it[THEME] ?: "dark" }.first()
    suspend fun setTheme(value: String) {
        appContext.pushDataStore.edit { it[THEME] = value }
    }
}
