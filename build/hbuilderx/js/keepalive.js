// 后台保活模块（移植老版完整策略）
// 1. 常驻通知（setOngoing）：用户可见 + 提升进程优先级
// 2. WakeLock：防止 CPU 休眠断连
// 3. WifiLock：防止 WiFi 休眠断连
// 4. AlarmManager 定时心跳：Doze 模式下唤醒 CPU 发心跳/触发重连
// 5. 电池优化白名单引导（checkBatteryOptimization）
// 6. 小米自启动权限引导（checkXiaomiAutoStart）
let wakeLock = null
let wifiLock = null
let _lastStartTs = 0

let _alarmPendingIntent = null
let _alarmReceiver = null
let _alarmReceiverRegistered = false
let _alarmHandler = null   // 由 ws.js 注册：闹钟触发时发心跳/重连

const CHANNEL_ID = 'push_service_foreground'
const NOTIFICATION_ID = 1001
const ALARM_ACTION = 'com.push.app.ALARM_HEARTBEAT'
const ALARM_INTERVAL = 15 * 1000  // 15 秒（Doze 维护窗口内更可能触发）

// 启动保活（含常驻通知 + WakeLock + WifiLock + AlarmManager 心跳）
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
        setupAlarmHeartbeat()
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

// ws.js 注册闹钟回调：参数为 { connected: bool }
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
        if (wakeLock) {
            if (wakeLock.isHeld()) wakeLock.release()
            wakeLock = null
        }
        if (wifiLock) {
            try { if (wifiLock.isHeld()) wifiLock.release() } catch(e) {}
            wifiLock = null
        }
    } catch(e) {}
    stopAlarmHeartbeat()
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

// ========== AlarmManager 定时心跳（移植老版） ==========
// Doze 模式下 setInterval 会被冻结，闹钟（setExactAndAllowWhileIdle）仍可唤醒 CPU
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
            ? 0x04000000 | 0x08000000  // FLAG_UPDATE_CURRENT | FLAG_IMMUTABLE
            : 0x04000000  // FLAG_UPDATE_CURRENT

        if (_alarmPendingIntent) {
            try { _alarmPendingIntent.cancel() } catch (e) {}
        }
        _alarmPendingIntent = PendingIntent.getBroadcast(main, 200, intent, flags)

        const am = main.getSystemService('alarm')  // Context.ALARM_SERVICE 实际值

        // RTC_WAKEUP = 0, 唤醒 CPU
        if (Build.VERSION.SDK_INT >= 23) {
            // setExactAndAllowWhileIdle: 在 Doze 模式下也能精确触发
            try {
                am.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, _alarmPendingIntent)
            } catch (e) {
                // Android 12+ 可能需要 SCHEDULE_EXACT_ALARM 权限
                console.warn('[KeepAlive] setExactAndAllowWhileIdle 失败，回退 setAndAllowWhileIdle', e)
                am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAt, _alarmPendingIntent)
            }
        } else {
            am.setExact(AlarmManager.RTC_WAKEUP, triggerAt, _alarmPendingIntent)
        }

        // 注册闹钟广播接收器
        if (!_alarmReceiverRegistered) {
            const BroadcastReceiver = plus.android.importClass('android.content.BroadcastReceiver')
            _alarmReceiver = new BroadcastReceiver({
                onReceive: function(context, intent) {
                    try {
                        const action = intent.getAction()
                        if (action !== ALARM_ACTION) return

                        // 关键：唤醒后立即获取短暂 WakeLock，防止 CPU 在心跳完成前再次休眠
                        let tmpWakeLock = null
                        try {
                            const PowerManager = plus.android.importClass('android.os.PowerManager')
                            const pm = context.getSystemService('power')
                            tmpWakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:AlarmWake')
                            tmpWakeLock.setReferenceCounted(false)
                            tmpWakeLock.acquire(10 * 1000)  // 10 秒后自动释放
                        } catch (e) {
                            console.warn('[KeepAlive] 闹钟临时 WakeLock 失败', e)
                        }

                        // 触发 ws.js 心跳/重连
                        if (_alarmHandler) {
                            try { _alarmHandler() } catch (e) {
                                console.warn('[KeepAlive] 闹钟回调执行失败', e)
                            }
                        }

                        // 重新设置下一次闹钟
                        setupAlarmHeartbeat()

                        // 保留引用防止被 GC（10 秒超时自动释放）
                        _tmpAlarmWakeLock = tmpWakeLock
                    } catch (e) {
                        console.warn('[KeepAlive] 闹钟 onReceive 异常', e)
                    }
                }
            })
            const IntentFilter = plus.android.importClass('android.content.IntentFilter')
            const intentFilter = new IntentFilter()
            intentFilter.addAction(ALARM_ACTION)
            try {
                main.registerReceiver(_alarmReceiver, intentFilter)
            } catch (e) {
                // Android 14+（targetSdk 34）要求指定 RECEIVER_EXPORTED/NOT_EXPORTED
                try {
                    main.registerReceiver(_alarmReceiver, intentFilter, 4)  // RECEIVER_NOT_EXPORTED
                } catch (e2) {
                    console.warn('[KeepAlive] 注册闹钟接收器失败', e, e2)
                }
            }
            _alarmReceiverRegistered = true
            console.log('[KeepAlive] AlarmManager 心跳接收器已注册')
        }
    } catch (e) {
        console.warn('[KeepAlive] 设置 AlarmManager 心跳失败', e)
    }
}
let _tmpAlarmWakeLock = null

export function stopAlarmHeartbeat() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        const main = plus.android.runtimeMainActivity()

        if (_alarmPendingIntent) {
            const AlarmManager = plus.android.importClass('android.app.AlarmManager')
            const am = main.getSystemService('alarm')
            am.cancel(_alarmPendingIntent)
            _alarmPendingIntent.cancel()
            _alarmPendingIntent = null
        }

        if (_alarmReceiver && _alarmReceiverRegistered) {
            try {
                main.unregisterReceiver(_alarmReceiver)
            } catch (e) {}
            _alarmReceiver = null
            _alarmReceiverRegistered = false
        }
    } catch (e) {
        console.warn('[KeepAlive] 停止 AlarmManager 心跳失败', e)
    }
}

// ========== 电池优化白名单（移植老版） ==========
export function checkBatteryOptimization() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return true
        const main = plus.android.runtimeMainActivity()
        const Build = plus.android.importClass('android.os.Build')

        if (Build.VERSION.SDK_INT < 23) return true

        const pm = main.getSystemService('power')
        const isIgnoring = pm.isIgnoringBatteryOptimizations(main.getPackageName())
        if (isIgnoring) {
            console.log('[KeepAlive] 已在电池优化白名单中')
            return true
        }

        // 引导用户关闭电池优化
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
                    } catch (e) {
                        console.warn('[KeepAlive] 打开电池优化设置失败', e)
                    }
                }
            }
        })
        return false
    } catch (e) {
        console.warn('[KeepAlive] 检查电池优化失败', e)
        return true
    }
}

// ========== 小米自启动权限引导（移植老版） ==========
export function checkXiaomiAutoStart() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        const main = plus.android.runtimeMainActivity()
        const Build = plus.android.importClass('android.os.Build')
        const manufacturer = (Build.MANUFACTURER || '').toLowerCase()
        if (manufacturer !== 'xiaomi') return

        const key = 'xiaomi_autostart_checked'
        const checked = uni.getStorageSync(key)
        if (checked) return

        uni.showModal({
            title: '开启后台保活权限',
            content: '小米手机需要开启以下权限才能在后台保持推送连接：\n\n1. 自启动权限\n2. 后台弹出通知\n3. 锁屏显示通知\n\n请在设置中找到本应用并开启以上权限。',
            confirmText: '去设置',
            cancelText: '稍后',
            success: (res) => {
                if (res.confirm) {
                    try {
                        const Intent = plus.android.importClass('android.content.Intent')
                        const intent = new Intent()
                        intent.setComponent(new plus.android.invoke('android.content.ComponentName', 'init', 'com.miui.securitycenter', 'com.miui.permcenter.autostart.AutoStartManagementActivity'))
                        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                        main.startActivity(intent)
                    } catch (e) {
                        console.warn('[KeepAlive] 打开小米自启动设置失败', e)
                        // 回退到应用详情页
                        try {
                            const Intent2 = plus.android.importClass('android.content.Intent')
                            const Uri2 = plus.android.importClass('android.net.Uri')
                            const intent2 = new Intent2('android.settings.APPLICATION_DETAILS_SETTINGS')
                            intent2.setData(Uri2.fromParts('package', main.getPackageName(), null))
                            main.startActivity(intent2)
                        } catch (e2) {}
                    }
                }
                uni.setStorageSync(key, true)
            }
        })
    } catch (e) {
        console.warn('[KeepAlive] 检查小米自启动失败', e)
    }
}
