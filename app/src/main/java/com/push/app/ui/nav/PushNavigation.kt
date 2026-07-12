package com.push.app.ui.nav

import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import com.push.app.data.PushRepository
import com.push.app.ui.screen.HomeScreen
import com.push.app.ui.screen.KeyInputScreen
import com.push.app.ui.screen.MessageListScreen
import com.push.app.ui.screen.SettingsScreen

/**
 * 路由常量定义。
 */
object Routes {
    const val KEY_INPUT = "key_input"
    const val HOME = "home"
    const val MESSAGES = "messages"
    const val SETTINGS = "settings"
}

/**
 * 底部导航项。
 */
private data class BottomItem(
    val route: String,
    val label: String,
    val icon: ImageVector,
)

private val bottomItems = listOf(
    BottomItem(Routes.HOME, "首页", Icons.Filled.Home),
    BottomItem(Routes.MESSAGES, "消息", Icons.Filled.List),
    BottomItem(Routes.SETTINGS, "设置", Icons.Filled.Settings),
)

/**
 * 应用导航入口。
 *
 * 默认起始页为首页 [Routes.HOME]；若未配置 Key，自动跳转到 [Routes.KEY_INPUT]。
 * 底部导航仅在首页 / 消息 / 设置页显示，Key 输入页不显示。
 */
@Composable
fun PushNavHost() {
    val navController = rememberNavController()
    val context = LocalContext.current
    val repo = PushRepository.get(context)

    // 监听 Key 状态：为空时跳转到 Key 输入页
    val key by repo.preferencesManager.keyFlow.collectAsState(initial = "")
    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = navBackStackEntry?.destination?.route

    LaunchedEffect(key) {
        if (key.isBlank() && currentRoute != Routes.KEY_INPUT) {
            navController.navigate(Routes.KEY_INPUT) {
                popUpTo(Routes.HOME) { inclusive = true }
                launchSingleTop = true
            }
        }
    }

    // 是否展示底部导航栏
    val showBottomBar = currentRoute in setOf(Routes.HOME, Routes.MESSAGES, Routes.SETTINGS)

    Scaffold(
        bottomBar = {
            if (showBottomBar) {
                PushBottomBar(navController, currentRoute)
            }
        },
    ) { padding ->
        NavHost(
            navController = navController,
            startDestination = Routes.HOME,
            modifier = Modifier
                .fillMaxSize()
                .padding(padding),
        ) {
            composable(Routes.KEY_INPUT) {
                KeyInputScreen(
                    onSaved = {
                        navController.navigate(Routes.HOME) {
                            popUpTo(Routes.HOME) { inclusive = true }
                            launchSingleTop = true
                        }
                    },
                )
            }
            composable(Routes.HOME) {
                HomeScreen(
                    onNavigateToMessages = { navController.navigate(Routes.MESSAGES) },
                    onNavigateToSettings = { navController.navigate(Routes.SETTINGS) },
                )
            }
            composable(Routes.MESSAGES) {
                MessageListScreen()
            }
            composable(Routes.SETTINGS) {
                SettingsScreen()
            }
        }
    }
}

/**
 * 底部导航栏。
 */
@Composable
private fun PushBottomBar(navController: NavHostController, currentRoute: String?) {
    NavigationBar {
        bottomItems.forEach { item ->
            val selected = currentRoute == item.route
            NavigationBarItem(
                selected = selected,
                onClick = {
                    if (!selected) {
                        navController.navigate(item.route) {
                            // 返回栈只保留到起始页，避免重复堆叠
                            popUpTo(navController.graph.findStartDestination().id) {
                                saveState = true
                            }
                            launchSingleTop = true
                            restoreState = true
                        }
                    }
                },
                icon = { Icon(item.icon, contentDescription = item.label) },
                label = { Text(item.label) },
            )
        }
    }
}
