let wakeLock = null

export function startKeepAlive() {
    try {
        if (typeof plus === 'undefined' || !plus.android) return
        const main = plus.android.runtimeMainActivity()
        const pm = main.getSystemService(main.POWER_SERVICE)
        const PowerManager = plus.android.importClass('android.os.PowerManager')
        wakeLock = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, 'PushApp:KeepAlive')
        wakeLock.setReferenceCounted(false)
        wakeLock.acquire(24 * 60 * 60 * 1000)
        console.log('[KeepAlive] WakeLock acquired')
    } catch(e) {
        console.warn('[KeepAlive] start fail', e)
    }
}

export function stopKeepAlive() {
    try {
        if (wakeLock) {
            if (wakeLock.isHeld()) wakeLock.release()
            wakeLock = null
        }
    } catch(e) {}
}
