import { PUSH_VIBRATE, PUSH_RINGTONE } from './storage.js'
import { checkNotificationPerm, requestNotificationPerm } from './permissions.js'

const CHANNEL_NORMAL = 'push_normal'
const CHANNEL_SILENT = 'push_silent'

export function notify(title, content, priority) {
    if (!title && !content) return
    try {
        if (typeof plus !== 'undefined' && plus.android) {
            if (!checkNotificationPerm()) {
                requestNotificationPerm()
            }
            _nativeNotify(title, content, priority)
        }
    } catch(e) {
        console.warn('[Notify] native fail', e)
    }
}

function _nativeNotify(title, content, priority) {
    const Intent = plus.android.importClass('android.content.Intent')
    const PendingIntent = plus.android.importClass('android.app.PendingIntent')
    const NotificationManager = plus.android.importClass('android.app.NotificationManager')
    const NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
    const Build = plus.android.importClass('android.os.Build')

    const main = plus.android.runtimeMainActivity()
    const nm = main.getSystemService(main.NOTIFICATION_SERVICE)

    const ringtone = uni.getStorageSync(PUSH_RINGTONE) || 'default'
    const isSilent = ringtone === 'silent'
    const channelId = isSilent ? CHANNEL_SILENT : CHANNEL_NORMAL

    if (Build.VERSION.SDK_INT >= 26) {
        const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')

        if (!isSilent) {
            const chNormal = new NotificationChannel(CHANNEL_NORMAL, '推送提醒 · 默认铃声', NotificationManager.IMPORTANCE_DEFAULT)
            chNormal.enableVibration(true)
            nm.createNotificationChannel(chNormal)
        }

        const chSilent = new NotificationChannel(CHANNEL_SILENT, '推送提醒 · 静默', NotificationManager.IMPORTANCE_LOW)
        chSilent.enableVibration(false)
        chSilent.setSound(null, null)
        nm.createNotificationChannel(chSilent)
    }

    const intent = new Intent(main, main.getClass())
    intent.addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP)
    const pi = PendingIntent.getActivity(main, 0, intent, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE)

    const builder = new NotificationCompat.Builder(main, channelId)
        .setSmallIcon(android.R.drawable.ic_dialog_info)
        .setContentTitle(title)
        .setContentText(content)
        .setAutoCancel(true)
        .setContentIntent(pi)
        .setPriority(priority === 'high' ? NotificationCompat.PRIORITY_HIGH : NotificationCompat.PRIORITY_DEFAULT)

    nm.notify(Date.now() & 0x7fffffff, builder.build())

    if (uni.getStorageSync(PUSH_VIBRATE) !== false) {
        try { uni.vibrateShort({}) } catch(e) {}
    }
}
