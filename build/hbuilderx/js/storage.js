const BOOT_KEY = 'push_boot_config'

export function ensureBootConfig() {
    if (!uni.getStorageSync(BOOT_KEY)) {
        uni.setStorageSync(BOOT_KEY, {})
    }
}

export function loadBootConfig() {
    ensureBootConfig()
    return uni.getStorageSync(BOOT_KEY) || {}
}

export function saveBootConfig(key, value) {
    const cfg = loadBootConfig()
    cfg[key] = value
    uni.setStorageSync(BOOT_KEY, cfg)
}

export function clearBootConfig() {
    uni.removeStorageSync(BOOT_KEY)
}

export const PUSH_KEY = 'push_key'
export const PUSH_WS_URL = 'push_ws_url'
export const PUSH_SERVER_URL = 'push_server_url'
export const PUSH_USER_ID = 'push_user_id'
export const PUSH_HEARTBEAT = 'push_heartbeat'
export const PUSH_AUTO_RECONNECT = 'push_auto_reconnect'
export const PUSH_VIBRATE = 'push_vibrate'
export const PUSH_WIFI_ONLY = 'push_wifi_only'
export const PUSH_THEME = 'push_theme'
export const PUSH_RINGTONE = 'push_ringtone'

const STORAGE_KEY = 'push_messages'

export function setMessages(list) {
    try {
        uni.setStorageSync(STORAGE_KEY, JSON.stringify(list))
        return true
    } catch(e) {
        // 写入失败：通常是 storage 容量超限（uni.setStorageSync 单 key 上限约 1MB）
        // 兜底：逐步减少消息数量后重试，确保最新消息能写入
        var half = list.slice(0, Math.max(20, Math.floor(list.length / 2)))
        try {
            uni.setStorageSync(STORAGE_KEY, JSON.stringify(half))
            console.warn('[storage] setMessages 容量超限，已减半至 ' + half.length + ' 条')
            return true
        } catch(e2) {
            var minimal = list.slice(0, 20)
            try {
                uni.setStorageSync(STORAGE_KEY, JSON.stringify(minimal))
                console.error('[storage] setMessages 严重超限，仅保留 20 条')
                return true
            } catch(e3) {
                console.error('[storage] setMessages 完全失败', e3)
                return false
            }
        }
    }
}

export function getMessages() {
    try { return JSON.parse(uni.getStorageSync(STORAGE_KEY) || '[]') } catch(e) { return [] }
}

export function addMessage(msg) {
    var list = getMessages()
    // 去重：按 id 检查是否已存在（避免 HTTP 刷新拉取的重复消息占满 storage）
    if (msg && msg.id) {
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === msg.id) return false
        }
    }
    list.unshift(msg)
    if (list.length > 300) list = list.slice(0, 300)
    return setMessages(list)
}

export function clearMessages() {
    uni.removeStorageSync(STORAGE_KEY)
}
export function markAllRead() {
    var list = getMessages()
    var changed = false
    for (var i = 0; i < list.length; i++) {
        if (!list[i].read) { list[i].read = true; changed = true }
    }
    if (changed) setMessages(list)
}

export function markRead(id) {
    var list = getMessages()
    for (var i = 0; i < list.length; i++) {
        if (list[i].id === id) { list[i].read = true; break }
    }
    setMessages(list)
}

export function deleteMessage(id) {
    var list = getMessages().filter(function(m){ return m.id !== id })
    setMessages(list)
}

export function getMessagesSize() {
    try {
        var raw = uni.getStorageSync(STORAGE_KEY)
        if (!raw) return 0
        if (typeof raw === 'string') return raw.length
        return JSON.stringify(raw).length
    } catch(e) { return 0 }
}

export function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B'
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
    return (bytes / 1024 / 1024).toFixed(2) + ' MB'
}