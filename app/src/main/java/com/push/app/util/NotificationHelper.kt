package com.push.app.util

import android.Manifest
import android.app.Activity
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.os.Build
import androidx.activity.result.ActivityResultLauncher
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.push.app.MainActivity
import com.push.app.R

object NotificationHelper {

    const val CHANNEL_ALERTS = "push_alerts"
    const val CHANNEL_HEARTBEAT = "push_heartbeat"

    private const val REQUEST_POST_NOTIFICATIONS = 1001
    private const val NOTIFICATION_ID_BASE = 2000

    fun createChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

        if (manager.getNotificationChannel(CHANNEL_ALERTS) != null &&
            manager.getNotificationChannel(CHANNEL_HEARTBEAT) != null
        ) return

        val alertsChannel = NotificationChannel(
            CHANNEL_ALERTS,
            "推送通知",
            NotificationManager.IMPORTANCE_DEFAULT,
        ).apply {
            description = "重要推送消息"
            enableLights(true)
            enableVibration(true)
        }

        val heartbeatChannel = NotificationChannel(
            CHANNEL_HEARTBEAT,
            "连接状态",
            NotificationManager.IMPORTANCE_LOW,
        ).apply {
            description = "后台连接状态"
            setShowBadge(false)
        }

        manager.createNotificationChannel(alertsChannel)
        manager.createNotificationChannel(heartbeatChannel)
    }

    fun buildNotification(
        context: Context,
        title: String,
        content: String,
        image: Bitmap? = null,
        channelId: String = CHANNEL_ALERTS,
    ): Notification {
        val launchIntent = Intent(context, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val pendingIntent = PendingIntent.getActivity(
            context, 0, launchIntent,
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M)
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
            else PendingIntent.FLAG_UPDATE_CURRENT,
        )

        val builder = NotificationCompat.Builder(context, channelId)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setContentTitle(title)
            .setContentText(content)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setDefaults(NotificationCompat.DEFAULT_ALL)

        if (image != null) {
            builder.setStyle(
                NotificationCompat.BigPictureStyle()
                    .bigPicture(image)
                    .setBigContentTitle(title)
                    .setSummaryText(content),
            )
        } else {
            builder.setStyle(NotificationCompat.BigTextStyle().bigText(content))
        }

        return builder.build()
    }

    fun showPushNotification(
        context: Context,
        id: String,
        title: String,
        content: String,
    ) {
        if (hasPostNotificationsPermission(context)) {
            createChannels(context)
            val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            val notificationId = NOTIFICATION_ID_BASE + id.hashCode().and(0xfffffff)
            manager.notify(notificationId, buildNotification(context, title, content))
        }
    }

    fun requestPostNotificationsPermission(activity: Activity) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            if (ContextCompat.checkSelfPermission(
                    activity,
                    Manifest.permission.POST_NOTIFICATIONS,
                ) != PackageManager.PERMISSION_GRANTED
            ) {
                ActivityCompat.requestPermissions(
                    activity,
                    arrayOf(Manifest.permission.POST_NOTIFICATIONS),
                    REQUEST_POST_NOTIFICATIONS,
                )
            }
        }
    }

    fun requestPostNotificationsPermission(launcher: ActivityResultLauncher<String>) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            launcher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    fun hasPostNotificationsPermission(context: Context): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            ContextCompat.checkSelfPermission(
                context,
                Manifest.permission.POST_NOTIFICATIONS,
            ) == PackageManager.PERMISSION_GRANTED
        } else {
            true
        }
    }
}
