package com.push.app.data

import android.content.Context
import android.util.Log
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.concurrent.TimeUnit

/**
 * 用户认证 API 客户端
 *
 * 提供以下能力：
 * - [register] 调用 POST /auth/register 注册账号，返回 token 与安全码
 * - [login] 调用 POST /auth/login 登录账号
 * - [resetPassword] 调用 POST /auth/reset-password 通过安全码重置密码
 * - [getCaptcha] 调用 GET /captcha/image 获取图形验证码
 *
 * 与 TestPushApi 风格一致：OkHttp + kotlinx.serialization，
 * 服务器地址来自 [PreferencesManager.httpServerUrlFlow]，自动处理 ws/wss → http/https 转换。
 */
class AuthApi(private val context: Context) {

    private val json = Json {
        ignoreUnknownKeys = true
        isLenient = true
        encodeDefaults = true
    }

    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(15, TimeUnit.SECONDS)
        .writeTimeout(15, TimeUnit.SECONDS)
        .build()

    /**
     * 注册账号。
     *
     * @param serverUrl 服务器 HTTP 地址（可为 ws/wss，内部会自动转换）
     * @param username  用户名
     * @param phone     手机号
     * @param email     邮箱
     * @param password  密码
     * @param codeType  验证码类型（与服务端约定，如图形验证码字段名）
     * @param codeTarget 验证码目标（如 captcha_token）
     * @param codeInput  用户输入的验证码
     * @return 注册结果，成功时 [AuthResult.securityCode] 不为空
     */
    suspend fun register(
        serverUrl: String,
        username: String,
        phone: String,
        email: String,
        password: String,
        codeType: String,
        codeTarget: String,
        codeInput: String,
    ): AuthResult = withContext(Dispatchers.IO) {
        val httpUrl = normalizeHttpUrl(serverUrl)
        val url = httpUrl.trimEnd('/') + "/auth/register"

        val body = json.encodeToString(
            RegisterRequest.serializer(),
            RegisterRequest(
                username = username,
                phone = phone,
                email = email,
                password = password,
                code_type = codeType,
                code_target = codeTarget,
                code_input = codeInput,
            ),
        )

        val request = Request.Builder()
            .url(url)
            .post(body.toRequestBody("application/json".toMediaType()))
            .build()

        executeAuth(request, url, expectSecurityCode = true)
    }

    /**
     * 登录账号。
     *
     * @param serverUrl    服务器 HTTP 地址
     * @param account      账号（用户名 / 手机号 / 邮箱）
     * @param password     密码
     * @param captchaToken 验证码 token（来自 [getCaptcha]）
     * @param captchaInput 用户输入的验证码
     * @return 登录结果
     */
    suspend fun login(
        serverUrl: String,
        account: String,
        password: String,
        captchaToken: String,
        captchaInput: String,
    ): AuthResult = withContext(Dispatchers.IO) {
        val httpUrl = normalizeHttpUrl(serverUrl)
        val url = httpUrl.trimEnd('/') + "/auth/login"

        val body = json.encodeToString(
            LoginRequest.serializer(),
            LoginRequest(
                account = account,
                password = password,
                captcha_token = captchaToken,
                captcha_input = captchaInput,
            ),
        )

        val request = Request.Builder()
            .url(url)
            .post(body.toRequestBody("application/json".toMediaType()))
            .build()

        executeAuth(request, url, expectSecurityCode = false)
    }

    /**
     * 通过安全码重置密码。
     *
     * @param serverUrl   服务器 HTTP 地址
     * @param account     账号
     * @param securityCode 安全码（注册时返回的 8 位数字）
     * @param newPassword 新密码
     * @return 成功返回 true，失败抛出 [RuntimeException] 含服务器 message
     */
    suspend fun resetPassword(
        serverUrl: String,
        account: String,
        securityCode: String,
        newPassword: String,
    ): Boolean = withContext(Dispatchers.IO) {
        val httpUrl = normalizeHttpUrl(serverUrl)
        val url = httpUrl.trimEnd('/') + "/auth/reset-password"

        val body = json.encodeToString(
            ResetPasswordRequest.serializer(),
            ResetPasswordRequest(
                account = account,
                security_code = securityCode,
                new_password = newPassword,
            ),
        )

        val request = Request.Builder()
            .url(url)
            .post(body.toRequestBody("application/json".toMediaType()))
            .build()

        val response = client.newCall(request).execute()
        val raw = response.body?.string() ?: ""

        if (!response.isSuccessful) {
            Log.e(TAG, "reset-password HTTP ${response.code}: $raw")
            throw RuntimeException("服务器返回 ${response.code}")
        }

        val envelope = json.parseToJsonElement(raw).jsonObject
        val code = envelope["code"]?.jsonPrimitive?.contentOrNull?.toIntOrNull()
        if (code != 0) {
            val msg = envelope["message"]?.jsonPrimitive?.contentOrNull ?: "重置失败"
            throw RuntimeException(msg)
        }
        true
    }

    /**
     * 获取图形验证码。
     *
     * @param serverUrl 服务器 HTTP 地址
     * @return 验证码结果：[CaptchaResult.token] 用于后续注册/登录请求，
     *         [CaptchaResult.image] 为 base64 data URI（如 "data:image/png;base64,xxx"）
     */
    suspend fun getCaptcha(serverUrl: String): CaptchaResult = withContext(Dispatchers.IO) {
        val httpUrl = normalizeHttpUrl(serverUrl)
        val url = httpUrl.trimEnd('/') + "/captcha/image"

        val request = Request.Builder()
            .url(url)
            .get()
            .build()

        val response = client.newCall(request).execute()
        val raw = response.body?.string() ?: ""

        if (!response.isSuccessful) {
            Log.e(TAG, "captcha HTTP ${response.code}: $raw")
            throw RuntimeException("获取验证码失败：${response.code}")
        }

        val envelope = json.parseToJsonElement(raw).jsonObject
        val code = envelope["code"]?.jsonPrimitive?.contentOrNull?.toIntOrNull()
        val dataObj = envelope["data"]?.jsonObject
        if (code != 0 || dataObj == null) {
            val msg = envelope["message"]?.jsonPrimitive?.contentOrNull ?: "获取验证码失败"
            throw RuntimeException(msg)
        }

        CaptchaResult(
            token = dataObj["token"]?.jsonPrimitive?.contentOrNull ?: "",
            image = dataObj["image"]?.jsonPrimitive?.contentOrNull
                ?: dataObj["image_base64"]?.jsonPrimitive?.contentOrNull
                ?: "",
        )
    }

    /**
     * 执行注册/登录请求并解析统一的 { code, message, data } 响应。
     *
     * @param expectSecurityCode 是否在 data 中期望包含 security_code 字段（仅注册接口会返回）
     */
    private fun executeAuth(
        request: Request,
        url: String,
        expectSecurityCode: Boolean,
    ): AuthResult {
        val response = client.newCall(request).execute()
        val raw = response.body?.string() ?: ""

        if (!response.isSuccessful) {
            Log.e(TAG, "auth HTTP ${response.code}: $raw")
            throw RuntimeException("服务器返回 ${response.code}")
        }

        val envelope = json.parseToJsonElement(raw).jsonObject
        val code = envelope["code"]?.jsonPrimitive?.contentOrNull?.toIntOrNull()
        val dataObj = envelope["data"]?.jsonObject

        if (code != 0 || dataObj == null) {
            val msg = envelope["message"]?.jsonPrimitive?.contentOrNull ?: "请求失败"
            return AuthResult(success = false, message = msg)
        }

        val token = dataObj["token"]?.jsonPrimitive?.contentOrNull ?: ""
        val user = dataObj["user"]?.jsonObject?.let { parseUser(it) }
        val securityCode = if (expectSecurityCode) {
            dataObj["security_code"]?.jsonPrimitive?.contentOrNull ?: ""
        } else {
            ""
        }

        if (token.isBlank()) {
            return AuthResult(success = false, message = "服务器未返回 token")
        }

        return AuthResult(
            success = true,
            token = token,
            user = user,
            securityCode = securityCode,
            message = envelope["message"]?.jsonPrimitive?.contentOrNull ?: "",
        )
    }

    private fun parseUser(obj: JsonObject): UserInfo {
        return UserInfo(
            id = obj["id"]?.jsonPrimitive?.contentOrNull ?: "",
            username = obj["username"]?.jsonPrimitive?.contentOrNull ?: "",
            phone = obj["phone"]?.jsonPrimitive?.contentOrNull ?: "",
            email = obj["email"]?.jsonPrimitive?.contentOrNull ?: "",
            status = obj["status"]?.jsonPrimitive?.contentOrNull ?: "",
            created_at = obj["created_at"]?.jsonPrimitive?.contentOrNull ?: "",
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
    private data class RegisterRequest(
        val username: String,
        val phone: String,
        val email: String,
        val password: String,
        val code_type: String,
        val code_target: String,
        val code_input: String,
    )

    @Serializable
    private data class LoginRequest(
        val account: String,
        val password: String,
        val captcha_token: String,
        val captcha_input: String,
    )

    @Serializable
    private data class ResetPasswordRequest(
        val account: String,
        val security_code: String,
        val new_password: String,
    )

    companion object {
        private const val TAG = "AuthApi"
    }
}

/**
 * 用户信息（与后端 user 对象字段对齐）。
 *
 * 用于持久化到 DataStore，避免每次启动重新拉取。
 */
@Serializable
data class UserInfo(
    val id: String = "",
    val username: String = "",
    val phone: String = "",
    val email: String = "",
    val status: String = "",
    val created_at: String = "",
)

/**
 * 注册 / 登录统一返回结果。
 *
 * @param success      是否成功（成功时 [token] 与 [user] 可用）
 * @param token        JWT Token（成功时非空）
 * @param user         用户信息
 * @param securityCode 安全码（仅注册成功时返回，8 位数字字符串）
 * @param message      服务器返回的提示信息（含错误描述，如 "未注册" / "密码错误"）
 */
data class AuthResult(
    val success: Boolean,
    val token: String = "",
    val user: UserInfo? = null,
    val securityCode: String = "",
    val message: String = "",
)

/**
 * 图形验证码结果。
 *
 * @param token 验证码 token，注册/登录时回传给服务器
 * @param image base64 data URI（如 "data:image/png;base64,xxx"），可直接交给 Coil 加载
 */
data class CaptchaResult(
    val token: String,
    val image: String,
)
