function notify(title, content, priority) {
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

    var channelId = 'push_alert'
    if (Build.VERSION.SDK_INT >= 26) {
        var NotificationChannel = plus.android.importClass('android.app.NotificationChannel')
        var channel = new NotificationChannel(channelId, '推送提醒', NotificationManager.IMPORTANCE_DEFAULT)
        channel.enableVibration(true)
        nm.createNotificationChannel(channel)
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

    var storage = require('./storage.js')
    if (uni.getStorageSync(storage.PUSH_VIBRATE) !== false) {
        try { uni.vibrateShort({}) } catch(e) {}
    }
}

module.exports = { notify: notify }
