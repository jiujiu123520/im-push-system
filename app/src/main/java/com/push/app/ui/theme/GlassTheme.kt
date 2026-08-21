package com.push.app.ui.theme

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.push.app.data.PreferencesManager
import com.push.app.ui.components.GlassCard as _GlassCard
import com.push.app.ui.components.GlassTopBar as _GlassTopBar

// 对比度保底：三种主题下所有次级信息（onSurfaceVariant）都要在对应背景上 ≥ 4.5:1
private val DarkColorScheme = darkColorScheme(
    primary = Primary,
    onPrimary = Color.White,
    secondary = Secondary,
    onSecondary = Color(0xFF05212b),
    tertiary = Accent,
    onTertiary = Color.White,
    background = Color(0xFF0b0b1a),
    onBackground = Color(0xFFf3f3fb),
    surface = Color(0xFF14142a),
    onSurface = Color(0xFFf3f3fb),
    surfaceVariant = Color(0xFF232344),
    // 关键：次级文字/图标不再是暗灰叠深底
    onSurfaceVariant = Color(0xFFd6d6ea),
    outline = Color(0xFF8a8ab0),
    outlineVariant = Color(0xFF4e4e77),
    scrim = Color(0xCC000000),
)

private val LightColorScheme = lightColorScheme(
    primary = Primary,
    onPrimary = Color.White,
    secondary = Secondary,
    onSecondary = Color(0xFF05212b),
    tertiary = Accent,
    onTertiary = Color.White,
    background = Color(0xFFf5f5fb),
    onBackground = Color(0xFF111118),
    surface = Color.White,
    onSurface = Color(0xFF111118),
    surfaceVariant = Color(0xFFeceaf7),
    // 关键：次级文字在浅色卡片上保持清晰，不使用浅灰
    onSurfaceVariant = Color(0xFF3a3a4a),
    outline = Color(0xFF8b8b9c),
    outlineVariant = Color(0xFFc4c4d4),
    scrim = Color(0x33000000),
)

private val FlatGradientColorScheme = darkColorScheme(
    primary = Primary,
    onPrimary = Color.White,
    secondary = Secondary,
    onSecondary = Color(0xFF05212b),
    tertiary = Accent,
    onTertiary = Color.White,
    background = Color(0xFF160c3a),
    onBackground = Color(0xFFf5f2ff),
    surface = Color(0xFF211452),
    onSurface = Color(0xFFf5f2ff),
    surfaceVariant = Color(0xFF321f70),
    // 扁平渐变：次级文字提升到接近白色的紫，避免叠在紫黑渐变上看不见
    onSurfaceVariant = Color(0xFFd9d2ff),
    outline = Color(0xFFb0a8ea),
    outlineVariant = Color(0xFF6e63b6),
    scrim = Color(0xCC000000),
)

enum class ThemeMode { DARK, LIGHT, FLAT }

fun parseTheme(raw: String?): ThemeMode = when (raw?.lowercase()) {
    "light" -> ThemeMode.LIGHT
    "flat", "gradient", "flat_gradient" -> ThemeMode.FLAT
    else -> ThemeMode.DARK
}

// 在主题里统一加粗次级字号，避免"头发丝"小字；只加字重不加尺寸，保证布局不变
private val PushReadableTypography: Typography
    @Composable get() = with(MaterialTheme.typography) {
        copy(
            bodyLarge = bodyLarge.copy(
                fontWeight = FontWeight.Medium,
            ),
            bodyMedium = bodyMedium.copy(
                fontWeight = FontWeight.Medium,
                lineHeight = 22.sp,
            ),
            bodySmall = TextStyle(
                fontWeight = FontWeight.Medium,
                fontSize = 12.sp,
                lineHeight = 18.sp,
                letterSpacing = 0.sp,
            ),
            titleMedium = titleMedium.copy(
                fontWeight = FontWeight.SemiBold,
            ),
            titleSmall = TextStyle(
                fontWeight = FontWeight.SemiBold,
                fontSize = 15.sp,
                lineHeight = 20.sp,
            ),
            labelLarge = TextStyle(
                fontWeight = FontWeight.SemiBold,
                fontSize = 15.sp,
                lineHeight = 20.sp,
                letterSpacing = 0.sp,
            ),
            labelMedium = labelMedium.copy(
                fontWeight = FontWeight.SemiBold,
            ),
            labelSmall = TextStyle(
                fontWeight = FontWeight.SemiBold,
                fontSize = 11.sp,
                lineHeight = 16.sp,
                letterSpacing = 0.sp,
            ),
        )
    }

@Composable
fun PushTheme(
    content: @Composable () -> Unit,
) {
    var theme by remember { mutableStateOf(ThemeMode.DARK) }
    val themeFlow = runCatching { PreferencesManager.themeFlow }.getOrNull()
    val currentTheme by themeFlow?.collectAsState(initial = "dark")
        ?: remember { mutableStateOf("dark") }

    LaunchedEffect(currentTheme) {
        theme = parseTheme(currentTheme)
    }

    val colorScheme = when (theme) {
        ThemeMode.DARK -> DarkColorScheme
        ThemeMode.LIGHT -> LightColorScheme
        ThemeMode.FLAT -> FlatGradientColorScheme
    }

    MaterialTheme(
        colorScheme = colorScheme,
        typography = PushReadableTypography,
        content = content,
    )
}

@Composable
fun GlassBackground(
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit = {},
) {
    val bgColor = MaterialTheme.colorScheme.background
    val isLight = with(MaterialTheme.colorScheme) { isLightTheme() }

    Box(
        modifier = modifier
            .fillMaxSize()
            .background(color = bgColor)
            .drawBehind {
                if (isLight) return@drawBehind

                val orbColors = listOf(
                    Primary.copy(alpha = 0.22f),
                    Secondary.copy(alpha = 0.18f),
                    Accent.copy(alpha = 0.14f),
                    BrandBlue.copy(alpha = 0.16f),
                    BrandPurple.copy(alpha = 0.16f),
                )
                val positions = listOf(
                    Offset(size.width * 0.15f, size.height * 0.2f),
                    Offset(size.width * 0.85f, size.height * 0.35f),
                    Offset(size.width * 0.5f, size.height * 0.7f),
                    Offset(size.width * 0.3f, size.height * 0.85f),
                    Offset(size.width * 0.8f, size.height * 0.15f),
                )
                val radii = listOf(
                    size.minDimension * 0.28f,
                    size.minDimension * 0.22f,
                    size.minDimension * 0.25f,
                    size.minDimension * 0.18f,
                    size.minDimension * 0.20f,
                )

                orbColors.forEachIndexed { index, color ->
                    drawCircle(
                        brush = Brush.radialGradient(
                            colors = listOf(color, Color.Transparent),
                            center = positions[index],
                            radius = radii[index],
                        ),
                        radius = radii[index],
                        center = positions[index],
                    )
                }
            },
    ) {
        content()
    }
}

@Composable
fun GlassCard(
    modifier: Modifier = Modifier,
    onClick: (() -> Unit)? = null,
    elevation: androidx.compose.material3.CardElevation = androidx.compose.material3.CardDefaults.cardElevation(defaultElevation = 0.dp),
    content: @Composable () -> Unit,
) {
    _GlassCard(modifier = modifier, onClick = onClick, elevation = elevation, content = content)
}

@Composable
fun GlassTopBar(
    title: String,
    onBack: (() -> Unit)? = null,
    modifier: Modifier = Modifier,
) {
    _GlassTopBar(title = title, onBack = onBack, modifier = modifier)
}
