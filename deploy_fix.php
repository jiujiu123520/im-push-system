<?php
require __DIR__ . '/vendor/autoload.php';
use App\Service\Config;
use App\Service\Database;
Config::loadEnv(__DIR__ . '/.env');

echo "=== A. 服务器 DeviceOfflineNotifier 源码检查 ===\n";
$src = file_get_contents(__DIR__ . '/src/Service/DeviceOfflineNotifier.php');
$hasOffMin = (strpos($src, 'notify_offline_minutes') !== false);
$hasNorm = (strpos($src, 'normalizeThresholdMinutes') !== false);
echo "  notify_offline_minutes 引用: " . ($hasOffMin ? "✅" : "❌ 没有！") . "\n";
echo "  normalizeThresholdMinutes 方法: " . ($hasNorm ? "✅" : "❌ 没有！") . "\n";
if (!$hasOffMin) {
    echo "  提取 processPendingQueue 阈值判断（搜索 OFFLINE_MINUTES 或 30*60 硬编码）：\n";
    preg_match_all('/30\s*\*\s*60|1800|OFFLINE|offline.*(minute|阈值|thresh)/i', $src, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($mm as $i => $m) {
        $off = $m[0][1];
        echo "  match#$i: ...".substr($src, max(0,$off-60), 140)."...\n";
    }
}

echo "\n=== B. push_keys 是否有 notify_offline_minutes 列 ===\n";
$cols = Database::fetchAll("SHOW COLUMNS FROM push_keys WHERE Field='notify_offline_minutes'");
if (empty($cols)) {
    echo "  ❌ 列不存在！执行迁移脚本\n";
    // 读迁移脚本并执行
    $sqlFile = __DIR__ . '/database/migrations/022_notify_offline_minutes.sql';
    if (!file_exists($sqlFile)) {
        echo "    ❌ 迁移脚本 $sqlFile 不存在\n";
    } else {
        echo "    ✅ 迁移脚本存在，开始执行...\n";
        $sql = file_get_contents($sqlFile);
        try {
            Database::execute(substr($sql, 0, 10000)); // PDO exec 一次一条？用多条
            echo "    ✅ 迁移脚本执行成功\n";
        } catch (\Throwable $e) {
            echo "    ⚠️  批量执行失败（可能有多个语句），改用手动 ALTER：" . $e->getMessage() . "\n";
            try {
                Database::execute("ALTER TABLE push_keys ADD COLUMN notify_offline_minutes INT NOT NULL DEFAULT 0 COMMENT '掉线提醒阈值（分钟）：0=系统默认30，范围5~1440' AFTER notify_interval");
                echo "    ✅ 手动 ALTER 成功\n";
            } catch (\Throwable $e2) {
                echo "    ❌ ALTER 也失败: " . $e2->getMessage() . "\n";
            }
        }
    }
} else {
    echo "  ✅ 列已存在，状态: {$cols[0]['Type']}  DEFAULT={$cols[0]['Default']}\n";
}
