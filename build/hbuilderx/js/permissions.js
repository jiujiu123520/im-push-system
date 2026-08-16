const BRAND_ACTIONS = {
    'Xiaomi': {
        autoStart: ['miui.intent.action.OP_AUTO_START'],
        batteryOpt: ['miui.intent.action.BATTERY_OPTIMIZATIONS'],
        permissionCenter: ['miui.intent.action.PERMISSION'],
        setting: ['miui.intent.action.APP_PERM_EDITOR']
    },
    'HUAWEI': {
        powerSave: ['com.huawei.systemmanager', 'com.huawei.systemmanager.optimize.process.ProtectActivity'],
        protectedApps: ['com.huawei.systemmanager', 'com.huawei.systemmanager.power.ui.HwPowerManagerActivity']
    },
    'OPPO': {
        permission: ['com.oppo.settings', 'com.coloros.safecenter.permission.startup.StartupAppListActivity'],
        battery: ['com.oppo.settings', 'com.coloros.safecenter.net.NetworkMonitorActivity']
    },
    'vivo': {
        protectedApps: ['com.vivo.permissionmanager', 'com.vivo.permissionmanager.activity.BgStartUpManagerActivity'],
        smartPower: ['com.vivo.permissionmanager', 'com.vivo.permissionmanager.activity.SmartPowerSavingActivity']
    },
    'Honor': {
        startup: ['com.hihonor.systemui', 'com.hihonor.systemui.permissionmanager.SwitchStartupActivity'],
        battery: ['com.hihonor.systemui', 'com.hihonor.systemui.optimize.process.ProcessProtectActivity']
    }
}

export function getDeviceBrand() {
    try {
        if (typeof plus === 'undefined') return ''
        const Build = plus.android.importClass('android.os.Build')
        const brand = (Build.BRAND || '').toLowerCase()
        if (brand.indexOf('xiaomi') >= 0 || brand.indexOf('redmi') >= 0 || brand.indexOf('poco') >= 0) return 'Xiaomi'
        if (brand.indexOf('huawei') >= 0 || brand.indexOf('emui') >= 0) return 'HUAWEI'
        if (brand.indexOf('oppo') >= 0 || brand.indexOf('realme') >= 0) return 'OPPO'
        if (brand.indexOf('vivo') >= 0 || brand.indexOf('iqoo') >= 0) return 'vivo'
        if (brand.indexOf('honor') >= 0) return 'Honor'
        if (brand.indexOf('samsung') >= 0) return 'Samsung'
    } catch(e) {}
    return ''
}

export function getDeviceInfo() {
    try {
        if (typeof plus === 'undefined') return { brand: '', model: '', os: '' }
        const Build = plus.android.importClass('android.os.Build')
        return {
            brand: getDeviceBrand(),
            model: Build.MODEL || '',
            os: Build.VERSION.RELEASE || ''
        }
    } catch(e) { return { brand: '', model: '', os: '' } }
}

export function checkNotificationPerm() {
    try {
        if (typeof plus === 'undefined') return true
        const main = plus.android.runtimeMainActivity()
        const nm = main.getSystemService('notification')  // Context.NOTIFICATION_SERVICE 实际值
        return nm.areNotificationsEnabled()
    } catch(e) { return false }
}

// 检查 Android 13+ POST_NOTIFICATIONS 运行时权限（老版 showNotification 双重校验用）
export function checkPostNotificationsPerm() {
    try {
        if (typeof plus === 'undefined') return true
        const Build = plus.android.importClass('android.os.Build')
        if (Build.VERSION.SDK_INT < 33) return true
        const main = plus.android.runtimeMainActivity()
        const ContextCompat = plus.android.importClass('androidx.core.content.ContextCompat')
        const Manifest = plus.android.importClass('android.Manifest')
        const PackageManager = plus.android.importClass('android.content.pm.PackageManager')
        const has = ContextCompat.checkSelfPermission(main, Manifest.permission.POST_NOTIFICATIONS)
        return has === PackageManager.PERMISSION_GRANTED
    } catch(e) {
        console.warn('[Perm] checkPostNotificationsPerm fail', e)
        return true
    }
}

// 请求通知权限（老版三层递进：全局检查 → 系统弹框 → 用户确认后跳设置页）
// opts.guide = true 时弹 uni.showModal 引导（收到推送时用，用户有动力开启）
export function requestNotificationPerm(opts) {
    try {
        if (typeof plus === 'undefined') return false
        const guide = opts && opts.guide
        const Build = plus.android.importClass('android.os.Build')
        const main = plus.android.runtimeMainActivity()
        const nm = main.getSystemService(main.NOTIFICATION_SERVICE)
        if (nm.areNotificationsEnabled()) return true

        // Android 13+：先弹系统授权框
        if (Build.VERSION.SDK_INT >= 33) {
            if (!checkPostNotificationsPerm()) {
                try {
                    const ActivityCompat = plus.android.importClass('androidx.core.app.ActivityCompat')
                    const Manifest = plus.android.importClass('android.Manifest')
                    ActivityCompat.requestPermissions(main, [Manifest.permission.POST_NOTIFICATIONS], 1001)
                    console.log('[Perm] 请求通知权限（Android 13+）')
                } catch(e) {
                    console.warn('[Perm] requestPermissions fail, fall back to settings', e)
                }
                return false
            }
        }

        // 运行时权限已授予/不适用但全局关闭，或 <13 全局关闭：
        // 仅在 guide=true（收到推送时）弹窗引导，避免启动/切前台反复打扰
        if (!guide) return false

        console.log('[Perm] 通知权限未开启，引导用户去设置')
        uni.showModal({
            title: '开启通知权限',
            content: '为了让您及时收到推送消息，请在设置中开启通知权限',
            confirmText: '去设置',
            cancelText: '稍后再说',
            success: (res) => {
                if (res.confirm) {
                    openNotificationSetting()
                }
            }
        })
        return false
    } catch(e) {
        console.warn('[Perm] requestNotificationPerm fail', e)
        return false
    }
}

export function checkBatteryOpt() {
    try {
        if (typeof plus === 'undefined') return true
        const main = plus.android.runtimeMainActivity()
        const pm = main.getSystemService('power')  // Context.POWER_SERVICE 实际值
        return pm.isIgnoringBatteryOptimizations(main.getPackageName())
    } catch(e) { return true }
}

export function openSystemSetting() {
    try {
        if (typeof plus === 'undefined') return
        const main = plus.android.runtimeMainActivity()
        const Intent = plus.android.importClass('android.content.Intent')
        const Settings = plus.android.importClass('android.provider.Settings')
        const intent = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
        const Uri = plus.android.importClass('android.net.Uri')
        intent.setData(Uri.parse('package:' + main.getPackageName()))
        main.startActivity(intent)
    } catch(e) { console.warn('[Perm] openSetting fail', e) }
}

export function openNotificationSetting() {
    try {
        if (typeof plus === 'undefined') return
        const main = plus.android.runtimeMainActivity()
        const Intent = plus.android.importClass('android.content.Intent')
        const Settings = plus.android.importClass('android.provider.Settings')
        const Build = plus.android.importClass('android.os.Build')
        let intent
        if (Build.VERSION.SDK_INT >= 26) {
            const nm = main.getSystemService(main.NOTIFICATION_SERVICE)
            intent = new Intent(Settings.ACTION_CHANNEL_NOTIFICATION_SETTINGS)
            intent.putExtra(Settings.EXTRA_APP_PACKAGE, main.getPackageName())
            const channels = nm.getActiveNotificationChannels()
            if (channels && channels.length > 0) {
                intent.putExtra(Settings.EXTRA_CHANNEL_ID, channels[0].getId())
            }
        } else {
            intent = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
        }
        main.startActivity(intent)
    } catch(e) { openSystemSetting() }
}

export function openBatteryOpt() {
    try {
        if (typeof plus === 'undefined') return
        const main = plus.android.runtimeMainActivity()
        const Intent = plus.android.importClass('android.content.Intent')
        const Settings = plus.android.importClass('android.provider.Settings')
        const Uri = plus.android.importClass('android.net.Uri')
        const intent = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS)
        intent.setData(Uri.parse('package:' + main.getPackageName()))
        main.startActivity(intent)
    } catch(e) {
        try {
            const main2 = plus.android.runtimeMainActivity()
            const Intent2 = plus.android.importClass('android.content.Intent')
            const Settings2 = plus.android.importClass('android.provider.Settings')
            const i = new Intent2(Settings2.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)
            main2.startActivity(i)
        } catch(e2) { openSystemSetting() }
    }
}

export function openBrandSetting(action) {
    try {
        if (typeof plus === 'undefined') return
        const main = plus.android.runtimeMainActivity()
        const Intent = plus.android.importClass('android.content.Intent')
        const info = BRAND_ACTIONS[getDeviceBrand()]
        if (!info || !info[action]) { openSystemSetting(); return }
        const act = info[action]
        if (act.length === 1) {
            const i = new Intent(act[0])
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            main.startActivity(i)
        } else if (act.length === 2) {
            const ComponentName = plus.android.importClass('android.content.ComponentName')
            const i2 = new Intent()
            i2.setComponent(new ComponentName(act[0], act[1]))
            i2.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            main.startActivity(i2)
        } else {
            openSystemSetting()
        }
    } catch(e) { openSystemSetting() }
}
