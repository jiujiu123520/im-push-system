/**
 * Toast 显示适配（解决 uni.showToast 长文本被截断）
 *
 * 背景：App 端 uni.showToast 携带 icon(success/error) 时，
 *       title 最多显示约 7 个全角字符，超出部分直接丢弃，
 *       例如“测试推送已发送，请留意通知栏”会被截成“测试推送已发…”；
 *       icon:'none' 时可换行显示约两行，长文本基本完整可见。
 * 方案：在 main.js 入口安装全局补丁，统一拦截 uni.showToast——
 *       1) 带图标且文本超长时自动降级为 icon:'none'；
 *       2) 未显式指定 duration 时按文本长度自动延长展示时间。
 *       旧代码里的 uni.showToast 调用无需逐处修改。
 */

/** 可视宽度：CJK 全角按 1 计，ASCII 半角按 0.5 计 */
function visibleLen(text) {
    const s = String(text == null ? '' : text)
    let n = 0
    for (const ch of s) {
        n += /[\u2E80-\u9FFF\uF900-\uFAFF\uFF00-\uFFEF\u3000-\u303F]/.test(ch) ? 1 : 0.5
    }
    return n
}

/** 带图标的 Toast 最多容纳约 7 个全角字符，超出降级为纯文本，保证完整显示 */
function normalizeIcon(title, icon) {
    if ((icon === 'success' || icon === 'error') && visibleLen(title) > 7) return 'none'
    return icon || 'none'
}

/** 文本越长展示越久，避免长提示还没读完就消失 */
function normalizeDuration(title, duration) {
    if (typeof duration === 'number' && duration > 0) return duration
    const len = visibleLen(title)
    if (len > 20) return 3000
    if (len > 10) return 2200
    return 1600
}

/** 统一 Toast：参数与 uni.showToast 完全兼容 */
export function showToast(options) {
    const opts = typeof options === 'string' ? { title: options } : (options || {})
    const title = String(opts.title == null ? '' : opts.title)
    const fixed = {
        title,
        icon: normalizeIcon(title, opts.icon),
        duration: normalizeDuration(title, opts.duration)
    }
    uni.$rawShowToast(Object.assign({}, opts, fixed))
}

/**
 * 安装全局补丁（幂等）。
 * 将原生 uni.showToast 备份到 uni.$rawShowToast，再替换为适配版。
 */
export function installToastPatch() {
    if (typeof uni === 'undefined') return
    if (uni.$rawShowToast) return
    try {
        uni.$rawShowToast = uni.showToast.bind(uni)
        uni.showToast = showToast
    } catch (e) {
        // 平台不允许覆写时静默失败，退化为原生行为
    }
}
