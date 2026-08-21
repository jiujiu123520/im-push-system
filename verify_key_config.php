<?php
// 最小化：只测数据库字段和 DeviceOfflineNotifier normalize
require __DIR__ . '/backend/vendor/autoload.php';

use App\Service\Database;

echo "=== 1. push_keys 列结构 ===\n";
try {
    $cols = Database::fetchAll("SHOW COLUMNS FROM push_keys WHERE Field IN ('notify_enabled','notify_email','notify_interval','notify_offline_minutes')");
    foreach ($cols as $c) echo "  {$c['Field']}  TYPE={$c['Type']}  DEFAULT={$c['Default']}\n";
    if (empty($cols)) echo "  ❌ 没有找到任何列\n";
} catch (\Throwable $e) {
    echo "  ❌ " . $e->getMessage() . "\n";
}

echo "\n=== 2. 样例 Key（后5条） ===\n";
try {
    $rows = Database::fetchAll("SELECT id,name,notify_enabled,notify_interval,notify_offline_minutes FROM push_keys ORDER BY id DESC LIMIT 5");
    foreach ($rows as $r) {
        echo "  [{$r['id']}] name={$r['name']}  enabled={$r['notify_enabled']}  interval={$r['notify_interval']}s  offline_minutes=" . var_export($r['notify_offline_minutes'], true) . "\n";
    }
} catch (\Throwable $e) {
    echo "  ❌ " . $e->getMessage() . "\n";
}

echo "\n=== 3. appendNotifyFields（列表接口会走这里） ===\n";
try {
    $ctrl = new \App\Controller\PushKeyController();
    $rf = new ReflectionMethod($ctrl, 'appendNotifyFields');
    $rf->setAccessible(true);
    $row = Database::fetch("SELECT id,name,status FROM push_keys ORDER BY id DESC LIMIT 1");
    $res = $rf->invoke($ctrl, [$row]);
    echo "  notify_offline_minutes in list = " . var_export($res[0]['notify_offline_minutes'] ?? '<MISSING>', true) . "\n";
    echo "  完整: " . json_encode($res[0], JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    echo "  ❌ " . $e->getMessage() . "\n";
}

echo "\n=== 4. UPDATE notify_offline_minutes = 15 再读回确认 ===\n";
try {
    $row = Database::fetch("SELECT id FROM push_keys ORDER BY id DESC LIMIT 1");
    $id = (int)$row['id'];
    echo "  target id = $id\n";
    // 模拟 PushKeyController.update 中的 notifyData 规范化逻辑
    $m = 15;
    $notify_offline_minutes = ($m !== 0 && ($m < 5 || $m > 1440)) ? 0 : $m;
    $notifyData = ['notify_offline_minutes' => $notify_offline_minutes, 'updated_at' => date('Y-m-d H:i:s')];
    $columns = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($notifyData)));
    $values = array_values($notifyData);
    $values[] = $id;
    $n = Database::execute("UPDATE push_keys SET {$columns} WHERE id = ?", $values);
    echo "  UPDATE affected rows = $n\n";
    $read = Database::fetch("SELECT id,notify_offline_minutes FROM push_keys WHERE id=$id");
    echo "  读回 notify_offline_minutes = " . var_export($read['notify_offline_minutes'], true) . " " . ($read['notify_offline_minutes'] == 15 ? "✅" : "❌ 写失败!") . "\n";
    Database::execute("UPDATE push_keys SET notify_offline_minutes=0,updated_at=NOW() WHERE id=$id");
    echo "  已还原\n";
} catch (\Throwable $e) {
    echo "  ❌ " . $e->getMessage() . "\n";
}

echo "\n=== 5. DeviceOfflineNotifier 读取 Key 阈值（核心：是否真从 DB 拿 notify_offline_minutes） ===\n";
try {
    // 看 processPendingQueue 里是否用到 notify_offline_minutes
    $src = file_get_contents(__DIR__ . '/backend/src/Service/DeviceOfflineNotifier.php');
    if (strpos($src, 'notify_offline_minutes') !== false) {
        echo "  ✅ 源码中引用了 notify_offline_minutes\n";
        // 抽取关键片段展示
        preg_match('/normalizeThresholdMinutes.+?\}/s', $src, $m1);
        preg_match('/notify_offline_minutes[^;]*;/', $src, $m2);
        if (!empty($m1)) echo "    normalizeThresholdMinutes 片段: " . trim($m1[0]) . "\n";
        if (!empty($m2)) echo "    读取 DB 字段片段: " . trim($m2[0]) . "\n";
    } else {
        echo "  ❌ 源码中未找到 notify_offline_minutes！这就是不生效的根因\n";
    }

    // 再调用 normalizeThresholdMinutes 验证
    $rf2 = new ReflectionMethod(\App\Service\DeviceOfflineNotifier::class, 'normalizeThresholdMinutes');
    $rf2->setAccessible(true);
    $notifier = new \App\Service\DeviceOfflineNotifier;
    echo "  normalize(0,30)   => " . $rf2->invoke($notifier, 0, 30) . " (expect 30, 回退默认)\n";
    echo "  normalize(5,30)   => " . $rf2->invoke($notifier, 5, 30) . " (expect 5)\n";
    echo "  normalize(3,30)   => " . $rf2->invoke($notifier, 3, 30) . " (expect 30, 过小回退)\n";
    echo "  normalize(2000,30)=> " . $rf2->invoke($notifier, 2000, 30) . " (expect 30, 过大回退)\n";
    echo "  normalize(60,30)  => " . $rf2->invoke($notifier, 60, 30) . " (expect 60)\n";
} catch (\Throwable $e) {
    echo "  ❌ " . $e->getMessage() . "\n";
}
