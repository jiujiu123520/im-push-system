package com.push.app.navigation

import android.content.Context
import android.content.SharedPreferences
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.push.app.ui.theme.BackgroundStart
import com.push.app.ui.theme.GlassBackground
import com.push.app.ui.theme.Primary

object Routes {
    const val LOGIN = "login"
    const val KEY_INPUT = "key_input"
    const val HOME = "home"
    const val MESSAGES = "messages"
    const val PROFILE = "profile"
    const val SETTINGS = "settings"
}

fun hasSavedKey(context: Context): Boolean {
    val prefs: SharedPreferences = context.getSharedPreferences("push_prefs", Context.MODE_PRIVATE)
    return prefs.contains("user_key") && prefs.getString("user_key", "")?.isNotEmpty() == true
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PushNavigation() {
    val navController = rememberNavController()
    val context = LocalContext.current
    val startDestination = if (hasSavedKey(context)) Routes.HOME else Routes.KEY_INPUT

    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = navBackStackEntry?.destination?.route

    val topBarTitle = when (currentRoute) {
        Routes.LOGIN -> "登录"
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
            containerColor = androidx.compose.ui.graphics.Color.Transparent
        ) { innerPadding ->
            NavHost(
                navController = navController,
                startDestination = startDestination,
                modifier = Modifier
                    .fillMaxSize()
                    .padding(innerPadding)
            ) {
                composable(Routes.LOGIN) {
                    PlaceholderScreen(
                        title = "登录",
                        onNavigate = { navController.navigate(Routes.KEY_INPUT) }
                    )
                }
                composable(Routes.KEY_INPUT) {
                    PlaceholderScreen(
                        title = "输入密钥",
                        onNavigate = { navController.navigate(Routes.HOME) }
                    )
                }
                composable(Routes.HOME) {
                    PlaceholderScreen(
                        title = "主页",
                        onNavigate = { navController.navigate(Routes.MESSAGES) }
                    )
                }
                composable(Routes.MESSAGES) {
                    PlaceholderScreen(
                        title = "消息",
                        onNavigate = { navController.navigate(Routes.PROFILE) }
                    )
                }
                composable(Routes.PROFILE) {
                    PlaceholderScreen(
                        title = "个人资料",
                        onNavigate = { navController.navigate(Routes.SETTINGS) }
                    )
                }
                composable(Routes.SETTINGS) {
                    PlaceholderScreen(
                        title = "设置",
                        onNavigate = { navController.popBackStack() }
                    )
                }
            }
        }
    }
}

@Composable
private fun PlaceholderScreen(title: String, onNavigate: () -> Unit) {
    Column(
        modifier = Modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Text(text = title, color = Primary)
    }
}
