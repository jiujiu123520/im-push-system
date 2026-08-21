package com.push.app.ui.screen

import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.ContentCopy
import androidx.compose.material.icons.filled.DeleteSweep
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Key
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Server
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.push.app.data.ConnectionState
import com.push.app.data.PushRepository
import com.push.app.data.TestPushApi
import com.push.app.ui.theme.GlassBackground
import com.push.app.ui.theme.GlassCard
import com.push.app.ui.theme.StatusOffline
import com.push.app.ui.theme.StatusOnline
import com.push.app.ui.theme.StatusWarning
import com.push.app.ui.theme.isLightTheme
import com.push.app.ui.theme.tileGlassBg
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

private data class QuickActionItem(
    val icon: androidx.compose.ui.graphics.vector.ImageVector,
    val label: String,
    val onClick: () -> Unit,
)

@Composable
fun ProfileScreen(
    onNavigateToMessages: () -> Unit,
    onNavigateToSettings: () -> Unit,
    onNavigateToLogin: () -> Unit,
) {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val scope = rememberCoroutineScope()
    val testPushApi = remember { TestPushApi(context) }

    val connectionState by repo.connectionState.collectAsState()
    val userIdPref by repo.prefs.userIdFlow.collectAsState(initial = "")
    val serverUrl by repo.prefs.httpServerUrlFlow.collectAsState(initial = "")
    val wsUrl by repo.prefs.wsUrlFlow.collectAsState(initial = "")
    val heartbeat by repo.prefs.heartbeatIntervalFlow.collectAsState(initial = 30)

    var editField by remember { mutableStateOf<EditField?>(null) }

    val userId = userIdPref.ifBlank { repo.getDeviceIdPublic() }
    val displayName = userIdPref.ifBlank { "访客" }

    val quickActions = listOf(
        QuickActionItem(Icons.Filled.ContentCopy, "复制 ID") {
            copyToClipboard(context, userId)
            Toast.makeText(context, "设备 ID 已复制", Toast.LENGTH_SHORT).show()
        },
        QuickActionItem(Icons.Filled.Refresh, "测试推送") {
            scope.launch {
                try {
                    val key = repo.prefs.keyFlow.first()
                    val httpUrl = repo.prefs.httpServerUrlFlow.first()
                    val deviceId = repo.getDeviceIdPublic()
                    val result = testPushApi.sendTestPush(key, httpUrl, deviceId)
                    Toast.makeText(
                        context,
                        if (result.success) "测试推送已发送" else "发送失败",
                        Toast.LENGTH_SHORT,
                    ).show()
                } catch (e: Exception) {
                    Toast.makeText(context, e.message ?: "发送失败", Toast.LENGTH_SHORT).show()
                }
            }
        },
        QuickActionItem(Icons.Filled.CheckCircle, "重新连接") {
            repo.reconnect()
            Toast.makeText(context, "正在重连...", Toast.LENGTH_SHORT).show()
        },
        QuickActionItem(Icons.Filled.DeleteSweep, "清空消息") {
            scope.launch {
                repo.clearMessages()
                Toast.makeText(context, "已清空消息", Toast.LENGTH_SHORT).show()
            }
        },
    )

    GlassBackground {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(horizontal = 16.dp),
        ) {
            Spacer(Modifier.height(16.dp))

            UserHeaderCard(
                displayName = displayName,
                userId = userId,
                connectionState = connectionState,
                onCopyId = {
                    copyToClipboard(context, userId)
                    Toast.makeText(context, "设备 ID 已复制", Toast.LENGTH_SHORT).show()
                },
                onNavigateToLogin = onNavigateToLogin,
            )

            Spacer(Modifier.height(16.dp))

            GlassCard(modifier = Modifier.fillMaxWidth()) {
                Column(modifier = Modifier.padding(16.dp)) {
                    Text(
                        text = "快捷操作",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.SemiBold,
                        color = MaterialTheme.colorScheme.onSurface,
                    )
                    Spacer(Modifier.height(12.dp))
                    LazyVerticalGrid(
                        columns = GridCells.Fixed(2),
                        horizontalArrangement = Arrangement.spacedBy(12.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                        modifier = Modifier.height(200.dp),
                    ) {
                        items(quickActions) { action ->
                            GlassCard(
                                modifier = Modifier.fillMaxWidth(),
                                onClick = action.onClick,
                            ) {
                                Column(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .padding(vertical = 16.dp),
                                    horizontalAlignment = Alignment.CenterHorizontally,
                                ) {
                                    Icon(
                                        imageVector = action.icon,
                                        contentDescription = null,
                                        tint = MaterialTheme.colorScheme.primary,
                                        modifier = Modifier.size(22.dp),
                                    )
                                    Spacer(Modifier.height(6.dp))
                                    Text(
                                        text = action.label,
                                        style = MaterialTheme.typography.labelMedium,
                                        color = MaterialTheme.colorScheme.onSurface,
                                    )
                                }
                            }
                        }
                    }
                }
            }

            Spacer(Modifier.height(16.dp))

            GlassCard(modifier = Modifier.fillMaxWidth()) {
                Column(modifier = Modifier.padding(16.dp)) {
                    Text(
                        text = "服务器配置",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.SemiBold,
                        color = MaterialTheme.colorScheme.onSurface,
                    )
                    Spacer(Modifier.height(12.dp))

                    ConfigRow(
                        icon = Icons.Filled.Server,
                        label = "服务器地址",
                        value = serverUrl.ifBlank { "未设置" },
                        onClick = { editField = EditField.SERVER_URL },
                    )
                    ConfigRow(
                        icon = Icons.Filled.Key,
                        label = "推送 Key",
                        value = "********",
                        onClick = { editField = EditField.PUSH_KEY },
                    )
                    ConfigRow(
                        icon = Icons.Filled.Settings,
                        label = "心跳间隔",
                        value = "${heartbeat} 秒",
                        onClick = { editField = EditField.HEARTBEAT },
                    )
                }
            }

            Spacer(Modifier.height(24.dp))
        }
    }

    editField?.let { field ->
        EditFieldDialog(
            field = field,
            currentValue = when (field) {
                EditField.SERVER_URL -> wsUrl
                EditField.PUSH_KEY -> ""
                EditField.HEARTBEAT -> heartbeat.toString()
            },
            onDismiss = { editField = null },
            onSave = { newValue ->
                scope.launch {
                    when (field) {
                        EditField.SERVER_URL -> repo.prefs.saveWsUrl(newValue)
                        EditField.PUSH_KEY -> repo.prefs.saveKey(newValue)
                        EditField.HEARTBEAT -> {
                            val sec = newValue.toIntOrNull() ?: 30
                            repo.prefs.saveHeartbeatInterval(sec)
                        }
                    }
                    editField = null
                    Toast.makeText(context, "已保存", Toast.LENGTH_SHORT).show()
                }
            },
        )
    }
}

private enum class EditField { SERVER_URL, PUSH_KEY, HEARTBEAT }

@Composable
private fun EditFieldDialog(
    field: EditField,
    currentValue: String,
    onDismiss: () -> Unit,
    onSave: (String) -> Unit,
) {
    var value by remember { mutableStateOf(currentValue) }
    val title = when (field) {
        EditField.SERVER_URL -> "编辑服务器地址"
        EditField.PUSH_KEY -> "编辑推送 Key"
        EditField.HEARTBEAT -> "编辑心跳间隔（秒）"
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            OutlinedTextField(
                value = value,
                onValueChange = { value = it },
                singleLine = field != EditField.HEARTBEAT,
                modifier = Modifier.fillMaxWidth(),
            )
        },
        confirmButton = {
            TextButton(onClick = { onSave(value) }) {
                Text("保存")
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text("取消")
            }
        },
    )
}

@Composable
private fun UserHeaderCard(
    displayName: String,
    userId: String,
    connectionState: ConnectionState,
    onCopyId: () -> Unit,
    onNavigateToLogin: () -> Unit,
) {
    val badgeText = when (connectionState) {
        ConnectionState.CONNECTED -> "在线"
        ConnectionState.CONNECTING -> "连接中"
        ConnectionState.RECONNECTING -> "连接中"
        ConnectionState.DISCONNECTED -> "离线"
    }
    val badgeColor = when (connectionState) {
        ConnectionState.CONNECTED -> StatusOnline
        ConnectionState.CONNECTING,
        ConnectionState.RECONNECTING -> StatusWarning
        ConnectionState.DISCONNECTED -> StatusOffline
    }
    val avatarChar = displayName.firstOrNull()?.uppercase() ?: "?"

    GlassCard(modifier = Modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(20.dp)) {
            Row(
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Box(
                    modifier = Modifier
                        .size(56.dp)
                        .clip(CircleShape)
                        .background(MaterialTheme.colorScheme.primary),
                    contentAlignment = Alignment.Center,
                ) {
                    Text(
                        text = avatarChar,
                        style = MaterialTheme.typography.headlineMedium,
                        fontWeight = FontWeight.Bold,
                        color = Color.White,
                    )
                }
                Spacer(Modifier.size(16.dp))
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        text = displayName,
                        style = MaterialTheme.typography.titleLarge,
                        fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.onSurface,
                    )
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            modifier = Modifier
                                .size(8.dp)
                                .clip(CircleShape)
                                .background(badgeColor),
                        )
                        Spacer(Modifier.size(6.dp))
                        Text(
                            text = badgeText,
                            style = MaterialTheme.typography.labelMedium,
                            color = badgeColor,
                        )
                    }
                }
            }

            Spacer(Modifier.height(16.dp))

            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(8.dp))
                    .background(
                        tileGlassBg(
                            alphaDark = 0.05f,
                            isLight = with(MaterialTheme.colorScheme) { isLightTheme() },
                        )
                    )
                    .padding(10.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        text = "设备 ID",
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Text(
                        text = userId,
                        style = MaterialTheme.typography.bodySmall,
                        fontFamily = FontFamily.Monospace,
                        color = MaterialTheme.colorScheme.onSurface,
                    )
                }
                IconButton(onClick = onCopyId) {
                    Icon(
                        imageVector = Icons.Filled.ContentCopy,
                        contentDescription = "复制",
                        tint = MaterialTheme.colorScheme.primary,
                    )
                }
            }
        }
    }
}

@Composable
private fun ConfigRow(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    value: String,
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(
                tileGlassBg(
                    alphaDark = 0.04f,
                    isLight = with(MaterialTheme.colorScheme) { isLightTheme() },
                )
            )
            .padding(horizontal = 12.dp, vertical = 12.dp)
            .then(Modifier),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            imageVector = icon,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(20.dp),
        )
        Spacer(Modifier.size(12.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = label,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Text(
                text = value,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurface,
            )
        }
        IconButton(onClick = onClick) {
            Icon(
                imageVector = Icons.Filled.Edit,
                contentDescription = "编辑",
                tint = MaterialTheme.colorScheme.primary,
            )
        }
    }
    Spacer(Modifier.height(8.dp))
}

private fun copyToClipboard(context: Context, text: String) {
    val cm = context.getSystemService(Context.CLIPBOARD_SERVICE) as ClipboardManager
    cm.setPrimaryClip(ClipData.newPlainText("ID", text))
}
