package com.push.app.util

import android.Manifest
import android.app.Activity
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.os.Build
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.push.app.MainActivity
import com.push.app.R

object NotificationHelper {

    const val CHANNEL_ALERTS = "push_alerts"
    const val CHANNEL_HEARTBEAT = "push_heartbeat"

    private const val REQUEST_POST_NOTIFICATIONS = 1001

    fun createChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager

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
    ): android.app.Notification {
        val launchIntent = Intent(context, MainActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            context, 0, launchIntent,
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M)
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
            else PendingIntent.FLAG_UPDATE_CURRENT,
        )

        val builder = NotificationCompat.Builder(context, channelId)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setContentTitle(title)
            .setContentText(content)
            .setAutoCancel(true)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)

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
