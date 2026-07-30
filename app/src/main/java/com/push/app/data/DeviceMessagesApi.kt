package com.push.app.data

import android.content.Context
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.long
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

/**
 * 设备端历史消息 API 客户端
 *
 * 调用后端 `GET /api/device/messages` 接口，支持两种分页方式：
 * 1. **游标分页（推荐，移动端无限下拉）**：[fetchPageByCursor] 使用 before_id + limit
 * 2. **页码分页（兼容）**：[fetchPageByNumber] 使用 page + page_size
 *
 * 响应中额外包含 [ServerMessagePage.hasNext] / [ServerMessagePage.nextBeforeId]，
 * 方便 APP 循环拉取直到历史同步完毕。
 */
class DeviceMessagesApi(private val context: Context) {

    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        encodeDefaults = true
    }

    private val client = OkHttpClient.Builder()
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .build()

    /**
     * 按游标方式拉取一页历史消息。
     *
     * @param serverUrl 服务器地址（ws/wss 会自动转 http/https）
     * @param pushKey   推送 Key 值（如 Key_836）
     * @param deviceId  设备 ID
     * @param limit     每页条数，默认 20，最大 100
     * @param beforeId  游标：返回 id < beforeId 的消息；传 0 表示从最新一条开始拉
     */
    suspend fun fetchPageByCursor(
        serverUrl: String,
        pushKey: String,
        deviceId: String,
        limit: Int = 20,
        beforeId: Long = 0L,
    ): ServerMessagePage = withContext(Dispatchers.IO) {
        val httpUrl = normalizeHttpUrl(serverUrl).trimEnd('/')
        val url = buildString {
            append(httpUrl)
            append("/api/device/messages?")
            append("push_key=").append(urlEncode(pushKey))
            append("&device_id=").append(urlEncode(deviceId))
            append("&limit=").append(limit.coerceIn(1, 100))
            if (beforeId > 0) append("&before_id=").append(beforeId)
        }
        executeGet(url)
    }

    /**
     * 按页码方式拉取一页历史消息。
     *
     * @param page     页码，从 1 开始
     * @param pageSize 每页条数，默认 20，最大 100
     */
    suspend fun fetchPageByNumber(
        serverUrl: String,
        pushKey: String,
        deviceId: String,
        page: Int = 1,
        pageSize: Int = 20,
    ): ServerMessagePage = withContext(Dispatchers.IO) {
        val httpUrl = normalizeHttpUrl(serverUrl).trimEnd('/')
        val safePage = page.coerceAtLeast(1)
        val safeSize = pageSize.coerceIn(1, 100)
        val url = buildString {
            append(httpUrl)
            append("/api/device/messages?")
            append("push_key=").append(urlEncode(pushKey))
            append("&device_id=").append(urlEncode(deviceId))
            append("&page=").append(safePage)
            append("&page_size=").append(safeSize)
        }
        executeGet(url)
    }

    private fun executeGet(url: String): ServerMessagePage {
        val request = Request.Builder().url(url).get().build()
        val response = client.newCall(request).execute()
        val raw = response.body?.string().orEmpty()

        if (!response.isSuccessful) {
            Log.e(TAG, "messages HTTP ${response.code}: $raw")
            throw RuntimeException("服务器返回 ${response.code}")
        }

        val envelope = json.parseToJsonElement(raw).jsonObject
        val code = envelope["code"]?.jsonPrimitive?.contentOrNull?.toIntOrNull()
        val msg = envelope["message"]?.jsonPrimitive?.contentOrNull ?: "请求失败"
        if (code != 0) {
            throw RuntimeException(msg)
        }

        val data = envelope["data"]?.jsonObject
            ?: throw RuntimeException("响应缺少 data 字段")

        val listArr = data["list"]?.jsonArray.orEmpty()
        val messages = listArr.mapNotNull { elem: JsonElement ->
            val obj = elem.jsonObject
            val sid = textField(obj, "message_id")
                ?: textField(obj, "id")
                ?: return@mapNotNull null
            val title = textField(obj, "title").orEmpty()
            val content = textField(obj, "content").orEmpty()
            val priority = textField(obj, "priority") ?: "default"
            val ts = textField(obj, "created_at")?.let { parseTimestamp(it) } ?: 0L
            PushMessage(
                id = sid,
                title = title,
                content = content,
                priority = priority,
                timestamp = ts,
                receivedAt = System.currentTimeMillis(),
            )
        }

        val total = intField(data, "total") ?: 0
        val nextBeforeId = longField(data, "next_before_id") ?: 0L
        val hasMore = booleanField(data, "has_more") ?: (nextBeforeId > 0L)
        val page = intField(data, "page")
        val pageSize = intField(data, "page_size")
        val totalPages = intField(data, "total_pages")

        return ServerMessagePage(
            messages = messages,
            total = total,
            hasNext = hasMore,
            nextBeforeId = nextBeforeId,
            page = page,
            pageSize = pageSize,
            totalPages = totalPages,
        )
    }

    private fun textField(obj: JsonObject, key: String): String? =
        obj[key]?.jsonPrimitive?.contentOrNull

    private fun intField(obj: JsonObject, key: String): Int? =
        textField(obj, key)?.toIntOrNull()

    private fun longField(obj: JsonObject, key: String): Long? =
        textField(obj, key)?.toLongOrNull()

    private fun booleanField(obj: JsonObject, key: String): Boolean? =
        runCatching { obj[key]?.jsonPrimitive?.boolean }.getOrNull()
            ?: textField(obj, key)?.toBooleanStrictOrNull()

    /** 解析后端 created_at（"yyyy-MM-dd HH:mm:ss"）为毫秒时间戳 */
    private fun parseTimestamp(value: String): Long {
        return runCatching {
            val fmt = java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.getDefault())
            fmt.parse(value)?.time ?: 0L
        }.getOrDefault(0L)
    }

    private fun urlEncode(s: String): String =
        java.net.URLEncoder.encode(s, Charsets.UTF_8.name())

    private fun normalizeHttpUrl(url: String): String {
        val trimmed = url.trim()
        return when {
            trimmed.startsWith("ws://") -> "http://" + trimmed.removePrefix("ws://")
            trimmed.startsWith("wss://") -> "https://" + trimmed.removePrefix("wss://")
            else -> trimmed
        }
    }

    companion object {
        private const val TAG = "DeviceMessagesApi"
    }
}

/**
 * 服务端返回的一页消息。
 *
 * @param messages      当前页消息列表（已转为 [PushMessage]）
 * @param total         该设备在该 Key 下的消息总数
 * @param hasNext       是否还有下一页（更旧的历史）
 * @param nextBeforeId  下一页游标：作为下一次调用 [DeviceMessagesApi.fetchPageByCursor] 的 beforeId
 * @param page          页码分页时的当前页（游标分页为 null）
 * @param pageSize      页码分页时的每页条数（游标分页为 null）
 * @param totalPages    页码分页时的总页数（游标分页为 null）
 */
@Serializable
data class ServerMessagePage(
    val messages: List<PushMessage>,
    val total: Int = 0,
    val hasNext: Boolean = false,
    val nextBeforeId: Long = 0L,
    val page: Int? = null,
    val pageSize: Int? = null,
    val totalPages: Int? = null,
)
