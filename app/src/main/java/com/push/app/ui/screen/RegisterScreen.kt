package com.push.app.ui.screen

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.widget.Toast
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.wrapContentHeight
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.VerifiedUser
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import coil.compose.rememberAsyncImagePainter
import com.push.app.data.AuthApi
import com.push.app.data.AuthResult
import com.push.app.data.PushRepository
import com.push.app.ui.theme.BrandBlue
import com.push.app.ui.theme.BrandPurple
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import java.util.regex.Pattern

/**
 * 注册页面。
 *
 * 流程：
 * 1. 用户填写用户名 / 手机号 / 邮箱 / 密码
 * 2. 加载并展示图形验证码（点击图片可刷新）
 * 3. 提交注册 → 后端返回 token、user、security_code
 * 4. 弹出安全码弹窗（8 位数字，提供复制按钮，提示用户妥善保存）
 * 5. 用户点击"已保存"后，保存 token 与 user 到 DataStore，跳转首页
 *
 * 表单校验：用户名 3-64 位、密码 6-64 位、手机号格式、邮箱格式。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RegisterScreen(
    onNavigateToLogin: () -> Unit,
    onRegisterSuccess: () -> Unit,
) {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val authApi = remember { AuthApi(context) }
    val scope = rememberCoroutineScope()

    var username by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var email by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var captchaInput by remember { mutableStateOf("") }

    var captchaToken by remember { mutableStateOf("") }
    var captchaImage by remember { mutableStateOf("") }
    var captchaLoading by remember { mutableStateOf(false) }

    var formError by remember { mutableStateOf<String?>(null) }
    var submitting by remember { mutableStateOf(false) }
    var securityCodeToShow by remember { mutableStateOf<String?>(null) }
    // 暂存注册返回的认证结果，待用户点击"已保存"后再写入 DataStore
    var pendingResult by remember { mutableStateOf<AuthResult?>(null) }

    val scrollState = rememberScrollState()

    // 首次进入加载验证码
    LaunchedEffect(Unit) {
        loadCaptcha(
            scope = scope,
            authApi = authApi,
            repo = repo,
            onLoading = { captchaLoading = it },
            onResult = { token, image ->
                captchaToken = token
                captchaImage = image
            },
            onError = { formError = it },
        )
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .imePadding()
            .verticalScroll(scrollState)
            .padding(24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.height(24.dp))

        // 顶部图标
        Box(
            modifier = Modifier
                .size(80.dp)
                .clip(RoundedCornerShape(24.dp))
                .background(Brush.linearGradient(listOf(BrandBlue, BrandPurple))),
            contentAlignment = Alignment.Center,
        ) {
            Icon(
                Icons.Filled.VerifiedUser,
                contentDescription = null,
                tint = Color.White,
                modifier = Modifier.size(40.dp),
            )
        }

        Spacer(Modifier.height(16.dp))
        Text(
            text = "创建账号",
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
        )
        Spacer(Modifier.height(4.dp))
        Text(
            text = "注册后可获得专属安全码，请妥善保存",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(24.dp))

        // 用户名
        OutlinedTextField(
            value = username,
            onValueChange = { username = it; formError = null },
            label = { Text("用户名（3-64 位）") },
            singleLine = true,
            isError = formError != null,
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(12.dp))

        // 手机号
        OutlinedTextField(
            value = phone,
            onValueChange = { phone = it; formError = null },
            label = { Text("手机号") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone),
            isError = formError != null,
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(12.dp))

        // 邮箱
        OutlinedTextField(
            value = email,
            onValueChange = { email = it; formError = null },
            label = { Text("邮箱") },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email),
            isError = formError != null,
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(12.dp))

        // 密码
        OutlinedTextField(
            value = password,
            onValueChange = { password = it; formError = null },
            label = { Text("密码（6-64 位）") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
            isError = formError != null,
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(12.dp))

        // 验证码输入 + 图片
        Row(
            modifier = Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            OutlinedTextField(
                value = captchaInput,
                onValueChange = { captchaInput = it; formError = null },
                label = { Text("图形验证码") },
                singleLine = true,
                isError = formError != null,
                modifier = Modifier.weight(1f),
            )
            Spacer(Modifier.width(12.dp))
            // 验证码图片
            Box(
                modifier = Modifier
                    .height(56.dp)
                    .width(120.dp)
                    .clip(RoundedCornerShape(8.dp))
                    .background(MaterialTheme.colorScheme.surfaceVariant)
                    .clickable {
                        if (!captchaLoading) {
                            loadCaptcha(
                                scope = scope,
                                authApi = authApi,
                                repo = repo,
                                onLoading = { captchaLoading = it },
                                onResult = { token, image ->
                                    captchaToken = token
                                    captchaImage = image
                                    captchaInput = ""
                                },
                                onError = { formError = it },
                            )
                        }
                    },
                contentAlignment = Alignment.Center,
            ) {
                when {
                    captchaLoading -> CircularProgressIndicator(
                        modifier = Modifier.size(24.dp),
                        strokeWidth = 2.dp,
                    )
                    captchaImage.isNotBlank() -> {
                        Image(
                            painter = rememberAsyncImagePainter(model = captchaImage),
                            contentDescription = "点击刷新验证码",
                            modifier = Modifier.fillMaxSize(),
                        )
                    }
                    else -> Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(
                            Icons.Filled.Refresh,
                            contentDescription = null,
                            modifier = Modifier.size(16.dp),
                            tint = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                        Spacer(Modifier.width(4.dp))
                        Text(
                            "点击加载",
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
        }

        // 错误提示
        formError?.let { err ->
            Spacer(Modifier.height(8.dp))
            Text(
                text = err,
                color = MaterialTheme.colorScheme.error,
                style = MaterialTheme.typography.bodySmall,
            )
        }

        Spacer(Modifier.height(24.dp))

        // 注册按钮
        Button(
            onClick = {
                val validation = validateRegisterForm(username, phone, email, password, captchaInput)
                if (validation != null) {
                    formError = validation
                    return@Button
                }
                if (captchaToken.isBlank()) {
                    formError = "验证码未加载，请点击刷新"
                    return@Button
                }
                submitting = true
                formError = null
                scope.launch {
                    try {
                        val serverUrl = repo.preferencesManager.httpServerUrlFlow.first()
                        val result = authApi.register(
                            serverUrl = serverUrl,
                            username = username.trim(),
                            phone = phone.trim(),
                            email = email.trim(),
                            password = password,
                            codeType = "captcha",
                            codeTarget = captchaToken,
                            codeInput = captchaInput.trim(),
                        )
                        if (!result.success) {
                            formError = result.message.ifBlank { "注册失败" }
                            // 注册失败时刷新验证码
                            loadCaptcha(
                                scope = scope,
                                authApi = authApi,
                                repo = repo,
                                onLoading = { captchaLoading = it },
                                onResult = { token, image ->
                                    captchaToken = token
                                    captchaImage = image
                                    captchaInput = ""
                                },
                                onError = { formError = it },
                            )
                        } else if (result.securityCode.isBlank()) {
                            formError = "服务器未返回安全码"
                        } else {
                            // 弹出安全码弹窗
                            securityCodeToShow = result.securityCode
                            // 暂存登录信息，待用户确认已保存后写入
                            pendingResult = result
                        }
                    } catch (e: Exception) {
                        formError = friendlyError(e)
                    } finally {
                        submitting = false
                    }
                }
            },
            enabled = !submitting,
            modifier = Modifier
                .fillMaxWidth()
                .height(52.dp),
        ) {
            if (submitting) {
                CircularProgressIndicator(
                    modifier = Modifier.size(24.dp),
                    color = MaterialTheme.colorScheme.onPrimary,
                    strokeWidth = 2.dp,
                )
            } else {
                Text("注册", style = MaterialTheme.typography.titleMedium)
            }
        }

        Spacer(Modifier.height(16.dp))
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.Center,
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = "已有账号？",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            TextButton(onClick = onNavigateToLogin) {
                Text("去登录")
            }
        }
        Spacer(Modifier.height(24.dp))
    }

    // 安全码弹窗
    securityCodeToShow?.let { code ->
        SecurityCodeDialog(
            securityCode = code,
            onCopy = {
                copyToClipboard(context, code)
                Toast.makeText(context, "已复制安全码", Toast.LENGTH_SHORT).show()
            },
            onSaved = {
                val result = pendingResult
                scope.launch {
                    if (result != null) {
                        repo.preferencesManager.saveUserToken(result.token)
                        result.user?.let { repo.preferencesManager.saveUserInfo(it) }
                    }
                    securityCodeToShow = null
                    pendingResult = null
                    onRegisterSuccess()
                }
            },
        )
    }
}

/**
 * 加载图形验证码。
 */
private fun loadCaptcha(
    scope: kotlinx.coroutines.CoroutineScope,
    authApi: AuthApi,
    repo: PushRepository,
    onLoading: (Boolean) -> Unit,
    onResult: (String, String) -> Unit,
    onError: (String) -> Unit,
) {
    scope.launch {
        onLoading(true)
        try {
            val serverUrl = repo.preferencesManager.httpServerUrlFlow.first()
            val captcha = authApi.getCaptcha(serverUrl)
            if (captcha.token.isBlank() || captcha.image.isBlank()) {
                onError("验证码加载失败")
            } else {
                onResult(captcha.token, captcha.image)
            }
        } catch (e: Exception) {
            onError(friendlyError(e))
        } finally {
            onLoading(false)
        }
    }
}

/**
 * 注册表单校验。
 * @return 校验失败返回错误描述，成功返回 null
 */
private fun validateRegisterForm(
    username: String,
    phone: String,
    email: String,
    password: String,
    captchaInput: String,
): String? {
    if (username.trim().length !in 3..64) return "用户名需 3-64 位"
    if (phone.isNotBlank() && !PHONE_PATTERN.matcher(phone.trim()).matches()) {
        return "手机号格式不正确"
    }
    if (email.isNotBlank() && !EMAIL_PATTERN.matcher(email.trim()).matches()) {
        return "邮箱格式不正确"
    }
    if (phone.isBlank() && email.isBlank()) return "手机号与邮箱至少填写一项"
    if (password.length !in 6..64) return "密码需 6-64 位"
    if (captchaInput.isBlank()) return "请输入图形验证码"
    return null
}

/**
 * 安全码弹窗：突出显示 8 位数字，提供复制按钮与"已保存"确认。
 *
 * 不允许点击外部或返回键关闭，强制用户确认已保存。
 */
@Composable
private fun SecurityCodeDialog(
    securityCode: String,
    onCopy: () -> Unit,
    onSaved: () -> Unit,
) {
    Dialog(
        onDismissRequest = { /* 不允许外部关闭，强制用户确认 */ },
        properties = DialogProperties(
            dismissOnBackPress = false,
            dismissOnClickOutside = false,
        ),
    ) {
        Surface(
            shape = RoundedCornerShape(24.dp),
            color = MaterialTheme.colorScheme.surface,
            tonalElevation = 6.dp,
            modifier = Modifier.fillMaxWidth(),
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .wrapContentHeight()
                    .padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                // 顶部图标
                Box(
                    modifier = Modifier
                        .size(56.dp)
                        .clip(RoundedCornerShape(18.dp))
                        .background(Brush.linearGradient(listOf(BrandBlue, BrandPurple))),
                    contentAlignment = Alignment.Center,
                ) {
                    Icon(
                        Icons.Filled.VerifiedUser,
                        contentDescription = null,
                        tint = Color.White,
                        modifier = Modifier.size(28.dp),
                    )
                }

                Spacer(Modifier.height(16.dp))
                Text(
                    text = "您的安全码",
                    style = MaterialTheme.typography.titleLarge,
                    fontWeight = FontWeight.Bold,
                )
                Spacer(Modifier.height(4.dp))
                Text(
                    text = "请妥善保存，安全码不可修改，\n用于忘记密码时重置",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                )

                Spacer(Modifier.height(20.dp))

                // 8 位数字大字体展示（带渐变背景）
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .clip(RoundedCornerShape(16.dp))
                        .background(Brush.linearGradient(listOf(BrandBlue, BrandPurple)))
                        .padding(vertical = 24.dp, horizontal = 16.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        text = securityCode,
                        color = Color.White,
                        fontSize = 36.sp,
                        fontWeight = FontWeight.Bold,
                        fontFamily = FontFamily.Monospace,
                        letterSpacing = 6.sp,
                        textAlign = TextAlign.Center,
                    )
                }

                Spacer(Modifier.height(16.dp))

                // 复制按钮
                OutlinedTextField(
                    value = securityCode,
                    onValueChange = { /* 只读，不允许编辑 */ },
                    readOnly = true,
                    singleLine = true,
                    trailingIcon = {
                        IconButton(onClick = onCopy) {
                            Icon(
                                Icons.Filled.ContentCopy,
                                contentDescription = "复制安全码",
                            )
                        }
                    },
                    modifier = Modifier.fillMaxWidth(),
                )

                Spacer(Modifier.height(20.dp))

                Button(
                    onClick = onSaved,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(52.dp),
                ) {
                    Text("已保存", style = MaterialTheme.typography.titleMedium)
                }
            }
        }
    }
}

/** 复制文本到系统剪贴板 */
private fun copyToClipboard(context: Context, text: String) {
    val clipboard = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
    clipboard.setPrimaryClip(ClipData.newPlainText("security_code", text))
}

/** 网络异常友好提示 */
private fun friendlyError(e: Throwable): String {
    val msg = e.message.orEmpty()
    return if (msg.contains("network", ignoreCase = true) ||
        msg.contains("failed to connect", ignoreCase = true) ||
        msg.contains("unable to resolve", ignoreCase = true) ||
        msg.contains("timeout", ignoreCase = true) ||
        msg.contains("timed out", ignoreCase = true)
    ) {
        "网络错误，请检查网络连接"
    } else if (msg.startsWith("服务器返回")) {
        "服务器错误，请稍后重试"
    } else {
        msg.ifBlank { "请求失败，请稍后重试" }
    }
}

// 手机号正则：中国大陆 11 位
private val PHONE_PATTERN: Pattern = Pattern.compile("^1[3-9]\\d{9}$")
// 邮箱正则
private val EMAIL_PATTERN: Pattern = Pattern.compile("^[A-Za-z0-9+_.-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}$")
