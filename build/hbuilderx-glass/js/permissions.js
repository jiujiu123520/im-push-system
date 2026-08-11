var BRAND_ACTIONS = {
    'Xiaomi': {
        autoStart: ['miui.intent.action.OP_AUTO_START', new Object({ extra_allow: true })],
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

function getDeviceBrand() {
    try {
        if (typeof plus === 'undefined') return ''
        var Build = plus.android.importClass('android.os.Build')
        var brand = (Build.BRAND || '').toLowerCase()
        if (brand.indexOf('xiaomi') >= 0 || brand.indexOf('redmi') >= 0 || brand.indexOf('poco') >= 0) return 'Xiaomi'
        if (brand.indexOf('huawei') >= 0 || brand.indexOf('emui') >= 0) return 'HUAWEI'
        if (brand.indexOf('oppo') >= 0 || brand.indexOf('realme') >= 0) return 'OPPO'
        if (brand.indexOf('vivo') >= 0 || brand.indexOf('iqoo') >= 0) return 'vivo'
        if (brand.indexOf('honor') >= 0) return 'Honor'
        if (brand.indexOf('samsung') >= 0) return 'Samsung'
    } catch(e) {}
    return ''
}

function getDeviceInfo() {
    try {
        if (typeof plus === 'undefined') return { brand: '', model: '', os: '' }
        var Build = plus.android.importClass('android.os.Build')
        return {
            brand: getDeviceBrand(),
            model: Build.MODEL || '',
            os: Build.VERSION.RELEASE || ''
        }
    } catch(e) { return { brand: '', model: '', os: '' } }
}

function checkNotificationPerm() {
    try {
        if (typeof plus === 'undefined') return true
        var main = plus.android.runtimeMainActivity()
        var nm = main.getSystemService(main.NOTIFICATION_SERVICE)
        return nm.areNotificationsEnabled()
    } catch(e) { return false }
}

function checkBatteryOpt() {
    try {
        if (typeof plus === 'undefined') return true
        var main = plus.android.runtimeMainActivity()
        var pm = main.getSystemService(main.POWER_SERVICE)
        return pm.isIgnoringBatteryOptimizations(main.getPackageName())
    } catch(e) { return true }
}

function openSystemSetting() {
    try {
        if (typeof plus === 'undefined') return
        var main = plus.android.runtimeMainActivity()
        var Intent = plus.android.importClass('android.content.Intent')
        var Settings = plus.android.importClass('android.provider.Settings')
        var intent = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
        var uri = plus.android.importClass('android.net.Uri').parse('package:' + main.getPackageName())
        intent.setData(uri)
        main.startActivity(intent)
    } catch(e) { console.warn('[Perm] openSetting fail', e) }
}

function openNotificationSetting() {
    try {
        if (typeof plus === 'undefined') return
        var main = plus.android.runtimeMainActivity()
        var Intent = plus.android.importClass('android.content.Intent')
        var Settings = plus.android.importClass('android.provider.Settings')
        var Build = plus.android.importClass('android.os.Build')
        var intent
        if (Build.VERSION.SDK_INT >= 26) {
            var nm = main.getSystemService(main.NOTIFICATION_SERVICE)
            var channelId = 'push_alert'
            intent = new Intent(Settings.ACTION_CHANNEL_NOTIFICATION_SETTINGS)
            intent.putExtra(Settings.EXTRA_APP_PACKAGE, main.getPackageName())
            intent.putExtra(Settings.EXTRA_CHANNEL_ID, nm.getActiveNotificationChannels()[0].getId())
        } else {
            intent = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
        }
        main.startActivity(intent)
    } catch(e) { openSystemSetting() }
}

function openBatteryOpt() {
    try {
        if (typeof plus === 'undefined') return
        var main = plus.android.runtimeMainActivity()
        var Intent = plus.android.importClass('android.content.Intent')
        var Settings = plus.android.importClass('android.provider.Settings')
        var intent = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS)
        intent.setData(plus.android.importClass('android.net.Uri').parse('package:' + main.getPackageName()))
        main.startActivity(intent)
    } catch(e) {
        try {
            var main2 = plus.android.runtimeMainActivity()
            var Intent2 = plus.android.importClass('android.content.Intent')
            var Settings2 = plus.android.importClass('android.provider.Settings')
            var i = new Intent2(Settings2.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)
            main2.startActivity(i)
        } catch(e2) { openSystemSetting() }
    }
}

function openBrandSetting(action) {
    try {
        if (typeof plus === 'undefined') return
        var main = plus.android.runtimeMainActivity()
        var Intent = plus.android.importClass('android.content.Intent')
        var info = BRAND_ACTIONS[getDeviceBrand()]
        if (!info || !info[action]) { openSystemSetting(); return }
        var act = info[action]
        if (act.length === 1) {
            var i = new Intent(act[0])
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            main.startActivity(i)
        } else if (act.length === 2) {
            var i2 = new Intent()
            i2.setComponent(new plus.android.importClass('android.content.ComponentName')(act[0], act[1]))
            i2.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            main.startActivity(i2)
        } else {
            openSystemSetting()
        }
    } catch(e) { openSystemSetting() }
}

module.exports = {
    getDeviceBrand: getDeviceBrand,
    getDeviceInfo: getDeviceInfo,
    checkNotificationPerm: checkNotificationPerm,
    checkBatteryOpt: checkBatteryOpt,
    openSystemSetting: openSystemSetting,
    openNotificationSetting: openNotificationSetting,
    openBatteryOpt: openBatteryOpt,
    openBrandSetting: openBrandSetting,
    BRAND_ACTIONS: BRAND_ACTIONS
}
