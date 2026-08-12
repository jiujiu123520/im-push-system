<script>
import { ensureBootConfig, loadBootConfig, saveBootConfig, clearBootConfig } from './js/storage.js'
import { connect, disconnect, reconnect, isConnected } from './js/ws.js'
import { startKeepAlive, stopKeepAlive } from './js/keepalive.js'
import { applyTheme } from './js/theme.js'
import { APP_CONFIG } from './config.js'

export default {
    onLaunch: function () {
        console.log('[PushApp] onLaunch')
        ensureBootConfig()
        saveBootConfig('app_name', APP_CONFIG.app_name)
        saveBootConfig('build_time', APP_CONFIG.build_time || '')
        saveBootConfig('server_url', APP_CONFIG.server_url)
        saveBootConfig('ws_url', APP_CONFIG.ws_url)
        saveBootConfig('default_key', APP_CONFIG.default_key)
        applyTheme()
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
