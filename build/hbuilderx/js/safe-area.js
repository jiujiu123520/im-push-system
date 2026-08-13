let _cached = null

export function getSafeArea() {
    if (_cached) return _cached
    try {
        var sys = uni.getSystemInfoSync()
        var ratio = sys.screenWidth / 750
        var sbH = Math.round(sys.statusBarHeight / ratio)
        var safeBottom = (sys.safeAreaInsets && sys.safeAreaInsets.bottom) || 0
        var tabBarPx = 50 + safeBottom
        var tabBarH = Math.round(tabBarPx / ratio) + 20
        if (sbH < 40) sbH = 60
        _cached = { statusBarH: sbH, tabBarH: tabBarH, tabBarPx: tabBarPx, safeBottom: safeBottom }
    } catch (e) {
        _cached = { statusBarH: 88, tabBarH: 150, tabBarPx: 84, safeBottom: 0 }
    }
    return _cached
}

export function applySafeArea() {
    var sa = getSafeArea()
    try {
        var pages = getCurrentPages()
        if (pages.length > 0 && pages[pages.length - 1].$el) {
            var el = pages[pages.length - 1].$el
            el.style.setProperty('--status-bar-h', sa.statusBarH + 'rpx')
            el.style.setProperty('--tabbar-h', sa.tabBarH + 'rpx')
        }
    } catch (e) {}
    return sa
}