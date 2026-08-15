package com.push.app.data

import android.content.Context
import android.util.Log
import com.push.app.data.model.PushMessage
import com.push.app.network.ApiClient
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import java.util.UUID

object RepoPrefs {
    val keyFlow get() = PreferencesManager.keyFlow
    val serverUrlFlow get() = PreferencesManager.serverUrlFlow
    val httpServerUrlFlow get() = PreferencesManager.serverUrlFlow
    val wsUrlFlow get() = PreferencesManager.wsUrlFlow
    val userTokenFlow get() = PreferencesManager.userTokenFlow
    val userIdFlow get() = PreferencesManager.userIdFlow
    val userInfoFlow get() = PreferencesManager.userIdFlow
    val heartbeatIntervalFlow get() = PreferencesManager.heartbeatIntervalFlow
    val vibrateFlow get() = PreferencesManager.vibrateFlow
    val wifiOnlyFlow get() = PreferencesManager.wifiOnlyFlow
    val autoReconnectFlow get() = PreferencesManager.autoReconnectFlow
    val themeModeFlow get() = PreferencesManager.themeFlow

    suspend fun saveKey(v: String) = PreferencesManager.setKey(v)
    suspend fun saveServerUrl(v: String) = PreferencesManager.setServerUrl(v)
    suspend fun saveHttpServerUrl(v: String) = PreferencesManager.setServerUrl(v)
    suspend fun saveWsUrl(v: String) = PreferencesManager.setWsUrl(v)
    suspend fun saveUserToken(v: String) = PreferencesManager.setUserToken(v)
    suspend fun saveUserInfo(v: Any) = run {
        val user = v as? com.push.app.data.UserInfo ?: return@run
        PreferencesManager.setUserId(user.id)
    }
    suspend fun saveUserId(v: String) = PreferencesManager.setUserId(v)
    suspend fun saveHeartbeatInterval(v: Int) = PreferencesManager.setHeartbeatInterval(v)
    suspend fun saveVibrate(v: Boolean) = PreferencesManager.setVibrate(v)
    suspend fun saveWifiOnly(v: Boolean) = PreferencesManager.setWifiOnly(v)
    suspend fun saveAutoReconnect(v: Boolean) = PreferencesManager.setAutoReconnect(v)
    suspend fun saveThemeMode(v: String) = PreferencesManager.setTheme(v)
    suspend fun clearUserAuth() {
        PreferencesManager.setUserToken("")
        PreferencesManager.setUserId("")
    }
}

data class KeyResponse(
    val success: Boolean,
    val message: String = "",
    val deviceId: String = "",
)

class PushRepository private constructor(
    private val context: Context,
    private val scope: CoroutineScope,
) {

    private val wsClient = PushWebSocket(
        client = PushWebSocket.Factory.createOkHttpClient(),
        scope = scope,
        onPushMessage = { msg ->
            scope.launch {
                store.add(msg)
            }
        },
    )

    private val store: MessageStore = MessageStore(
        File(context.filesDir, "messages").apply { mkdirs() }
    )

    val connectionState: StateFlow<ConnectionState> get() = wsClient.state
    val messages: StateFlow<List<PushMessage>> get() = store.messages
    val prefs = RepoPrefs

    private var cachedDeviceId: String? = null

    fun connect() {
        scope.launch {
            val url = PreferencesManager.getWsUrl()
            val key = PreferencesManager.getKey()
            val hb = PreferencesManager.getHeartbeatInterval()
            val auto = PreferencesManager.isAutoReconnect()
            if (url.isBlank() || key.isBlank()) {
                Log.w(TAG, "connect aborted: url/key blank")
                return@launch
            }
            val deviceId = getDeviceIdPublic()
            wsClient.connect(
                ConnectConfig(
                    url = url,
                    key = key,
                    deviceId = deviceId,
                    heartbeatInterval = hb,
                    autoReconnect = auto,
                )
            )
        }
    }

    fun reconnect() {
        scope.launch {
            wsClient.disconnect()
            val url = PreferencesManager.getWsUrl()
            val key = PreferencesManager.getKey()
            val hb = PreferencesManager.getHeartbeatInterval()
            val auto = PreferencesManager.isAutoReconnect()
            val deviceId = getDeviceIdPublic()
            if (url.isBlank() || key.isBlank()) return@launch
            wsClient.connect(
                ConnectConfig(
                    url = url,
                    key = key,
                    deviceId = deviceId,
                    heartbeatInterval = hb,
                    autoReconnect = auto,
                )
            )
        }
    }

    fun disconnect() {
        wsClient.disconnect()
    }

    suspend fun clearMessages() {
        store.clear()
    }

    suspend fun deleteMessageLocal(id: String) {
        store.delete(id)
    }

    suspend fun markAsReadLocal(id: String) {
        store.markAsRead(id)
    }

    suspend fun deleteMessage(id: String): Boolean = withContext(Dispatchers.IO) {
        runCatching {
            val baseUrl = ApiClient.baseUrl().trimEnd('/')
            val url = "$baseUrl/api/messages/$id"
            val request = Request.Builder().url(url).delete().build()
            val response = ApiClient.client.newCall(request).execute()
            val raw = response.body?.string() ?: ""
            val ok = JSONObject(raw).optBoolean("success", response.isSuccessful)
            if (ok) store.delete(id)
            ok
        }.getOrDefault(false)
    }

    suspend fun fetchMessages(page: Int): List<PushMessage> = withContext(Dispatchers.IO) {
        runCatching {
            val baseUrl = ApiClient.baseUrl().trimEnd('/')
            val userId = PreferencesManager.getUserId()
            val url = "$baseUrl/api/messages?page=$page&user_id=$userId"
            val request = Request.Builder().url(url).get().build()
            val response = ApiClient.client.newCall(request).execute()
            val raw = response.body?.string() ?: ""
            val json = JSONObject(raw)
            val data = json.optJSONObject("data") ?: return@runCatching emptyList()
            val array = data.optJSONArray("messages") ?: data.optJSONArray("list") ?: JSONArray()
            val result = mutableListOf<PushMessage>()
            for (i in 0 until array.length()) {
                result.add(PushMessage.fromJson(array.getJSONObject(i).toString()))
            }
            result
        }.getOrDefault(emptyList())
    }

    suspend fun markAsRead(id: String): Boolean = withContext(Dispatchers.IO) {
        runCatching {
            val baseUrl = ApiClient.baseUrl().trimEnd('/')
            val url = "$baseUrl/api/messages/$id/read"
            val body = JSONObject().put("id", id).toString()
            val request = Request.Builder()
                .url(url)
                .post(body.toRequestBody("application/json".toMediaType()))
                .build()
            val response = ApiClient.client.newCall(request).execute()
            val raw = response.body?.string() ?: ""
            JSONObject(raw).optBoolean("success", response.isSuccessful)
        }.getOrDefault(false)
    }

    suspend fun sendKey(key: String): Result<KeyResponse> = withContext(Dispatchers.IO) {
        runCatching {
            val baseUrl = ApiClient.baseUrl().trimEnd('/')
            val url = "$baseUrl/api/device/key"
            val body = JSONObject().put("key", key).toString()
            val request = Request.Builder()
                .url(url)
                .post(body.toRequestBody("application/json".toMediaType()))
                .build()
            val response = ApiClient.client.newCall(request).execute()
            val raw = response.body?.string() ?: ""
            val json = JSONObject(raw)
            KeyResponse(
                success = json.optBoolean("success", response.isSuccessful),
                message = json.optString("message", ""),
                deviceId = json.optJSONObject("data")?.optString("device_id", "") ?: "",
            )
        }
    }

    fun getDeviceIdPublic(): String {
        cachedDeviceId?.let { return it }
        val prefsFile = File(context.filesDir.parentFile, "shared_prefs")
        cachedDeviceId = prefsFile?.listFiles()
            ?.mapNotNull { f ->
                runCatching {
                    val xml = File(f, "user_id.xml")
                    if (xml.exists()) {
                        val text = xml.readText()
                        val m = Regex("""value="([^"]+)"""").find(text)
                        m?.groupValues?.getOrNull(1)
                    } else null
                }.getOrNull()
            }?.firstOrNull()
        if (cachedDeviceId.isNullOrBlank()) {
            cachedDeviceId = runCatching {
                android.provider.Settings.Secure.getString(
                    context.contentResolver,
                    android.provider.Settings.Secure.ANDROID_ID
                )
            }.getOrNull() ?: UUID.randomUUID().toString().take(16)
        }
        return cachedDeviceId!!
    }

    fun getStorageSize(): String {
        return runCatching {
            val dir = context.filesDir
            var total = 0L
            dir.walkTopDown().forEach {
                if (it.isFile) total += it.length()
            }
            val prefsDir = File(context.filesDir.parentFile, "shared_prefs")
            prefsDir?.walkTopDown()?.forEach {
                if (it.isFile) total += it.length()
            }
            val kb = total / 1024.0
            if (kb > 1024) "%.1f MB".format(kb / 1024) else "%.1f KB".format(kb)
        }.getOrDefault("0 KB")
    }

    fun getMessages(): MessageStore = store

    companion object {
        private const val TAG = "PushRepository"

        @Volatile
        private var instance: PushRepository? = null

        fun get(context: Context): PushRepository =
            instance ?: synchronized(this) {
                instance ?: PushRepository(
                    context.applicationContext,
                    CoroutineScope(SupervisorJob() + Dispatchers.IO)
                ).also { instance = it }
            }
    }
}
