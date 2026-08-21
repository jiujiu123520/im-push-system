<?php
// 验证掉线阈值功能部署状态
require '/www/push-system/backend/vendor/autoload.php';

// 1. 数据库字段
try {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=push_system', 'push_user', trim(shell_exec("grep '^DB_PASSWORD=' /www/push-system/backend/.env | cut -d= -f2")));
    $col = $pdo->query("SHOW COLUMNS FROM push_keys LIKE 'notify_offline_minutes'")->fetch(PDO::FETCH_ASSOC);
    echo "1. DB 字段: " . ($col ? "✅ 存在 ({$col['Type']}, default={$col['Default']})" : "❌ 不存在") . PHP_EOL;
} catch (Throwable $e) {
    echo "1. DB 检查失败: {$e->getMessage()}" . PHP_EOL;
}

// 2. 后端 KeyController 是否含新字段
$kc = file_get_contents('/www/push-system/backend/src/Controller/UserConsole/KeyController.php');
echo "2. KeyController 新字段: " . (strpos($kc, 'notify_offline_minutes') !== false ? '✅ 已部署' : '❌ 未部署') . PHP_EOL;

// 3. WebSocketServer handleReconnect
$ws = file_get_contents('/www/push-system/backend/src/WebSocketServer.php');
echo "3. WS handleReconnect: " . (strpos($ws, 'handleReconnect') !== false ? '✅ 已部署' : '❌ 未部署') . PHP_EOL;

// 4. MailService 恢复通知
$mail = file_get_contents('/www/push-system/backend/src/Service/MailService.php');
echo "4. 恢复邮件模板: " . (strpos($mail, 'sendRecoveryNotification') !== false ? '✅ 已部署' : '❌ 未部署') . PHP_EOL;

// 5. DeviceOfflineNotifier 阈值逻辑
$dn = file_get_contents('/www/push-system/backend/src/Service/DeviceOfflineNotifier.php');
echo "5. Key级阈值逻辑: " . (strpos($dn, 'normalizeThresholdMinutes') !== false ? '✅ 已部署' : '❌ 未部署') . PHP_EOL;

// 6. 前端产物是否包含"掉线阈值"文案
$found = false;
$jsDir = '/www/push-system/user/dist/assets';
foreach (glob($jsDir . '/*.js') as $f) {
    if (strpos(file_get_contents($f), '掉线阈值') !== false) { $found = true; break; }
}
echo "6. 前端'掉线阈值'文案: " . ($found ? '✅ 已构建' : '❌ 未找到') . PHP_EOL;
