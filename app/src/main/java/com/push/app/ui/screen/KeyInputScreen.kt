package com.push.app.ui.screen

import com.push.app.util.ToastUtils
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Connection
import androidx.compose.material.icons.filled.Key
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.push.app.PushApplication
import com.push.app.data.PushRepository
import com.push.app.ui.theme.GlassBackground
import com.push.app.ui.theme.GlassCard
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

@Composable
fun KeyInputScreen(
    onSaved: () -> Unit,
) {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val scope = rememberCoroutineScope()
    val app = remember { context.applicationContext as PushApplication }

    var key by remember { mutableStateOf("") }
    var serverUrl by remember { mutableStateOf("") }
    var wsUrl by remember { mutableStateOf("") }

    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) {
        val cfg = app.globalConfig
        val savedKey = repo.prefs.keyFlow.first()
        key = savedKey.ifBlank { cfg.defaultKey }
        serverUrl = repo.prefs.httpServerUrlFlow.first()
            .ifBlank { cfg.serverUrl }
        wsUrl = repo.prefs.wsUrlFlow.first()
            .ifBlank { cfg.wsUrl }
    }

    GlassBackground {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .imePadding()
                .padding(24.dp),
            contentAlignment = Alignment.Center,
        ) {
            GlassCard(
                modifier = Modifier.fillMaxWidth(),
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(24.dp),
                ) {
                    Text(
                        text = "配置推送 Key",
                        style = MaterialTheme.typography.headlineMedium,
                        fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.onSurface,
                    )
                    Spacer(Modifier.height(4.dp))
                    Text(
                        text = "输入推送 Key 与服务器地址以建立连接",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )

                    Spacer(Modifier.height(24.dp))

                    OutlinedTextField(
                        value = key,
                        onValueChange = { key = it; error = null },
                        label = { Text("推送 Key") },
                        leadingIcon = { Icon(Icons.Filled.Key, contentDescription = null) },
                        singleLine = true,
                        shape = RoundedCornerShape(12.dp),
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Spacer(Modifier.height(12.dp))

                    OutlinedTextField(
                        value = serverUrl,
                        onValueChange = { serverUrl = it; error = null },
                        label = { Text("服务器地址 (HTTP)") },
                        singleLine = true,
                        shape = RoundedCornerShape(12.dp),
                        modifier = Modifier.fillMaxWidth(),
                    )

                    Spacer(Modifier.height(12.dp))

                    OutlinedTextField(
                        value = wsUrl,
                        onValueChange = { wsUrl = it; error = null },
                        label = { Text("WebSocket 地址 (WS)") },
                        leadingIcon = { Icon(Icons.Filled.Connection, contentDescription = null) },
                        singleLine = true,
                        shape = RoundedCornerShape(12.dp),
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

                    Spacer(Modifier.height(24.dp))

                    Button(
                        onClick = {
                            if (key.isBlank()) {
                                error = "请输入推送 Key"
                                return@Button
                            }
                            loading = true
                            error = null
                            scope.launch {
                                try {
                                    repo.prefs.saveKey(key)
                                    repo.prefs.saveHttpServerUrl(serverUrl)
                                    repo.prefs.saveWsUrl(wsUrl)
                                    repo.connect()
                                    ToastUtils.show(context, "已保存并连接")
                                    onSaved()
                                } catch (e: Exception) {
                                    error = e.message?.ifBlank { "保存失败" } ?: "保存失败"
                                } finally {
                                    loading = false
                                }
                            }
                        },
                        enabled = !loading,
                        shape = RoundedCornerShape(12.dp),
                        colors = ButtonDefaults.buttonColors(
                            containerColor = MaterialTheme.colorScheme.primary,
                        ),
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(52.dp),
                    ) {
                        if (loading) {
                            CircularProgressIndicator(
                                modifier = Modifier.height(20.dp),
                                color = MaterialTheme.colorScheme.onPrimary,
                                strokeWidth = 2.dp,
                            )
                        } else {
                            Text("保存并连接", style = MaterialTheme.typography.titleMedium)
                        }
                    }

                    Spacer(Modifier.height(16.dp))

                    Text(
                        text = "当前配置",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.Medium,
                        color = MaterialTheme.colorScheme.onSurface,
                    )
                    Spacer(Modifier.height(8.dp))
                    CurrentValueRow(label = "推送 Key", value = key.ifBlank { "未设置" })
                    CurrentValueRow(label = "HTTP 地址", value = serverUrl.ifBlank { "未设置" })
                    CurrentValueRow(label = "WS 地址", value = wsUrl.ifBlank { "未设置" })
                }
            }
        }
    }
}

@Composable
private fun CurrentValueRow(label: String, value: String) {
    Column(modifier = Modifier.fillMaxWidth()) {
        Text(
            text = label,
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(
            text = value,
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.padding(top = 2.dp),
            // Key / 服务器地址都很长，强制单行 + 省略号，避免换行/溢出把卡片撑破
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        Spacer(Modifier.height(4.dp))
    }
}
