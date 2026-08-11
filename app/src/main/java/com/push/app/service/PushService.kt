package com.push.app.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.util.Log
import androidx.core.app.NotificationCompat
import com.push.app.MainActivity
import com.push.app.R
import com.push.app.data.PreferencesManager
import com.push.app.network.PushWebSocket
import com.push.app.util.NotificationHelper
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

class PushService : Service() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var wakeLock: PowerManager.WakeLock? = null
    private var webSocket: PushWebSocket? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                stopPush()
                return START_NOT_STICKY
            }
            else -> startPush()
        }
        return START_STICKY
    }

    private fun startPush() {
        acquireWakeLock()
        createNotificationChannel()
        startForeground(NOTIFICATION_ID, buildNotification())

        scope.launch {
            val wsUrl = PreferencesManager.getWsUrl()
            val key = PreferencesManager.getKey()
            val autoReconnect = PreferencesManager.isAutoReconnect()
            val heartbeatInterval = PreferencesManager.getHeartbeatInterval()

            if (wsUrl.isBlank() || key.isBlank()) {
                Log.w(TAG, "wsUrl or key is blank, abort")
                stopSelf()
                return@launch
            }

            webSocket?.destroy()
            webSocket = PushWebSocket(object : PushWebSocket.Events {
                override fun onOpen() {
                    Log.i(TAG, "WebSocket connected")
                    updateNotification("已连接")
                }

                override fun onMessage(text: String) {
                    Log.i(TAG, "onMessage: $text")
                    sendMessageBroadcast(text)
                }

                override fun onClose() {
                    Log.i(TAG, "WebSocket closed, reconnecting...")
                    updateNotification("重连中...")
                }

                override fun onFailure(t: Throwable) {
                    Log.e(TAG, "WebSocket failure: ${t.message}")
                    updateNotification("连接失败，重连中...")
                }
            }).apply {
                setAutoReconnect(autoReconnect)
                setHeartbeatInterval(heartbeatInterval)
                connect(wsUrl, key)
            }
        }
    }

    @Suppress("DEPRECATION")
    private fun stopPush() {
        releaseWakeLock()
        webSocket?.destroy()
        webSocket = null
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            stopForeground(STOP_FOREGROUND_REMOVE)
        } else {
            stopForeground(true)
        }
        stopSelf()
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            val channel = NotificationChannel(
                CHANNEL_ID,
                "推送服务",
                NotificationManager.IMPORTANCE_LOW,
            ).apply {
                description = "推送服务常驻通知"
                setShowBadge(false)
            }
            manager.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(): Notification {
        val launchIntent = Intent(this, MainActivity::class.java)
        val pendingIntent = PendingIntent.getActivity(
            this, 0, launchIntent,
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M)
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
            else PendingIntent.FLAG_UPDATE_CURRENT,
        )
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.app_name))
            .setContentText("正在连接...")
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setOngoing(true)
            .setSilent(true)
            .setContentIntent(pendingIntent)
            .build()
    }

    private fun updateNotification(text: String) {
        val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        val notification = NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(text)
            .setSmallIcon(R.drawable.ic_launcher_foreground)
            .setOngoing(true)
            .setSilent(true)
            .build()
        manager.notify(NOTIFICATION_ID, notification)
    }

    private fun sendMessageBroadcast(message: String) {
        val intent = Intent(ACTION_PUSH_MESSAGE).apply {
            putExtra(EXTRA_MESSAGE, message)
            setPackage(packageName)
        }
        sendBroadcast(intent)
    }

    private fun acquireWakeLock() {
        try {
            val pm = getSystemService(POWER_SERVICE) as PowerManager
            wakeLock = pm.newWakeLock(
                PowerManager.PARTIAL_WAKE_LOCK,
                "PushApp:ServiceWakeLock",
            ).apply {
                setReferenceCounted(false)
                acquire(10 * 60 * 1000L)
            }
            Log.i(TAG, "WakeLock acquired")
        } catch (e: Exception) {
            Log.w(TAG, "acquireWakeLock failed: ${e.message}")
        }
    }

    private fun releaseWakeLock() {
        try {
            wakeLock?.let { if (it.isHeld) it.release() }
            wakeLock = null
            Log.i(TAG, "WakeLock released")
        } catch (e: Exception) {
            Log.w(TAG, "releaseWakeLock failed: ${e.message}")
        }
    }

    override fun onDestroy() {
        Log.i(TAG, "onDestroy")
        releaseWakeLock()
        webSocket?.destroy()
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        private const val TAG = "PushService"
        private const val CHANNEL_ID = "push_service_channel"
        private const val NOTIFICATION_ID = 1001

        const val ACTION_START = "com.push.app.action.START"
        const val ACTION_STOP = "com.push.app.action.STOP"
        const val ACTION_PUSH_MESSAGE = "com.push.app.action.PUSH_MESSAGE"
        const val EXTRA_MESSAGE = "extra_message"
    }
}
