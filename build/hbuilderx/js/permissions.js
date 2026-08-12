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
        const nm = main.getSystemService(main.NOTIFICATION_SERVICE)
        return nm.areNotificationsEnabled()
    } catch(e) { return false }
}

export function checkBatteryOpt() {
    try {
        if (typeof plus === 'undefined') return true
        const main = plus.android.runtimeMainActivity()
        const pm = main.getSystemService(main.POWER_SERVICE)
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
