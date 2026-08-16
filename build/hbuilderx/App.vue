<script>
import { ensureBootConfig, loadBootConfig, saveBootConfig, clearBootConfig,
         PUSH_KEY, PUSH_SERVER_URL, PUSH_WS_URL } from './js/storage.js'
import { connect, disconnect, reconnect, isConnected } from './js/ws.js'
import { startKeepAlive, stopKeepAlive } from './js/keepalive.js'
import { applyTheme } from './js/theme.js'
import { APP_CONFIG } from './config.js'

function _looksEmpty(v) {
    if (!v) return true
    if (typeof v !== 'string') return true
    if (v.length < 2) return true
    if (/example\.com|default_key|placeholder/i.test(v)) return true
    return false
}

function _ensureUserConfig(key, defaultValue) {
    try {
        var cur = uni.getStorageSync(key)
        if (_looksEmpty(cur)) {
            uni.setStorageSync(key, defaultValue)
        }
    } catch(e) {}
}

export default {
    onLaunch: function () {
        console.log('[PushApp] onLaunch')
        try {
            ensureBootConfig()
            saveBootConfig('app_name', APP_CONFIG.app_name)
            saveBootConfig('build_time', APP_CONFIG.build_time || '')
            saveBootConfig('server_url', APP_CONFIG.server_url)
            saveBootConfig('ws_url', APP_CONFIG.ws_url)
            saveBootConfig('default_key', APP_CONFIG.default_key)
            saveBootConfig('version_name', APP_CONFIG.version_name)
            saveBootConfig('build_time', APP_CONFIG.build_time || '')
        } catch(e) {
            console.warn('[PushApp] boot config save failed:', e.message)
        }
        try {
            _ensureUserConfig(PUSH_KEY, APP_CONFIG.default_key)
            _ensureUserConfig(PUSH_SERVER_URL, APP_CONFIG.server_url)
            _ensureUserConfig(PUSH_WS_URL, APP_CONFIG.ws_url)
        } catch(e) {
            console.warn('[PushApp] user config ensure failed:', e.message)
        }
        try { applyTheme() } catch(e) { console.warn('[PushApp] applyTheme failed:', e.message) }
    },

    onShow: function () {
        console.log('[PushApp] onShow')
        var config = loadBootConfig()
        var key = uni.getStorageSync('push_key') || config.default_key
        var wsUrl = uni.getStorageSync('push_ws_url') || config.ws_url

        if (key && wsUrl) {
            startKeepAlive()
            if (!isConnected()) {
                connect(wsUrl, key)
            }
        }
    },

    onHide: function () {
        console.log('[PushApp] onHide')
    },

    onError: function (err) {
        console.error('[PushApp] onError', err)
    }
}
</script>

<style>
@import './css/glass.css';
</style>
