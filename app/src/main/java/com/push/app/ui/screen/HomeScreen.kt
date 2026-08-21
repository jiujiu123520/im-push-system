package com.push.app.ui.screen

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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.DeleteSweep
import androidx.compose.material.icons.filled.Push
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.WifiOff
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.push.app.data.ConnectionState
import com.push.app.data.PushMessage
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
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

@Composable
fun HomeScreen(
    onNavigateToMessages: () -> Unit,
    onNavigateToSettings: () -> Unit,
) {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val scope = rememberCoroutineScope()
    val testPushApi = remember { TestPushApi(context) }

    val connectionState by repo.connectionState.collectAsState()
    val messages by repo.messages.collectAsState()
    val wsUrl by repo.prefs.wsUrlFlow.collectAsState(initial = "")

    GlassBackground {
        LazyColumn(
            modifier = Modifier
                .fillMaxSize()
                .padding(horizontal = 16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(
                top = 16.dp,
                bottom = 120.dp,
            ),
        ) {
            item {
                StatusCard(
                    state = connectionState,
                    wsUrl = wsUrl,
                    onSettings = onNavigateToSettings,
                )
            }

            item {
                GlassCard(
                    modifier = Modifier.fillMaxWidth(),
                    onClick = onNavigateToMessages,
                ) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(
                                text = "最近消息",
                                style = MaterialTheme.typography.titleMedium,
                                fontWeight = FontWeight.SemiBold,
                                color = MaterialTheme.colorScheme.onSurface,
                            )
                            TextButton(onClick = onNavigateToMessages) {
                                Text("查看全部")
                            }
                        }

                        val recent = messages.takeLast(3).reversed()
                        if (recent.isEmpty()) {
                            Spacer(Modifier.height(8.dp))
                            Text(
                                text = "暂无消息",
                                style = MaterialTheme.typography.bodyMedium,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .padding(vertical = 24.dp),
                            )
                        } else {
                            recent.forEachIndexed { index, msg ->
                                if (index > 0) {
                                    Spacer(Modifier.height(8.dp))
                                }
                                RecentMessageItem(msg = msg)
                            }
                        }
                    }
                }
            }

            item {
                QuickActionsRow(
                    onTestPush = {
                        scope.launch {
                            try {
                                val key = repo.prefs.keyFlow.first()
                                val serverUrl = repo.prefs.httpServerUrlFlow.first()
                                val deviceId = repo.getDeviceIdPublic()
                                val result = testPushApi.sendTestPush(key, serverUrl, deviceId)
                                Toast.makeText(
                                    context,
                                    if (result.success) "测试推送已发送" else "发送失败",
                                    Toast.LENGTH_SHORT,
                                ).show()
                            } catch (e: Exception) {
                                Toast.makeText(
                                    context,
                                    e.message ?: "发送失败",
                                    Toast.LENGTH_SHORT,
                                ).show()
                            }
                        }
                    },
                    onReconnect = {
                        repo.reconnect()
                        Toast.makeText(context, "正在重连...", Toast.LENGTH_SHORT).show()
                    },
                    onClear = {
                        scope.launch {
                            repo.clearMessages()
                            Toast.makeText(context, "已清空消息", Toast.LENGTH_SHORT).show()
                        }
                    },
                )
            }
        }
    }
}

@Composable
private fun StatusCard(
    state: ConnectionState,
    wsUrl: String,
    onSettings: () -> Unit = {},
) {
    val label = when (state) {
        ConnectionState.CONNECTED -> "在线"
        ConnectionState.CONNECTING -> "连接中"
        ConnectionState.RECONNECTING -> "重连中"
        ConnectionState.DISCONNECTED -> "离线"
    }
    val color = when (state) {
        ConnectionState.CONNECTED -> StatusOnline
        ConnectionState.CONNECTING,
        ConnectionState.RECONNECTING -> StatusWarning
        ConnectionState.DISCONNECTED -> StatusOffline
    }

    GlassCard(modifier = Modifier.fillMaxWidth()) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                modifier = Modifier
                    .size(10.dp)
                    .clip(CircleShape)
                    .background(color),
            )
            Spacer(Modifier.width(10.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = label,
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                    color = MaterialTheme.colorScheme.onSurface,
                )
                Text(
                    text = wsUrl.ifBlank { "未连接" },
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            IconButton(onClick = onSettings) {
                Icon(
                    imageVector = Icons.Filled.Settings,
                    contentDescription = "设置",
                    tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    modifier = Modifier.size(20.dp),
                )
            }
            Icon(
                imageVector = when (state) {
                    ConnectionState.CONNECTED -> Icons.Filled.CheckCircle
                    ConnectionState.DISCONNECTED -> Icons.Filled.WifiOff
                    else -> Icons.Filled.Refresh
                },
                contentDescription = null,
                tint = color,
            )
        }
    }
}

@Composable
private fun RecentMessageItem(msg: PushMessage) {
    val timeText = remember(msg.timestamp) {
        val fmt = SimpleDateFormat("HH:mm", Locale.getDefault())
        fmt.format(Date(msg.timestamp))
    }
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(
                tileGlassBg(
                    alphaDark = 0.05f,
                    isLight = with(MaterialTheme.colorScheme) { isLightTheme() },
                )
            )
            .padding(12.dp),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Text(
                text = msg.title.ifBlank { "(无标题)" },
                style = MaterialTheme.typography.titleSmall,
                fontWeight = FontWeight.SemiBold,
                color = MaterialTheme.colorScheme.onSurface,
                modifier = Modifier.weight(1f),
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Spacer(Modifier.width(8.dp))
            Text(
                text = timeText,
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        Spacer(Modifier.height(4.dp))
        Text(
            text = msg.content,
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

@Composable
private fun QuickActionsRow(
    onTestPush: () -> Unit,
    onReconnect: () -> Unit,
    onClear: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        QuickActionButton(
            icon = Icons.Filled.Push,
            label = "测试推送",
            onClick = onTestPush,
            modifier = Modifier.weight(1f),
        )
        QuickActionButton(
            icon = Icons.Filled.Refresh,
            label = "重新连接",
            onClick = onReconnect,
            modifier = Modifier.weight(1f),
        )
        QuickActionButton(
            icon = Icons.Filled.DeleteSweep,
            label = "清空消息",
            onClick = onClear,
            modifier = Modifier.weight(1f),
        )
    }
}

@Composable
private fun QuickActionButton(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    GlassCard(
        modifier = modifier,
        onClick = onClick,
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .padding(vertical = 16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Icon(
                imageVector = icon,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.primary,
                modifier = Modifier.size(24.dp),
            )
            Spacer(Modifier.height(6.dp))
            Text(
                text = label,
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.onSurface,
            )
        }
    }
}
