<?php
require __DIR__ . '/vendor/autoload.php';
use App\Service\Config;
use App\Service\Database;
Config::loadEnv(__DIR__ . '/.env');

echo "=== 1. push_keys 现含 notify_offline_minutes？ ===\n";
$cols = Database::fetchAll("SHOW COLUMNS FROM push_keys WHERE Field IN ('notify_enabled','notify_email','notify_interval','notify_offline_minutes')");
foreach ($cols as $c) echo "  {$c['Field']}  TYPE={$c['Type']}  DEFAULT={$c['Default']}\n";

echo "\n=== 2. 选一个已开通知的 Key 做测试（notify_enabled=1），设阈值 10 分钟，然后调 processPendingQueue 看是否读取 ===\n";
$row = Database::fetch("SELECT * FROM push_keys WHERE notify_enabled=1 ORDER BY id DESC LIMIT 1");
if (!$row) {
    $row = Database::fetch("SELECT * FROM push_keys ORDER BY id DESC LIMIT 1");
    Database::execute("UPDATE push_keys SET notify_enabled=1, notify_email='test@example.com' WHERE id=?", [$row['id']]);
    $row = Database::fetch("SELECT * FROM push_keys WHERE id=?", [$row['id']]);
}
$kid = (int)$row['id'];
echo "  测试 Key id=$kid name={$row['name']}\n";

// 设置阈值=10
Database::execute("UPDATE push_keys SET notify_offline_minutes=10 WHERE id=?", [$kid]);
$read = Database::fetch("SELECT notify_offline_minutes FROM push_keys WHERE id=?", [$kid]);
echo "  写入 10 后读回 = ".var_export($read['notify_offline_minutes'], true)."  (期望 10)  ".(($read['notify_offline_minutes']==10)?"✅":"❌")."\n";

echo "\n=== 3. 读 processPendingQueue 内部逻辑（提取阈值判断路径） ===\n";
$src = file_get_contents(__DIR__ . '/src/Service/DeviceOfflineNotifier.php');
// 找查询 Key 配置时读取了哪些列
preg_match('/SELECT[\s\S]{0,400}?FROM\s+push_keys[\s\S]{0,200}?notify_offline_minutes[\s\S]{0,100}?;/iU', $src, $sqlMatch);
if (!empty($sqlMatch)) {
    echo "  ✅ processPendingQueue 所用 Key 配置查询 SQL 包含 notify_offline_minutes：\n";
    echo "    ".trim(preg_replace('/\s+/',' ',$sqlMatch[0]))."\n";
} else {
    // 再查 keyConfig 数组结构
    if (preg_match('/keyConfig\s*=\s*\[([\s\S]{0,800}?)\];/', $src, $arrM)) {
        echo "  ⚠️  keyConfig 硬编码结构（没走 push_keys 全查）：\n    ".trim(preg_replace('/\s+/',' ',$arrM[1]))."\n";
    }
    // 或 push_keys 查询但没带这个字段
    if (preg_match('/FROM\s+push_keys[\s\S]{0,400}?\bwhere\b/iU', $src, $wMatch)) {
        $wMatch = preg_replace('/\s+/',' ',$wMatch[0]);
        if (strpos($wMatch, 'notify_offline_minutes') === false) {
            echo "  ❌ push_keys 查询 SQL 不含 notify_offline_minutes！这是后端真正的 BUG！\n    片段：$wMatch\n";
            // 查完整 SQL
            if (preg_match('/(SELECT[\s\S]{0,600}?FROM\s+push_keys[\s\S]{0,600}?;)/iU', $src, $fsql)) {
                echo "  完整 SQL: ".trim(preg_replace('/\s+/',' ',$fsql[1]))."\n";
            }
        }
    }
    // 查 thresholdMinutes 计算：看 processPendingQueue 最终怎么算阈值
    if (preg_match('/thresholdMinutes[\s\S]{0,500}?normalizeThresholdMinutes[\s\S]{0,100}\(([\s\S]{0,100}?)\)/U', $src, $tM)) {
        echo "  thresholdMinutes 计算: normalizeThresholdMinutes(" . trim(preg_replace('/\s+/',' ',$tM[1])) . ")\n";
    }
}

// 还原
Database::execute("UPDATE push_keys SET notify_offline_minutes=0 WHERE id=?", [$kid]);

echo "\n=== 4. 再查 processPendingQueue 的 fallback 常量 OFFLINE_MINUTES ===\n";
if (preg_match('/OFFLINE.*MINUTES|DEFAULT_OFFLINE.*=\s*(\d+)/', $src, $cm)) {
    echo "  找到常量定义: ".trim($cm[0])."\n";
}
echo "  全文搜 30\s*\*\s*60 = 1800 秒硬编码阈值：".(preg_match('/30\s*\*\s*60/', $src) ? "存在（可能是旧硬编码没删）" : "没找到")."\n";
