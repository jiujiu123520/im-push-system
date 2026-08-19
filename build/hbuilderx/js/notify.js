// ============================================================
// 本地通知模块（完全移植老版本 showNotification + fallbackNotify）
// 核心要点：
//   1. 双重权限检查（nm.areNotificationsEnabled + POST_NOTIFICATIONS 运行时）
//   2. 渠道 IMPORTANCE_HIGH + bypassDnd + enableLights + 绿色LED
//   3. Builder setPriority(2) PRIORITY_MAX + setVisibility(1) VISIBILITY_PUBLIC
//   4. Android 28+ FullScreenIntent 锁屏优先显示
//   5. 大文本（>50字符自动 BigTextStyle）
//   6. 失败兜底 fallbackNotify（震动+Toast+提示音）
//   7. 渠道被禁用检测（getImportance() === 0 时引导设置）
//
// 兼容导出（首页/App.vue 直接用这些名字导入）：
//   notify(title, content, priority)  → showNotification 的别名
//   ensureChannels()                 → 创建通知渠道（幂等，冷启动调用）
// ============================================================

import { getNotificationSmallIcon } from './keepalive.js'
// 🔴 静态导入权限模块（vue3/vite APP 端无 require 函数，CommonJS 不可用）
//   之前 require('./permissions.js') 会抛 "require is not defined" → 权限引导永远不执行
import * as _permLib from './permissions.js'

// 1. 创建消息推送通知渠道（老版本 createNotificationChannel，直接复用）
export function createNotificationChannel() {
    if (typeof plus === 'undefined' || !plus.android) return
    try {
        const Build = plus.android.importClass('android.os.Build')
        if (Build.VERSION.SDK_INT < 26) return
        const main = plus.android.runtimeMainActivity()
        const Context = plus.android.importClass('android.content.Context')
        const NotificationManager = plus.android.importClass('android.app.NotificationManager')
        const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')

        const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
        const msgChannelId = 'push_messages'
        const msgChannel = nm.getNotificationChannel(msgChannelId)
        if (msgChannel === null || msgChannel === undefined) {
            const importance = NotificationManager.IMPORTANCE_HIGH
            const channel = new NotificationChannel(msgChannelId, '消息推送', importance)
            channel.enableLights(true)
            channel.enableVibration(true)
            channel.setShowBadge(true)
            channel.setDescription('推送消息通知（锁屏可见）')
            channel.setLockscreenVisibility(1)
            try { channel.setBypassDnd(true) } catch (_) {}
            try { channel.setLightColor(0xFF00FF00) } catch (_) {}
            try { channel.setVibrationPattern([0, 200, 200, 200]) } catch (_) {}
            nm.createNotificationChannel(channel)
            console.log('[Notify] 消息推送通知渠道已创建（锁屏可见）')
        }
    } catch (e) {
        console.error('[Notify] 创建通知渠道失败', e)
    }
}

// 2. 通知栏显示失败的兜底方案（震动+Toast+提示音）
export function fallbackNotify(title, content, reason) {
    try {
        console.warn('[Notify] 进入 fallback 通知方案: ' + (reason || '未知原因'))
        uni.showToast({
            title: (title || '新消息') + (reason ? '（' + reason + '）' : ''),
            icon: 'none',
            duration: 2500
        })
        try {
            if (uni.vibrateShort) { uni.vibrateShort({ type: 'heavy' }) }
            else if (uni.vibrateLong) { uni.vibrateLong() }
        } catch (_) {}
        try {
            if (typeof Notification !== 'undefined') {
                new Notification(title || '新消息', { body: content || '' })
            }
        } catch (_) {}
    } catch (_) {}
}

// 3. 主函数：showNotification（完全移植老版本，不做任何简化）
// 注意：收到推送时先收到消息是第一位的！所以策略是：
//   1. 先**尝试构建并显示通知**（即使权限检查看起来没过，也让系统做最终判断）
//   2. 失败时再 fallbackNotify（震动+Toast+提示音）确保用户能感知
//   3. 权限未开启时同时请求权限（guide=true，强制引导用户去设置）
export function showNotification(title, content, opts) {
    if (typeof plus === 'undefined' || !plus.android) {
        // H5/非 APP-PLUS 环境
        if (uni.showNotification) {
            try { uni.showNotification({ title, content }) } catch (_) {}
        }
        return true
    }

    const notifTitle = title || '新消息'
    const notifContent = content || ''

    try {
        const main = plus.android.runtimeMainActivity()
        const Context = plus.android.importClass('android.content.Context')
        const Intent = plus.android.importClass('android.content.Intent')
        const PendingIntent = plus.android.importClass('android.app.PendingIntent')
        const Build = plus.android.importClass('android.os.Build')
        const NotificationManager = plus.android.importClass('android.app.NotificationManager')

        const channelId = 'push_messages'
        const notificationId = Math.floor(Math.random() * 100000) + 1

        // ===== 0. 先判断通知权限状态（不阻塞，用于失败时引导） =====
        let nm = null
        let globalEnabled = true
        let postPermOk = true
        try {
            nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
            try { globalEnabled = nm.areNotificationsEnabled() !== false } catch (_) { globalEnabled = true }
        } catch (e) {
            console.error('[Notify] 获取 NotificationManager 失败', e)
            fallbackNotify(notifTitle, notifContent, '获取系统通知服务失败')
            return false
        }

        if (Build.VERSION.SDK_INT >= 33) {
            try {
                const Manifest = plus.android.importClass('android.Manifest')
                const PermissionCompat = plus.android.importClass('androidx.core.content.ContextCompat')
                const PackageManager = plus.android.importClass('android.content.pm.PackageManager')
                const hasPerm = PermissionCompat.checkSelfPermission(main, Manifest.permission.POST_NOTIFICATIONS)
                postPermOk = hasPerm === PackageManager.PERMISSION_GRANTED
            } catch (e) {
                console.warn('[Notify] 检查 POST_NOTIFICATIONS 权限失败（不阻塞显示）', e)
            }
        }

        // 如果权限看起来没开：先引导（不阻塞显示！），guide=true 强制引导
        // （项目 memory：Android 13+ 系统授权框最多弹 2 次，之后静默失败 → guide=true 时直接 showModal 引导）
        if (!globalEnabled || !postPermOk) {
            console.warn('[Notify] 权限检查未通过：全局=' + globalEnabled + ' POST=' + postPermOk + '，先引导用户开启')
            try {
                // 静态导入优先（vue3/vite APP 端唯一可靠路径），require 仅作老编译环境兜底
                var permMod = null
                if (_permLib && typeof _permLib.requestNotificationPerm === 'function') {
                    permMod = _permLib
                } else if (typeof require === 'function') {
                    try { permMod = require('./permissions.js') } catch (_) { permMod = null }
                }
                if (permMod && typeof permMod.requestNotificationPerm === 'function') {
                    // guide=true：收到推送场景，强制弹引导框（用户有动力开启）
                    permMod.requestNotificationPerm({ guide: true })
                }
            } catch (reqErr) {
                console.warn('[Notify] 请求权限失败', reqErr)
            }
            // 注意：**即使权限没开，我们也继续尝试显示**
            // 系统自己会判断，如果真不能显示会抛异常，再走 fallback
        }

        // ===== 2. 创建通知渠道（push_messages IMPORTANCE_HIGH） =====
        if (Build.VERSION.SDK_INT >= 26) {
            try {
                const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
                let channel = nm.getNotificationChannel(channelId)
                if (channel === null || channel === undefined) {
                    const importance = NotificationManager.IMPORTANCE_HIGH
                    const mChannel = new NotificationChannel(channelId, '消息推送', importance)
                    mChannel.enableLights(true)
                    mChannel.enableVibration(true)
                    mChannel.setShowBadge(true)
                    mChannel.setDescription('推送消息通知（锁屏可见）')
                    mChannel.setLockscreenVisibility(1)
                    try { mChannel.setBypassDnd(true) } catch (_) {}
                    try { mChannel.setLightColor(0xFF00FF00) } catch (_) {}
                    try { mChannel.setVibrationPattern([0, 200, 200, 200]) } catch (_) {}
                    nm.createNotificationChannel(mChannel)
                    console.log('[Notify] 消息推送通知渠道已创建')
                } else {
                    // 渠道存在：额外检查是否被用户禁用 → 只引导，不阻塞显示（也让系统做最终判断）
                    try {
                        const channelImportance = channel.getImportance()
                        if (channelImportance === 0) {
                            console.warn('[Notify] 通知渠道"消息推送"被用户禁用，引导设置')
                            try {
                                uni.showToast({ title: '通知渠道被关闭，请在设置中开启', icon: 'none', duration: 2500 })
                            } catch (_) {}
                            openNotificationSettings()
                        }
                    } catch (_) {}
                }
            } catch (e) {
                console.error('[Notify] 创建通知渠道失败', e)
            }
        }

        // ===== 3. 创建点击 PendingIntent =====
        let contentIntent = null
        try {
            const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
            launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
            const flags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
            contentIntent = PendingIntent.getActivity(main, notificationId, launchIntent, flags)
        } catch (e) {
            console.error('[Notify] 创建 PendingIntent 失败', e)
        }

        // ===== 4. 获取小图标 =====
        let smallIcon = 0
        try {
            smallIcon = getNotificationSmallIcon(main)
            if (!smallIcon || smallIcon <= 0) {
                console.warn('[Notify] 图标资源无效(' + smallIcon + ')，使用默认值')
                smallIcon = 17301651
            }
        } catch (e) {
            console.warn('[Notify] 获取小图标失败，使用默认', e)
            smallIcon = 17301651
        }

        // ===== 5. 构建 Notification（先 Compat，失败回退原生） =====
        let builder = null
        let useCompat = false
        try {
            const NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
            builder = new NotificationCompat.Builder(main, channelId)
            useCompat = true
        } catch (e) {
            try {
                const Notification = plus.android.importClass('android.app.Notification')
                builder = new Notification.Builder(main, channelId)
            } catch (e2) {
                console.error('[Notify] 两种 Builder 都创建失败', e, e2)
                fallbackNotify(notifTitle, notifContent, '无法创建通知构建器')
                return false
            }
        }

        // ===== 5.1 设置 Builder 属性（严格按老版本顺序） =====
        try {
            builder.setContentTitle(notifTitle)
            builder.setContentText(notifContent)
            builder.setSmallIcon(smallIcon)
            if (contentIntent !== null) builder.setContentIntent(contentIntent)
            builder.setAutoCancel(true)

            try { builder.setTicker('收到推送：' + notifTitle) } catch (_) {}
            try {
                const JavaSystem = plus.android.importClass('java.lang.System')
                builder.setWhen(JavaSystem.currentTimeMillis())
                try { builder.setShowWhen(true) } catch (_) {}
            } catch (_) {}

            // PRIORITY_MAX(2) + DEFAULT_ALL(-1) + VISIBILITY_PUBLIC(1) + Category.msg
            if (useCompat) {
                try { builder.setPriority(2) } catch (_) {}
                try { builder.setDefaults(-1) } catch (_) {}
                try { builder.setVisibility(1) } catch (_) {}
                try { builder.setCategory('msg') } catch (_) {}
            } else {
                if (Build.VERSION.SDK_INT >= 16) { try { builder.setPriority(2) } catch (_) {} }
                if (Build.VERSION.SDK_INT < 21) { try { builder.setDefaults(-1) } catch (_) {} }
                if (Build.VERSION.SDK_INT >= 21) {
                    try { builder.setCategory('msg') } catch (_) {}
                    try { builder.setVisibility(1) } catch (_) {}
                }
            }

            // FullScreenIntent（锁屏优先显示，Android 28+）
            if (Build.VERSION.SDK_INT >= 28 && contentIntent !== null) {
                try {
                    const fsFlags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
                    const fullScreenPendingIntent = PendingIntent.getActivity(
                        main, notificationId + 10000,
                        main.getPackageManager().getLaunchIntentForPackage(main.getPackageName()),
                        fsFlags
                    )
                    builder.setFullScreenIntent(fullScreenPendingIntent, true)
                } catch (_) {}
            }

            // 大文本（>50字符自动展开）
            if (notifContent && notifContent.length > 50) {
                try {
                    if (useCompat) {
                        const BigTextStyle = plus.android.importClass('androidx.core.app.NotificationCompat$BigTextStyle')
                        const bigText = new BigTextStyle()
                        bigText.bigText(notifContent)
                        bigText.setBigContentTitle(notifTitle)
                        builder.setStyle(bigText)
                    } else {
                        const BigTextStyle = plus.android.importClass('android.app.Notification$BigTextStyle')
                        const bigText = new BigTextStyle()
                        bigText.bigText(notifContent)
                        bigText.setBigContentTitle(notifTitle)
                        builder.setStyle(bigText)
                    }
                } catch (_) {}
            }
        } catch (e) {
            console.error('[Notify] 设置 Builder 属性失败', e)
        }

        // ===== 6. 构建并显示通知 =====
        let notification = null
        try {
            notification = builder.build()
        } catch (e) {
            console.error('[Notify] builder.build() 构建通知失败', e)
            fallbackNotify(notifTitle, notifContent, '构建通知失败')
            return false
        }

        try {
            nm.notify(notificationId, notification)
            console.log('[Notify] 推送消息通知已显示 id=' + notificationId + ' title=' + notifTitle.substring(0, 20))
            return true
        } catch (e) {
            console.error('[Notify] nm.notify() 显示通知失败', e)
            fallbackNotify(notifTitle, notifContent, '系统拒绝显示通知')
            return false
        }
    } catch (e) {
        console.error('[Notify] showNotification 顶层异常', e)
        try {
            uni.showToast({ title: '通知栏未显示（消息已保存到列表）', icon: 'none', duration: 2500 })
        } catch (_) {}
        fallbackNotify(notifTitle, notifContent, '顶层异常')
        return false
    }
}

// 4. 打开通知设置（移植老版本 openNotificationSettings）
export function openNotificationSettings() {
    if (typeof plus === 'undefined' || !plus.android) return
    try {
        const main = plus.android.runtimeMainActivity()
        const Intent = plus.android.importClass('android.content.Intent')
        const Uri = plus.android.importClass('android.net.Uri')
        const Build = plus.android.importClass('android.os.Build')

        const intent = new Intent()
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

        if (Build.VERSION.SDK_INT >= 26) {
            intent.setAction('android.settings.APP_NOTIFICATION_SETTINGS')
            intent.putExtra('android.provider.extra.APP_PACKAGE', main.getPackageName())
        } else if (Build.VERSION.SDK_INT >= 21) {
            intent.setAction('android.settings.APP_NOTIFICATION_SETTINGS')
            intent.putExtra('app_package', main.getPackageName())
            intent.putExtra('app_uid', main.getApplicationInfo().uid)
        } else {
            intent.setAction('android.settings.APPLICATION_DETAILS_SETTINGS')
            intent.setData(Uri.fromParts('package', main.getPackageName(), null))
        }
        main.startActivity(intent)
    } catch (e) {
        console.warn('[Notify] 打开通知设置失败', e)
    }
}

// 5. 请求通知权限（移植老版本 requestNotificationPermission，含 1.5s 复查引导）
export function requestNotificationPerm() {
    if (typeof plus === 'undefined' || !plus.android) return false
    try {
        const main = plus.android.runtimeMainActivity()
        const Context = plus.android.importClass('android.content.Context')
        const Build = plus.android.importClass('android.os.Build')
        const NotificationManager = plus.android.importClass('android.app.NotificationManager')

        const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
        if (nm.areNotificationsEnabled()) {
            console.log('[Notify] 通知权限已开启')
            return true
        }

        if (Build.VERSION.SDK_INT >= 33) {
            try {
                const Manifest = plus.android.importClass('android.Manifest')
                const PermissionCompat = plus.android.importClass('androidx.core.content.ContextCompat')
                const ActivityCompat = plus.android.importClass('androidx.core.app.ActivityCompat')
                const PackageManager = plus.android.importClass('android.content.pm.PackageManager')
                const hasPermission = PermissionCompat.checkSelfPermission(main, Manifest.permission.POST_NOTIFICATIONS)
                if (hasPermission !== PackageManager.PERMISSION_GRANTED) {
                    ActivityCompat.requestPermissions(main, [Manifest.permission.POST_NOTIFICATIONS], 1001)
                    console.log('[Notify] 请求通知权限（Android 13+）')
                    // 1.5s 复查：Android 13+ 最多弹 2 次授权框，被拒绝后不再弹 → 引导设置页
                    setTimeout(function() {
                        try {
                            const nm2 = main.getSystemService(Context.NOTIFICATION_SERVICE)
                            if (nm2.areNotificationsEnabled() === false) {
                                console.warn('[Notify] 请求后通知权限仍未开启，引导去设置页')
                                openNotificationSettings()
                            }
                        } catch (_) {}
                    }, 1500)
                    return false
                }
            } catch (e) {
                console.warn('[Notify] 请求 POST_NOTIFICATIONS 运行时权限失败', e)
            }
        }

        // 没有权限 → 弹框引导去设置页
        console.log('[Notify] 通知权限未开启，引导用户去设置')
        uni.showModal({
            title: '开启通知权限',
            content: '为了让您及时收到推送消息，请在设置中开启通知权限',
            confirmText: '去设置',
            cancelText: '稍后再说',
            success: (res) => {
                if (res.confirm) openNotificationSettings()
            }
        })
        return false
    } catch (e) {
        console.warn('[Notify] 请求通知权限失败', e)
        return false
    }
}

// 6. 检查通知是否已开启（全局开关 + 运行时权限双重检查）
export function isNotificationEnabled() {
    if (typeof plus === 'undefined' || !plus.android) return true
    try {
        const main = plus.android.runtimeMainActivity()
        const Context = plus.android.importClass('android.content.Context')
        const Build = plus.android.importClass('android.os.Build')
        const NotificationManager = plus.android.importClass('android.app.NotificationManager')
        const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
        const globalEnabled = nm.areNotificationsEnabled()
        if (!globalEnabled) return false
        if (Build.VERSION.SDK_INT >= 33) {
            try {
                const Manifest = plus.android.importClass('android.Manifest')
                const PermissionCompat = plus.android.importClass('androidx.core.content.ContextCompat')
                const PackageManager = plus.android.importClass('android.content.pm.PackageManager')
                const hasPerm = PermissionCompat.checkSelfPermission(main, Manifest.permission.POST_NOTIFICATIONS)
                if (hasPerm !== PackageManager.PERMISSION_GRANTED) return false
            } catch (_) {}
        }
        return true
    } catch (e) {
        console.warn('[Notify] 检查通知权限失败', e)
        return true
    }
}

// ============================================================
// 兼容导出：解决 home.vue / App.vue import 名字不匹配问题
// ============================================================

/**
 * 兼容别名：首页 import { notify } 用这个
 * @param {string} title
 * @param {string} content
 * @param {string|object} priorityOrOpts - 'high'|'default' 或 opts 对象
 */
export function notify(title, content, priorityOrOpts) {
    try {
        let opts = {}
        if (typeof priorityOrOpts === 'string') {
            opts.priority = priorityOrOpts
        } else if (priorityOrOpts && typeof priorityOrOpts === 'object') {
            opts = priorityOrOpts
        }
        return showNotification(title, content, opts)
    } catch (e) {
        console.error('[Notify] notify() 兼容调用失败', e)
        try { fallbackNotify(title, content, 'notify 兼容层异常') } catch (_) {}
        return false
    }
}

/**
 * 兼容别名：App.vue onLaunch 调用 ensureChannels() 预创建通知渠道
 * 幂等：渠道已存在时直接跳过
 */
export function ensureChannels() {
    try {
        createNotificationChannel()
        // 顺带也创建常驻通知渠道（push_service_foreground），避免首次显示常驻通知时渠道缺失
        if (typeof plus !== 'undefined' && plus.android) {
            try {
                const Build = plus.android.importClass('android.os.Build')
                if (Build.VERSION.SDK_INT >= 26) {
                    const main = plus.android.runtimeMainActivity()
                    const Context = plus.android.importClass('android.content.Context')
                    const NotificationManager = plus.android.importClass('android.app.NotificationManager')
                    const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
                    const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
                    const ch = nm.getNotificationChannel('push_service_foreground')
                    if (ch === null || ch === undefined) {
                        const importance = NotificationManager.IMPORTANCE_DEFAULT
                        const mChannel = new NotificationChannel(
                            'push_service_foreground', '推送服务', importance
                        )
                        mChannel.setShowBadge(false)
                        try { mChannel.setSound(null, null) } catch (_) {}
                        try { mChannel.enableVibration(false) } catch (_) {}
                        mChannel.setDescription('推送服务运行状态，保持后台连接')
                        try { mChannel.setLockscreenVisibility(1) } catch (_) {}
                        nm.createNotificationChannel(mChannel)
                        console.log('[Notify] 预创建常驻通知渠道 push_service_foreground')
                    }
                }
            } catch (_) {}
        }
        console.log('[Notify] ensureChannels 完成')
    } catch (e) {
        console.warn('[Notify] ensureChannels 失败', e)
    }
}
