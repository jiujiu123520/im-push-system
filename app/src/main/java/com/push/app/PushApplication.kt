package com.push.app

import android.app.Application
import android.content.Context
import androidx.work.Configuration
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import com.push.app.data.PreferencesManager
import com.push.app.keepalive.KeepAliveWorker
import com.push.app.util.NotificationHelper
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader
import java.util.concurrent.TimeUnit

data class BuildConfig(
    val serverUrl: String,
    val wsUrl: String,
    val defaultKey: String,
    val appName: String
) {
    companion object {
        fun load(context: Context): BuildConfig {
            return try {
                val inputStream = context.assets.open("build_config.json")
                val reader = BufferedReader(InputStreamReader(inputStream))
                val sb = StringBuilder()
                var line: String?
                while (reader.readLine().also { line = it } != null) {
                    sb.append(line)
                }
                reader.close()
                val json = JSONObject(sb.toString())
                BuildConfig(
                    serverUrl = json.optString("server_url", ""),
                    wsUrl = json.optString("ws_url", ""),
                    defaultKey = json.optString("default_key", ""),
                    appName = json.optString("app_name", "PushApp")
                )
            } catch (e: Exception) {
                BuildConfig("", "", "", "PushApp")
            }
        }
    }
}

class PushApplication : Application(), Configuration.Provider {

    lateinit var globalConfig: BuildConfig
        private set

    override fun onCreate() {
        super.onCreate()
        instance = this
        globalConfig = BuildConfig.load(this)
        PreferencesManager.init(this)
        NotificationHelper.createChannels(this)
        scheduleKeepAlive()
    }

    /** 调度 KeepAliveWorker：每 15 分钟兜底检查连接状态，断开时触发重连 */
    private fun scheduleKeepAlive() {
        // getKey 为 suspend 函数，需在协程中读取
        CoroutineScope(Dispatchers.IO).launch {
            val key = PreferencesManager.getKey()
            if (key.isBlank()) {
                // 未配置推送 Key 时无需保活
                return@launch
            }
            val request = PeriodicWorkRequestBuilder<KeepAliveWorker>(15, TimeUnit.MINUTES).build()
            WorkManager.getInstance(this@PushApplication).enqueueUniquePeriodicWork(
                KeepAliveWorker.WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request,
            )
        }
    }

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder()
            .setMinimumLoggingLevel(android.util.Log.INFO)
            .build()

    companion object {
        lateinit var instance: PushApplication
            private set
    }
}
