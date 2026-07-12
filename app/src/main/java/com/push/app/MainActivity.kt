package com.push.app

import android.Manifest
import android.content.Intent
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.Modifier
import androidx.lifecycle.lifecycleScope
import com.push.app.data.PreferencesManager
import com.push.app.service.PushService
import com.push.app.ui.nav.PushNavHost
import com.push.app.ui.theme.PushAppTheme
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

/**
 * 主活动，承载 Compose 导航。
 *
 * 启动时：
 * 1. 申请通知权限（Android 13+）
 * 2. 如已存在 Key，则启动前台保活 Service
 */
class MainActivity : ComponentActivity() {

    private lateinit var preferencesManager: PreferencesManager

    // 通知权限申请
    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { /* 用户授权与否均不阻塞，后续在设置页可重新引导 */ }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        preferencesManager = PreferencesManager(applicationContext)

        // 申请通知权限（Android 13 / API 33+ 必须运行时申请）
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }

        // 若已配置 Key，则启动前台服务保活
        lifecycleScope.launch {
            val key = preferencesManager.keyFlow.first()
            if (key.isNotBlank()) {
                startPushService()
            }
        }

        setContent {
            PushAppTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    PushNavHost()
                }
            }
        }
    }

    /** 启动前台保活 Service（适配 Android 8+ 的启动方式） */
    private fun startPushService() {
        val intent = Intent(this, PushService::class.java).apply {
            action = PushService.ACTION_START
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            startForegroundService(intent)
        } else {
            startService(intent)
        }
    }
}
