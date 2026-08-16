// 后台保活模块（移植老版策略）
// 常驻通知（setOngoing）+ WakeLock + WifiLock 三件套：
// 常驻通知让用户可见、提升进程优先级，减少小米/华为等厂商后台查杀
let wakeLock = null
let wifiLock = null
let _lastStartTs = 0

const CHANNEL_ID = 'push_service_foreground'
const NOTIFICATION_ID = 1001

// 启动保活（含常驻通知）
export function startKeepAlive(connected) {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        // 节流：5 秒内重复调用直接跳过（通知已存在，避免反复弹出）
        const now = Date.now()
        if (_lastStartTs && (now - _lastStartTs) < 5000) return
        _lastStartTs = now

        _showForegroundNotification(connected === true)
        _acquireWakeLock()
        _acquireWifiLock()
        console.log('[KeepAlive] 前台服务保活已启动')
    } catch(e) {
        console.warn('[KeepAlive] start fail', e)
    }
}

// WS 状态变化时更新常驻通知文案（不重新弹提示）
export function updateKeepAliveStatus(connected) {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        _showForegroundNotification(connected === true)
    } catch(e) {}
}

export function stopKeepAlive() {
    try {
        if (typeof plus !== 'undefined' && plus.android) {
            const main = plus.android.runtimeMainActivity()
            const nm = main.getSystemService('notification')
            nm.cancel(NOTIFICATION_ID)
        }
        if (wakeLock) {
            if (wakeLock.isHeld()) wakeLock.release()
            wakeLock = null
        }
        if (wifiLock) {
            try { if (wifiLock.isHeld()) wifiLock.release() } catch(e) {}
            wifiLock = null
        }
    } catch(e) {}
}

// ========== 常驻通知 ==========
function _showForegroundNotification(connected) {
    const main = plus.android.runtimeMainActivity()
    const Build = plus.android.importClass('android.os.Build')
    const nm = main.getSystemService('notification')

    // 通知权限未开启时不显示（Android 13+ notify 会静默失败）
    try {
        if (!nm.areNotificationsEnabled()) {
            console.warn('[KeepAlive] 通知权限未开启，跳过常驻通知')
            return
        }
    } catch(e) {}

    // 创建渠道（静音、无振动、无角标，避免打扰）
    if (Build.VERSION.SDK_INT >= 26) {
        try {
            const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
            const NotificationManager = plus.android.importClass('android.app.NotificationManager')
            let channel = nm.getNotificationChannel(CHANNEL_ID)
            if (channel === null || channel === undefined) {
                channel = new NotificationChannel(CHANNEL_ID, '推送服务', NotificationManager.IMPORTANCE_DEFAULT)
                channel.setShowBadge(false)
                channel.setSound(null, null)
                channel.enableVibration(false)
                channel.setDescription('推送服务运行状态，保持后台连接')
                channel.setLockscreenVisibility(1)  // VISIBILITY_PUBLIC
                nm.createNotificationChannel(channel)
            }
        } catch(e) { console.warn('[KeepAlive] 创建渠道失败', e) }
    }

    // 点击 Intent：打开 APP
    let contentIntent = null
    try {
        const Intent = plus.android.importClass('android.content.Intent')
        const PendingIntent = plus.android.importClass('android.app.PendingIntent')
        const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
        launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
        const flags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
        contentIntent = PendingIntent.getActivity(main, 0, launchIntent, flags)
    } catch(e) {
        console.warn('[KeepAlive] 创建 PendingIntent 失败', e)
    }

    // 构建通知（Compat 失败回退原生 Builder）
    let builder = null
    try {
        const NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
        builder = new NotificationCompat.Builder(main, CHANNEL_ID)
    } catch(e) {
        try {
            const Notification = plus.android.importClass('android.app.Notification')
            builder = new Notification.Builder(main, CHANNEL_ID)
        } catch(e2) {
            console.warn('[KeepAlive] Builder 创建失败', e, e2)
            return
        }
    }

    try {
        const statusText = connected ? '已连接' : '正在连接...'
        builder.setContentTitle('推送服务 · ' + statusText)
        builder.setContentText('保持后台运行，实时接收推送消息')
        builder.setSmallIcon(_appIcon(main))
        if (contentIntent) builder.setContentIntent(contentIntent)
        builder.setOngoing(true)          // 常驻不可滑动删除
        builder.setAutoCancel(false)
        builder.setPriority(0)            // PRIORITY_DEFAULT
        builder.setVisibility(1)          // VISIBILITY_PUBLIC
        builder.setCategory('service')
        builder.setOnlyAlertOnce(true)    // 已存在时仅更新内容，不重新弹出
    } catch(e) { console.warn('[KeepAlive] 设置通知属性失败', e) }

    try {
        nm.notify(NOTIFICATION_ID, builder.build())
        console.log('[KeepAlive] 常驻通知已显示, id=' + NOTIFICATION_ID)
    } catch(e) {
        console.warn('[KeepAlive] 显示通知失败', e)
    }
}

// 取 APP 图标作为小图标，失败用系统图标
function _appIcon(main) {
    try {
        const appInfo = main.getApplicationInfo()
        const icon = appInfo.icon
        if (icon && icon > 0) return icon
    } catch(e) {}
    return 17301651  // android.R.drawable.ic_dialog_info
}

// ========== WakeLock ==========
function _acquireWakeLock() {
    try {
        const main = plus.android.runtimeMainActivity()
        const pm = main.getSystemService('power')  // Context.POWER_SERVICE 实际值
        const PowerManager = plus.android.importClass('android.os.PowerManager')
        if (!wakeLock) {
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:KeepAlive')
            wakeLock.setReferenceCounted(false)
        }
        if (!wakeLock.isHeld()) wakeLock.acquire(24 * 60 * 60 * 1000)
        console.log('[KeepAlive] WakeLock acquired')
    } catch(e) {
        console.warn('[KeepAlive] WakeLock fail', e)
    }
}

// ========== WifiLock（防止 WiFi 休眠断连） ==========
function _acquireWifiLock() {
    try {
        const main = plus.android.runtimeMainActivity()
        const appCtx = main.getApplicationContext()
        const wm = appCtx.getSystemService('wifi')  // Context.WIFI_SERVICE 实际值
        const WifiManager = plus.android.importClass('android.net.wifi.WifiManager')
        if (!wifiLock) {
            wifiLock = wm.createWifiLock(WifiManager.WIFI_MODE_FULL_HIGH_PERF, 'PushApp:WifiLock')
            wifiLock.setReferenceCounted(false)
        }
        if (!wifiLock.isHeld()) wifiLock.acquire()
        console.log('[KeepAlive] WifiLock acquired')
    } catch(e) {
        console.warn('[KeepAlive] WifiLock fail', e)
    }
}
