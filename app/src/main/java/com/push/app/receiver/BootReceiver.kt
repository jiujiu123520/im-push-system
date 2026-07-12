package com.push.app.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log
import com.push.app.data.PreferencesManager
import com.push.app.service.PushService
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

/**
 * 开机自启接收器。
 *
 * 收到 BOOT_COMPLETED 后：若用户已配置 Key，则启动 [PushService] 维持长连接。
 */
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent?) {
        val action = intent?.action
        Log.i(TAG, "onReceive: $action")
        if (action !in BOOT_ACTIONS) return

        // 使用 goAsync 避免阻塞主线程读取 DataStore
        val pendingResult = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                val key = PreferencesManager(context).keyFlow.first()
                if (key.isNotBlank()) {
                    Log.i(TAG, "key exists, starting PushService")
                    startPushService(context)
                } else {
                    Log.i(TAG, "no key configured, skip")
                }
            } catch (e: Exception) {
                Log.e(TAG, "read key failed", e)
            } finally {
                pendingResult.finish()
            }
        }
    }

    /** 启动前台 Service（适配 Android 8+） */
    private fun startPushService(context: Context) {
        val serviceIntent = Intent(context, PushService::class.java).apply {
            action = PushService.ACTION_START
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            context.startForegroundService(serviceIntent)
        } else {
            context.startService(serviceIntent)
        }
    }

    companion object {
        private const val TAG = "BootReceiver"
        private val BOOT_ACTIONS = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            "android.intent.action.QUICKBOOT_POWERON",
            "com.htc.intent.action.QUICKBOOT_POWERON",
            Intent.ACTION_MY_PACKAGE_REPLACED,
        )
    }
}
