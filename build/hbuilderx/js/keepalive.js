// 后台保活模块（移植老版完整策略 + 本轮 BUG 修复）
// 1. 常驻通知（setOngoing）+ 完整权限检查
// 2. WakeLock + WifiLock
// 3. AlarmManager 15秒心跳（setExactAndAllowWhileIdle，Doze 可唤醒）
// 4. SCREEN_ON 广播接收器（亮屏后验证 WS 连接，ping+5s超时重连）
// 5. 电池优化白名单引导（节流：24h 内只提示一次）
// 6. 全厂商自启动/电池保护引导（小米/华为/OPPO/vivo/三星）
// 7. SCHEDULE_EXACT_ALARM 用户授权引导（Android 12+）
let wakeLock = null
let wifiLock = null
let _lastStartTs = 0
let _lastStatusUpdateTs = 0   // BUG-D: 节流 updateKeepAliveStatus

let _alarmPendingIntent = null
let _alarmReceiver = null
let _alarmReceiverRegistered = false
let _alarmHandler = null

let _screenReceiver = null
let _screenReceiverRegistered = false
let _screenPingOk = false
let _screenPingTimer = null
let _tmpAlarmWakeLock = null  // BUG-G: 提前声明

const CHANNEL_ID = 'push_service_foreground'
const NOTIFICATION_ID = 1001
const ALARM_ACTION = 'com.push.app.ALARM_HEARTBEAT'
const ALARM_INTERVAL = 15 * 1000
const BATTERY_CHECK_INTERVAL = 24 * 60 * 60 * 1000  // BUG-A: 24 小时节流

// ============ 品牌引导映射（复用 permissions.js 的映射，避免 import 循环） ============
const BRAND_GUIDES = {
    Xiaomi: { msg: '小米手机需要开启以下权限才能在后台保持推送连接：\n\n1. 自启动权限\n2. 后台弹出通知\n3. 锁屏显示通知', component: ['com.miui.securitycenter', 'com.miui.permcenter.autostart.AutoStartManagementActivity'] },
    HUAWEI: { msg: '华为手机需要开启以下权限才能在后台保持推送连接：\n\n1. 自启动权限\n2. 后台运行保护', component: ['com.huawei.systemmanager', 'com.huawei.systemmanager.optimize.process.ProtectActivity'] },
    OPPO: { msg: 'OPPO/realme 手机需要开启以下权限：\n\n1. 自启动权限\n2. 允许后台活动', component: ['com.oppo.settings', 'com.coloros.safecenter.permission.startup.StartupAppListActivity'] },
    vivo: { msg: 'vivo/iQOO 手机需要开启以下权限：\n\n1. 自启动权限\n2. 后台电池保护', component: ['com.vivo.permissionmanager', 'com.vivo.permissionmanager.activity.BgStartUpManagerActivity'] },
    Honor: { msg: '荣耀手机需要开启以下权限：\n\n1. 自启动权限\n2. 后台保护', component: ['com.hihonor.systemui', 'com.hihonor.systemui.permissionmanager.SwitchStartupActivity'] },
    Samsung: { msg: '三星手机需要开启以下权限：\n\n1. 自启动权限\n2. 后台允许活动', action: 'com.samsung.android.oneshot.action.APP_SETTINGS' }
}

function _getBrand() {
    try {
        if (typeof plus === 'undefined') return ''
        const Build = plus.android.importClass('android.os.Build')
        const b = (Build.BRAND || '').toLowerCase()
        if (b.indexOf('xiaomi') >= 0 || b.indexOf('redmi') >= 0 || b.indexOf('poco') >= 0) return 'Xiaomi'
        if (b.indexOf('huawei') >= 0 || b.indexOf('emui') >= 0) return 'HUAWEI'
        if (b.indexOf('oppo') >= 0 || b.indexOf('realme') >= 0) return 'OPPO'
        if (b.indexOf('vivo') >= 0 || b.indexOf('iqoo') >= 0) return 'vivo'
        if (b.indexOf('honor') >= 0) return 'Honor'
        if (b.indexOf('samsung') >= 0) return 'Samsung'
    } catch(e) {}
    return ''
}

// ============ 启动保活 ============
export function startKeepAlive(connected) {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        const now = Date.now()
        if (_lastStartTs && (now - _lastStartTs) < 5000) return
        _lastStartTs = now

        _acquireWakeLock()
        _acquireWifiLock()
        setupAlarmHeartbeat()
        _registerScreenReceiver()

        // 常驻通知不在此处显示 — 由 ws.js state 变化时通过 updateKeepAliveStatus 触发
        // 避免 startKeepAlive() 时立刻显示"正在连接..."然后永远不变
        // 第一次状态更新（home 页面 onShow 时 getState + connect 触发 state 变化）会自动显示

        console.log('[KeepAlive] 前台服务保活已启动')
    } catch(e) {
        console.warn('[KeepAlive] start fail', e)
    }
}

// BUG-D: 给 updateKeepAliveStatus 加 3 秒节流
export function updateKeepAliveStatus(connected) {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        const now = Date.now()
        if (_lastStatusUpdateTs && (now - _lastStatusUpdateTs) < 3000) return
        _lastStatusUpdateTs = now
        _showForegroundNotification(connected === true)
    } catch(e) {}
}

export function setAlarmHandler(fn) {
    _alarmHandler = typeof fn === 'function' ? fn : null
}

export function stopKeepAlive() {
    try {
        if (typeof plus !== 'undefined' && plus.android) {
            const main = plus.android.runtimeMainActivity()
            const nm = main.getSystemService('notification')
            nm.cancel(NOTIFICATION_ID)
        }
        if (wakeLock) { if (wakeLock.isHeld()) wakeLock.release(); wakeLock = null }
        if (wifiLock) { try { if (wifiLock.isHeld()) wifiLock.release() } catch(e) {}; wifiLock = null }
    } catch(e) {}
    stopAlarmHeartbeat()
    _unregisterScreenReceiver()
}

// ============ 常驻通知（BUG-C 修复：完整权限检查 + 通知没权限时仍尝试前台服务） ============
function _showForegroundNotification(connected) {
    const main = plus.android.runtimeMainActivity()
    const Build = plus.android.importClass('android.os.Build')
    const nm = main.getSystemService('notification')

    // BUG-C: 完整检查通知权限
    let notifAllowed = true
    try {
        if (!nm.areNotificationsEnabled()) notifAllowed = false
    } catch(e) {}
    if (notifAllowed && Build.VERSION.SDK_INT >= 33) {
        try {
            const ContextCompat = plus.android.importClass('androidx.core.content.ContextCompat')
            const Manifest = plus.android.importClass('android.Manifest')
            const PackageManager = plus.android.importClass('android.content.pm.PackageManager')
            const granted = ContextCompat.checkSelfPermission(main, Manifest.permission.POST_NOTIFICATIONS)
            if (granted !== PackageManager.PERMISSION_GRANTED) notifAllowed = false
        } catch(e) {}
    }

    if (!notifAllowed) {
        console.warn('[KeepAlive] 通知权限未开启，跳过常驻通知（前台服务仍维持 WakeLock+WifiLock）')
        return   // BUG-C 说明：即使不显示通知，WakeLock/WifiLock/AlarmManager 仍工作，进程优先级仍提升
    }

    // 创建渠道（静音、无振动、无角标）
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

    // 点击 Intent
    let contentIntent = null
    try {
        const Intent = plus.android.importClass('android.content.Intent')
        const PendingIntent = plus.android.importClass('android.app.PendingIntent')
        const launchIntent = main.getPackageManager().getLaunchIntentForPackage(main.getPackageName())
        launchIntent.addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP)
        const flags = Build.VERSION.SDK_INT >= 31 ? 0x04000000 | 0x08000000 : 0x04000000
        contentIntent = PendingIntent.getActivity(main, 0, launchIntent, flags)
    } catch(e) { console.warn('[KeepAlive] 创建 PendingIntent 失败', e) }

    // Builder（Compat → 原生 回退）
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
        builder.setOngoing(true)
        builder.setAutoCancel(false)
        builder.setPriority(0)
        builder.setVisibility(1)
        builder.setCategory('service')
        builder.setOnlyAlertOnce(true)
    } catch(e) { console.warn('[KeepAlive] 设置通知属性失败', e) }

    try {
        nm.notify(NOTIFICATION_ID, builder.build())
        console.log('[KeepAlive] 常驻通知已显示, id=' + NOTIFICATION_ID)
    } catch(e) { console.warn('[KeepAlive] 显示通知失败', e) }
}

function _appIcon(main) {
    try {
        const appInfo = main.getApplicationInfo()
        const icon = appInfo.icon
        if (icon && icon > 0) return icon
    } catch(e) {}
    return 17301651  // android.R.drawable.ic_dialog_info
}

// ============ WakeLock ============
function _acquireWakeLock() {
    try {
        const main = plus.android.runtimeMainActivity()
        const pm = main.getSystemService('power')
        const PowerManager = plus.android.importClass('android.os.PowerManager')
        if (!wakeLock) {
            wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:KeepAlive')
            wakeLock.setReferenceCounted(false)
        }
        if (!wakeLock.isHeld()) wakeLock.acquire(24 * 60 * 60 * 1000)
        console.log('[KeepAlive] WakeLock acquired')
    } catch(e) { console.warn('[KeepAlive] WakeLock fail', e) }
}

// ============ WifiLock ============
function _acquireWifiLock() {
    try {
        const main = plus.android.runtimeMainActivity()
        const appCtx = main.getApplicationContext()
        const wm = appCtx.getSystemService('wifi')
        const WifiManager = plus.android.importClass('android.net.wifi.WifiManager')
        if (!wifiLock) {
            wifiLock = wm.createWifiLock(WifiManager.WIFI_MODE_FULL_HIGH_PERF, 'PushApp:WifiLock')
            wifiLock.setReferenceCounted(false)
        }
        if (!wifiLock.isHeld()) wifiLock.acquire()
        console.log('[KeepAlive] WifiLock acquired')
    } catch(e) { console.warn('[KeepAlive] WifiLock fail', e) }
}

// ============ AlarmManager 定时心跳 ============
export function setupAlarmHeartbeat() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        const main = plus.android.runtimeMainActivity()
        const AlarmManager = plus.android.importClass('android.app.AlarmManager')
        const PendingIntent = plus.android.importClass('android.app.PendingIntent')
        const Intent = plus.android.importClass('android.content.Intent')
        const System = plus.android.importClass('java.lang.System')
        const Build = plus.android.importClass('android.os.Build')

        const triggerAt = System.currentTimeMillis() + ALARM_INTERVAL
        const intent = new Intent(ALARM_ACTION)
        intent.setPackage(main.getPackageName())
        const flags = Build.VERSION.SDK_INT >= 31
            ? 0x04000000 | 0x08000000 : 0x04000000

        if (_alarmPendingIntent) { try { _alarmPendingIntent.cancel() } catch(e) {} }
        _alarmPendingIntent = PendingIntent.getBroadcast(main, 200, intent, flags)

        const am = main.getSystemService('alarm')

        if (Build.VERSION.SDK_INT >= 23) {
            try {
                am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, _alarmPendingIntent)
            } catch(e) {
                console.warn('[KeepAlive] setExactAndAllowWhileIdle 失败，回退 setAndAllowWhileIdle', e)
                am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, _alarmPendingIntent)
            }
        } else {
            am.setExact(AlarmManager.RTC_WAKEUP, triggerAt, _alarmPendingIntent)
        }

        if (!_alarmReceiverRegistered) {
            const BroadcastReceiver = plus.android.importClass('android.content.BroadcastReceiver')
            _alarmReceiver = new BroadcastReceiver({
                onReceive: function(context, intent) {
                    try {
                        if (intent.getAction() !== ALARM_ACTION) return
                        let tmpWakeLock = null
                        try {
                            const PowerManager = plus.android.importClass('android.os.PowerManager')
                            const pm = context.getSystemService('power')
                            tmpWakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:AlarmWake')
                            tmpWakeLock.setReferenceCounted(false)
                            tmpWakeLock.acquire(10 * 1000)
                        } catch(e) { console.warn('[KeepAlive] 闹钟临时 WakeLock 失败', e) }

                        if (_alarmHandler) { try { _alarmHandler() } catch(e) { console.warn('[KeepAlive] 闹钟回调执行失败', e) } }
                        setupAlarmHeartbeat()
                        _tmpAlarmWakeLock = tmpWakeLock
                    } catch(e) { console.warn('[KeepAlive] 闹钟 onReceive 异常', e) }
                }
            })
            const IntentFilter = plus.android.importClass('android.content.IntentFilter')
            const intentFilter = new IntentFilter()
            intentFilter.addAction(ALARM_ACTION)
            try {
                main.registerReceiver(_alarmReceiver, intentFilter)
            } catch(e) {
                try { main.registerReceiver(_alarmReceiver, intentFilter, 4) } catch(e2) {
                    console.warn('[KeepAlive] 注册闹钟接收器失败', e, e2)
                }
            }
            _alarmReceiverRegistered = true
            console.log('[KeepAlive] AlarmManager 心跳接收器已注册')
        }
    } catch(e) { console.warn('[KeepAlive] 设置 AlarmManager 心跳失败', e) }
}

export function stopAlarmHeartbeat() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        const main = plus.android.runtimeMainActivity()
        if (_alarmPendingIntent) {
            const am = main.getSystemService('alarm')
            am.cancel(_alarmPendingIntent); _alarmPendingIntent.cancel(); _alarmPendingIntent = null
        }
        if (_alarmReceiver && _alarmReceiverRegistered) {
            try { main.unregisterReceiver(_alarmReceiver) } catch(e) {}
            _alarmReceiver = null; _alarmReceiverRegistered = false
        }
    } catch(e) { console.warn('[KeepAlive] 停止 AlarmManager 心跳失败', e) }
}

// ============ BUG-B: SCREEN_ON 广播接收器（亮屏后验证 WS 连接） ============
export function _registerScreenReceiver() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        if (_screenReceiverRegistered) return
        const main = plus.android.runtimeMainActivity()
        const BroadcastReceiver = plus.android.importClass('android.content.BroadcastReceiver')
        _screenReceiver = new BroadcastReceiver({
            onReceive: function(context, intent) {
                const action = intent.getAction()
                if (action === 'android.intent.action.SCREEN_ON') {
                    console.log('[KeepAlive] SCREEN_ON: 验证 WS 连接存活')
                    if (_alarmHandler) {
                        try { _alarmHandler() } catch(e) {}
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
        } catch(e) {
            try { main.registerReceiver(_screenReceiver, filter, 4) } catch(e2) {}
        }
        _screenReceiverRegistered = true
        console.log('[KeepAlive] 屏幕广播接收器已注册')
    } catch(e) { console.warn('[KeepAlive] 注册屏幕接收器失败', e) }
}

export function _unregisterScreenReceiver() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        if (_screenReceiver && _screenReceiverRegistered) {
            const main = plus.android.runtimeMainActivity()
            try { main.unregisterReceiver(_screenReceiver) } catch(e) {}
            _screenReceiver = null; _screenReceiverRegistered = false
        }
    } catch(e) {}
}

// ============ BUG-A: 电池优化白名单引导（24h 节流） ============
export function checkBatteryOptimization() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return true
        const main = plus.android.runtimeMainActivity()
        const Build = plus.android.importClass('android.os.Build')
        if (Build.VERSION.SDK_INT < 23) return true

        // BUG-A: 24h 节流
        try {
            var last = parseInt(uni.getStorageSync('push_battery_checked') || '0')
            if (last && (Date.now() - last) < BATTERY_CHECK_INTERVAL) {
                console.log('[KeepAlive] 电池优化引导已在 24h 内展示过，跳过')
                return true
            }
        } catch(e) {}

        const pm = main.getSystemService('power')
        const isIgnoring = pm.isIgnoringBatteryOptimizations(main.getPackageName())
        if (isIgnoring) {
            console.log('[KeepAlive] 已在电池优化白名单中')
            return true
        }

        try { uni.setStorageSync('push_battery_checked', String(Date.now())) } catch(e) {}

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
    } catch (e) { console.warn('[KeepAlive] 检查电池优化失败', e); return true }
}

// ============ BUG-F: 全厂商自启动/电池保护引导 ============
// 统一入口：根据当前品牌弹对应引导，只提示一次
export function checkBrandAutoStart() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        const brand = _getBrand()
        if (!brand) return
        const key = 'push_brand_checked_' + brand
        try { if (uni.getStorageSync(key)) return } catch(e) {}

        const guide = BRAND_GUIDES[brand]
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
                        console.warn('[KeepAlive] 打开' + brand + '自启动设置失败', e)
                        // 回退：应用详情页
                        try {
                            const Intent2 = plus.android.importClass('android.content.Intent')
                            const Uri2 = plus.android.importClass('android.net.Uri')
                            const intent2 = new Intent2('android.settings.APPLICATION_DETAILS_SETTINGS')
                            intent2.setData(Uri2.fromParts('package', main.getPackageName(), null))
                            main.startActivity(intent2)
                        } catch(e2) {}
                    }
                }
                try { uni.setStorageSync(key, 1) } catch(e) {}
            }
        })
    } catch (e) { console.warn('[KeepAlive] 检查厂商权限失败', e) }
}

// 兼容老的 checkXiaomiAutoStart（已合并到 checkBrandAutoStart）
export function checkXiaomiAutoStart() {
    checkBrandAutoStart()
}

// ============ BUG-E: SCHEDULE_EXACT_ALARM 用户授权引导（Android 12+） ============
// Android 12+（targetSdk 33）：即使 manifest 声明了 SCHEDULE_EXACT_ALARM，
// 用户仍需在系统设置"闹钟和提醒"里手动授权"允许始终发送闹钟"才能使用 setExactAndAllowWhileIdle
export function checkScheduleExactAlarm() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return true
        const main = plus.android.runtimeMainActivity()
        const Build = plus.android.importClass('android.os.Build')
        if (Build.VERSION.SDK_INT < 31) return true

        // 24h 节流
        try {
            var last = parseInt(uni.getStorageSync('push_alarm_checked') || '0')
            if (last && (Date.now() - last) < BATTERY_CHECK_INTERVAL) return true
        } catch(e) {}

        const AlarmManager = plus.android.importClass('android.app.AlarmManager')
        const am = main.getSystemService('alarm')
        var canSchedule = true
        try {
            // Android 12+ 的 AlarmManager 有 canScheduleExactAlarms() 方法
            canSchedule = am.canScheduleExactAlarms()
        } catch(e) {
            // 旧版无此方法，假设可用
            canSchedule = true
        }

        if (canSchedule) {
            console.log('[KeepAlive] 闹钟精确权限已授予')
            return true
        }

        try { uni.setStorageSync('push_alarm_checked', String(Date.now())) } catch(e) {}

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
    } catch(e) { console.warn('[KeepAlive] 检查闹钟权限失败', e); return true }
}
