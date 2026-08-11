var BOOT_KEY = 'push_boot_config'

function ensureBootConfig() {
    if (!uni.getStorageSync(BOOT_KEY)) {
        uni.setStorageSync(BOOT_KEY, {})
    }
}

function loadBootConfig() {
    ensureBootConfig()
    return uni.getStorageSync(BOOT_KEY) || {}
}

function saveBootConfig(key, value) {
    var cfg = loadBootConfig()
    cfg[key] = value
    uni.setStorageSync(BOOT_KEY, cfg)
}

function clearBootConfig() {
    uni.removeStorageSync(BOOT_KEY)
}

var PUSH_KEY = 'push_key'
var PUSH_WS_URL = 'push_ws_url'
var PUSH_SERVER_URL = 'push_server_url'
var PUSH_USER_TOKEN = 'push_user_token'
var PUSH_USER_ID = 'push_user_id'
var PUSH_HEARTBEAT = 'push_heartbeat'
var PUSH_AUTO_RECONNECT = 'push_auto_reconnect'
var PUSH_VIBRATE = 'push_vibrate'
var PUSH_WIFI_ONLY = 'push_wifi_only'
var PUSH_THEME = 'push_theme'

var STORAGE_KEY = 'push_messages'

function setMessages(list) {
    try { uni.setStorageSync(STORAGE_KEY, JSON.stringify(list)) } catch(e) {}
}

function getMessages() {
    try { return JSON.parse(uni.getStorageSync(STORAGE_KEY) || '[]') } catch(e) { return [] }
}

function addMessage(msg) {
    var list = getMessages()
    list.unshift(msg)
    if (list.length > 200) list = list.slice(0, 200)
    setMessages(list)
}

function clearMessages() {
    uni.removeStorageSync(STORAGE_KEY)
}

module.exports = {
    ensureBootConfig: ensureBootConfig,
    loadBootConfig: loadBootConfig,
    saveBootConfig: saveBootConfig,
    clearBootConfig: clearBootConfig,
    BOOT_KEY: BOOT_KEY,
    PUSH_KEY: PUSH_KEY,
    PUSH_WS_URL: PUSH_WS_URL,
    PUSH_SERVER_URL: PUSH_SERVER_URL,
    PUSH_USER_TOKEN: PUSH_USER_TOKEN,
    PUSH_USER_ID: PUSH_USER_ID,
    PUSH_HEARTBEAT: PUSH_HEARTBEAT,
    PUSH_AUTO_RECONNECT: PUSH_AUTO_RECONNECT,
    PUSH_VIBRATE: PUSH_VIBRATE,
    PUSH_WIFI_ONLY: PUSH_WIFI_ONLY,
    PUSH_THEME: PUSH_THEME,
    setMessages: setMessages,
    getMessages: getMessages,
    addMessage: addMessage,
    clearMessages: clearMessages
}
