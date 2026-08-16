import { PUSH_THEME } from './storage.js'

const _listeners = []
let _theme = ''

const DARK = 'dark'
const LIGHT = 'light'
const FLAT = 'flat'

function _readFromStorage() {
    try {
        const v = uni.getStorageSync(PUSH_THEME)
        if (v === LIGHT || v === DARK || v === FLAT) return v
    } catch(e) {}
    return DARK
}

export function getTheme() {
    if (!_theme) _theme = _readFromStorage()
    return _theme
}

export function isDark() { return getTheme() === DARK }
export function isLight() { return getTheme() === LIGHT }
export function isFlat() { return getTheme() === FLAT }

export function setTheme(theme) {
    const t = (theme === DARK || theme === LIGHT || theme === FLAT) ? theme : DARK
    _theme = t
    try { uni.setStorageSync(PUSH_THEME, t) } catch(e) {}
    applyTheme()
    for (let i = 0; i < _listeners.length; i++) {
        try { _listeners[i](t) } catch(e) {}
    }
}

export function toggleTheme() {
    setTheme(isLight() ? DARK : (isDark() ? FLAT : LIGHT))
}

export function onThemeChange(cb) {
    if (typeof cb === 'function' && _listeners.indexOf(cb) === -1) {
        _listeners.push(cb)
    }
}

export function offThemeChange(cb) {
    if (typeof cb === 'undefined' || cb === null) {
        _listeners.length = 0
        return
    }
    const i = _listeners.indexOf(cb)
    if (i !== -1) _listeners.splice(i, 1)
}

export function applyTheme() {
    const t = getTheme()
    try {
        if (typeof document !== 'undefined' && document.documentElement) {
            document.documentElement.setAttribute('data-theme', t)
            document.body && document.body.setAttribute('data-theme', t)
        }
    } catch(e) {}
    for (let i = 0; i < _listeners.length; i++) {
        try { _listeners[i](t) } catch(e) {}
    }
}
