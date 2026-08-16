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
        var nm = main.getSystemService(main.NOTIFICATION_SERVICE)
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
    var NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
    var Build = plus.android.importClass('android.os.Build')

    var main = plus.android.runtimeMainActivity()
    var nm = main.getSystemService(main.NOTIFICATION_SERVICE)

    var ringtone = 'default'
    try { ringtone = uni.getStorageSync(PUSH_RINGTONE) || 'default' } catch(e) {}
    var isSilent = ringtone === 'silent'
    var channelId = isSilent ? CHANNEL_SILENT : CHANNEL_NORMAL

    if (Build.VERSION.SDK_INT >= 26) {
        var NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
        if (!isSilent) {
            _createNormalChannel(nm, NotificationChannel, NotificationManager)
        }
        _createSilentChannel(nm, NotificationChannel, NotificationManager)
    }

    var intent = new Intent(main, main.getClass())
    intent.addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
    var pi = PendingIntent.getActivity(main, 0, intent, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE)

    var builder = new NotificationCompat.Builder(main, channelId)
        .setSmallIcon(android.R.drawable.ic_dialog_info)
        .setContentTitle(title)
        .setContentText(content)
        .setAutoCancel(true)
        .setContentIntent(pi)
        .setPriority(priority === 'high' ? NotificationCompat.PRIORITY_HIGH : NotificationCompat.PRIORITY_DEFAULT)

    nm.notify(Date.now() & 0x7fffffff, builder.build())

    var vibrateOn = true
    try { vibrateOn = uni.getStorageSync(PUSH_VIBRATE) !== false } catch(e) {}
    if (vibrateOn) {
        try { uni.vibrateShort({}) } catch(e) {}
    }
}
