import { PUSH_VIBRATE } from './storage.js'

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
    const Intent = plus.android.importClass('android.content.Intent')
    const PendingIntent = plus.android.importClass('android.app.PendingIntent')
    const NotificationManager = plus.android.importClass('android.app.NotificationManager')
    const NotificationCompat = plus.android.importClass('androidx.core.app.NotificationCompat')
    const Build = plus.android.importClass('android.os.Build')

    const main = plus.android.runtimeMainActivity()
    const nm = main.getSystemService(main.NOTIFICATION_SERVICE)

    const channelId = 'push_alert'
    if (Build.VERSION.SDK_INT >= 26) {
        const NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
        const channel = new NotificationChannel(channelId, '推送提醒', NotificationManager.IMPORTANCE_DEFAULT)
        channel.enableVibration(true)
        nm.createNotificationChannel(channel)
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
