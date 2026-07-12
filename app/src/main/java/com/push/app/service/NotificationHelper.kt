package com.push.app.service

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import androidx.core.app.NotificationCompat
import com.push.app.MainActivity
import com.push.app.R
import com.push.app.data.ConnectionState
import com.push.app.data.PushMessage
import java.util.concurrent.atomic.AtomicInteger

/**
 * 通知助手。
 *
 * 维护四类通知渠道：
 * 1. [CHANNEL_FOREGROUND] 前台服务常驻通知（低优先级、静默）
 * 2. [CHANNEL_HIGH] 高优先级推送（声音 + 横幅）
 * 3. [CHANNEL_DEFAULT] 普通推送
 * 4. [CHANNEL_LOW] 低优先级推送（静默）
 */
object NotificationHelper {

    const val CHANNEL_FOREGROUND = "push_foreground"
    const val CHANNEL_HIGH = "push_high"
    const val CHANNEL_DEFAULT = "push_default"
    const val CHANNEL_LOW = "push_low"

    // 前台服务通知 ID（固定，常驻）
    const val FOREGROUND_NOTIFICATION_ID = 1001
    // 推送消息通知起始 ID（自增避免覆盖）；原子操作保证多线程并发安全
    private val pushNotificationId = AtomicInteger(2000)

    /**
     * 创建所有通知渠道。应在 Application.onCreate 中调用。
     */
    fun createChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(NotificationManager::class.java) ?: return

        // 前台服务渠道
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_FOREGROUND,
                context.getString(R.string.foreground_channel_name),
                NotificationManager.IMPORTANCE_LOW,
            ).apply {
                description = context.getString(R.string.foreground_channel_desc)
                setShowBadge(false)
            }
        )

        // 高优先级
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_HIGH,
                context.getString(R.string.channel_high_name),
                NotificationManager.IMPORTANCE_HIGH,
            ).apply {
                description = context.getString(R.string.channel_high_desc)
                enableVibration(true)
                enableLights(true)
            }
        )

        // 默认
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_DEFAULT,
                context.getString(R.string.channel_default_name),
                NotificationManager.IMPORTANCE_DEFAULT,
            ).apply {
                description = context.getString(R.string.channel_default_desc)
            }
        )

        // 低优先级
        manager.createNotificationChannel(
            NotificationChannel(
                CHANNEL_LOW,
                context.getString(R.string.channel_low_name),
                NotificationManager.IMPORTANCE_MIN,
            ).apply {
                description = context.getString(R.string.channel_low_desc)
                setShowBadge(false)
            }
        )
    }

    /**
     * 构建前台服务常驻通知。连接状态变化时调用 [updateForegroundNotification] 刷新文案。
     */
    fun buildForegroundNotification(
        context: Context,
        state: ConnectionState = ConnectionState.CONNECTING,
    ): android.app.Notification {
        val text = when (state) {
            ConnectionState.CONNECTED -> context.getString(R.string.foreground_notification_text)
            ConnectionState.RECONNECTING -> "正在重连服务器…"
            else -> "等待连接…"
        }
        return NotificationCompat.Builder(context, CHANNEL_FOREGROUND)
            .setContentTitle(context.getString(R.string.foreground_notification_title))
            .setContentText(text)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setOngoing(true)
            .setSilent(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setContentIntent(buildMainActivityIntent(context))
            .build()
    }

    /**
     * 更新前台通知文案（连接状态变化时调用）。
     */
    fun updateForegroundNotification(context: Context, state: ConnectionState) {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        manager.notify(
            FOREGROUND_NOTIFICATION_ID,
            buildForegroundNotification(context, state),
        )
    }

    /**
     * 展示推送通知。
     * 根据消息 priority 选择渠道与样式；点击跳转到 MainActivity。
     */
    fun showPushNotification(context: Context, message: PushMessage) {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return

        val (channelId, priority) = when (message.priority.lowercase()) {
            "high" -> CHANNEL_HIGH to NotificationCompat.PRIORITY_HIGH
            "low" -> CHANNEL_LOW to NotificationCompat.PRIORITY_MIN
            else -> CHANNEL_DEFAULT to NotificationCompat.PRIORITY_DEFAULT
        }

        val builder = NotificationCompat.Builder(context, channelId)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setContentTitle(message.title.ifBlank { context.getString(R.string.app_name) })
            .setContentText(message.content)
            .setStyle(NotificationCompat.BigTextStyle().bigText(message.content))
            .setAutoCancel(true)
            .setPriority(priority)
            .setContentIntent(buildMainActivityIntent(context))
            .setGroup("push_messages")

        when (message.priority.lowercase()) {
            "high" -> {
                builder.setDefaults(NotificationCompat.DEFAULT_ALL)
                builder.setVibrate(longArrayOf(0, 300, 200, 300))
            }
            "low" -> builder.setSilent(true)
            else -> builder.setDefaults(NotificationCompat.DEFAULT_SOUND or NotificationCompat.DEFAULT_VIBRATE)
        }

        val id = pushNotificationId.getAndIncrement()
        manager.notify(id, builder.build())
    }

    /** 点击通知跳转到 MainActivity 的 PendingIntent */
    private fun buildMainActivityIntent(context: Context): PendingIntent {
        val intent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        } else {
            PendingIntent.FLAG_UPDATE_CURRENT
        }
        return PendingIntent.getActivity(context, 0, intent, flags)
    }
}
