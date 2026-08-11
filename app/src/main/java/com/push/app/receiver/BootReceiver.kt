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
import kotlinx.coroutines.launch

class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent?) {
        val action = intent?.action
        Log.i(TAG, "onReceive: $action")

        if (action != Intent.ACTION_BOOT_COMPLETED &&
            action != Intent.ACTION_MY_PACKAGE_REPLACED
        ) return

        val pendingResult = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                PreferencesManager.init(context)
                val key = PreferencesManager.getKey()
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
    }
}
