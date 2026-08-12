var wakeLock = null

function startKeepAlive() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        var main = plus.android.runtimeMainActivity()
        var pm = main.getSystemService(main.POWER_SERVICE)
        var flags = plus.android.importClass('android.os.PowerManager').PARTIAL_WAKE_LOCK
        wakeLock = pm.newWakeLock(flags, 'PushApp:KeepAlive')
        wakeLock.setReferenceCounted(false)
        wakeLock.acquire(24 * 60 * 60 * 1000)
        console.log('[KeepAlive] WakeLock acquired')
    } catch(e) {
        console.warn('[KeepAlive] start fail', e)
    }
}

function stopKeepAlive() {
    try {
        if (wakeLock) {
            if (wakeLock.isHeld()) wakeLock.release()
            wakeLock = null
        }
    } catch(e) {}
}

module.exports = {
    startKeepAlive: startKeepAlive,
    stopKeepAlive: stopKeepAlive
}
