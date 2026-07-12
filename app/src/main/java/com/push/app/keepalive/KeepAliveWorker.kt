package com.push.app.keepalive

import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.push.app.data.ConnectionState
import com.push.app.data.PushRepository
import com.push.app.service.PushService

/**
 * WorkManager 定时保活任务。
 *
 * 每 15 分钟执行一次（WorkManager 周期任务最小间隔为 15 分钟）：
 * 1. 检查 WebSocket 连接状态，若未连接则触发重连
 * 2. 确保 [PushService] 仍在运行（被系统杀死时重新拉起）
 *
 * 这是在前台 Service 之外的第二道保活防线，适配 HyperOS 的激进修策略。
 */
class KeepAliveWorker(
    appContext: Context,
    params: WorkerParameters,
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        Log.i(TAG, "doWork: start keep-alive check")
        val context = applicationContext
        val repo = PushRepository.get(context)

        return try {
            // 1. 确保前台 Service 在运行
            ensureServiceRunning(context)
            // 2. 检查连接状态，必要时重连
            val state = repo.connectionState.value
            Log.i(TAG, "current connection state = $state")
            when (state) {
                ConnectionState.DISCONNECTED,
                ConnectionState.RECONNECTING -> {
                    Log.i(TAG, "connection not healthy, trigger reconnect")
                    repo.reconnect()
                }
                else -> { /* CONNECTING / CONNECTED 无需处理 */ }
            }
            Result.success()
        } catch (e: Exception) {
            Log.e(TAG, "doWork failed", e)
            // 失败时返回 retry，WorkManager 会按退避策略重试
            Result.retry()
        }
    }

    /** 拉起前台 Service（若已运行则无副作用） */
    private fun ensureServiceRunning(context: Context) {
        val intent = Intent(context, PushService::class.java).apply {
            action = PushService.ACTION_START
        }
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                context.startForegroundService(intent)
            } else {
                context.startService(intent)
            }
        } catch (e: Exception) {
            // Android 12+ 后台启动前台服务受限，记录日志即可
            Log.w(TAG, "startForegroundService restricted: ${e.message}")
        }
    }

    companion object {
        const val WORK_NAME = "push_keep_alive"
        private const val TAG = "KeepAliveWorker"
    }
}
