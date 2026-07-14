package com.push.app.data

import android.content.Context
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.contentOrNull
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.concurrent.TimeUnit

/**
 * 测试推送 API 客户端
 *
 * 用于 APP 自测推送通道是否正常：
 * 调用后端 POST /api/test-push-self 接口，向当前设备发送一条测试消息。
 *
 * 与正式推送走同一 WebSocket 通道，可用于排查：
 *  - Key 是否有效
 *  - 设备是否在线
 *  - 通知渠道是否正常
 */
class TestPushApi(private val context: Context) {

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
     * 发送测试推送到当前设备
     *
     * @param key     推送 Key
     * @param serverUrl 服务器地址（HTTP，如 http://192.168.1.100:9501）
     * @param deviceId    当前设备 ID
     * @return 结果对象
     */
    suspend fun sendTestPush(
        key: String,
        serverUrl: String,
        deviceId: String,
    ): TestPushResult = withContext(Dispatchers.IO) {
        // 兼容传入 ws/wss 协议地址，自动转换为 http/https
        val httpUrl = normalizeHttpUrl(serverUrl)
        val url = httpUrl.trimEnd('/') + "/api/test-push-self"

        val body = json.encodeToString(
            TestPushRequest.serializer(),
            TestPushRequest(key = key, device_id = deviceId),
        )

        val request = Request.Builder()
            .url(url)
            .post(body.toRequestBody("application/json".toMediaType()))
            .build()

        val response = client.newCall(request).execute()
        val raw = response.body?.string() ?: ""

        if (!response.isSuccessful) {
            Log.e(TAG, "test push HTTP ${response.code}: $raw")
            throw RuntimeException("服务器返回 ${response.code}")
        }

        val envelope = json.parseToJsonElement(raw).jsonObject
        val code = envelope["code"]?.jsonPrimitive?.contentOrNull?.toIntOrNull()
        val dataObj = envelope["data"]?.jsonObject

        if (code != 0 || dataObj == null) {
            val msg = envelope["message"]?.jsonPrimitive?.contentOrNull ?: "未知错误"
            throw RuntimeException(msg)
        }

        parseResult(dataObj)
    }

    private fun parseResult(obj: JsonObject): TestPushResult {
        return TestPushResult(
            online = obj["online"]?.jsonPrimitive?.contentOrNull?.lowercase() == "true",
            success = obj["success"]?.jsonPrimitive?.contentOrNull?.lowercase() == "true",
            message = obj["message"]?.jsonPrimitive?.contentOrNull ?: "",
            elapsed_ms = obj["elapsed_ms"]?.jsonPrimitive?.contentOrNull?.toIntOrNull() ?: 0,
        )
    }

    /**
     * 将 WebSocket 协议地址转换为 HTTP 协议地址：
     * ws://  → http://
     * wss:// → https://
     * 其他协议保持不变。
     */
    private fun normalizeHttpUrl(url: String): String {
        val trimmed = url.trim()
        return when {
            trimmed.startsWith("ws://") -> "http://" + trimmed.removePrefix("ws://")
            trimmed.startsWith("wss://") -> "https://" + trimmed.removePrefix("wss://")
            else -> trimmed
        }
    }

    @Serializable
    private data class TestPushRequest(
        val key: String,
        val device_id: String,
    )

    data class TestPushResult(
        val online: Boolean,
        val success: Boolean,
        val message: String,
        val elapsed_ms: Int,
    )

    companion object {
        private const val TAG = "TestPushApi"
    }
}
