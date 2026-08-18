// ============================================================
// 后台保活模块（完全移植老版本实现，不做任何简化）
// 包括：
//   1. 常驻通知（前台服务 - push_service_foreground 渠道）
//   2. WakeLock + WifiLock
//   3. AlarmManager 15 秒心跳 + 临时 WakeLock（10 秒）
//   4. SCREEN_ON / SCREEN_OFF 广播接收器（亮屏后 ping+5s 超时重连）
//   5. 电池优化白名单引导（24h 节流）
//   6. 厂商自启动引导（小米/华为/OPPO/vivo/三星，各品牌只提示一次）
//   7. SCHEDULE_EXACT_ALARM 权限引导（Android 12+）
// ============================================================

let _lastStartForegroundTs = 0
let _wakeLock = null
let _wifiLock = null
let _mediaReceiver = null
let _mediaReceiverRegistered = false
let _screenReceiver = null
let _screenReceiverRegistered = false
let _alarmPendingIntent = null
let _alarmReceiver = null
let _alarmReceiverRegistered = false
let _tmpAlarmWakeLock = null

const BATTERY_CHECK_INTERVAL = 24 * 60 * 60 * 1000

// ============================================================
// 导出：获取 APP 自定义图标（移植老版 getNotificationSmallIcon）
// ============================================================
export function getNotificationSmallIcon(main) {
    try {
        const appInfo = main.getApplicationInfo()
        const icon = appInfo.icon
        if (icon && icon > 0) return icon
    } catch (e) {
        console.warn('[KeepAlive] 获取 APP 图标失败', e)
    }
    return 17301651  // android.R.drawable.ic_dialog_info
}

// ============================================================
// 启动前台服务保活（移植老版 startForegroundService）
// @param {boolean} connected - WS 是否已连接，用于显示通知文案
// ============================================================
export function startKeepAlive(connected) {
    if (typeof plus === 'undefined' || !plus.android) return

    // 5 秒节流
    const now = Date.now()
    if (_lastStartForegroundTs && (now - _lastStartForegroundTs) < 5000) {
        console.log('[KeepAlive] 节流跳过（5秒内已调用）')
        return
    }
    _lastStartForegroundTs = now

    let main, Context, Build, NotificationManager, Intent, PendingIntent
    try {
        main = plus.android.runtimeMainActivity()
        Context = plus.android.importClass('android.content.Context')
        Build = plus.android.importClass('android.os.Build')
        NotificationManager = plus.android.importClass('android.app.NotificationManager')
        Intent = plus.android.importClass('android.content.Intent')
        PendingIntent = plus.android.importClass('android.app.PendingIntent')
    } catch (e) {
        console.error('[KeepAlive] 导入基础类失败', e)
        return
    }

    const channelId = 'push_service_foreground'
    const notificationId = 1001

    // ===== 1. 检查通知权限 =====
    try {
        const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
        if (nm.areNotificationsEnabled() === false) {
            console.warn('[KeepAlive] 通知权限未开启，无法显示常驻通知，但仍执行 WakeLock 保活')
        }
    } catch (e) {
        console.warn('[KeepAlive] 检查通知权限失败', e)
    }

    // ===== 2. 创建常驻通知渠道（push_service_foreground） =====
    try {
        if (Build.VERSION.SDK_INT >= 26) {
            const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
            const channel = nm.getNotificationChannel(channelId)
            if (channel === null || channel === undefined) {
                const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
                const importance = NotificationManager.IMPORTANCE_DEFAULT
                const mChannel = new NotificationChannel(channelId, '推送服务', importance)
                mChannel.setShowBadge(false)
                mChannel.setSound(null, null)
                mChannel.enableVibration(false)
                mChannel.setDescription('推送服务运行状态，保持后台连接')
                mChannel.setLockscreenVisibility(1)  // VISIBILITY_PUBLIC
                nm.createNotificationChannel(mChannel)
                console.log('[KeepAlive] 推送服务常驻通知渠道已创建')
            }
        }
    } catch (e) {
        console.error('[KeepAlive] 创建常驻通知渠道失败', e)
    }

    // ===== 3. 构建 PendingIntent（点击通知打开 APP） =====
    let contentIntent = null
    try {
        const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
        launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
        const piFlags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
        contentIntent = PendingIntent.getActivity(main, 0, launchIntent, piFlags)
    } catch (e) {
        console.error('[KeepAlive] 创建 PendingIntent 失败', e)
    }

    // ===== 4. 构建常驻通知 =====
    let notification = null
    try {
        const smallIcon = getNotificationSmallIcon(main)
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
                console.error('[KeepAlive] 两种 Builder 都失败', e, e2)
            }
        }

        if (builder) {
            const statusText = connected === true ? '已连接' : '正在连接...'
            builder.setContentTitle('推送服务 · ' + statusText)
            builder.setContentText('保持后台运行，实时接收推送消息')
            builder.setSmallIcon(smallIcon)
            if (contentIntent) builder.setContentIntent(contentIntent)
            builder.setOngoing(true)
            builder.setAutoCancel(false)

            try {
                if (useCompat) {
                    builder.setPriority(0)
                    builder.setVisibility(1)
                    builder.setCategory('service')
                    try { builder.setOnlyAlertOnce(true) } catch (_) {}
                } else {
                    if (Build.VERSION.SDK_INT >= 16) {
                        builder.setPriority(0)
                        try { builder.setOnlyAlertOnce(true) } catch (_) {}
                    }
                }
            } catch (_) {}

            notification = builder.build()
        }
    } catch (e) {
        console.error('[KeepAlive] 构建常驻通知失败', e)
    }

    // ===== 5. 显示常驻通知 =====
    if (notification) {
        try {
            const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
            nm.notify(notificationId, notification)
            console.log('[KeepAlive] 常驻通知已显示，id=' + notificationId)
        } catch (e) {
            console.error('[KeepAlive] 显示常驻通知失败', e)
        }
    }

    // ===== 6. WakeLock =====
    try {
        const PowerManager = plus.android.importClass('android.os.PowerManager')
        const pm = main.getSystemService(Context.POWER_SERVICE)
        if (!_wakeLock) {
            _wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:WakeLock')
            _wakeLock.setReferenceCounted(false)
        }
        if (!_wakeLock.isHeld()) {
            _wakeLock.acquire()
        }
    } catch (e) {
        console.warn('[KeepAlive] 获取 WakeLock 失败', e)
    }

    // ===== 7. WifiLock =====
    try {
        const WifiManager = plus.android.importClass('android.net.wifi.WifiManager')
        const wm = main.getApplicationContext().getSystemService(Context.WIFI_SERVICE)
        if (!_wifiLock) {
            const WifiLockCls = plus.android.importClass('android.net.wifi.WifiManager$WifiLock')
            _wifiLock = wm.createWifiLock(3, 'PushApp:WifiLock')  // WIFI_MODE_FULL_HIGH_PERF = 3
            try { _wifiLock.setReferenceCounted(false) } catch (_) {}
        }
        if (!_wifiLock.isHeld()) {
            _wifiLock.acquire()
        }
    } catch (e) {
        console.warn('[KeepAlive] 获取 WifiLock 失败', e)
    }

    // ===== 8. AlarmManager 心跳 =====
    setupAlarmHeartbeat(main, Context, Build)

    // ===== 9. SCREEN_ON / SCREEN_OFF 广播接收器 =====
    _registerScreenReceiver(main, Context, Build)

    console.log('[KeepAlive] 前台服务保活已启动')
}

// ============================================================
// 更新常驻通知状态（WS 连接状态变化时调用）
// ============================================================
export function updateKeepAliveStatus(connected) {
    // 常驻通知直接重新创建一次（5秒节流在 startKeepAlive 里做了）
    startKeepAlive(connected)
}

// ============================================================
// 停止前台服务保活
// ============================================================
export function stopKeepAlive() {
    _lastStartForegroundTs = 0
    if (typeof plus === 'undefined' || !plus.android) return
    try {
        const main = plus.android.runtimeMainActivity()
        const Context = plus.android.importClass('android.content.Context')
        const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
        try { nm.cancel(1001) } catch (e) {}

        if (_screenReceiver && _screenReceiverRegistered) {
            try {
                main.unregisterReceiver(_screenReceiver)
                _screenReceiver = null
                _screenReceiverRegistered = false
            } catch (e) {}
        }
        stopAlarmHeartbeat()
        if (_wakeLock) {
            try { if (_wakeLock.isHeld()) _wakeLock.release() } catch (e) {}
            _wakeLock = null
        }
        if (_wifiLock) {
            try { if (_wifiLock.isHeld()) _wifiLock.release() } catch (e) {}
            _wifiLock = null
        }
        console.log('[KeepAlive] 前台服务保活已停止')
    } catch (e) {
        console.error('[KeepAlive] 停止前台服务失败', e)
    }
}

// ============================================================
// AlarmManager 15 秒心跳（移植老版 setupAlarmHeartbeat）
// ============================================================
let _alarmHandlerCallback = null

export function setAlarmHandler(cb) {
    _alarmHandlerCallback = cb
}

export function setupAlarmHeartbeat(main, Context, Build) {
    if (typeof plus === 'undefined' || !plus.android) return
    if (!main) {
        main = plus.android.runtimeMainActivity()
        Context = plus.android.importClass('android.content.Context')
        Build = plus.android.importClass('android.os.Build')
    }
    try {
        const AlarmManager = plus.android.importClass('android.app.AlarmManager')
        const PendingIntent = plus.android.importClass('android.app.PendingIntent')
        const Intent = plus.android.importClass('android.content.Intent')
        const System = plus.android.importClass('java.lang.System')

        const alarmAction = 'com.push.app.ALARM_HEARTBEAT'
        const interval = 15 * 1000
        const triggerAt = System.currentTimeMillis() + interval

        const intent = new Intent(alarmAction)
        intent.setPackage(main.getPackageName())

        const flags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000

        if (_alarmPendingIntent) {
            try { _alarmPendingIntent.cancel() } catch (e) {}
        }
        _alarmPendingIntent = PendingIntent.getBroadcast(main, 200, intent, flags)

        const am = main.getSystemService(Context.ALARM_SERVICE)
        if (Build.VERSION.SDK_INT >= 23) {
            try {
                am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, _alarmPendingIntent)
            } catch (e) {
                console.warn('[KeepAlive] setExactAndAllowWhileIdle 失败，回退', e)
                try { am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, _alarmPendingIntent) } catch (e2) {}
            }
        } else {
            try { am.setExact(AlarmManager.RTC_WAKEUP, triggerAt, _alarmPendingIntent) } catch (e) {}
        }

        if (!_alarmReceiverRegistered) {
            const BroadcastReceiver = plus.android.importClass('android.content.BroadcastReceiver')
            const selfContext = Context
            _alarmReceiver = new BroadcastReceiver({
                onReceive: function(context, intent) {
                    const action = intent.getAction()
                    if (action === alarmAction) {
                        // 临时 WakeLock 10 秒，防止 CPU 在心跳完成前再次休眠
                        let tmpWakeLock = null
                        try {
                            const PowerManager = plus.android.importClass('android.os.PowerManager')
                            const pm = context.getSystemService(selfContext.POWER_SERVICE)
                            tmpWakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:AlarmWake')
                            tmpWakeLock.setReferenceCounted(false)
                            tmpWakeLock.acquire(10 * 1000)
                            _tmpAlarmWakeLock = tmpWakeLock
                        } catch (e) {
                            console.warn('[Alarm] 获取临时 WakeLock 失败', e)
                        }
                        if (_alarmHandlerCallback) {
                            try { _alarmHandlerCallback() } catch (e) {}
                        }
                        // 重新设置下一次
                        setupAlarmHeartbeat(main, Context, Build)
                    }
                }
            })
            const IntentFilter = plus.android.importClass('android.content.IntentFilter')
            const intentFilter = new IntentFilter()
            intentFilter.addAction(alarmAction)
            main.registerReceiver(_alarmReceiver, intentFilter)
            _alarmReceiverRegistered = true
            console.log('[KeepAlive] AlarmManager 心跳广播接收器已注册')
        }
    } catch (e) {
        console.error('[KeepAlive] 设置 AlarmManager 心跳失败', e)
    }
}

export function stopAlarmHeartbeat() {
    if (typeof plus === 'undefined' || !plus.android) return
    try {
        const main = plus.android.runtimeMainActivity()
        const Context = plus.android.importClass('android.content.Context')
        if (_alarmPendingIntent) {
            try {
                const AlarmManager = plus.android.importClass('android.app.AlarmManager')
                const am = main.getSystemService(Context.ALARM_SERVICE)
                am.cancel(_alarmPendingIntent)
                _alarmPendingIntent.cancel()
                _alarmPendingIntent = null
            } catch (e) {}
        }
        if (_alarmReceiver && _alarmReceiverRegistered) {
            try {
                main.unregisterReceiver(_alarmReceiver)
                _alarmReceiver = null
                _alarmReceiverRegistered = false
            } catch (e) {}
        }
    } catch (e) {}
}

// ============================================================
// SCREEN_ON / SCREEN_OFF 广播接收器（移植老版逻辑）
// ============================================================
let _screenPingTimer = null
let _screenPongOk = false
let _screenPingCallback = null

export function setScreenPingCallback(sendPingCb, isConnectedCb, cleanupAndReconnectCb) {
    _screenPingCallback = { sendPing: sendPingCb, isConnected: isConnectedCb, reconnect: cleanupAndReconnectCb }
}

export function _registerScreenReceiver(main, Context, Build) {
    if (typeof plus === 'undefined' || !plus.android) return
    if (!main) {
        main = plus.android.runtimeMainActivity()
        Context = plus.android.importClass('android.content.Context')
        Build = plus.android.importClass('android.os.Build')
    }
    if (_screenReceiverRegistered) return
    try {
        const BroadcastReceiver = plus.android.importClass('android.content.BroadcastReceiver')
        _screenReceiver = new BroadcastReceiver({
            onReceive: function(context, intent) {
                const action = intent.getAction()
                if (action === 'android.intent.action.SCREEN_ON') {
                    console.log('[KeepAlive] SCREEN_ON: 验证 WS 连接')
                    const cb = _screenPingCallback
                    const alarmCb = _alarmHandlerCallback
                    if (cb && cb.reconnect && cb.isConnected) {
                        if (!cb.isConnected()) {
                            cb.reconnect()
                        } else if (cb.sendPing) {
                            _screenPingTimer && clearTimeout(_screenPingTimer)
                            _screenPongOk = false
                            _screenPingTimer = setTimeout(() => {
                                if (!_screenPongOk && cb.reconnect) {
                                    cb.reconnect()
                                }
                                _screenPingTimer = null
                            }, 5000)
                            try {
                                cb.sendPing()
                            } catch (e) {
                                _screenPingTimer && clearTimeout(_screenPingTimer)
                                _screenPingTimer = null
                                cb.reconnect()
                            }
                        }
                    } else if (alarmCb) {
                        // 兼容：没设置 WS 回调时直接走 AlarmHandler
                        try { alarmCb() } catch (e) {}
                    }
                }
            }
        })
        const IntentFilter = plus.android.importClass('android.content.IntentFilter')
        const filter = new IntentFilter()
        filter.addAction('android.intent.action.SCREEN_ON')
        filter.addAction('android.intent.action.SCREEN_OFF')
        try {
            main.registerReceiver(_screenReceiver, filter)
        } catch (e) {
            try { main.registerReceiver(_screenReceiver, filter, 4) } catch (e2) {}
        }
        _screenReceiverRegistered = true
        console.log('[KeepAlive] 屏幕广播接收器已注册')
    } catch (e) {
        console.warn('[KeepAlive] 注册屏幕接收器失败', e)
    }
}

// ============================================================
// 电池优化白名单引导（24h 节流，移植老版 + 节流）
// ============================================================
export function checkBatteryOptimization() {
    if (typeof plus === 'undefined' || !plus.android) return true
    try {
        const main = plus.android.runtimeMainActivity()
        const Context = plus.android.importClass('android.content.Context')
        const Build = plus.android.importClass('android.os.Build')
        const PowerManager = plus.android.importClass('android.os.PowerManager')

        if (Build.VERSION.SDK_INT < 23) return true

        try {
            const last = parseInt(uni.getStorageSync('push_battery_checked') || '0')
            if (last && (Date.now() - last) < BATTERY_CHECK_INTERVAL) {
                console.log('[KeepAlive] 电池优化引导 24h 内已提示，跳过')
                return true
            }
        } catch (e) {}

        const pm = main.getSystemService(Context.POWER_SERVICE)
        const isIgnoring = pm.isIgnoringBatteryOptimizations(main.getPackageName())
        if (isIgnoring) {
            console.log('[KeepAlive] 已在电池优化白名单中')
            return true
        }

        try { uni.setStorageSync('push_battery_checked', String(Date.now())) } catch (e) {}

        uni.showModal({
            title: '关闭电池优化',
            content: '为了保持后台推送连接，需要将本应用加入电池优化白名单。请在弹出的设置中选择"不优化"。',
            confirmText: '去设置',
            cancelText: '稍后',
            success: (res) => {
                if (res.confirm) {
                    try {
                        const Intent = plus.android.importClass('android.content.Intent')
                        const intent = new Intent('android.settings.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS')
                        const Uri = plus.android.importClass('android.net.Uri')
                        intent.setData(Uri.fromParts('package', main.getPackageName(), null))
                        main.startActivity(intent)
                    } catch (e) { console.warn('[KeepAlive] 打开电池优化设置失败', e) }
                }
            }
        })
        return false
    } catch (e) { return true }
}

// ============================================================
// 厂商自启动引导（全品牌 + 各品牌只提示一次）
// ============================================================
const BRAND_GUIDES = {
    xiaomi: {
        msg: '小米手机需要开启以下权限才能后台保活：\n\n1. 自启动权限\n2. 后台弹出通知\n3. 锁屏显示通知\n\n请在设置中找到本应用并开启以上权限。',
        component: ['com.miui.securitycenter', 'com.miui.permcenter.autostart.AutoStartManagementActivity']
    },
    huawei: {
        msg: '华为手机需要开启以下权限才能后台保活：\n\n1. 自启动（手机管家→启动管理→手动管理→本应用→允许自启动/允许后台活动）\n2. 忽略电池优化\n\n请点击"去设置"按指引开启。',
        component: ['com.huawei.systemmanager', 'com.huawei.systemmanager.optimize.process.ProtectActivity']
    },
    honor: {
        msg: '荣耀手机需要开启自启动和后台活动权限：\n\n手机管家→启动管理→手动管理→本应用→允许自启动+允许后台活动。',
        component: ['com.huawei.systemmanager', 'com.huawei.systemmanager.optimize.process.ProtectActivity']
    },
    oppo: {
        msg: 'OPPO 手机需要开启以下权限才能后台保活：\n\n1. 自启动\n2. 允许后台活动\n3. 电池优化白名单\n\n请点击"去设置"按指引开启。',
        action: 'com.coloros.action.AUTOBOOT_APPS'
    },
    vivo: {
        msg: 'vivo 手机需要开启以下权限才能后台保活：\n\n1. 自启动\n2. 后台电池保护：不要关闭\n3. 高耗电管理：允许\n\n请点击"去设置"按指引开启。',
        component: ['com.vivo.permissionmanager', 'com.vivo.permissionmanager.activity.BgStartUpManagerActivity']
    },
    samsung: {
        msg: '三星手机需要开启以下权限才能后台保活：\n\n1. 自适应电池：关闭\n2. 未使用的应用：关闭自动禁用\n3. 电池和设备保养→设备维护→后台使用限制→从不睡眠的应用：添加本应用\n\n请点击"去设置"按指引开启。',
        component: ['com.samsung.android.lool', 'com.samsung.android.sm.ui.battery.BatteryActivity']
    },
    realme: {
        msg: 'realme 手机需要开启自启动和后台活动权限：\n\n手机管家→应用管理→本应用→开启自启动+允许后台活动。',
        action: 'com.coloros.action.AUTOBOOT_APPS'
    }
}

function _getBrand() {
    if (typeof plus === 'undefined' || !plus.android) return null
    try {
        const Build = plus.android.importClass('android.os.Build')
        return (Build.MANUFACTURER || '').toString().toLowerCase()
    } catch (e) { return null }
}

export function checkBrandAutoStart() {
    if (typeof plus === 'undefined' || !plus.android) return
    try {
        const brand = _getBrand()
        if (!brand) return
        let matched = null
        for (const b of Object.keys(BRAND_GUIDES)) {
            if (brand.indexOf(b) !== -1) { matched = b; break }
        }
        if (!matched) return
        const key = 'push_brand_checked_' + matched
        try { if (uni.getStorageSync(key)) return } catch (e) {}
        const guide = BRAND_GUIDES[matched]
        if (!guide) return

        const main = plus.android.runtimeMainActivity()
        uni.showModal({
            title: '开启后台保活权限',
            content: guide.msg,
            confirmText: '去设置',
            cancelText: '稍后',
            success: (res) => {
                if (res.confirm) {
                    try {
                        const Intent = plus.android.importClass('android.content.Intent')
                        const ComponentName = plus.android.importClass('android.content.ComponentName')
                        const intent = new Intent()
                        if (guide.component) {
                            intent.setComponent(new ComponentName(guide.component[0], guide.component[1]))
                        } else if (guide.action) {
                            intent.setAction(guide.action)
                        }
                        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        main.startActivity(intent)
                    } catch (e) {
                        try {
                            const Intent2 = plus.android.importClass('android.content.Intent')
                            const Uri2 = plus.android.importClass('android.net.Uri')
                            const intent2 = new Intent2('android.settings.APPLICATION_DETAILS_SETTINGS')
                            intent2.setData(Uri2.fromParts('package', main.getPackageName(), null))
                            main.startActivity(intent2)
                        } catch (e2) {}
                    }
                }
                try { uni.setStorageSync(key, 1) } catch (e) {}
            }
        })
    } catch (e) { console.warn('[KeepAlive] 厂商自启动引导失败', e) }
}

// ============================================================
// SCHEDULE_EXACT_ALARM 权限引导（Android 12+，24h 节流）
// ============================================================
export function checkScheduleExactAlarm() {
    if (typeof plus === 'undefined' || !plus.android) return true
    try {
        const main = plus.android.runtimeMainActivity()
        const Build = plus.android.importClass('android.os.Build')
        if (Build.VERSION.SDK_INT < 31) return true
        try {
            const last = parseInt(uni.getStorageSync('push_alarm_checked') || '0')
            if (last && (Date.now() - last) < BATTERY_CHECK_INTERVAL) return true
        } catch (e) {}

        const AlarmManager = plus.android.importClass('android.app.AlarmManager')
        const am = main.getSystemService('alarm')
        let canSchedule = true
        try { canSchedule = am.canScheduleExactAlarms() } catch (e) { canSchedule = true }
        if (canSchedule) {
            console.log('[KeepAlive] 闹钟精确权限已授予')
            return true
        }
        try { uni.setStorageSync('push_alarm_checked', String(Date.now())) } catch (e) {}
        uni.showModal({
            title: '开启闹钟后台保活',
            content: '需要开启"闹钟和提醒"权限，才能在锁屏/Doze 模式下保持推送连接。请在设置中选择"允许始终发送闹钟"。',
            confirmText: '去设置',
            cancelText: '稍后',
            success: (res) => {
                if (res.confirm) {
                    try {
                        const Intent = plus.android.importClass('android.content.Intent')
                        const intent = new Intent('android.settings.SCHEDULE_EXACT_ALARM_SETTINGS')
                        main.startActivity(intent)
                    } catch (e) { console.warn('[KeepAlive] 打开闹钟设置失败', e) }
                }
            }
        })
        return false
    } catch (e) { return true }
}
