// 设备 ID 稳定模块（移植自老版策略）
// 解决：换包名/签名/卸载重装导致设备 ID 随机变化的问题
//   1. 优先从本地存储读取
//   2. 本地无则从外部存储公共目录恢复（/sdcard/PushApp/.device_id，跨包名共享）
//   3. 都没有才生成新 ID（基于 androidId 哈希 —— 同设备永远生成同一 ID；取不到才随机 UUID）
//   4. 生成后同时写入本地存储 + 外部存储备份
let _cachedId = ''

export function getDeviceId() {
    if (_cachedId) return _cachedId

    // 1. 本地存储
    let deviceId = ''
    try { deviceId = uni.getStorageSync('push_device_id') || '' } catch(e) {}

    // 2. 外部存储恢复（Android 10+ 可能无权限，失败静默）
    if (!deviceId) {
        deviceId = _readExternal()
        if (deviceId) console.log('[DeviceID] 从外部存储恢复:', deviceId)
    }

    // 3. 生成新 ID：androidId 哈希优先（同设备重装后 ID 仍不变）
    if (!deviceId) {
        const hw = _androidId()
        if (hw) {
            deviceId = 'app-' + _stableHash(hw + 'pushapp')
            console.log('[DeviceID] 基于 androidId 生成:', deviceId)
        } else {
            deviceId = 'app-' + _uuid16()
            console.log('[DeviceID] 随机生成（androidId 不可用）:', deviceId)
        }
    }

    // 4. 双写持久化
    try { uni.setStorageSync('push_device_id', deviceId) } catch(e) {}
    _writeExternal(deviceId)

    _cachedId = deviceId
    return deviceId
}

// 取 androidId（多路径容错）
function _androidId() {
    // #ifdef APP-PLUS
    try {
        const Settings = plus.android.importClass('android.provider.Settings$Secure')
        const main = plus.android.runtimeMainActivity()
        const id = Settings.getString(main.getContentResolver(), 'android_id')
        if (id && String(id).length >= 8) return String(id)
    } catch(e) {}
    // #endif
    try {
        const info = uni.getSystemInfoSync()
        const id = info.androidId || info.deviceId || ''
        if (id && String(id).length >= 8) return String(id)
    } catch(e) {}
    return ''
}

// 外部存储读写（/sdcard/PushApp/.device_id）
function _externalPath() {
    // #ifdef APP-PLUS
    try {
        const Environment = plus.android.importClass('android.os.Environment')
        const File = plus.android.importClass('java.io.File')
        const sdCard = Environment.getExternalStorageDirectory().getAbsolutePath()
        return { dir: sdCard + '/PushApp', file: sdCard + '/PushApp/.device_id', File: File }
    } catch(e) {}
    // #endif
    return null
}

function _readExternal() {
    // #ifdef APP-PLUS
    try {
        const p = _externalPath()
        if (!p) return ''
        const file = new p.File(p.file)
        if (!file.exists()) return ''
        const FileInputStream = plus.android.importClass('java.io.FileInputStream')
        const BufferedReader = plus.android.importClass('java.io.BufferedReader')
        const InputStreamReader = plus.android.importClass('java.io.InputStreamReader')
        const reader = new BufferedReader(new InputStreamReader(new FileInputStream(file), 'UTF-8'))
        const id = (reader.readLine() || '').trim()
        reader.close()
        return id
    } catch(e) { return '' }
    // #endif
    return ''
}

function _writeExternal(deviceId) {
    // #ifdef APP-PLUS
    try {
        const p = _externalPath()
        if (!p) return
        const dir = new p.File(p.dir)
        if (!dir.exists()) dir.mkdirs()
        const FileOutputStream = plus.android.importClass('java.io.FileOutputStream')
        const fos = new FileOutputStream(p.file)
        const bytes = plus.android.invoke('java.lang.String', 'getBytes', deviceId, 'UTF-8')
        fos.write(bytes)
        fos.close()
    } catch(e) {
        // Android 10+ 无外部存储权限时静默失败，不影响使用（androidId 哈希路径已保证稳定）
    }
    // #endif
}

// 稳定哈希（同输入永远同输出）
function _stableHash(str) {
    let h1 = 0xdeadbeef ^ 0
    let h2 = 0x41c6ce57 ^ 0
    for (let i = 0; i < str.length; i++) {
        const ch = str.charCodeAt(i)
        h1 = Math.imul(h1 ^ ch, 2654435761)
        h2 = Math.imul(h2 ^ ch, 1597334677)
    }
    h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909)
    h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909)
    const hash = 4294967296 * (2097151 & h2) + (h1 >>> 0)
    return hash.toString(16).padStart(13, '0').substring(0, 12)
}

function _uuid16() {
    // #ifdef APP-PLUS
    try {
        const uuidClass = plus.android.importClass('java.util.UUID')
        return uuidClass.randomUUID().toString().replace(/-/g, '').substring(0, 16)
    } catch(e) {}
    // #endif
    return Date.now().toString(36) + Math.random().toString(36).substring(2, 10)
}
