package com.push.app.ui.screen

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
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowForward
import androidx.compose.material.icons.filled.BugReport
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LargeTopAppBar
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBarDefaults
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
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.push.app.R
import com.push.app.data.ConnectionState
import com.push.app.data.PushMessage
import com.push.app.data.PushRepository
import com.push.app.data.TestPushApi
import com.push.app.ui.theme.BrandBlue
import com.push.app.ui.theme.BrandPurple
import com.push.app.ui.theme.StatusOffline
import com.push.app.ui.theme.StatusOnline
import com.push.app.ui.theme.StatusWarning
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * 首页：展示连接状态与最近消息，支持发送测试推送。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(
    onNavigateToMessages: () -> Unit,
    onNavigateToSettings: () -> Unit,
) {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val connectionState by repo.connectionState.collectAsState()
    val messages by repo.messages.collectAsState()
    val scope = rememberCoroutineScope()
    val snackbarHostState = remember { SnackbarHostState() }

    var testing by remember { mutableStateOf(false) }

    val scrollBehavior = TopAppBarDefaults.exitUntilCollapsedScrollBehavior()

    Scaffold(
        modifier = Modifier.nestedScroll(scrollBehavior.nestedScrollConnection),
        topBar = {
            LargeTopAppBar(
                title = { Text(stringResource(R.string.home_title)) },
                actions = {
                    // 测试推送按钮
                    IconButton(
                        onClick = {
                            if (testing) return@IconButton
                            scope.launch {
                                testing = true
                                try {
                                    val key = repo.preferencesManager.keyFlow.first()
                                    val serverUrl = repo.preferencesManager.serverUrlFlow.first()
                                    if (key.isBlank()) {
                                        snackbarHostState.showSnackbar("请先输入 Key")
                                        return@launch
                                    }
                                    val api = TestPushApi(context)
                                    val result = api.sendTestPush(key, serverUrl, repo.getDeviceIdPublic())
                                    val msg = if (result.success) {
                                        "测试推送成功（${result.elapsed_ms}ms）"
                                    } else {
                                        "测试推送：${result.message}"
                                    }
                                    snackbarHostState.showSnackbar(msg)
                                } catch (e: Exception) {
                                    snackbarHostState.showSnackbar("测试失败：${e.message}")
                                } finally {
                                    testing = false
                                }
                            }
                        },
                        enabled = !testing,
                    ) {
                        if (testing) {
                            CircularProgressIndicator(
                                modifier = Modifier.size(20.dp),
                                strokeWidth = 2.dp,
                            )
                        } else {
                            Icon(Icons.Filled.BugReport, contentDescription = stringResource(R.string.home_test_push))
                        }
                    }
                    IconButton(onClick = onNavigateToSettings) {
                        Icon(Icons.Filled.Settings, contentDescription = stringResource(R.string.settings_title))
                    }
                },
                scrollBehavior = scrollBehavior,
            )
        },
        snackbarHost = { SnackbarHost(snackbarHostState) },
    ) { padding ->
        LazyColumn(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding),
            contentPadding = androidx.compose.foundation.layout.PaddingValues(16.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            item {
                ConnectionStatusCard(connectionState)
            }
            // 测试推送快捷按钮
            item {
                OutlinedButton(
                    onClick = {
                        if (testing) return@OutlinedButton
                        scope.launch {
                            testing = true
                            try {
                                val key = repo.preferencesManager.keyFlow.first()
                                val serverUrl = repo.preferencesManager.serverUrlFlow.first()
                                if (key.isBlank()) {
                                    snackbarHostState.showSnackbar("请先输入 Key")
                                    return@launch
                                }
                                val api = TestPushApi(context)
                                val result = api.sendTestPush(key, serverUrl, repo.getDeviceIdPublic())
                                val msg = if (result.success) {
                                    "测试推送成功（${result.elapsed_ms}ms），请查看通知栏"
                                } else {
                                    "测试推送：${result.message}"
                                }
                                snackbarHostState.showSnackbar(msg)
                            } catch (e: Exception) {
                                snackbarHostState.showSnackbar("测试失败：${e.message}")
                            } finally {
                                testing = false
                            }
                        }
                    },
                    modifier = Modifier.fillMaxWidth(),
                    enabled = !testing,
                ) {
                    if (testing) {
                        CircularProgressIndicator(
                            modifier = Modifier.size(16.dp),
                            strokeWidth = 2.dp,
                        )
                    } else {
                        Icon(Icons.Filled.BugReport, contentDescription = null, modifier = Modifier.size(18.dp))
                    }
                    Spacer(Modifier.width(8.dp))
                    Text(stringResource(R.string.home_test_push))
                }
            }
            item {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        text = stringResource(R.string.home_recent_messages),
                        style = MaterialTheme.typography.titleMedium,
                    )
                    FilledTonalButton(onClick = onNavigateToMessages) {
                        Text(stringResource(R.string.message_list_title))
                        Spacer(Modifier.width(6.dp))
                        Icon(
                            Icons.AutoMirrored.Filled.ArrowForward,
                            contentDescription = null,
                            modifier = Modifier.size(18.dp),
                        )
                    }
                }
            }
            if (messages.isEmpty()) {
                item { EmptyMessageHint() }
            } else {
                items(repo.recentMessages(5)) { msg ->
                    MessageItemCard(msg)
                }
            }
        }
    }
}

/**
 * 连接状态卡片，带渐变背景与状态指示灯。
 */
@Composable
private fun ConnectionStatusCard(state: ConnectionState) {
    val (text, color) = when (state) {
        ConnectionState.CONNECTED -> stringResource(R.string.home_connected) to StatusOnline
        ConnectionState.CONNECTING -> "连接中" to StatusWarning
        ConnectionState.RECONNECTING -> "重连中" to StatusWarning
        ConnectionState.DISCONNECTED -> stringResource(R.string.home_disconnected) to StatusOffline
    }

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(20.dp),
        colors = CardDefaults.cardColors(containerColor = Color.Transparent),
    ) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .background(
                    Brush.linearGradient(listOf(BrandBlue, BrandPurple)),
                    shape = RoundedCornerShape(20.dp),
                )
                .padding(20.dp),
        ) {
            Column {
                Text(
                    text = stringResource(R.string.home_connection_status),
                    color = Color.White.copy(alpha = 0.85f),
                    style = MaterialTheme.typography.labelLarge,
                )
                Spacer(Modifier.height(8.dp))
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        modifier = Modifier
                            .size(12.dp)
                            .clip(CircleShape)
                            .background(color),
                    )
                    Spacer(Modifier.width(8.dp))
                    Text(
                        text = text,
                        color = Color.White,
                        style = MaterialTheme.typography.headlineSmall,
                        fontWeight = FontWeight.Bold,
                    )
                }
            }
        }
    }
}

/** 消息项卡片 */
@Composable
private fun MessageItemCard(message: PushMessage) {
    val time = SimpleDateFormat("MM-dd HH:mm:ss", Locale.getDefault())
        .format(Date(message.timestamp))
    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
    ) {
        Column(modifier = Modifier.padding(14.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Text(
                    text = message.title.ifBlank { stringResource(R.string.app_name) },
                    style = MaterialTheme.typography.titleSmall,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 1,
                    modifier = Modifier.weight(1f),
                )
                Spacer(Modifier.width(8.dp))
                Text(
                    text = time,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.outline,
                )
            }
            Spacer(Modifier.height(6.dp))
            Text(
                text = message.content,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                maxLines = 2,
            )
        }
    }
}

/** 空消息提示 */
@Composable
private fun EmptyMessageHint() {
    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 48.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Icon(
            Icons.Filled.Notifications,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.outline,
            modifier = Modifier.size(48.dp),
        )
        Spacer(Modifier.height(12.dp))
        Text(
            text = stringResource(R.string.home_no_messages),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.outline,
        )
    }
}
