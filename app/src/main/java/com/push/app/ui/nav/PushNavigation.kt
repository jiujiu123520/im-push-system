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
import androidx.compose.runtime.remember
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
import com.push.app.ui.screen.LoginScreen
import com.push.app.ui.screen.MessageListScreen
import com.push.app.ui.screen.RegisterScreen
import com.push.app.ui.screen.SettingsScreen
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking

/**
 * 路由常量定义。
 */
object Routes {
    const val KEY_INPUT = "key_input"
    const val HOME = "home"
    const val MESSAGES = "messages"
    const val SETTINGS = "settings"
    const val LOGIN = "login"
    const val REGISTER = "register"
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
 * 鉴权路由策略：
 * - 启动时同步读取 [PreferencesManager.userTokenFlow] 决定起始页：
 *   有 token → [Routes.HOME]；无 token → [Routes.LOGIN]
 * - 运行时监听 [PreferencesManager.userTokenFlow]：token 变空时跳转登录页
 *
 * 同时保留 Key 鉴权流程：未配置 Key 时跳转 [Routes.KEY_INPUT]。
 * 底部导航仅在首页 / 消息 / 设置页显示，Key 输入页与登录/注册页不显示。
 */
@Composable
fun PushNavHost() {
    val navController = rememberNavController()
    val context = LocalContext.current
    val repo = PushRepository.get(context)

    // 启动时同步读取 token 决定起始页，避免闪烁
    val startDestination = remember {
        runBlocking {
            val token = repo.preferencesManager.userTokenFlow.first()
            if (token.isNotBlank()) Routes.HOME else Routes.LOGIN
        }
    }

    // 监听 Key 与 Token 状态
    val key by repo.preferencesManager.keyFlow.collectAsState(initial = "")
    val userToken by repo.preferencesManager.userTokenFlow.collectAsState(initial = "")
    val navBackStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = navBackStackEntry?.destination?.route

    // Key 为空且当前不在登录/注册页时，跳转 Key 输入页
    LaunchedEffect(key) {
        if (key.isBlank() && currentRoute != Routes.KEY_INPUT &&
            currentRoute != Routes.LOGIN && currentRoute != Routes.REGISTER
        ) {
            navController.navigate(Routes.KEY_INPUT) {
                popUpTo(Routes.HOME) { inclusive = true }
                launchSingleTop = true
            }
        }
    }

    // 监听 userToken 变化：有 token → 跳转 HOME；无 token → 跳转 LOGIN
    LaunchedEffect(userToken) {
        if (userToken.isBlank() && currentRoute != Routes.LOGIN && currentRoute != Routes.REGISTER) {
            navController.navigate(Routes.LOGIN) {
                popUpTo(Routes.HOME) { inclusive = true }
                launchSingleTop = true
            }
        } else if (userToken.isNotBlank() &&
            (currentRoute == Routes.LOGIN || currentRoute == Routes.REGISTER)
        ) {
            navController.navigate(Routes.HOME) {
                popUpTo(Routes.LOGIN) { inclusive = true }
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
            startDestination = startDestination,
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
            composable(Routes.LOGIN) {
                LoginScreen(
                    onNavigateToRegister = {
                        navController.navigate(Routes.REGISTER) {
                            launchSingleTop = true
                        }
                    },
                    onLoginSuccess = {
                        navController.navigate(Routes.HOME) {
                            popUpTo(Routes.LOGIN) { inclusive = true }
                            launchSingleTop = true
                        }
                    },
                )
            }
            composable(Routes.REGISTER) {
                RegisterScreen(
                    onNavigateToLogin = {
                        navController.navigate(Routes.LOGIN) {
                            popUpTo(Routes.REGISTER) { inclusive = true }
                            launchSingleTop = true
                        }
                    },
                    onRegisterSuccess = {
                        navController.navigate(Routes.HOME) {
                            popUpTo(Routes.LOGIN) { inclusive = true }
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
