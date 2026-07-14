package com.push.app.ui.screen

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.clickable
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Bolt
import androidx.compose.material.icons.filled.BatteryFull
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.DeleteForever
import androidx.compose.material.icons.filled.Key
import androidx.compose.material.icons.filled.LockClock
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.OpenInNew
import androidx.compose.material.icons.filled.PowerSettingsNew
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import com.push.app.R
import com.push.app.data.PreferencesManager
import com.push.app.data.PushRepository
import com.push.app.util.PermissionHelper
import kotlinx.coroutines.launch
import kotlin.math.roundToInt

/**
 * 设置页：服务器地址、心跳间隔、断开连接、清除 Key、HyperOS 权限引导。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen() {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val scope = rememberCoroutineScope()

    val savedServerUrl by repo.preferencesManager.serverUrlFlow.collectAsState(
        initial = PreferencesManager.DEFAULT_SERVER_URL
    )
    val savedHttpServerUrl by repo.preferencesManager.httpServerUrlFlow.collectAsState(
        initial = PreferencesManager.DEFAULT_HTTP_SERVER_URL
    )
    val savedHeartbeat by repo.preferencesManager.heartbeatIntervalFlow.collectAsState(
        initial = PreferencesManager.DEFAULT_HEARTBEAT
    )
    val savedKey by repo.preferencesManager.keyFlow.collectAsState(initial = "")
    val savedDefaultKey by repo.preferencesManager.defaultKeyFlow.collectAsState(initial = "")

    // 本地编辑态（首次回填已保存值）
    var serverUrl by remember { mutableStateOf(savedServerUrl) }
    var httpServerUrl by remember { mutableStateOf(savedHttpServerUrl) }
    var heartbeat by remember { mutableStateOf(savedHeartbeat.toFloat()) }
    LaunchedEffect(savedServerUrl) { serverUrl = savedServerUrl }
    LaunchedEffect(savedHttpServerUrl) { httpServerUrl = savedHttpServerUrl }
    LaunchedEffect(savedHeartbeat) { heartbeat = savedHeartbeat.toFloat() }

    var showClearKeyDialog by remember { mutableStateOf(false) }
    var maskedKey by remember(savedKey) { mutableStateOf(savedKey) }

    Scaffold(
        topBar = {
            TopAppBar(title = { Text(stringResource(R.string.settings_title)) })
        },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            // ===== 连接配置 =====
            SectionTitle("连接配置")
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(modifier = Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    OutlinedTextField(
                        value = httpServerUrl,
                        onValueChange = { httpServerUrl = it },
                        label = { Text("HTTP API 地址") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    OutlinedTextField(
                        value = serverUrl,
                        onValueChange = { serverUrl = it },
                        label = { Text("WebSocket 地址") },
                        singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                        modifier = Modifier.fillMaxWidth(),
                    )
                    Text(
                        text = "心跳间隔：${heartbeat.roundToInt()} 秒（10-300）",
                        style = MaterialTheme.typography.bodyMedium,
                    )
                    Slider(
                        value = heartbeat,
                        onValueChange = { heartbeat = it },
                        valueRange = PreferencesManager.MIN_HEARTBEAT.toFloat()..
                            PreferencesManager.MAX_HEARTBEAT.toFloat(),
                    )
                    Button(
                        onClick = {
                            scope.launch {
                                repo.saveServerUrl(serverUrl.trim())
                                repo.preferencesManager.saveHttpServerUrl(httpServerUrl.trim())
                                repo.saveHeartbeatInterval(heartbeat.roundToInt())
                                // 配置变更后重连以应用新参数
                                repo.disconnect()
                                repo.reconnect()
                            }
                        },
                        modifier = Modifier.fillMaxWidth(),
                    ) { Text(stringResource(R.string.save)) }
                }
            }

            // ===== 连接控制 =====
            SectionTitle("连接控制")
            Card(modifier = Modifier.fillMaxWidth()) {
                Column {
                    ListItem(
                        headlineContent = { Text(stringResource(R.string.settings_disconnect)) },
                        leadingContent = { Icon(Icons.Filled.PowerSettingsNew, null) },
                        modifier = Modifier.clickable {
                            scope.launch { repo.disconnect() }
                        },
                    )
                    HorizontalDivider()
                    ListItem(
                        headlineContent = { Text("重连") },
                        leadingContent = { Icon(Icons.Filled.Bolt, null) },
                        modifier = Modifier.clickable {
                            scope.launch { repo.reconnect() }
                        },
                    )
                }
            }

            // ===== 权限管理（HyperOS 适配）=====
            SectionTitle(stringResource(R.string.settings_permissions))
            Card(modifier = Modifier.fillMaxWidth()) {
                Column {
                    PermissionItem(
                        icon = Icons.Filled.OpenInNew,
                        title = stringResource(R.string.settings_permission_autostart),
                        onClick = { PermissionHelper.openAutoStartSettings(context) },
                    )
                    HorizontalDivider()
                    PermissionItem(
                        icon = Icons.Filled.BatteryFull,
                        title = stringResource(R.string.settings_permission_battery),
                        onClick = { PermissionHelper.openBatteryOptimizationSettings(context) },
                    )
                    HorizontalDivider()
                    PermissionItem(
                        icon = Icons.Filled.LockClock,
                        title = stringResource(R.string.settings_permission_lockscreen),
                        onClick = { PermissionHelper.openLockScreenCleanupSettings(context) },
                    )
                    HorizontalDivider()
                    PermissionItem(
                        icon = Icons.Filled.Notifications,
                        title = stringResource(R.string.settings_permission_notification),
                        onClick = { PermissionHelper.openNotificationSettings(context) },
                    )
                    HorizontalDivider()
                    PermissionItem(
                        icon = Icons.Filled.OpenInNew,
                        title = stringResource(R.string.settings_permission_popup),
                        onClick = { PermissionHelper.openBackgroundPopupSettings(context) },
                    )
                }
            }

            // ===== Key 管理 =====
            SectionTitle("Key 管理")
            Card(modifier = Modifier.fillMaxWidth()) {
                Column(modifier = Modifier.padding(16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.Filled.Key, null, tint = MaterialTheme.colorScheme.primary)
                        Spacer(Modifier.size(8.dp))
                        Text(
                            text = "当前 Key：${if (maskedKey.isBlank()) "未设置" else maskedKey.take(4) + "****" + maskedKey.takeLast(2)}",
                            style = MaterialTheme.typography.bodyMedium,
                        )
                    }
                    if (savedDefaultKey.isNotBlank()) {
                        Spacer(Modifier.height(6.dp))
                        Text(
                            text = "默认 Key：$savedDefaultKey（未设置自定义 Key 时使用）",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.outline,
                        )
                    }
                    Spacer(Modifier.height(12.dp))
                    OutlinedButton(
                        onClick = { showClearKeyDialog = true },
                        enabled = maskedKey.isNotBlank(),
                        modifier = Modifier.fillMaxWidth(),
                    ) {
                        Icon(Icons.Filled.DeleteForever, null, modifier = Modifier.size(18.dp))
                        Spacer(Modifier.size(8.dp))
                        Text(stringResource(R.string.settings_clear_key))
                    }
                }
            }

            Spacer(Modifier.height(24.dp))
        }
    }

    // 清除 Key 确认弹窗
    if (showClearKeyDialog) {
        AlertDialog(
            onDismissRequest = { showClearKeyDialog = false },
            title = { Text(stringResource(R.string.settings_clear_key)) },
            text = { Text("清除后需重新输入 Key 才能接收消息，确定清除吗？") },
            confirmButton = {
                TextButton(onClick = {
                    scope.launch {
                        repo.clearKey()
                        maskedKey = ""
                    }
                    showClearKeyDialog = false
                }) { Text(stringResource(R.string.confirm)) }
            },
            dismissButton = {
                TextButton(onClick = { showClearKeyDialog = false }) {
                    Text(stringResource(R.string.cancel))
                }
            },
        )
    }
}

/** 小节标题 */
@Composable
private fun SectionTitle(text: String) {
    Text(
        text = text,
        style = MaterialTheme.typography.titleSmall,
        color = MaterialTheme.colorScheme.primary,
    )
}

/** 权限引导项 */
@Composable
private fun PermissionItem(
    icon: ImageVector,
    title: String,
    onClick: () -> Unit,
) {
    ListItem(
        headlineContent = { Text(title) },
        leadingContent = { Icon(icon, null) },
        trailingContent = { Icon(Icons.Filled.ChevronRight, null, modifier = Modifier.size(20.dp)) },
        modifier = Modifier.clickable(onClick = onClick),
    )
}
