package com.push.app.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.util.Log
import androidx.core.app.NotificationCompat
import com.push.app.MainActivity
import com.push.app.R
import com.push.app.data.ConnectionState
import com.push.app.data.PushRepository
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch

class PushService : Service() {

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)

    // 锁屏 WakeLock：仅在屏幕关闭时持有，保持 CPU 唤醒以维持 WebSocket 心跳，
    // 避免 HyperOS/Doze 冻结后台协程导致 90 秒内无法响应服务端 ping 而掉线。
    // 亮屏时释放，兼顾耗电（不做永久持锁）。
    private var wakeLock: PowerManager.WakeLock? = null

    private val screenReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            when (intent?.action) {
                Intent.ACTION_SCREEN_OFF -> acquireWakeLock()
                Intent.ACTION_SCREEN_ON -> releaseWakeLock()
            }
        }
    }

    override fun onCreate() {
        super.onCreate()
        registerScreenReceiver()
        // 服务启动时若已处于锁屏状态，立即持锁
        val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
        if (!pm.isInteractive) acquireWakeLock()
    }

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
        createNotificationChannel()
        startForeground(NOTIFICATION_ID, buildNotification("正在连接..."))

        val repo = PushRepository.get(this)

        serviceScope.launch {
            repo.connectionState.collect { state ->
                val text = when (state) {
                    ConnectionState.CONNECTED -> "已连接"
                    ConnectionState.CONNECTING -> "连接中..."
                    ConnectionState.RECONNECTING -> "重连中..."
                    ConnectionState.DISCONNECTED -> "已断开"
                    ConnectionState.ERROR -> "连接错误，请手动重连"
                }
                val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
                manager.notify(NOTIFICATION_ID, buildNotification(text))
            }
        }

        repo.connect()
    }

    @Suppress("DEPRECATION")
    private fun stopPush() {
        val repo = PushRepository.get(this)
        repo.disconnect()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            stopForeground(STOP_FOREGROUND_REMOVE)
        } else {
            stopForeground(true)
        }
        stopSelf()
    }

    /** 注册屏幕开关广播，锁屏时持锁、亮屏时释放 */
    private fun registerScreenReceiver() {
        val filter = IntentFilter().apply {
            addAction(Intent.ACTION_SCREEN_OFF)
            addAction(Intent.ACTION_SCREEN_ON)
        }
        registerReceiver(screenReceiver, filter)
    }

    /** 持有 PARTIAL_WAKE_LOCK，保持 CPU 唤醒（不点亮屏幕） */
    @Suppress("DEPRECATION")
    private fun acquireWakeLock() {
        if (wakeLock?.isHeld == true) return
        val pm = getSystemService(Context.POWER_SERVICE) as PowerManager
        val lock = wakeLock ?: pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "$TAG:keepalive").apply {
            setReferenceCounted(false)
        }
        wakeLock = lock
        lock.acquire()
        Log.i(TAG, "WakeLock acquired (screen off)")
    }

    /** 释放 WakeLock */
    private fun releaseWakeLock() {
        if (wakeLock?.isHeld == true) {
            wakeLock?.release()
            Log.i(TAG, "WakeLock released")
        }
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val manager = getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
            if (manager.getNotificationChannel(CHANNEL_ID) != null) return
            val channel = NotificationChannel(
                CHANNEL_ID,
                "推送服务",
                NotificationManager.IMPORTANCE_HIGH,
            ).apply {
                description = "推送服务常驻通知"
                setShowBadge(false)
                enableLights(false)
                enableVibration(false)
                setSound(null, null)
            }
            manager.createNotificationChannel(channel)
        }
    }

    private fun buildNotification(text: String): Notification {
        val launchIntent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
        }
        val pendingIntent = PendingIntent.getActivity(
            this, 0, launchIntent,
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M)
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
            else PendingIntent.FLAG_UPDATE_CURRENT,
        )
        return NotificationCompat.Builder(this, CHANNEL_ID)
            .setContentTitle(getString(R.string.app_name))
            .setContentText(text)
            .setSmallIcon(android.R.drawable.ic_dialog_info)
            .setOngoing(true)
            .setSilent(true)
            .setContentIntent(pendingIntent)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .build()
    }

    override fun onDestroy() {
        Log.i(TAG, "onDestroy")
        releaseWakeLock()
        runCatching { unregisterReceiver(screenReceiver) }
        serviceScope.cancel()
        super.onDestroy()
    }

    companion object {
        private const val TAG = "PushService"
        private const val CHANNEL_ID = "push_service_channel"
        private const val NOTIFICATION_ID = 1001

        const val ACTION_START = "com.push.app.action.START"
        const val ACTION_STOP = "com.push.app.action.STOP"
    }
}
