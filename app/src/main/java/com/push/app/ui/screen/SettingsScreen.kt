package com.push.app.ui.screen

import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.BatterySaver
import androidx.compose.material.icons.filled.ChevronDown
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.ChevronUp
import androidx.compose.material.icons.filled.DeleteForever
import androidx.compose.material.icons.filled.Folder
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Palette
import androidx.compose.material.icons.filled.PowerSettingsNew
import androidx.compose.material.icons.filled.RocketLaunch
import androidx.compose.material.icons.filled.Sync
import androidx.compose.material.icons.filled.Waves
import androidx.compose.material.icons.filled.Wifi
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Divider
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.push.app.data.PushRepository
import com.push.app.ui.theme.GlassBackground
import com.push.app.ui.theme.GlassCard
import com.push.app.ui.theme.GlassTopBar
import com.push.app.util.PermissionHelper
import kotlinx.coroutines.launch

private enum class ThemeMode(val label: String) {
    LIGHT("浅色"), DARK("深色"), SYSTEM("跟随系统"),
}

@Composable
fun SettingsScreen(
    onBack: () -> Unit,
) {
    val context = LocalContext.current
    val repo = PushRepository.get(context)
    val scope = rememberCoroutineScope()
    val scrollState = rememberScrollState()

    val vibrate by repo.prefs.vibrateFlow.collectAsState(initial = true)
    val wifiOnly by repo.prefs.wifiOnlyFlow.collectAsState(initial = false)
    val autoReconnect by repo.prefs.autoReconnectFlow.collectAsState(initial = true)
    val themeModeStr by repo.prefs.themeModeFlow.collectAsState(initial = "dark")

    var permissionsExpanded by remember { mutableStateOf(true) }
    var showLogoutDialog by remember { mutableStateOf(false) }

    val brand = remember { PermissionHelper.detectBrand() }
    var notificationGranted by remember { mutableStateOf(false) }
    var batteryIgnored by remember { mutableStateOf(false) }

    LaunchedEffect(Unit) {
        notificationGranted = PermissionHelper.isNotificationEnabled(context)
        batteryIgnored = PermissionHelper.isBatteryOptimizationIgnored(context)
    }

    GlassBackground {
        Column(modifier = Modifier.fillMaxSize()) {
            GlassTopBar(
                title = "设置",
                onBack = onBack,
            )

            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .verticalScroll(scrollState)
                    .padding(horizontal = 16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Spacer(Modifier.height(12.dp))

                PermissionSectionCard(
                    expanded = permissionsExpanded,
                    onToggleExpanded = { permissionsExpanded = !permissionsExpanded },
                    brandLabel = brand.label,
                    brandShortLabel = brand.shortLabel,
                    notificationGranted = notificationGranted,
                    batteryIgnored = batteryIgnored,
                    onRefreshStatus = {
                        notificationGranted = PermissionHelper.isNotificationEnabled(context)
                        batteryIgnored = PermissionHelper.isBatteryOptimizationIgnored(context)
                    },
                    onOpenNotification = { PermissionHelper.openNotificationSettings(context) },
                    onOpenAutoStart = { PermissionHelper.openAutoStartSettings(context) },
                    onOpenBattery = { PermissionHelper.openBatteryOptimizationSettings(context) },
                    onOpenLockCleanup = { PermissionHelper.openLockScreenCleanupSettings(context) },
                    onOpenBackgroundPopup = { PermissionHelper.openBackgroundPopupSettings(context) },
                )

                GlassCard(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        Text(
                            text = "通用",
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = MaterialTheme.colorScheme.onSurface,
                        )
                        Spacer(Modifier.height(12.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                imageVector = Icons.Filled.Palette,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.size(20.dp),
                            )
                            Spacer(Modifier.width(12.dp))
                            Column(modifier = Modifier.weight(1f)) {
                                Text(
                                    text = "主题",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurface,
                                )
                            }
                        }
                        Spacer(Modifier.height(8.dp))
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(8.dp),
                        ) {
                            ThemeMode.values().forEach { mode ->
                                val selected = themeModeStr == mode.name.lowercase()
                                FilterChip(
                                    selected = selected,
                                    onClick = {
                                        scope.launch {
                                            repo.prefs.saveThemeMode(mode.name.lowercase())
                                        }
                                    },
                                    label = { Text(mode.label) },
                                    modifier = Modifier.height(36.dp),
                                )
                            }
                        }

                        Spacer(Modifier.height(12.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                imageVector = Icons.Filled.Waves,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.size(20.dp),
                            )
                            Spacer(Modifier.width(12.dp))
                            Column(modifier = Modifier.weight(1f)) {
                                Text(
                                    text = "震动反馈",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurface,
                                )
                                Text(
                                    text = "收到推送时震动提醒",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            Switch(
                                checked = vibrate,
                                onCheckedChange = {
                                    scope.launch { repo.prefs.saveVibrate(it) }
                                },
                            )
                        }
                    }
                }

                GlassCard(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        Text(
                            text = "网络",
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = MaterialTheme.colorScheme.onSurface,
                        )
                        Spacer(Modifier.height(12.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                imageVector = Icons.Filled.Wifi,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.size(20.dp),
                            )
                            Spacer(Modifier.width(12.dp))
                            Column(modifier = Modifier.weight(1f)) {
                                Text(
                                    text = "仅 Wi-Fi",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurface,
                                )
                                Text(
                                    text = "移动网络下不建立连接",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            Switch(
                                checked = wifiOnly,
                                onCheckedChange = {
                                    scope.launch { repo.prefs.saveWifiOnly(it) }
                                },
                            )
                        }

                        Spacer(Modifier.height(8.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                imageVector = Icons.Filled.Sync,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.size(20.dp),
                            )
                            Spacer(Modifier.width(12.dp))
                            Column(modifier = Modifier.weight(1f)) {
                                Text(
                                    text = "自动重连",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurface,
                                )
                                Text(
                                    text = "断开后指数退避自动重连",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            Switch(
                                checked = autoReconnect,
                                onCheckedChange = {
                                    scope.launch { repo.prefs.saveAutoReconnect(it) }
                                },
                            )
                        }
                    }
                }

                GlassCard(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        Text(
                            text = "存储",
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = MaterialTheme.colorScheme.onSurface,
                        )
                        Spacer(Modifier.height(12.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                imageVector = Icons.Filled.Folder,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.size(20.dp),
                            )
                            Spacer(Modifier.width(12.dp))
                            Column(modifier = Modifier.weight(1f)) {
                                Text(
                                    text = "存储占用",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurface,
                                )
                                Text(
                                    text = repo.getStorageSize(),
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        }

                        Spacer(Modifier.height(12.dp))

                        Button(
                            onClick = {
                                scope.launch {
                                    repo.clearMessages()
                                    Toast.makeText(context, "缓存已清除", Toast.LENGTH_SHORT).show()
                                }
                            },
                            shape = RoundedCornerShape(10.dp),
                            colors = ButtonDefaults.buttonColors(
                                containerColor = MaterialTheme.colorScheme.error.copy(alpha = 0.8f),
                            ),
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Icon(
                                imageVector = Icons.Filled.DeleteForever,
                                contentDescription = null,
                                modifier = Modifier.size(18.dp),
                            )
                            Spacer(Modifier.width(8.dp))
                            Text("清除缓存")
                        }
                    }
                }

                GlassCard(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        Text(
                            text = "关于",
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = MaterialTheme.colorScheme.onSurface,
                        )
                        Spacer(Modifier.height(12.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Icon(
                                imageVector = Icons.Filled.Info,
                                contentDescription = null,
                                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                                modifier = Modifier.size(20.dp),
                            )
                            Spacer(Modifier.width(12.dp))
                            Text(
                                text = "版本信息",
                                style = MaterialTheme.typography.bodyMedium,
                                color = MaterialTheme.colorScheme.onSurface,
                                modifier = Modifier.weight(1f),
                            )
                            Text(
                                text = "v1.0.0 (1)",
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                    }
                }

                GlassCard(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        Text(
                            text = "危险操作",
                            style = MaterialTheme.typography.titleSmall,
                            fontWeight = FontWeight.SemiBold,
                            color = MaterialTheme.colorScheme.error,
                        )
                        Spacer(Modifier.height(12.dp))

                        Button(
                            onClick = { showLogoutDialog = true },
                            shape = RoundedCornerShape(10.dp),
                            colors = ButtonDefaults.buttonColors(
                                containerColor = MaterialTheme.colorScheme.error,
                            ),
                            modifier = Modifier.fillMaxWidth(),
                        ) {
                            Icon(
                                imageVector = Icons.Filled.PowerSettingsNew,
                                contentDescription = null,
                                modifier = Modifier.size(18.dp),
                            )
                            Spacer(Modifier.width(8.dp))
                            Text("退出登录")
                        }
                    }
                }

                Spacer(Modifier.height(24.dp))
            }
        }
    }

    if (showLogoutDialog) {
        AlertDialog(
            onDismissRequest = { showLogoutDialog = false },
            title = { Text("退出登录") },
            text = { Text("确定要退出登录吗？将清除本地账号信息。") },
            confirmButton = {
                TextButton(onClick = {
                    scope.launch {
                        repo.prefs.clearUserAuth()
                        repo.disconnect()
                        showLogoutDialog = false
                        onBack()
                    }
                }) {
                    Text("确定", color = MaterialTheme.colorScheme.error)
                }
            },
            dismissButton = {
                TextButton(onClick = { showLogoutDialog = false }) {
                    Text("取消")
                }
            },
        )
    }
}

@Composable
private fun PermissionSectionCard(
    expanded: Boolean,
    onToggleExpanded: () -> Unit,
    brandLabel: String,
    brandShortLabel: String,
    notificationGranted: Boolean,
    batteryIgnored: Boolean,
    onRefreshStatus: () -> Unit,
    onOpenNotification: () -> Unit,
    onOpenAutoStart: () -> Unit,
    onOpenBattery: () -> Unit,
    onOpenLockCleanup: () -> Unit,
    onOpenBackgroundPopup: () -> Unit,
) {
    GlassCard(modifier = Modifier.fillMaxWidth()) {
        Column {
            Row(
                modifier = Modifier
                    .fillMaxWidth()
                    .then(Modifier)
                    .padding(16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(
                    imageVector = Icons.Filled.RocketLaunch,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.primary,
                    modifier = Modifier.size(20.dp),
                )
                Spacer(Modifier.width(12.dp))
                Column(modifier = Modifier.weight(1f)) {
                    Text(
                        text = "权限引导",
                        style = MaterialTheme.typography.titleSmall,
                        fontWeight = FontWeight.SemiBold,
                        color = MaterialTheme.colorScheme.onSurface,
                    )
                    Text(
                        text = "$brandLabel · $brandShortLabel",
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
                IconButton(onClick = onToggleExpanded) {
                    Icon(
                        imageVector = if (expanded) Icons.Filled.ChevronUp else Icons.Filled.ChevronDown,
                        contentDescription = null,
                        tint = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }

            if (expanded) {
                Divider(color = Color.White.copy(alpha = 0.12f))

                Column(modifier = Modifier.padding(horizontal = 8.dp)) {
                    PermissionRow(
                        icon = Icons.Filled.Notifications,
                        label = "通知权限",
                        statusText = if (notificationGranted) "已开启" else "未开启",
                        statusOk = notificationGranted,
                        onClick = onOpenNotification,
                    )
                    PermissionRow(
                        icon = Icons.Filled.RocketLaunch,
                        label = "自启动",
                        statusText = "点击查看",
                        statusOk = true,
                        onClick = onOpenAutoStart,
                    )
                    PermissionRow(
                        icon = Icons.Filled.BatterySaver,
                        label = "电池白名单",
                        statusText = if (batteryIgnored) "已加入" else "未加入",
                        statusOk = batteryIgnored,
                        onClick = onOpenBattery,
                    )
                    PermissionRow(
                        icon = Icons.Filled.Sync,
                        label = "锁屏清理",
                        statusText = "点击查看",
                        statusOk = true,
                        onClick = onOpenLockCleanup,
                    )
                    PermissionRow(
                        icon = Icons.Filled.Info,
                        label = "后台弹出界面",
                        statusText = "点击查看",
                        statusOk = true,
                        onClick = onOpenBackgroundPopup,
                    )
                }
                Spacer(Modifier.height(8.dp))
            }
        }
    }
}

@Composable
private fun PermissionRow(
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    statusText: String,
    statusOk: Boolean,
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(8.dp))
            .background(Color.White.copy(alpha = 0.04f))
            .padding(horizontal = 12.dp, vertical = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(
            imageVector = icon,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.size(20.dp),
        )
        Spacer(Modifier.width(12.dp))
        Column(modifier = Modifier.weight(1f)) {
            Text(
                text = label,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurface,
            )
            Text(
                text = statusText,
                style = MaterialTheme.typography.labelSmall,
                color = if (statusOk) StatusOnline else StatusOffline,
            )
        }
        androidx.compose.material3.IconButton(onClick = onClick) {
            Icon(
                imageVector = Icons.Filled.ChevronRight,
                contentDescription = "前往",
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
    Spacer(Modifier.height(6.dp))
}

private val StatusOnline = Color(0xFF22C55E)
private val StatusOffline = Color(0xFFEF4444)
