package com.push.app.util

import android.app.NotificationManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings

/**
 * HyperOS / MIUI 权限引导助手。
 *
 * 由于小米系统的自启动、省电白名单、锁屏清理、后台弹出界面等权限无法通过标准 API 申请，
 * 需要引导用户跳转到对应系统设置页手动开启。每个方法都先尝试 MIUI 专属 Intent，
 * 失败后回退到通用设置页。
 *
 * 同时提供通知权限、省电白名单的运行时检测方法。
 */
object PermissionHelper {

    /**
     * 自启动权限（MIUI 安全中心）。
     */
    fun openAutoStartSettings(context: Context) {
        val miuiIntent = Intent().apply {
            setComponent(
                ComponentName(
                    "com.miui.securitycenter",
                    "com.miui.permcenter.autostart.AutoStartManagementActivity",
                )
            )
        }
        launchIntent(context, miuiIntent, fallback = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
            data = Uri.fromParts("package", context.packageName, null)
        })
    }

    /**
     * 省电白名单（忽略电池优化）。
     */
    fun openBatteryOptimizationSettings(context: Context) {
        val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS).apply {
            data = Uri.fromParts("package", context.packageName, null)
        }
        // 部分系统不支持该 action，回退到电池优化列表
        val fallback = Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)
        launchIntent(context, intent, fallback = fallback)
    }

    /**
     * 锁屏清理白名单（MIUI 安全中心 - 神隐模式 / 锁屏清理）。
     */
    fun openLockScreenCleanupSettings(context: Context) {
        val miuiIntent = Intent().apply {
            setComponent(
                ComponentName(
                    "com.miui.securitycenter",
                    "com.miui.permcenter.lockscreen.LockScreenCleanupActivity",
                )
            )
        }
        val fallback = Intent().apply {
            setComponent(
                ComponentName(
                    "com.miui.securitycenter",
                    "com.miui.permcenter.permissions.PermissionsEditorActivity",
                )
            )
        }
        launchIntent(
            context,
            miuiIntent,
            fallback = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.fromParts("package", context.packageName, null)
            },
        )
    }

    /**
     * 应用通知设置页（Android 8+）。
     */
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
        launchIntent(context, intent, fallback = Intent(Settings.ACTION_SETTINGS))
    }

    /**
     * 后台弹出界面权限（MIUI - 允许后台活动 / 弹出界面）。
     */
    fun openBackgroundPopupSettings(context: Context) {
        val miuiIntent = Intent().apply {
            setComponent(
                ComponentName(
                    "com.miui.securitycenter",
                    "com.miui.permcenter.permissions.PermissionsEditorActivity",
                )
            )
            putExtra("extra_pkgname", context.packageName)
        }
        launchIntent(context, miuiIntent, fallback = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
            data = Uri.fromParts("package", context.packageName, null)
        })
    }

    // ========== 检测方法 ==========

    /**
     * 通知是否已开启。
     */
    fun isNotificationEnabled(context: Context): Boolean {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return false
        return manager.areNotificationsEnabled()
    }

    /**
     * 是否已加入省电白名单（忽略电池优化）。
     */
    fun isBatteryOptimizationIgnored(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return true
        val pm = context.getSystemService(PowerManager::class.java) ?: return false
        return pm.isIgnoringBatteryOptimizations(context.packageName)
    }

    /**
     * 判断当前是否为小米 / HyperOS 系统。
     */
    fun isMiuiDevice(): Boolean {
        val brand = Build.BRAND?.lowercase()
        return brand == "xiaomi" || brand == "redmi" || brand == "poco"
    }

    // ========== 内部工具 ==========

    /** 尝试启动首选 Intent，失败则启动 fallback，再失败则启动通用设置 */
    private fun launchIntent(context: Context, primary: Intent, fallback: Intent) {
        try {
            primary.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            context.startActivity(primary)
        } catch (e: Exception) {
            try {
                fallback.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                context.startActivity(fallback)
            } catch (e2: Exception) {
                // 最终回退到系统设置
                runCatching {
                    context.startActivity(
                        Intent(Settings.ACTION_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                    )
                }
            }
        }
    }
}
