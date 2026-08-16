package com.push.app.ui.theme

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.ui.unit.dp
import com.push.app.data.PreferencesManager
import com.push.app.ui.components.GlassCard as _GlassCard
import com.push.app.ui.components.GlassTopBar as _GlassTopBar

private val DarkColorScheme = darkColorScheme(
    primary = Primary,
    secondary = Secondary,
    tertiary = Accent,
    background = BrandDeep,
    surface = Color(0xFF151528),
    surfaceVariant = Color(0xFF1e1e35),
    onPrimary = Color.White,
    onSecondary = Color.Black,
    onBackground = Color.White,
    onSurface = Color.White,
)

private val LightColorScheme = lightColorScheme(
    primary = Primary,
    secondary = Secondary,
    tertiary = Accent,
    background = Color(0xFFf8f8fc),
    surface = Color.White,
    onPrimary = Color.White,
    onSecondary = Color.Black,
    onBackground = Color.Black,
    onSurface = Color.Black,
)

private val FlatGradientColorScheme = darkColorScheme(
    primary = Primary,
    secondary = Secondary,
    tertiary = Accent,
    background = Color(0xFF1a1040),
    surface = Color(0xFF221555),
    surfaceVariant = Color(0xFF2d1d70),
    onPrimary = Color.White,
    onSecondary = Color.White,
    onBackground = Color.White,
    onSurface = Color.White,
)

enum class ThemeMode { DARK, LIGHT, FLAT }

fun parseTheme(raw: String?): ThemeMode = when (raw?.lowercase()) {
    "light" -> ThemeMode.LIGHT
    "flat", "gradient", "flat_gradient" -> ThemeMode.FLAT
    else -> ThemeMode.DARK
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
        content = content,
    )
}

@Composable
fun GlassBackground(
    modifier: Modifier = Modifier,
    content: @Composable () -> Unit = {},
) {
    val bgColor = MaterialTheme.colorScheme.background
    val isLight = MaterialTheme.colorScheme.onBackground == Color.Black

    Box(
        modifier = modifier
            .fillMaxSize()
            .background(
                brush = Brush.verticalGradient(
                    colors = listOf(bgColor, bgColor),
                ),
            )
            .drawBehind {
                if (isLight) return@drawBehind

                val orbColors = listOf(
                    Primary.copy(alpha = 0.25f),
                    Secondary.copy(alpha = 0.20f),
                    Accent.copy(alpha = 0.18f),
                    BrandBlue.copy(alpha = 0.20f),
                    BrandPurple.copy(alpha = 0.20f),
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
                            colors = listOf(color, androidx.compose.ui.graphics.Color.Transparent),
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
