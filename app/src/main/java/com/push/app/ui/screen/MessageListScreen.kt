package com.push.app.ui.screen

import com.push.app.util.ToastUtils
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.push.app.data.MessageFilter
import com.push.app.data.PushMessage
import com.push.app.data.PushRepository
import com.push.app.ui.theme.GlassBackground
import com.push.app.ui.theme.GlassCard
import com.push.app.ui.theme.isLightTheme
import com.push.app.ui.theme.tileGlassBg
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private data class FilterOption(val label: String, val filter: MessageFilter?)

private val filters = listOf(
    FilterOption("全部", MessageFilter.ALL),
    FilterOption("高优先", MessageFilter.HIGH),
    FilterOption("系统", MessageFilter.SYSTEM),
    FilterOption("未读", MessageFilter.UNREAD),
)

@Composable
fun MessageListScreen() {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val scope = rememberCoroutineScope()

    val allMessages by repo.messages.collectAsState()
    var keyword by remember { mutableStateOf("") }
    var selectedFilter by remember { mutableStateOf<MessageFilter>(MessageFilter.ALL) }

    val filtered = remember(allMessages, keyword, selectedFilter) {
        val kw = keyword.trim().lowercase()
        val filteredByFilter = when (selectedFilter) {
            MessageFilter.ALL -> allMessages
            MessageFilter.HIGH -> allMessages.filter { it.priority.equals("high", ignoreCase = true) }
            MessageFilter.SYSTEM -> allMessages.filter { it.type.equals("system", ignoreCase = true) }
            MessageFilter.UNREAD -> allMessages.filter { !it.isRead }
        }
        if (kw.isBlank()) {
            filteredByFilter.reversed()
        } else {
            filteredByFilter.filter { msg ->
                msg.title.lowercase().contains(kw) ||
                    msg.content.lowercase().contains(kw)
            }.reversed()
        }
    }

    GlassBackground {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(horizontal = 16.dp),
        ) {
            Spacer(Modifier.height(12.dp))

            OutlinedTextField(
                value = keyword,
                onValueChange = { keyword = it },
                modifier = Modifier.fillMaxWidth(),
                leadingIcon = { Icon(Icons.Filled.Search, contentDescription = null) },
                trailingIcon = {
                    if (keyword.isNotBlank()) {
                        IconButton(onClick = { keyword = "" }) {
                            Icon(Icons.Filled.Close, contentDescription = "清除")
                        }
                    }
                },
                placeholder = { Text("搜索消息") },
                singleLine = true,
                shape = RoundedCornerShape(12.dp),
            )

            Spacer(Modifier.height(12.dp))

            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                filters.forEach { opt ->
                    val selected = selectedFilter == opt.filter
                    FilterChip(
                        selected = selected,
                        onClick = {
                            selectedFilter = opt.filter ?: MessageFilter.ALL
                        },
                        label = { Text(opt.label) },
                        modifier = Modifier.height(36.dp),
                    )
                }
            }

            Spacer(Modifier.height(12.dp))

            if (filtered.isEmpty()) {
                EmptyMessagesView()
            } else {
                LazyColumn(
                    modifier = Modifier.fillMaxSize(),
                    verticalArrangement = Arrangement.spacedBy(10.dp),
                    contentPadding = PaddingValues(bottom = 120.dp),
                ) {
                    items(
                        items = filtered,
                        key = { it.id },
                    ) { msg ->
                        SwipeableMessageItem(
                            message = msg,
                            onDelete = {
                                scope.launch {
                                    repo.deleteMessage(msg.id)
                                    ToastUtils.show(context, "已删除")
                                }
                            },
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun SwipeableMessageItem(
    message: PushMessage,
    onDelete: () -> Unit,
) {
    val timeText = remember(message.timestamp) {
        val fmt = SimpleDateFormat("MM-dd HH:mm", Locale.getDefault())
        fmt.format(Date(message.timestamp))
    }

    Box(
        modifier = Modifier.fillMaxWidth(),
    ) {
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .background(
                    color = MaterialTheme.colorScheme.error.copy(alpha = 0.2f),
                    shape = RoundedCornerShape(16.dp),
                )
                .padding(end = 16.dp),
            contentAlignment = Alignment.CenterEnd,
        ) {
            IconButton(onClick = onDelete) {
                Icon(
                    imageVector = Icons.Filled.Delete,
                    contentDescription = "删除",
                    tint = MaterialTheme.colorScheme.error,
                )
            }
        }

        GlassCard(
            modifier = Modifier
                .fillMaxWidth()
                .padding(end = 40.dp),
        ) {
            Column(modifier = Modifier.padding(14.dp)) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        text = message.title.ifBlank { "(无标题)" },
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.Bold,
                        color = MaterialTheme.colorScheme.onSurface,
                        modifier = Modifier.weight(1f),
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                    if (!message.isRead) {
                        Box(
                            modifier = Modifier
                                .size(6.dp)
                                .background(
                                    MaterialTheme.colorScheme.primary,
                                    RoundedCornerShape(3.dp),
                                ),
                        )
                        Spacer(Modifier.width(6.dp))
                    }
                    Text(
                        text = timeText,
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                Spacer(Modifier.height(6.dp))
                Text(
                    text = message.content,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }
        }
    }
}

@Composable
private fun EmptyMessagesView() {
    Box(
        modifier = Modifier.fillMaxSize(),
        contentAlignment = Alignment.Center,
    ) {
        Column(
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(
                modifier = Modifier
                    .size(80.dp)
                    .background(
                        color = tileGlassBg(
                            alphaDark = 0.08f,
                            isLight = with(MaterialTheme.colorScheme) { isLightTheme() },
                        ),
                        shape = RoundedCornerShape(40.dp),
                    ),
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    imageVector = Icons.Filled.Search,
                    contentDescription = null,
                    modifier = Modifier.size(36.dp),
                    tint = MaterialTheme.colorScheme.onSurfaceVariant.copy(alpha = 0.5f),
                )
            }
            Spacer(Modifier.height(16.dp))
            Text(
                text = "暂无消息",
                style = MaterialTheme.typography.titleMedium,
                color = MaterialTheme.colorScheme.onSurface,
            )
            Spacer(Modifier.height(4.dp))
            Text(
                text = "连接成功后将在此显示推送消息",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}
