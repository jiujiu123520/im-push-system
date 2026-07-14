package com.push.app.ui.screen

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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Login
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import coil.compose.rememberAsyncImagePainter
import com.push.app.data.AuthApi
import com.push.app.data.PushRepository
import com.push.app.ui.theme.BrandBlue
import com.push.app.ui.theme.BrandPurple
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

/**
 * 登录页面。
 *
 * 流程：
 * 1. 用户输入账号（用户名 / 手机号 / 邮箱）与密码
 * 2. 加载并展示图形验证码（点击图片可刷新）
 * 3. 提交登录 → 后端返回 token 与 user
 * 4. 登录失败时根据 message 区分提示：
 *    - "未注册" → 提示并提供"去注册"按钮
 *    - "密码错误" → 提示重新输入
 *    - 其他 → 直接显示 message
 * 5. 登录成功后保存 token 与 user 到 DataStore，跳转首页
 *
 * 提供"忘记密码"链接 → 弹出重置密码对话框（输入账号、安全码、新密码）。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LoginScreen(
    onNavigateToRegister: () -> Unit,
    onLoginSuccess: () -> Unit,
) {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val authApi = remember { AuthApi(context) }
    val scope = rememberCoroutineScope()

    var account by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var captchaInput by remember { mutableStateOf("") }

    var captchaToken by remember { mutableStateOf("") }
    var captchaImage by remember { mutableStateOf("") }
    var captchaLoading by remember { mutableStateOf(false) }

    var formError by remember { mutableStateOf<String?>(null) }
    var notRegistered by remember { mutableStateOf(false) }
    var submitting by remember { mutableStateOf(false) }
    var showResetDialog by remember { mutableStateOf(false) }

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
                Icons.Filled.Login,
                contentDescription = null,
                tint = Color.White,
                modifier = Modifier.size(40.dp),
            )
        }

        Spacer(Modifier.height(16.dp))
        Text(
            text = "登录账号",
            style = MaterialTheme.typography.headlineSmall,
            fontWeight = FontWeight.Bold,
        )
        Spacer(Modifier.height(4.dp))
        Text(
            text = "登录后开始接收推送消息",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            textAlign = TextAlign.Center,
        )
        Spacer(Modifier.height(24.dp))

        // 账号
        OutlinedTextField(
            value = account,
            onValueChange = { account = it; formError = null; notRegistered = false },
            label = { Text("账号（用户名 / 手机号 / 邮箱）") },
            singleLine = true,
            isError = formError != null,
            modifier = Modifier.fillMaxWidth(),
        )
        Spacer(Modifier.height(12.dp))

        // 密码
        OutlinedTextField(
            value = password,
            onValueChange = { password = it; formError = null },
            label = { Text("密码") },
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

        // "未注册"时显示去注册按钮
        if (notRegistered) {
            Spacer(Modifier.height(8.dp))
            Button(
                onClick = onNavigateToRegister,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("去注册")
            }
        }

        Spacer(Modifier.height(16.dp))

        // 登录按钮
        Button(
            onClick = {
                if (account.isBlank()) {
                    formError = "请输入账号"
                    return@Button
                }
                if (password.isBlank()) {
                    formError = "请输入密码"
                    return@Button
                }
                if (captchaInput.isBlank()) {
                    formError = "请输入图形验证码"
                    return@Button
                }
                if (captchaToken.isBlank()) {
                    formError = "验证码未加载，请点击刷新"
                    return@Button
                }
                submitting = true
                formError = null
                notRegistered = false
                scope.launch {
                    try {
                        val serverUrl = repo.preferencesManager.httpServerUrlFlow.first()
                        val result = authApi.login(
                            serverUrl = serverUrl,
                            account = account.trim(),
                            password = password,
                            captchaToken = captchaToken,
                            captchaInput = captchaInput.trim(),
                        )
                        if (!result.success) {
                            val msg = result.message.ifBlank { "登录失败" }
                            when {
                                msg.contains("未注册") -> {
                                    notRegistered = true
                                    formError = "该账号未注册，请注册后使用"
                                }
                                msg.contains("密码错误") -> {
                                    formError = "密码错误，请重新输入"
                                }
                                else -> formError = msg
                            }
                            // 登录失败刷新验证码
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
                        } else {
                            // 登录成功
                            repo.preferencesManager.saveUserToken(result.token)
                            result.user?.let { repo.preferencesManager.saveUserInfo(it) }
                            Toast.makeText(context, "登录成功", Toast.LENGTH_SHORT).show()
                            onLoginSuccess()
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
                Text("登录", style = MaterialTheme.typography.titleMedium)
            }
        }

        Spacer(Modifier.height(12.dp))
        // 忘记密码 + 去注册 链接
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            TextButton(onClick = { showResetDialog = true }) {
                Text("忘记密码？")
            }
            TextButton(onClick = onNavigateToRegister) {
                Text("去注册")
            }
        }
        Spacer(Modifier.height(24.dp))
    }

    // 重置密码对话框
    if (showResetDialog) {
        ResetPasswordDialog(
            initialAccount = account,
            onDismiss = { showResetDialog = false },
            onSubmit = { resetAccount, securityCode, newPassword, onError ->
                scope.launch {
                    try {
                        val serverUrl = repo.preferencesManager.httpServerUrlFlow.first()
                        val ok = authApi.resetPassword(
                            serverUrl = serverUrl,
                            account = resetAccount.trim(),
                            securityCode = securityCode.trim(),
                            newPassword = newPassword,
                        )
                        if (ok) {
                            Toast.makeText(context, "密码重置成功，请使用新密码登录", Toast.LENGTH_LONG).show()
                            showResetDialog = false
                        }
                    } catch (e: Exception) {
                        onError(friendlyError(e))
                    }
                }
            },
        )
    }
}

/**
 * 重置密码对话框。
 *
 * 输入：账号、安全码、新密码。
 */
@Composable
private fun ResetPasswordDialog(
    initialAccount: String,
    onDismiss: () -> Unit,
    onSubmit: (account: String, securityCode: String, newPassword: String, onError: (String) -> Unit) -> Unit,
) {
    var account by remember { mutableStateOf(initialAccount) }
    var securityCode by remember { mutableStateOf("") }
    var newPassword by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var submitting by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = { if (!submitting) onDismiss() },
        icon = { Icon(Icons.Filled.Lock, contentDescription = null) },
        title = { Text("重置密码") },
        text = {
            Column {
                OutlinedTextField(
                    value = account,
                    onValueChange = { account = it; error = null },
                    label = { Text("账号") },
                    singleLine = true,
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(12.dp))
                OutlinedTextField(
                    value = securityCode,
                    onValueChange = { securityCode = it; error = null },
                    label = { Text("安全码（8 位数字）") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                    modifier = Modifier.fillMaxWidth(),
                )
                Spacer(Modifier.height(12.dp))
                OutlinedTextField(
                    value = newPassword,
                    onValueChange = { newPassword = it; error = null },
                    label = { Text("新密码（6-64 位）") },
                    singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                    modifier = Modifier.fillMaxWidth(),
                )
                error?.let {
                    Spacer(Modifier.height(8.dp))
                    Text(
                        text = it,
                        color = MaterialTheme.colorScheme.error,
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }
        },
        confirmButton = {
            Button(
                onClick = {
                    if (account.isBlank()) { error = "请输入账号"; return@Button }
                    if (securityCode.trim().length != 8) { error = "安全码需为 8 位数字"; return@Button }
                    if (newPassword.length !in 6..64) { error = "密码需 6-64 位"; return@Button }
                    submitting = true
                    onSubmit(account, securityCode, newPassword) { err ->
                        error = err
                        submitting = false
                    }
                },
                enabled = !submitting,
            ) {
                if (submitting) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(16.dp),
                        strokeWidth = 2.dp,
                    )
                } else {
                    Text("重置")
                }
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss, enabled = !submitting) {
                Text("取消")
            }
        },
    )
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

/** 网络异常友好提示 */
private fun friendlyError(e: Throwable): String {
    val msg = e.message.orEmpty()
    return if (msg.contains("network", ignoreCase = true) ||
        msg.contains("failed to connect", ignoreCase = true) ||
        msg.contains("unable to resolve", ignoreCase = true) ||
        msg.contains("timeout", ignoreCase = true)
    ) {
        "网络错误，请检查网络连接"
    } else if (msg.startsWith("服务器返回")) {
        "服务器错误，请稍后重试"
    } else {
        msg.ifBlank { "请求失败，请稍后重试" }
    }
}
