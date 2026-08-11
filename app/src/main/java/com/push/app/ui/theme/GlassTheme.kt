package com.push.app.ui.theme

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
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

@Composable
fun PushTheme(
    darkTheme: Boolean = true,
    content: @Composable () -> Unit,
) {
    val colorScheme = if (darkTheme) DarkColorScheme else LightColorScheme
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
    Box(
        modifier = modifier
            .fillMaxSize()
            .background(
                brush = Brush.verticalGradient(
                    colors = listOf(BrandDeep, BrandDeep),
                ),
            )
            .drawBehind {
                val orbColors = listOf(
                    Primary.copy(alpha = 0.35f),
                    Secondary.copy(alpha = 0.3f),
                    Accent.copy(alpha = 0.25f),
                    BrandBlue.copy(alpha = 0.25f),
                    BrandPurple.copy(alpha = 0.25f),
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
