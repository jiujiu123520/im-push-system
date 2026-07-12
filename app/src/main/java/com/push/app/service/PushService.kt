package com.push.app.service

import android.app.Service
import android.content.Intent
import android.os.IBinder
import android.util.Log
import com.push.app.data.ConnectionState
import com.push.app.data.PushRepository
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch

/**
 * 前台保活 Service。
 *
 * 职责：
 * 1. 启动前台常驻通知（HyperOS / Android 14 下保活的基础）
 * 2. 通过 [PushRepository] 维持 WebSocket 长连接
 * 3. 订阅连接状态变化，实时刷新前台通知文案
 *
 * HyperOS 适配要点：
 * - foregroundServiceType=dataSync（已在清单声明）
 * - START_STICKY：被杀后系统尝试重建
 * - onTaskRemoved 中重新拉起自身，避免从最近任务划掉即被杀
 */
class PushService : Service() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var stateObserverJob: Job? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                Log.i(TAG, "ACTION_STOP")
                stopPush()
                return START_NOT_STICKY
            }
            // ACTION_START 或 null 都视为启动
            else -> startPush()
        }
        // 被系统杀死后尝试重建，保证长连接恢复
        return START_STICKY
    }

    /** 启动保活：拉起前台通知并连接 WebSocket */
    private fun startPush() {
        // 必须立即调用 startForeground，否则 Android 12+ 会抛出 ForegroundServiceStartNotAllowedException
        startForeground(
            NotificationHelper.FOREGROUND_NOTIFICATION_ID,
            NotificationHelper.buildForegroundNotification(this, ConnectionState.CONNECTING),
        )

        val repo = PushRepository.get(this)

        // 订阅连接状态，刷新前台通知文案
        stateObserverJob?.cancel()
        stateObserverJob = scope.launch {
            repo.connectionState.collect { state ->
                NotificationHelper.updateForegroundNotification(this@PushService, state)
            }
        }

        // 发起连接
        scope.launch {
            repo.connect()
        }
    }

    /** 停止保活：断开连接并停止前台服务 */
    @Suppress("DEPRECATION")
    private fun stopPush() {
        PushRepository.get(this).disconnect()
        stateObserverJob?.cancel()
        // stopForeground(int) 在 API 24+ 可用；低版本使用已废弃的 boolean 重载
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.N) {
            stopForeground(STOP_FOREGROUND_REMOVE)
        } else {
            stopForeground(true)
        }
        stopSelf()
    }

    /**
     * 用户从最近任务列表划掉应用时触发。
     * 重新启动自身，维持后台运行（HyperOS 保活关键点之一）。
     */
    override fun onTaskRemoved(rootIntent: Intent?) {
        Log.i(TAG, "onTaskRemoved, schedule restart")
        val restartIntent = Intent(applicationContext, PushService::class.java).apply {
            action = ACTION_START
        }
        // 通过 PendingIntent + Alarm 1s 后重启自身
        val pendingIntent = android.app.PendingIntent.getService(
            this,
            1,
            restartIntent,
            if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.M)
                android.app.PendingIntent.FLAG_ONE_SHOT or
                    android.app.PendingIntent.FLAG_IMMUTABLE
            else android.app.PendingIntent.FLAG_ONE_SHOT,
        )
        val alarm = getSystemService(ALARM_SERVICE) as android.app.AlarmManager
        alarm.set(
            android.app.AlarmManager.ELAPSED_REALTIME,
            android.os.SystemClock.elapsedRealtime() + 1000L,
            pendingIntent,
        )
        super.onTaskRemoved(rootIntent)
    }

    override fun onDestroy() {
        Log.i(TAG, "onDestroy")
        stateObserverJob?.cancel()
        scope.cancel()
        super.onDestroy()
    }

    companion object {
        private const val TAG = "PushService"
        const val ACTION_START = "com.push.app.action.START"
        const val ACTION_STOP = "com.push.app.action.STOP"
    }
}
