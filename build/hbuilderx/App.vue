<script>
import { ensureBootConfig, loadBootConfig, saveBootConfig, clearBootConfig,
         PUSH_KEY, PUSH_SERVER_URL, PUSH_WS_URL } from './js/storage.js'
import { connect, disconnect, reconnect, isConnected } from './js/ws.js'
import { startKeepAlive, stopKeepAlive, checkBatteryOptimization, checkXiaomiAutoStart } from './js/keepalive.js'
import { applyTheme } from './js/theme.js'
import { requestNotificationPerm } from './js/permissions.js'
import { ensureChannels } from './js/notify.js'
import * as _cfg from './config.js'

const _DEFAULT_CONFIG = {
    app_name: 'PushApp',
    default_key: 'sQhrgtacqssANoklLtQsKwEOda0es8E7',
    server_url: 'https://api1.98dyy.cn',
    ws_url: 'wss://api1.98dyy.cn/ws/client',
    version_name: '1.0.0',
    build_time: ''
}

const APP_CONFIG = Object.assign({}, _DEFAULT_CONFIG, (_cfg && _cfg.APP_CONFIG) || {})
// config.js 字段为空时保留硬编码兜底（Object.assign 空字符串也会覆盖默认值）
// 同时剥离首尾的反引号/引号/空白（从文档复制地址时 markdown 装饰字符会混入，导致 URL 非法）
Object.keys(_DEFAULT_CONFIG).forEach(function(k) {
    var v = APP_CONFIG[k]
    if (typeof v === 'string') {
        v = v.replace(/^[\s`'"]+|[\s`'"]+$/g, '')
    }
    if (!v || typeof v !== 'string' || v.length < 2 || /example\.com|placeholder/i.test(v)) {
        v = _DEFAULT_CONFIG[k]
    }
    APP_CONFIG[k] = v
})

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
        try { ensureChannels() } catch(e) { console.warn('[PushApp] ensureChannels failed:', e.message) }
    },

    onShow: function () {
        console.log('[PushApp] onShow')
        try { requestNotificationPerm() } catch(e) {}
        var config = loadBootConfig()
        var key = uni.getStorageSync('push_key') || config.default_key || APP_CONFIG.default_key
        var wsUrl = uni.getStorageSync('push_ws_url') || config.ws_url || APP_CONFIG.ws_url

        if (key && wsUrl) {
            startKeepAlive()
            if (!isConnected()) {
                connect(wsUrl, key)
            }
        }

        // 保活权限引导（移植老版：延迟弹出避免和通知权限弹窗叠加）
        // 2 秒后电池优化白名单，4 秒后小米自启动（各自有节流/只提示一次逻辑）
        setTimeout(function() {
            try { checkBatteryOptimization() } catch(e) {}
        }, 2000)
        setTimeout(function() {
            try { checkXiaomiAutoStart() } catch(e) {}
        }, 4000)
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
