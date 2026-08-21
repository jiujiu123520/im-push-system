package com.push.app.ui.theme

import androidx.compose.ui.graphics.Color

val BackgroundStart = Color(0xFF0d0d1a)
val BackgroundEnd = Color(0xFF1a1a2e)

val SurfaceGlass = Color(0x1FFFFFFF)

val Primary = Color(0xFF7c5cfc)
val Secondary = Color(0xFF00d4ff)
val Accent = Color(0xFFff6b9d)

val GlassOverlay = Color(0x14FFFFFF)

val BrandDeep = Color(0xFF0a0a1a)
val BrandBlue = Color(0xFF1a4a8a)
val BrandPurple = Color(0xFF4a2a7a)

val StatusOnline = Color(0xFF00e676)
val StatusWarning = Color(0xFFFFC107)
val StatusOffline = Color(0xFF9E9E9E)

/**
 * 轻量判断当前是否浅色主题（与 GlassTheme.LightColorScheme 的 onBackground == 0xFF111118 对齐）。
 * 避免 GlassCard、各个 Screen 里再写硬编码"白叠白"的对比灾难。
 */
val LightOnBackgroundToken = Color(0xFF111118)

fun androidx.compose.material3.ColorScheme.isLightTheme(): Boolean =
    onBackground == LightOnBackgroundToken

/**
 * 按主题返回"玻璃感"的容器底色/描边：
 *   - 浅色：不透明白底 + 黑色细描边，避免白叠白。
 *   - 暗色：半透明白 + 半透明白描边，同时加强透明度避免糊在背景上。
 */
fun glassLayer(
    isLight: Boolean,
    darkAlpha: Float = 0.10f,
    darkBorderAlpha: Float = 0.18f,
): Pair<Color, Color> {
    return if (isLight) {
        Color(0xCCFFFFFF) to Color(0x1F000000)
    } else {
        androidx.compose.ui.graphics.Color.White.copy(alpha = darkAlpha) to
            androidx.compose.ui.graphics.Color.White.copy(alpha = darkBorderAlpha)
    }
}

/**
 * 按主题返回"小玻璃块"背景色：用于消息气泡底、设置项条底色、设备信息块底。
 *   - 浅色：淡黑灰（0x1A000000 级别）
 *   - 暗色：更高亮的半透明白（比原先 0.04/0.05/0.08 明显）
 */
fun tileGlassBg(alphaDark: Float, isLight: Boolean): Color {
    // 浅色保持温和的分层感
    val lightValue = when {
        alphaDark <= 0.04f -> 0x12000000
        alphaDark <= 0.06f -> 0x16000000
        else -> 0x1E000000
    }
    // 暗色：原来的 alpha 太低几乎隐形，统一乘以 2.8 并夹到 0.42 上限
    val boosted = (alphaDark * 2.8f).coerceAtMost(0.42f)
    return if (isLight) Color(lightValue) else androidx.compose.ui.graphics.Color.White.copy(alpha = boosted)
}
