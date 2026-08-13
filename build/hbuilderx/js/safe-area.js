let _lastSBH = -1
let _lastTBH = -1

function _compute() {
    try {
        var sys = uni.getSystemInfoSync()
        var ratio = sys.screenWidth / 750
        var sbPx = sys.statusBarHeight || 20
        var sbH = Math.round(sbPx / ratio)
        if (sbH < 40) sbH = 60
        var safeBottom = (sys.safeAreaInsets && sys.safeAreaInsets.bottom) || 0
        var tabBarPx = 50 + safeBottom
        var tabBarH = Math.round(tabBarPx / ratio) + 20
        if (tabBarH < 100) tabBarH = 130
        return { statusBarH: sbH, tabBarH: tabBarH, safeBottom: safeBottom }
    } catch (e) {
        return { statusBarH: 88, tabBarH: 150, safeBottom: 0 }
    }
}

export function getSafeArea() { return _compute() }

export function applySafeArea() {
    var sa = _compute()
    if (sa.statusBarH === _lastSBH && sa.tabBarH === _lastTBH) return sa
    _lastSBH = sa.statusBarH
    _lastTBH = sa.tabBarH
    try {
        var pages = getCurrentPages()
        if (pages.length > 0) {
            var page = pages[pages.length - 1]
            if (page.$el && page.$el.style) {
                page.$el.style.setProperty('--status-bar-h', sa.statusBarH + 'rpx')
                page.$el.style.setProperty('--tabbar-h', sa.tabBarH + 'rpx')
            }
            if (page.$vm && page.$vm.$el && page.$vm.$el.style) {
                page.$vm.$el.style.setProperty('--status-bar-h', sa.statusBarH + 'rpx')
                page.$vm.$el.style.setProperty('--tabbar-h', sa.tabBarH + 'rpx')
            }
        }
    } catch (e) {}
    return sa
}
