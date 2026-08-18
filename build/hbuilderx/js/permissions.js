// ============================================================
// 权限模块（完全移植老版本 requestNotificationPerm 三层递进逻辑）
// 1. 检查全局通知 nm.areNotificationsEnabled()
// 2. Android 13+ 检查 POST_NOTIFICATIONS 运行时权限
// 3. 系统授权框（ActivityCompat.requestPermissions）
// 4. 1.5s 复查：Android 13+ 同一权限生命周期最多弹 2 次，
//    被拒过则 requestPermissions 静默失败 → 引导跳设置页手动开启
// 5. 冷启动节流：首次启动只引导一次，存 push_perm_guided 标记
// ============================================================

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
        const Context = plus.android.importClass('android.content.Context')
        const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)
        return nm.areNotificationsEnabled()
    } catch(e) { return false }
}

// 检查 Android 13+ POST_NOTIFICATIONS 运行时权限
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

// 全局通知权限 + 运行时权限 双重检查
export function checkNotificationPermFull() {
    if (!checkNotificationPerm()) return false
    return checkPostNotificationsPerm()
}

// 引导弹窗：用户确认后跳 APP 通知设置页
function _guideToSettings(msg) {
    uni.showModal({
        title: '开启通知权限',
        content: msg || '系统授权框未能弹出（可能已被系统记住拒绝记录）。请在设置中手动开启"显示通知"',
        confirmText: '去设置',
        cancelText: '稍后再说',
        success: (res) => {
            if (res.confirm) {
                openNotificationSetting()
            }
        }
    })
}

/**
 * 请求通知权限（老版三层递进：全局检查 → 系统弹框 → 1.5s 复查 → 引导跳设置页）
 * @param {object} opts
 * @param {boolean} opts.guide - true 时弹 uni.showModal 强制引导（收到推送时用，用户有动力开启）
 *                              false/不传时冷启动场景：仅首次引导，存 push_perm_guided 节流
 */
export function requestNotificationPerm(opts) {
    try {
        if (typeof plus === 'undefined') return false
        const guide = opts && opts.guide
        const Build = plus.android.importClass('android.os.Build')
        const main = plus.android.runtimeMainActivity()
        const Context = plus.android.importClass('android.content.Context')
        const nm = main.getSystemService(Context.NOTIFICATION_SERVICE)

        // 第一层：全局通知已开启 → 直接过
        if (nm.areNotificationsEnabled()) {
            console.log('[Perm] 通知权限已开启（全局开关）')
            // 再确认一下运行时权限（理论上全局开了运行时也开了）
            if (Build.VERSION.SDK_INT >= 33 && !checkPostNotificationsPerm()) {
                // 极少数情况：全局开了但运行时没开 → 补一下请求
                try {
                    const ActivityCompat = plus.android.importClass('androidx.core.app.ActivityCompat')
                    const Manifest = plus.android.importClass('android.Manifest')
                    ActivityCompat.requestPermissions(main, [Manifest.permission.POST_NOTIFICATIONS], 1001)
                } catch (_) {}
            }
            return true
        }

        // 第二层：Android 13+ → 先弹系统授权框
        if (Build.VERSION.SDK_INT >= 33) {
            if (!checkPostNotificationsPerm()) {
                try {
                    const ActivityCompat = plus.android.importClass('androidx.core.app.ActivityCompat')
                    const Manifest = plus.android.importClass('android.Manifest')
                    ActivityCompat.requestPermissions(main, [Manifest.permission.POST_NOTIFICATIONS], 1001)
                    console.log('[Perm] 请求通知权限（Android 13+ 系统授权框）')
                } catch(e) {
                    console.warn('[Perm] ActivityCompat.requestPermissions 失败，回退引导设置', e)
                }

                // 关键修复（老版核心逻辑）：
                // Android 13+ 对同一权限生命周期最多弹 2 次系统授权框，
                // 被拒过则 requestPermissions 静默失败（框不出现）。
                // 延迟 1.5s 复查：仍未授予 → 判断是「被拒/没弹」 → 引导用户去设置页手动开
                setTimeout(function() {
                    try {
                        const nm2 = plus.android.runtimeMainActivity().getSystemService(Context.NOTIFICATION_SERVICE)
                        if (nm2.areNotificationsEnabled()) {
                            console.log('[Perm] 用户已通过系统授权框授予通知权限 ✅')
                            return
                        }
                        if (!checkPostNotificationsPerm()) {
                            console.warn('[Perm] 1.5s 复查：系统授权框未出现或被拒（系统拒绝记录）→ 引导手动开启')
                            if (guide) {
                                // 收到推送场景：强制引导（用户有动力开启）
                                _guideToSettings('收到了新推送但通知权限未开启，请在设置中手动开启"显示通知"以查看消息')
                            } else {
                                // 冷启动场景：仅首次引导，避免每次启动都打扰
                                try {
                                    var guided = uni.getStorageSync('push_perm_guided')
                                    if (!guided) {
                                        uni.setStorageSync('push_perm_guided', 1)
                                        _guideToSettings()
                                    } else {
                                        console.log('[Perm] 冷启动已引导过一次，跳过（用户去设置里可手动开启）')
                                    }
                                } catch(_) {
                                    // 存储失败也强制引导一次
                                    _guideToSettings()
                                }
                            }
                        }
                    } catch(e) {
                        console.warn('[Perm] 1.5s 复查异常', e)
                    }
                }, 1500)
                return false
            }
        }

        // 第三层：<Android 13 全局开关关闭，或运行时权限已授予但全局仍关闭
        //   - guide=true（收到推送时）：强制弹窗引导
        //   - guide=false（冷启动）：仅首次引导节流
        if (!guide) {
            try {
                var guided2 = uni.getStorageSync('push_perm_guided')
                if (!guided2) {
                    uni.setStorageSync('push_perm_guided', 1)
                    _guideToSettings()
                }
            } catch(_) {}
            return false
        }

        console.log('[Perm] 通知权限未开启（guide=true），引导用户去设置')
        _guideToSettings('收到了新推送但通知权限未开启，请在设置中开启通知以查看消息')
        return false
    } catch(e) {
        console.warn('[Perm] requestNotificationPerm 顶层异常', e)
        return false
    }
}

export function checkBatteryOpt() {
    try {
        if (typeof plus === 'undefined') return true
        const main = plus.android.runtimeMainActivity()
        const Context = plus.android.importClass('android.content.Context')
        const pm = main.getSystemService(Context.POWER_SERVICE)
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
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        main.startActivity(intent)
    } catch(e) { console.warn('[Perm] openSystemSetting fail', e) }
}

// 跳 APP 级通知设置页（比单渠道设置页更上层，有"显示通知"总开关）
export function openNotificationSetting() {
    try {
        if (typeof plus === 'undefined') return
        const main = plus.android.runtimeMainActivity()
        const Intent = plus.android.importClass('android.content.Intent')
        const Settings = plus.android.importClass('android.provider.Settings')
        const Build = plus.android.importClass('android.os.Build')
        let intent
        if (Build.VERSION.SDK_INT >= 26) {
            intent = new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
            intent.putExtra(Settings.EXTRA_APP_PACKAGE, main.getPackageName())
        } else {
            intent = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS)
            const Uri = plus.android.importClass('android.net.Uri')
            intent.setData(Uri.parse('package:' + main.getPackageName()))
        }
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        main.startActivity(intent)
    } catch(e) {
        console.warn('[Perm] openNotificationSetting 直接路径失败，回退系统设置页', e)
        openSystemSetting()
    }
}

// 兼容别名（老版本代码可能用这个名字）
export function openNotificationSettings() {
    openNotificationSetting()
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
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        main.startActivity(intent)
    } catch(e) {
        try {
            const main2 = plus.android.runtimeMainActivity()
            const Intent2 = plus.android.importClass('android.content.Intent')
            const Settings2 = plus.android.importClass('android.provider.Settings')
            const i = new Intent2(Settings2.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)
            i.addFlags(Intent2.FLAG_ACTIVITY_NEW_TASK)
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
