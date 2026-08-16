import { PUSH_VIBRATE, PUSH_RINGTONE } from './storage.js'
import { checkNotificationPerm, checkPostNotificationsPerm, requestNotificationPerm } from './permissions.js'

const CHANNEL_NORMAL = 'push_normal'
const CHANNEL_SILENT = 'push_silent'

// 创建"默认铃声"渠道（老版增强：锁屏可见 + 灯光 + 振动模式；已存在则跳过，不覆盖用户手动改的设置）
function _createNormalChannel(nm, NotificationChannel, NotificationManager) {
    var exist = nm.getNotificationChannel(CHANNEL_NORMAL)
    if (exist !== null && exist !== undefined) return exist
    var ch = new NotificationChannel(CHANNEL_NORMAL, '推送提醒 · 默认铃声', NotificationManager.IMPORTANCE_DEFAULT)
    ch.enableVibration(true)
    ch.setShowBadge(true)
    ch.setDescription('推送消息通知（锁屏可见）')
    ch.setLockscreenVisibility(1)  // VISIBILITY_PUBLIC 锁屏完全可见
    try { ch.setLightColor(0xFF00FF00) } catch(e) {}
    try { ch.setVibrationPattern([0, 200, 200, 200]) } catch(e) {}
    nm.createNotificationChannel(ch)
    return ch
}

// 创建"静默"渠道（已存在则跳过）
function _createSilentChannel(nm, NotificationChannel, NotificationManager) {
    var exist = nm.getNotificationChannel(CHANNEL_SILENT)
    if (exist !== null && exist !== undefined) return exist
    var ch = new NotificationChannel(CHANNEL_SILENT, '推送提醒 · 静默', NotificationManager.IMPORTANCE_LOW)
    ch.enableVibration(false)
    ch.setSound(null, null)
    nm.createNotificationChannel(ch)
    return ch
}

// 提前创建通知渠道（App 启动时调用，确保设置页可见 APP）
export function ensureChannels() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        var Build = plus.android.importClass('android.os.Build')
        if (Build.VERSION.SDK_INT < 26) return
        var main = plus.android.runtimeMainActivity()
        var nm = main.getSystemService('notification')  // Context.NOTIFICATION_SERVICE 实际值，实例代理取不到静态常量
        var NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
        var NotificationManager = plus.android.importClass('android.app.NotificationManager')

        _createNormalChannel(nm, NotificationChannel, NotificationManager)
        _createSilentChannel(nm, NotificationChannel, NotificationManager)
    } catch(e) {
        console.warn('[Notify] ensureChannels fail', e)
    }
}

export function notify(title, content, priority) {
    if (!title && !content) return
    try {
        if (typeof plus !== 'undefined' && plus.android) {
            // ========== 1. 检查全局通知开关（老版双重校验） ==========
            if (checkNotificationPerm() === false) {
                console.warn('[Notify] 通知权限未开启（全局禁用）')
                uni.showToast({
                    title: '通知权限未开启',
                    icon: 'none',
                    duration: 2500
                })
                requestNotificationPerm({ guide: true })
                return false
            }
            // ========== 2. Android 13+ 检查 POST_NOTIFICATIONS 运行时权限 ==========
            if (checkPostNotificationsPerm() === false) {
                console.warn('[Notify] POST_NOTIFICATIONS 运行时权限未授予')
                uni.showToast({
                    title: '请授予通知权限（设置中开启）',
                    icon: 'none',
                    duration: 2500
                })
                requestNotificationPerm({ guide: true })
                return false
            }
            _nativeNotify(title, content, priority)
        }
    } catch(e) {
        console.warn('[Notify] native fail', e)
    }
}

function _nativeNotify(title, content, priority) {
    var Intent = plus.android.importClass('android.content.Intent')
    var PendingIntent = plus.android.importClass('android.app.PendingIntent')
    var NotificationManager = plus.android.importClass('android.app.NotificationManager')
    var Build = plus.android.importClass('android.os.Build')

    var main = plus.android.runtimeMainActivity()
    var nm = main.getSystemService('notification')  // Context.NOTIFICATION_SERVICE 实际值，实例代理取不到静态常量
    var notificationId = Math.floor(Math.random() * 100000) + 1

    var ringtone = 'default'
    try { ringtone = uni.getStorageSync(PUSH_RINGTONE) || 'default' } catch(e) {}
    var isSilent = ringtone === 'silent'
    var channelId = isSilent ? CHANNEL_SILENT : CHANNEL_NORMAL

    // ========== 1. 渠道（老版逻辑：已存在则检查是否被用户禁用） ==========
    if (Build.VERSION.SDK_INT >= 26) {
        var NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
        if (!isSilent) {
            _createNormalChannel(nm, NotificationChannel, NotificationManager)
        }
        _createSilentChannel(nm, NotificationChannel, NotificationManager)
        try {
            var ch = nm.getNotificationChannel(channelId)
            if (ch !== null && ch !== undefined && ch.getImportance() === 0) {
                console.warn('[Notify] 通知渠道被用户禁用')
                uni.showToast({ title: '通知渠道被关闭，请在设置中开启', icon: 'none', duration: 2500 })
                return false
            }
        } catch(e) {}
    }

    // ========== 2. 点击 Intent（老版：getLaunchIntentForPackage 打开应用） ==========
    var contentIntent = null
    try {
        var launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
        launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
        var flags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
        contentIntent = PendingIntent.getActivity(main, notificationId, launchIntent, flags)
    } catch(e) {
        console.warn('[Notify] 创建 PendingIntent 失败（不致命）', e)
    }

    // ========== 3. Builder（老版双路径：NotificationCompat 失败回退原生 Builder） ==========
    var builder = null
    var useCompat = false
    try {
        var NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
        builder = new NotificationCompat.Builder(main, channelId)
        useCompat = true
    } catch(e) {
        try {
            var Notification = plus.android.importClass('android.app.Notification')
            builder = new Notification.Builder(main, channelId)
        } catch(e2) {
            console.error('[Notify] 两种 Builder 都创建失败', e, e2)
            return false
        }
    }

    // ========== 4. 设置属性（老版完整参数） ==========
    try {
        builder.setContentTitle(title)
        builder.setContentText(content)
        builder.setSmallIcon(17301651)  // android.R.drawable.ic_dialog_info
        if (contentIntent !== null) {
            builder.setContentIntent(contentIntent)
        }
        builder.setAutoCancel(true)
        try { builder.setTicker('收到推送：' + title) } catch(_) {}
        try {
            var JavaSystem = plus.android.importClass('java.lang.System')
            builder.setWhen(JavaSystem.currentTimeMillis())
            try { builder.setShowWhen(true) } catch(_) {}
        } catch(_) {}
        try { builder.setPriority(priority === 'high' ? 2 : 0) } catch(_) {}
        try { builder.setDefaults(-1) } catch(_) {}
        try { builder.setVisibility(1) } catch(_) {}
        try { builder.setCategory('msg') } catch(_) {}
        // 全屏 Intent（锁屏优先显示，老版逻辑）
        if (Build.VERSION.SDK_INT >= 28 && contentIntent !== null) {
            try {
                var fsFlags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
                var fsIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
                var fullScreenPi = PendingIntent.getActivity(main, notificationId + 10000, fsIntent, fsFlags)
                builder.setFullScreenIntent(fullScreenPi, true)
            } catch(_) {}
        }
        // 大文本
        if (content && content.length > 50) {
            try {
                var BigTextStyle = plus.android.importClass(useCompat ? 'androidx.core.app.NotificationCompat$BigTextStyle' : 'android.app.Notification$BigTextStyle')
                var bigText = new BigTextStyle()
                bigText.bigText(content)
                bigText.setBigContentTitle(title)
                builder.setStyle(bigText)
            } catch(_) {}
        }
    } catch(e) {
        console.error('[Notify] 设置 Builder 属性失败', e)
    }

    // ========== 5. 显示 ==========
    nm.notify(notificationId, builder.build())

    var vibrateOn = true
    try { vibrateOn = uni.getStorageSync(PUSH_VIBRATE) !== false } catch(e) {}
    if (vibrateOn) {
        try { uni.vibrateShort({}) } catch(e) {}
    }
}
