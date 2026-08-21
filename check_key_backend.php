<?php
require __DIR__ . '/vendor/autoload.php';
use App\Service\Config;
use App\Service\Database;
Config::loadEnv(__DIR__ . '/.env');

echo "=== 1. push_keys 列结构 ===\n";
try {
    $cols = Database::fetchAll("SHOW COLUMNS FROM push_keys WHERE Field IN ('notify_enabled','notify_email','notify_interval','notify_offline_minutes')");
    foreach ($cols as $c) echo "  {$c['Field']}  TYPE={$c['Type']}  DEFAULT={$c['Default']}\n";
    if (empty($cols)) echo "  ❌ 没有找到列\n";
} catch (\Throwable $e) { echo "  ❌ ".$e->getMessage()."\n"; }

echo "\n=== 2. 样例 Key（后5条） ===\n";
$rows = [];
try {
    $rows = Database::fetchAll("SELECT id,name,notify_enabled,notify_interval,notify_offline_minutes FROM push_keys ORDER BY id DESC LIMIT 5");
    foreach ($rows as $r) {
        echo "  [{$r['id']}] {$r['name']}  enabled={$r['notify_enabled']}  interval={$r['notify_interval']}s  offline_minutes=" . var_export($r['notify_offline_minutes'], true) . "\n";
    }
} catch (\Throwable $e) { echo "  ❌ ".$e->getMessage()."\n"; }

echo "\n=== 3. UPDATE 写入+读回验证 ===\n";
if (!empty($rows)) {
    $id = (int)$rows[0]['id'];
    try {
        Database::execute("UPDATE push_keys SET notify_offline_minutes=15, updated_at=NOW() WHERE id=?", [$id]);
        $v = Database::fetch("SELECT notify_offline_minutes FROM push_keys WHERE id=?", [$id]);
        $ok = ((int)$v['notify_offline_minutes'] === 15) ? "✅ 写入+读回 OK" : "❌ 失败 (got=".var_export($v['notify_offline_minutes'],true).")";
        echo "  $ok\n";
        Database::execute("UPDATE push_keys SET notify_offline_minutes=0, updated_at=NOW() WHERE id=?", [$id]);
        echo "  已还原为 0\n";
    } catch (\Throwable $e) { echo "  ❌ ".$e->getMessage()."\n"; }
}

echo "\n=== 4. DeviceOfflineNotifier 是否实际读取 Key 级 notify_offline_minutes ===\n";
$src = file_get_contents(__DIR__ . '/src/Service/DeviceOfflineNotifier.php');
// 先提取 processPendingQueue 方法里的关键片段
preg_match('/function processPendingQueue\(\).+?^    \}/sm', $src, $mm);
$body = $mm[0] ?? '';
if (strpos($body, 'notify_offline_minutes') !== false || strpos($body, 'offline_minutes') !== false) {
    echo "  ✅ processPendingQueue 中确实引用了 notify_offline_minutes 或 offline_minutes\n";
    // 进一步找“从 DB 读 Key 配置”的 SQL 片段
    if (preg_match('/SELECT[^;{]+notify_offline_minutes[^;{]+;/s', $src, $sql)) {
        echo "    Key 配置查询 SQL: ".trim(preg_replace('/\s+/',' ',$sql[0]))."\n";
    } elseif (preg_match('/push_keys[\s\S]{0,300}notify_offline_minutes/', $src, $m2)) {
        echo "    包含 push_keys + 字段的查询: ".trim(preg_replace('/\s+/',' ',$m2[0]))."\n";
    } else {
        echo "    ⚠️  没找到 SELECT 该字段的 SQL，可能通过 UserConsole KeyController 间接查\n";
        // 看 processPendingQueue 中 keyConfig 的来源
        if (preg_match('/keyConfig[\s\S]{0,500}notify_offline_minutes/', $src, $m3)) {
            echo "    keyConfig 处理片段: ".trim(preg_replace('/\s+/',' ',$m3[0]))."\n";
        }
        if (preg_match('/keyConfig[\s\S]{0,500}offline_minutes/', $src, $m4)) {
            echo "    keyConfig+offline_minutes片段: ".trim(preg_replace('/\s+/',' ',$m4[0]))."\n";
        }
    }
} else {
    echo "  ❌ processPendingQueue 中没有 notify_offline_minutes！这就是不生效的根因！\n";
    echo "    实际 processPendingQueue 内容节选:\n";
    echo "    ".substr(trim(preg_replace('/\s+/',' ',$body)), 0, 600)."\n";
}
