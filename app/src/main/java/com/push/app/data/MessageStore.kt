package com.push.app.data

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.json.Json
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

// ========== 数据模型 ==========

/**
 * 推送消息数据模型。
 * 与服务端约定字段：id / title / content / priority / timestamp。
 */
@Serializable
data class PushMessage(
    val id: String,
    val title: String,
    val content: String,
    val priority: String = "default", // high / default / low
    val timestamp: Long,
    val receivedAt: Long = System.currentTimeMillis(),
)

/**
 * WebSocket 连接状态。
 */
enum class ConnectionState {
    DISCONNECTED,   // 已断开（手动或未连接）
    CONNECTING,     // 连接中
    CONNECTED,      // 已连接并鉴权成功
    RECONNECTING,   // 重连中（自动重试）
}

// ========== 本地消息存储 ==========

/**
 * 本地消息存储：基于文件的 JSON 持久化。
 *
 * 采用「读时全量、写时全量」的简单策略，适合消息量不大的推送场景。
 * 通过 Mutex 保证并发写入安全。
 */
class MessageStore(private val storageDir: File) {

    // 最多保留的消息条数，避免无限增长
    private val maxMessages = 500

    private val json = Json {
        ignoreUnknownKeys = true
        prettyPrint = false
        encodeDefaults = true
    }

    private val messageFile: File = File(storageDir, "messages.json")
    private val mutex = Mutex()

    // 内存中的消息流，UI 订阅此流刷新列表
    private val _messages = MutableStateFlow<List<PushMessage>>(emptyList())
    val messages: StateFlow<List<PushMessage>> = _messages.asStateFlow()

    init {
        storageDir.mkdirs()
        loadFromDisk()
    }

    /** 从磁盘加载历史消息 */
    private fun loadFromDisk() {
        runCatching {
            if (messageFile.exists()) {
                val text = messageFile.readText()
                if (text.isNotBlank()) {
                    val list = json.decodeFromString(ListSerializer(PushMessage.serializer()), text)
                    _messages.value = list
                }
            }
        }
    }

    /** 追加一条消息，并落盘 */
    suspend fun add(message: PushMessage) {
        mutex.withLock {
            val updated = (_messages.value + message).takeLast(maxMessages)
            _messages.value = updated
            persist(updated)
        }
    }

    /** 清空所有消息 */
    suspend fun clear() {
        mutex.withLock {
            _messages.value = emptyList()
            runCatching { messageFile.delete() }
        }
    }

    /** 获取最近 N 条消息 */
    fun recent(limit: Int = 5): List<PushMessage> =
        _messages.value.takeLast(limit).reversed()

    /**
     * 分页查询消息（倒序，最新在前）。
     *
     * @param page 页码，从 1 开始
     * @param pageSize 每页条数
     * @param keyword 搜索关键词（为空则不筛选），匹配标题或内容
     * @return 分页结果
     */
    fun queryPage(
        page: Int = 1,
        pageSize: Int = 10,
        keyword: String = "",
    ): PagedResult {
        val all = _messages.value.asReversed() // 倒序：最新在前
        val filtered = if (keyword.isBlank()) {
            all
        } else {
            val kw = keyword.trim().lowercase()
            all.filter { msg ->
                msg.title.lowercase().contains(kw) ||
                    msg.content.lowercase().contains(kw)
            }
        }
        val total = filtered.size
        val totalPages = if (total == 0) 0 else (total + pageSize - 1) / pageSize
        val safePage = page.coerceIn(1, if (totalPages == 0) 1 else totalPages)
        val fromIndex = (safePage - 1) * pageSize
        val toIndex = minOf(fromIndex + pageSize, total)
        val pageItems = if (fromIndex < total) filtered.subList(fromIndex, toIndex) else emptyList()
        return PagedResult(
            items = pageItems,
            page = safePage,
            pageSize = pageSize,
            total = total,
            totalPages = totalPages,
            keyword = keyword,
        )
    }

    /** 分页查询结果 */
    data class PagedResult(
        val items: List<PushMessage>,
        val page: Int,
        val pageSize: Int,
        val total: Int,
        val totalPages: Int,
        val keyword: String,
    ) {
        val hasNext: Boolean get() = page < totalPages
        val hasPrev: Boolean get() = page > 1
        val isEmpty: Boolean get() = items.isEmpty()
    }

    private fun persist(list: List<PushMessage>) {
        runCatching {
            val text = json.encodeToString(ListSerializer(PushMessage.serializer()), list)
            // 原子写入：先写临时文件，再 rename，避免写入中途崩溃导致数据损坏
            val tmpFile = java.io.File(messageFile.parentFile, "${messageFile.name}.tmp")
            tmpFile.writeText(text)
            if (!tmpFile.renameTo(messageFile)) {
                // rename 失败时回退到直接写入（跨文件系统时 rename 可能失败）
                messageFile.writeText(text)
                tmpFile.delete()
            }
        }
    }

    // ========== 消息导出 ==========

    /** 导出格式 */
    enum class ExportFormat(val ext: String, val mime: String, val label: String) {
        TXT("txt", "text/plain", "文本文件"),
        CSV("csv", "text/csv", "CSV 表格"),
        JSON("json", "application/json", "JSON 数据");

        companion object {
            fun fromOrdinalSafe(ord: Int): ExportFormat =
                values().getOrElse(ord) { TXT }
        }
    }

    /** 导出结果 */
    data class ExportResult(val file: File, val format: ExportFormat)

    /**
     * 导出全部消息到指定格式文件。
     * 文件写入 [storageDir] 下的 export/ 子目录，文件名带时间戳。
     */
    suspend fun export(format: ExportFormat): ExportResult = mutex.withLock {
        val list = _messages.value
        val exportDir = File(storageDir, "export").apply { mkdirs() }
        val timestamp = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.getDefault())
            .format(Date())
        val fileName = "messages_${timestamp}.${format.ext}"
        val file = File(exportDir, fileName)

        val content = when (format) {
            ExportFormat.TXT -> buildText(list)
            ExportFormat.CSV -> buildCsv(list)
            ExportFormat.JSON -> buildJson(list)
        }
        file.writeText(content)
        ExportResult(file, format)
    }

    /** 纯文本格式：按时间正序列出每条消息 */
    private fun buildText(list: List<PushMessage>): String {
        val sb = StringBuilder()
        sb.appendLine("====== 消息推送记录 ======")
        sb.appendLine("导出时间: ${SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(Date())}")
        sb.appendLine("消息总数: ${list.size}")
        sb.appendLine("==========================")
        sb.appendLine()
        list.forEachIndexed { i, msg ->
            val time = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault())
                .format(Date(msg.timestamp))
            sb.appendLine("[${i + 1}] ${msg.title.ifBlank { "(无标题)" }}")
            sb.appendLine("    时间: $time")
            sb.appendLine("    优先级: ${msg.priority}")
            sb.appendLine("    内容: ${msg.content}")
            sb.appendLine()
        }
        return sb.toString()
    }

    /** CSV 格式：标准表头 + 逐行数据 */
    private fun buildCsv(list: List<PushMessage>): String {
        val sb = StringBuilder()
        // UTF-8 BOM，确保 Excel 正确识别中文
        sb.append("\uFEFF")
        sb.appendLine("序号,消息ID,标题,内容,优先级,推送时间,接收时间")
        list.forEachIndexed { i, msg ->
            val pushTime = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault())
                .format(Date(msg.timestamp))
            val recvTime = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault())
                .format(Date(msg.receivedAt))
            sb.appendLine(
                "${i + 1},${csvEscape(msg.id)},${csvEscape(msg.title)}," +
                    "${csvEscape(msg.content)},${csvEscape(msg.priority)}," +
                    "${csvEscape(pushTime)},${csvEscape(recvTime)}"
            )
        }
        return sb.toString()
    }

    /** CSV 字段转义：含逗号/引号/换行时用双引号包裹，内部引号翻倍 */
    private fun csvEscape(value: String): String {
        return if (value.contains(',') || value.contains('"') || value.contains('\n')) {
            "\"${value.replace("\"", "\"\"")}\""
        } else {
            value
        }
    }

    /** JSON 格式：结构化数组 */
    private fun buildJson(list: List<PushMessage>): String {
        val pretty = Json {
            ignoreUnknownKeys = true
            prettyPrint = true
            encodeDefaults = true
        }
        return pretty.encodeToString(ListSerializer(PushMessage.serializer()), list)
    }
}
