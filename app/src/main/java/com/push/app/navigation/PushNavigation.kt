package com.push.app.navigation

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.push.app.data.PreferencesManager
import com.push.app.ui.screen.HomeScreen
import com.push.app.ui.screen.KeyInputScreen
import com.push.app.ui.screen.MessageListScreen
import com.push.app.ui.screen.ProfileScreen
import com.push.app.ui.screen.SettingsScreen
import com.push.app.ui.theme.BackgroundStart
import com.push.app.ui.theme.GlassBackground
import com.push.app.ui.theme.Primary
import kotlinx.coroutines.flow.first

object Routes {
    const val KEY_INPUT = "key_input"
    const val HOME = "home"
    const val MESSAGES = "messages"
    const val PROFILE = "profile"
    const val SETTINGS = "settings"
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PushNavigation() {
    val navController = rememberNavController()
    val context = LocalContext.current
    var hasKey by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        hasKey = runCatching { PreferencesManager.getKey() }.getOrDefault("").isNotBlank()
    }

    val startDestination = if (hasKey) Routes.HOME else Routes.KEY_INPUT

    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = navBackStackEntry?.destination?.route

    val topBarTitle = when (currentRoute) {
        Routes.KEY_INPUT -> "输入密钥"
        Routes.HOME -> "主页"
        Routes.MESSAGES -> "消息"
        Routes.PROFILE -> "个人资料"
        Routes.SETTINGS -> "设置"
        else -> "PushApp"
    }

    GlassBackground {
        Scaffold(
            topBar = {
                TopAppBar(
                    title = { Text(text = topBarTitle) },
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = BackgroundStart.copy(alpha = 0.85f),
                        titleContentColor = Primary
                    )
                )
            },
            containerColor = Color.Transparent
        ) { innerPadding ->
            NavHost(
                navController = navController,
                startDestination = startDestination,
                modifier = Modifier
                    .fillMaxSize()
                    .padding(innerPadding)
            ) {
                composable(Routes.KEY_INPUT) {
                    KeyInputScreen(
                        onSaved = {
                            navController.navigate(Routes.HOME) {
                                popUpTo(Routes.KEY_INPUT) { inclusive = true }
                            }
                        }
                    )
                }
                composable(Routes.HOME) {
                    HomeScreen(
                        onNavigateToMessages = { navController.navigate(Routes.MESSAGES) },
                        onNavigateToSettings = { navController.navigate(Routes.SETTINGS) }
                    )
                }
                composable(Routes.MESSAGES) {
                    MessageListScreen()
                }
                composable(Routes.PROFILE) {
                    ProfileScreen(
                        onNavigateToMessages = { navController.navigate(Routes.MESSAGES) },
                        onNavigateToSettings = { navController.navigate(Routes.SETTINGS) },
                        onNavigateToLogin = {
                            navController.navigate(Routes.KEY_INPUT) {
                                popUpTo(0) { inclusive = true }
                            }
                        }
                    )
                }
                composable(Routes.SETTINGS) {
                    SettingsScreen(
                        onBack = { navController.popBackStack() }
                    )
                }
            }
        }
    }
}
