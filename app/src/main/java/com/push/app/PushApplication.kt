package com.push.app

import android.app.Application
import androidx.work.Configuration
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import com.push.app.keepalive.KeepAliveWorker
import java.util.concurrent.TimeUnit

/**
 * 应用入口。
 *
 * 主要职责：
 * 1. 初始化 WorkManager（自定义 Configuration 以便使用依赖注入式的初始化）
 * 2. 创建通知渠道
 * 3. 注册 15 分钟周期的保活 Worker（检查 WebSocket 连接状态）
 */
class PushApplication : Application(), Configuration.Provider {

    // WorkManager 自定义配置，指定使用默认执行器
    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder()
            .setMinimumLoggingLevel(android.util.Log.INFO)
            .build()

    override fun onCreate() {
        super.onCreate()
        instance = this

        // 创建通知渠道（前台服务 + 推送消息各优先级）
        com.push.app.service.NotificationHelper.createChannels(this)

        // 实现了 Configuration.Provider 后，WorkManager 在首次调用 getInstance 时按需初始化，
        // 无需手动 initialize()，否则可能与 androidx.startup 的初始化冲突。
        scheduleKeepAlive()
    }

    /**
     * 注册 15 分钟周期任务，检查 WebSocket 连接存活。
     * 使用 KEEP 策略避免重复注册。
     */
    private fun scheduleKeepAlive() {
        val request = PeriodicWorkRequestBuilder<KeepAliveWorker>(
            15, TimeUnit.MINUTES
        ).build()
        WorkManager.getInstance(this).enqueueUniquePeriodicWork(
            KeepAliveWorker.WORK_NAME,
            ExistingPeriodicWorkPolicy.KEEP,
            request,
        )
    }

    companion object {
        @Volatile
        lateinit var instance: PushApplication
            private set
    }
}
