import { PUSH_VIBRATE, PUSH_RINGTONE } from './storage.js'
import { checkNotificationPerm, checkPostNotificationsPerm, requestNotificationPerm } from './permissions.js'

// ============ 通知渠道 ============
// push_messages: 高优先级消息渠道（锁屏可见 + 勿扰模式也能弹）
// push_service_foreground: 常驻保活通知渠道（keepalive.js 创建）
const CHANNEL_MSG = 'push_messages'
const CHANNEL_SILENT = 'push_silent'

// 提前创建所有通知渠道（App 启动时调用，确保设置页可见 APP）
export function ensureChannels() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        const Build = plus.android.importClass('android.os.Build')
        if (Build.VERSION.SDK_INT < 26) return
        const main = plus.android.runtimeMainActivity()
        const nm = main.getSystemService('notification')
        const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
        const NotificationManager = plus.android.importClass('android.app.NotificationManager')

        _ensureChannelMsg(nm, NotificationChannel, NotificationManager)
        _ensureChannelSilent(nm, NotificationChannel, NotificationManager)
    } catch(e) { console.warn('[Notify] ensureChannels fail', e) }
}

// ============ 核心：显示推送通知 ============
export function notify(title, content, priority) {
    if (!title && !content) return
    try {
        if (typeof plus === 'undefined' || !plus.android) {
            if (uni.showNotification) { try { uni.showNotification({ title: title || '新消息', content: content || '' }) } catch(_) {} }
            return true
        }

        // 1. 权限检查（双重校验，老版逻辑）
        if (checkNotificationPerm() === false) {
            console.warn('[Notify] 全局通知开关关闭')
            uni.showToast({ title: '通知权限未开启', icon: 'none', duration: 2500 })
            requestNotificationPerm({ guide: true })
            _fallbackNotify(title, content, '通知权限未开启')
            return false
        }
        if (checkPostNotificationsPerm() === false) {
            console.warn('[Notify] POST_NOTIFICATIONS 运行时权限未授予')
            uni.showToast({ title: '请授予通知权限', icon: 'none', duration: 2500 })
            requestNotificationPerm({ guide: true })
            _fallbackNotify(title, content, '运行时权限未授予')
            return false
        }

        _nativeNotify(title, content, priority)
    } catch(e) {
        console.error('[Notify] notify 顶层异常', e)
        _fallbackNotify(title, content, '顶层异常')
    }
}

// ============ 原生通知实现（移植老版完整逻辑） ============
function _nativeNotify(title, content, priority) {
    const notifTitle = title || '新消息'
    const notifContent = content || ''
    const main = plus.android.runtimeMainActivity()
    const Build = plus.android.importClass('android.os.Build')
    const Intent = plus.android.importClass('android.content.Intent')
    const PendingIntent = plus.android.importClass('android.app.PendingIntent')
    const NotificationManager = plus.android.importClass('android.app.NotificationManager')
    const nm = main.getSystemService('notification')
    const notificationId = Math.floor(Math.random() * 100000) + 1

    // ========== 2. 渠道 ==========
    let channelId = CHANNEL_MSG  // 默认走高优先级渠道
    let isSilent = false
    try {
        const ringtone = uni.getStorageSync(PUSH_RINGTONE) || 'default'
        isSilent = ringtone === 'silent'
        channelId = isSilent ? CHANNEL_SILENT : CHANNEL_MSG
    } catch(e) {}

    if (Build.VERSION.SDK_INT >= 26) {
        _ensureChannelMsg(nm,
            plus.android.importClass('android.app.NotificationChannel'),
            NotificationManager)
        if (isSilent) _ensureChannelSilent(nm,
            plus.android.importClass('android.app.NotificationChannel'),
            NotificationManager)
        // 渠道被用户禁用检测（IMPORTANCE_NONE = 0）
        try {
            const ch = nm.getNotificationChannel(channelId)
            if (ch !== null && ch !== undefined && ch.getImportance() === 0) {
                console.warn('[Notify] 通知渠道"' + channelId + '"被用户禁用')
                uni.showToast({ title: '通知渠道被关闭，请在设置中开启', icon: 'none', duration: 2500 })
                _fallbackNotify(title, content, '渠道被禁用')
                return false
            }
        } catch(e) {}
    }

    // ========== 3. 点击 Intent ==========
    let contentIntent = null
    try {
        const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
        launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
        const flags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
        contentIntent = PendingIntent.getActivity(main, notificationId, launchIntent, flags)
    } catch(e) { console.warn('[Notify] 创建 PendingIntent 失败（不致命）', e) }

    // ========== 4. Builder（Compat → 原生 回退） ==========
    let builder = null
    let useCompat = false
    try {
        const NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
        builder = new NotificationCompat.Builder(main, channelId)
        useCompat = true
    } catch(e) {
        try {
            const Notification = plus.android.importClass('android.app.Notification')
            builder = new Notification.Builder(main, channelId)
        } catch(e2) {
            console.error('[Notify] 两种 Builder 都创建失败', e, e2)
            _fallbackNotify(title, content, '无法创建 Builder')
            return false
        }
    }

    // ========== 4.5 获取小图标（移植老版 getNotificationSmallIcon） ==========
    let smallIcon = 17301651
    try {
        const appInfo = main.getApplicationInfo()
        const icon = appInfo.icon
        if (icon && icon > 0) smallIcon = icon
    } catch(e) { console.warn('[Notify] 获取 APP 图标失败，使用默认', e) }

    // ========== 5. 设置属性（老版完整参数 + PRIORITY_MAX） ==========
    try {
        builder.setContentTitle(notifTitle)
        builder.setContentText(notifContent)
        builder.setSmallIcon(smallIcon)
        if (contentIntent) builder.setContentIntent(contentIntent)
        builder.setAutoCancel(true)
        try { builder.setTicker('收到推送：' + notifTitle) } catch(_) {}
        try {
            const JavaSystem = plus.android.importClass('java.lang.System')
            builder.setWhen(JavaSystem.currentTimeMillis())
            try { builder.setShowWhen(true) } catch(_) {}
        } catch(_) {}

        // 移植老版：硬编码 PRIORITY_MAX（2），不再依赖 priority 参数
        // 高优先级通知在锁屏/厂商 ROM 上更可能弹出显示
        if (useCompat) {
            try { builder.setPriority(2) } catch(_) {}
            try { builder.setDefaults(-1) } catch(_) {}
            try { builder.setVisibility(1) } catch(_) {}  // VISIBILITY_PUBLIC 锁屏完全可见
            try { builder.setCategory('msg') } catch(_) {}
        } else {
            if (Build.VERSION.SDK_INT >= 16) { try { builder.setPriority(2) } catch(_) {} }
            if (Build.VERSION.SDK_INT < 21) { try { builder.setDefaults(-1) } catch(_) {} }
            if (Build.VERSION.SDK_INT >= 21) {
                try { builder.setCategory('msg') } catch(_) {}
                try { builder.setVisibility(1) } catch(_) {}
            }
        }

        // 全屏 Intent（锁屏优先显示，老版逻辑）
        if (Build.VERSION.SDK_INT >= 28 && contentIntent) {
            try {
                const fsFlags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
                const fullScreenPi = PendingIntent.getActivity(main, notificationId + 10000,
                    main.getPackageManager().getLaunchIntentForPackage(main.getPackageName()), fsFlags)
                builder.setFullScreenIntent(fullScreenPi, true)
            } catch(_) {}
        }

        // 大文本
        if (notifContent && notifContent.length > 50) {
            try {
                const BigTextStyle = plus.android.importClass(useCompat
                    ? 'androidx.core.app.NotificationCompat$BigTextStyle'
                    : 'android.app.Notification$BigTextStyle')
                const bigText = new BigTextStyle()
                bigText.bigText(notifContent)
                bigText.setBigContentTitle(notifTitle)
                builder.setStyle(bigText)
            } catch(_) {}
        }
    } catch(e) { console.error('[Notify] 设置 Builder 属性失败', e) }

    // ========== 6. 构建并显示 ==========
    let notification = null
    try { notification = builder.build() } catch(e) {
        console.error('[Notify] builder.build() 失败', e)
        _fallbackNotify(title, content, '构建通知失败')
        return false
    }

    try {
        nm.notify(notificationId, notification)
        console.log('[Notify] 推送通知已显示 id=' + notificationId + ' title=' + notifTitle.substring(0, 20))
    } catch(e) {
        console.error('[Notify] nm.notify() 失败', e)
        _fallbackNotify(title, content, '系统拒绝显示通知')
        return false
    }

    // 震动（用户未关闭时）
    let vibrateOn = true
    try { vibrateOn = uni.getStorageSync(PUSH_VIBRATE) !== false } catch(e) {}
    if (vibrateOn) {
        try { uni.vibrateShort({}) } catch(e) {}
    }
    return true
}

// ============ 渠道创建（老版增强） ============
function _ensureChannelMsg(nm, NotificationChannel, NotificationManager) {
    var exist = nm.getNotificationChannel(CHANNEL_MSG)
    if (exist !== null && exist !== undefined) return exist
    var ch = new NotificationChannel(CHANNEL_MSG, '消息推送', NotificationManager.IMPORTANCE_HIGH)  // 老版：IMPORTANCE_HIGH！
    ch.enableLights(true)           // 老版有，新版没有
    ch.enableVibration(true)
    ch.setShowBadge(true)
    ch.setDescription('推送消息通知（锁屏可见 + 勿扰模式也可提醒）')
    ch.setLockscreenVisibility(1)   // VISIBILITY_PUBLIC 锁屏完全可见
    try { ch.setBypassDnd(true) } catch(_) {}  // 老版有，新版没有：勿扰模式下仍能弹出
    try { ch.setLightColor(0xFF00FF00) } catch(_) {}
    try { ch.setVibrationPattern([0, 200, 200, 200]) } catch(_) {}
    nm.createNotificationChannel(ch)
    console.log('[Notify] 消息推送渠道已创建 (IMPORTANCE_HIGH + bypassDnd)')
    return ch
}

function _ensureChannelSilent(nm, NotificationChannel, NotificationManager) {
    var exist = nm.getNotificationChannel(CHANNEL_SILENT)
    if (exist !== null && exist !== undefined) return exist
    var ch = new NotificationChannel(CHANNEL_SILENT, '推送提醒 · 静默', NotificationManager.IMPORTANCE_LOW)
    ch.enableVibration(false)
    ch.setSound(null, null)
    ch.setShowBadge(true)
    nm.createNotificationChannel(ch)
    return ch
}

// ============ 通知失败兜底（老版有，新版完全缺失） ============
// 保证用户至少感知到消息（震动 + Toast + 轻微提示音）
function _fallbackNotify(title, content, reason) {
    try {
        console.warn('[Notify] fallback: ' + (reason || '未知'))
        uni.showToast({
            title: (title || '新消息') + (reason ? '（' + reason + '）' : ''),
            icon: 'none',
            duration: 2500
        })
        try { uni.vibrateShort({ type: 'heavy' }) } catch(_) {}
    } catch(e) {}
}
