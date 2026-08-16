import { PUSH_VIBRATE, PUSH_RINGTONE } from './storage.js'

const CHANNEL_NORMAL = 'push_normal'
const CHANNEL_SILENT = 'push_silent'

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

        var chNormal = new NotificationChannel(CHANNEL_NORMAL, '推送提醒 · 默认铃声', NotificationManager.IMPORTANCE_DEFAULT)
        chNormal.enableVibration(true)
        nm.createNotificationChannel(chNormal)

        var chSilent = new NotificationChannel(CHANNEL_SILENT, '推送提醒 · 静默', NotificationManager.IMPORTANCE_LOW)
        chSilent.enableVibration(false)
        chSilent.setSound(null, null)
        nm.createNotificationChannel(chSilent)
    } catch(e) {
        console.warn('[Notify] ensureChannels fail', e)
    }
}

export function notify(title, content, priority) {
    if (!title && !content) return
    try {
        if (typeof plus !== 'undefined' && plus.android) {
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
            var chNormal = new NotificationChannel(CHANNEL_NORMAL, '推送提醒 · 默认铃声', NotificationManager.IMPORTANCE_DEFAULT)
            chNormal.enableVibration(true)
            nm.createNotificationChannel(chNormal)
        }
        var chSilent = new NotificationChannel(CHANNEL_SILENT, '推送提醒 · 静默', NotificationManager.IMPORTANCE_LOW)
        chSilent.enableVibration(false)
        chSilent.setSound(null, null)
        nm.createNotificationChannel(chSilent)
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
