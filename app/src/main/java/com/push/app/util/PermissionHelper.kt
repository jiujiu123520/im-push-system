package com.push.app.util

import android.app.NotificationManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings

object PermissionHelper {

    fun isNotificationEnabled(context: Context): Boolean {
        val manager = context.getSystemService(Context.NOTIFICATION_SERVICE) as? NotificationManager
            ?: return false
        return manager.areNotificationsEnabled()
    }

    fun isIgnoringBatteryOptimizations(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return true
        val pm = context.getSystemService(Context.POWER_SERVICE) as? PowerManager ?: return false
        return pm.isIgnoringBatteryOptimizations(context.packageName)
    }

    fun isAutoStartAvailable(context: Context): Boolean {
        val brand = detectBrand()
        if (brand == Brand.XIAOMI) {
            return try {
                val intent = Intent().apply {
                    component = ComponentName(
                        "com.miui.securitycenter",
                        "com.miui.permcenter.autostart.AutoStartManagementActivity",
                    )
                }
                context.packageManager.resolveActivity(intent, 0) != null
            } catch (e: Exception) {
                false
            }
        }
        return true
    }

    fun isLockscreenClearProtected(context: Context): Boolean = true

    fun openNotificationSettings(context: Context) {
        val intent = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).apply {
                putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)
            }
        } else {
            Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.fromParts("package", context.packageName, null)
            }
        }
        launch(context, intent)
    }

    fun openBatteryOptimizationSettings(context: Context) {
        val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
            data = Uri.fromParts("package", context.packageName, null)
        }
        val fallback = Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)
        launch(context, intent, fallback)
    }

    fun openAutoStartSettings(context: Context) {
        val brand = detectBrand()
        val intent = buildAutoStartIntent(context, brand)
        launch(context, intent, appDetailsIntent(context))
    }

    fun openLockscreenSettings(context: Context) {
        val brand = detectBrand()
        val intent = buildLockscreenIntent(brand)
        launch(context, intent, appDetailsIntent(context))
    }

    fun openOverlaySettings(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            val intent = Intent(
                Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                Uri.parse("package:${context.packageName}"),
            )
            launch(context, intent)
        }
    }

    private fun buildAutoStartIntent(context: Context, brand: Brand): Intent = when (brand) {
        Brand.XIAOMI -> Intent().apply {
            component = ComponentName(
                "com.miui.securitycenter",
                "com.miui.permcenter.autostart.AutoStartManagementActivity",
            )
        }
        Brand.OPPO -> Intent().apply {
            component = ComponentName(
                "com.coloros.safecenter",
                "com.coloros.safecenter.permission.startup.StartupAppListActivity",
            )
        }
        Brand.SAMSUNG -> Intent().apply {
            component = ComponentName(
                "com.samsung.android.sm",
                "com.samsung.android.sm.policy.SmPolicyActivity",
            )
        }
        Brand.HUAWEI -> Intent().apply {
            component = ComponentName(
                "com.huawei.systemmanager",
                "com.huawei.systemmanager.optimize.process.ProtectActivity",
            )
        }
        Brand.VIVO -> Intent().apply {
            component = ComponentName(
                "com.vivo.permissionmanager",
                "com.vivo.permissionmanager.activity.BgStartUpManagerActivity",
            )
        }
        Brand.GENERIC -> appDetailsIntent(context)
    }

    private fun buildLockscreenIntent(brand: Brand): Intent = when (brand) {
        Brand.XIAOMI -> Intent().apply {
            component = ComponentName(
                "com.miui.securitycenter",
                "com.miui.permcenter.lockscreen.LockScreenCleanupActivity",
            )
        }
        Brand.HUAWEI -> Intent().apply {
            component = ComponentName(
                "com.huawei.systemmanager",
                "com.huawei.systemmanager.appcontrol.activity.StartupAppControlActivity",
            )
        }
        else -> Intent(Settings.ACTION_SETTINGS)
    }

    private fun appDetailsIntent(context: Context): Intent = Intent(
        Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
        Uri.fromParts("package", context.packageName, null),
    )

    private fun launch(context: Context, primary: Intent, fallback: Intent? = null) {
        try {
            primary.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            context.startActivity(primary)
        } catch (e: Exception) {
            fallback?.let {
                try {
                    it.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    context.startActivity(it)
                } catch (_: Exception) {
                }
            }
        }
    }

    enum class Brand(val label: String, val shortLabel: String) {
        XIAOMI("小米 / Redmi", "小米"),
        OPPO("OPPO / OnePlus / Realme", "OPPO"),
        SAMSUNG("Samsung", "三星"),
        HUAWEI("华为 / Honor", "华为"),
        VIVO("vivo / iQOO", "vivo"),
        GENERIC("通用 Android", "通用");
    }

    internal fun detectBrand(): Brand {
        val brand = Build.BRAND?.lowercase().orEmpty()
        val all = "$brand ${Build.MANUFACTURER?.lowercase().orEmpty()}"
        return when {
            all.contains("xiaomi") || all.contains("redmi") || all.contains("poco") -> Brand.XIAOMI
            all.contains("oppo") || all.contains("oneplus") || all.contains("realme") -> Brand.OPPO
            all.contains("samsung") -> Brand.SAMSUNG
            all.contains("huawei") || all.contains("honor") -> Brand.HUAWEI
            all.contains("vivo") || all.contains("iqoo") -> Brand.VIVO
            else -> Brand.GENERIC
        }
    }
}
