package com.push.app

import android.app.Application
import android.content.Context
import androidx.work.Configuration
import androidx.work.WorkManager
import org.json.JSONObject
import java.io.BufferedReader
import java.io.InputStreamReader

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
        initializeWorkManager()
    }

    private fun initializeWorkManager() {
        val config = Configuration.Builder()
            .setMinimumLoggingLevel(android.util.Log.INFO)
            .build()
        WorkManager.initialize(this, config)
    }

    override fun getWorkManagerConfiguration(): Configuration {
        return Configuration.Builder()
            .setMinimumLoggingLevel(android.util.Log.INFO)
            .build()
    }

    companion object {
        lateinit var instance: PushApplication
            private set
    }
}
