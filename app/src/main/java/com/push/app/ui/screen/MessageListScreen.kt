package com.push.app.ui.screen

import android.content.Intent
import androidx.core.content.FileProvider
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Clear
import androidx.compose.material.icons.filled.DeleteSweep
import androidx.compose.material.icons.filled.IosShare
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.push.app.R
import com.push.app.data.MessageStore
import com.push.app.data.PushMessage
import com.push.app.data.PushRepository
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private const val PAGE_SIZE = 10

/**
 * 消息列表页：展示历史消息，支持搜索、分页、清空与导出。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun MessageListScreen() {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val messages by repo.messages.collectAsState()
    val scope = rememberCoroutineScope()
    val snackbarHostState = remember { SnackbarHostState() }

    var showClearDialog by remember { mutableStateOf(false) }
    var showExportMenu by remember { mutableStateOf(false) }
    var exporting by remember { mutableStateOf(false) }

    // 搜索与分页状态
    var searchQuery by remember { mutableStateOf("") }
    var searchInput by remember { mutableStateOf("") }
    var currentPage by remember { mutableStateOf(1) }

    // 当消息列表变化或搜索关键词变化时，重置到第 1 页
    LaunchedEffect(messages, searchQuery) {
        currentPage = 1
    }

    // 当前页数据（从 MessageStore 实时查询）
    val pagedResult by remember(messages, searchQuery, currentPage) {
        derivedStateOf {
            repo.queryPage(currentPage, PAGE_SIZE, searchQuery)
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(stringResource(R.string.message_list_title)) },
                actions = {
                    if (messages.isNotEmpty()) {
                        // 导出按钮
                        IconButton(
                            onClick = { showExportMenu = true },
                            enabled = !exporting,
                        ) {
                            Icon(
                                Icons.Filled.IosShare,
                                contentDescription = stringResource(R.string.message_export),
                            )
                        }
                        // 导出格式下拉菜单
                        DropdownMenu(
                            expanded = showExportMenu,
                            onDismissRequest = { showExportMenu = false },
                        ) {
                            MessageStore.ExportFormat.values().forEach { format ->
                                DropdownMenuItem(
                                    text = { Text(format.label) },
                                    onClick = {
                                        showExportMenu = false
                                        scope.launch {
                                            exporting = true
                                            try {
                                                val result = repo.exportMessages(format)
                                                shareExportedFile(context, result.file, result.format)
                                            } catch (e: Exception) {
                                                snackbarHostState.showSnackbar(
                                                    context.getString(R.string.message_export_failed) + ": ${e.message}"
                                                )
                                            } finally {
                                                exporting = false
                                            }
                                        }
                                    },
                                )
                            }
                        }
                        // 清空按钮
                        IconButton(onClick = { showClearDialog = true }) {
                            Icon(
                                Icons.Filled.DeleteSweep,
                                contentDescription = stringResource(R.string.message_clear_all),
                            )
                        }
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbarHostState) },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding),
        ) {
            // 搜索栏
            SearchBar(
                input = searchInput,
                onInputChange = { searchInput = it },
                onSearch = {
                    searchQuery = searchInput.trim()
                    currentPage = 1
                },
                onClear = {
                    searchInput = ""
                    searchQuery = ""
                    currentPage = 1
                },
            )

            // 分页信息条
            if (!pagedResult.isEmpty) {
                PaginationInfoBar(
                    page = pagedResult.page,
                    totalPages = pagedResult.totalPages,
                    total = pagedResult.total,
                    keyword = searchQuery,
                )
            }

            // 消息列表
            if (pagedResult.isEmpty) {
                EmptyListHint(
                    modifier = Modifier.weight(1f),
                    hasMessages = messages.isNotEmpty(),
                    keyword = searchQuery,
                )
            } else {
                LazyColumn(
                    modifier = Modifier.weight(1f),
                    contentPadding = androidx.compose.foundation.layout.PaddingValues(
                        horizontal = 16.dp,
                        vertical = 8.dp,
                    ),
                    verticalArrangement = Arrangement.spacedBy(12.dp),
                ) {
                    items(pagedResult.items) { msg ->
                        MessageRow(msg)
                    }
                }

                // 分页控件
                PaginationControls(
                    page = pagedResult.page,
                    totalPages = pagedResult.totalPages,
                    onPrev = { currentPage = (currentPage - 1).coerceAtLeast(1) },
                    onNext = {
                        currentPage = (currentPage + 1).coerceAtMost(pagedResult.totalPages)
                    },
                )
            }
        }
    }

    // 清空确认弹窗
    if (showClearDialog) {
        AlertDialog(
            onDismissRequest = { showClearDialog = false },
            title = { Text(stringResource(R.string.message_clear_all)) },
            text = { Text("确定要清空所有消息吗？此操作不可撤销。") },
            confirmButton = {
                TextButton(onClick = {
                    scope.launch { repo.clearMessages() }
                    showClearDialog = false
                    searchQuery = ""
                    searchInput = ""
                    currentPage = 1
                }) { Text(stringResource(R.string.confirm)) }
            },
            dismissButton = {
                TextButton(onClick = { showClearDialog = false }) {
                    Text(stringResource(R.string.cancel))
                }
            },
        )
    }
}

/**
 * 搜索栏：输入关键词后点击搜索按钮触发搜索。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SearchBar(
    input: String,
    onInputChange: (String) -> Unit,
    onSearch: () -> Unit,
    onClear: () -> Unit,
) {
    OutlinedTextField(
        value = input,
        onValueChange = onInputChange,
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 8.dp),
        placeholder = { Text("搜索消息标题或内容…") },
        leadingIcon = {
            Icon(Icons.Filled.Search, contentDescription = null)
        },
        trailingIcon = {
            if (input.isNotEmpty()) {
                IconButton(onClick = onClear) {
                    Icon(Icons.Filled.Clear, contentDescription = "清除搜索")
                }
            } else {
                IconButton(onClick = onSearch) {
                    Icon(Icons.Filled.Search, contentDescription = "搜索")
                }
            }
        },
        singleLine = true,
        shape = RoundedCornerShape(12.dp),
    )
    // 输入框右侧搜索按钮（输入不为空时显示）
    if (input.isNotEmpty()) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp),
            horizontalArrangement = Arrangement.End,
        ) {
            TextButton(onClick = onSearch) {
                Text("搜索")
            }
        }
    }
}

/**
 * 分页信息条：显示当前页码、总页数、总条数。
 */
@Composable
private fun PaginationInfoBar(
    page: Int,
    totalPages: Int,
    total: Int,
    keyword: String,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 4.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            text = if (keyword.isNotBlank()) {
                "搜索 \"$keyword\"：共 $total 条"
            } else {
                "共 $total 条消息"
            },
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.outline,
        )
        Text(
            text = "第 $page / ${if (totalPages == 0) 1 else totalPages} 页",
            style = MaterialTheme.typography.labelMedium,
            color = MaterialTheme.colorScheme.outline,
        )
    }
}

/**
 * 分页控件：上一页/下一页按钮 + 页码显示。
 */
@Composable
private fun PaginationControls(
    page: Int,
    totalPages: Int,
    onPrev: () -> Unit,
    onNext: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 12.dp),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        // 上一页
        TextButton(
            onClick = onPrev,
            enabled = page > 1,
        ) {
            Text("上一页")
        }
        Spacer(Modifier.size(16.dp))
        Text(
            text = "$page / ${if (totalPages == 0) 1 else totalPages}",
            style = MaterialTheme.typography.bodyMedium,
            fontWeight = FontWeight.Medium,
        )
        Spacer(Modifier.size(16.dp))
        // 下一页
        TextButton(
            onClick = onNext,
            enabled = page < totalPages,
        ) {
            Text("下一页")
        }
    }
}

/**
 * 通过系统分享面板分享导出的文件。
 */
private fun shareExportedFile(
    context: android.content.Context,
    file: java.io.File,
    format: MessageStore.ExportFormat,
) {
    val uri = FileProvider.getUriForFile(
        context,
        "${context.packageName}.fileprovider",
        file,
    )
    val intent = Intent(Intent.ACTION_SEND).apply {
        type = format.mime
        putExtra(Intent.EXTRA_STREAM, uri)
        putExtra(Intent.EXTRA_SUBJECT, "消息推送记录.${format.ext}")
        addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    }
    context.startActivity(Intent.createChooser(intent, "分享导出文件"))
}

/** 单条消息卡片 */
@Composable
private fun MessageRow(message: PushMessage) {
    val time = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault())
        .format(Date(message.timestamp))
    val priorityLabel = when (message.priority.lowercase()) {
        "high" -> "重要"
        "low" -> "低"
        else -> "普通"
    }

    Card(
        modifier = Modifier.fillMaxWidth(),
        shape = RoundedCornerShape(12.dp),
    ) {
        Column(modifier = Modifier.padding(14.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    text = message.title.ifBlank { stringResource(R.string.app_name) },
                    style = MaterialTheme.typography.titleMedium,
                    fontWeight = FontWeight.SemiBold,
                    maxLines = 1,
                    modifier = Modifier.weight(1f),
                )
                Text(
                    text = priorityLabel,
                    style = MaterialTheme.typography.labelSmall,
                    color = when (message.priority.lowercase()) {
                        "high" -> MaterialTheme.colorScheme.error
                        "low" -> MaterialTheme.colorScheme.outline
                        else -> MaterialTheme.colorScheme.primary
                    },
                )
            }
            Spacer(Modifier.size(6.dp))
            Text(
                text = message.content,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            Spacer(Modifier.size(8.dp))
            Text(
                text = time,
                style = MaterialTheme.typography.labelSmall,
                color = MaterialTheme.colorScheme.outline,
            )
        }
    }
}

/** 空列表占位 */
@Composable
private fun EmptyListHint(
    modifier: Modifier = Modifier,
    hasMessages: Boolean = false,
    keyword: String = "",
) {
    Box(
        modifier = modifier.fillMaxSize(),
        contentAlignment = Alignment.Center,
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(
                Icons.Filled.Notifications,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.outline,
                modifier = Modifier.size(56.dp),
            )
            Spacer(Modifier.size(12.dp))
            Text(
                text = when {
                    keyword.isNotBlank() -> "未找到匹配 \"$keyword\" 的消息"
                    hasMessages -> "当前页无消息"
                    else -> stringResource(R.string.message_list_empty)
                },
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.outline,
            )
        }
    }
}
