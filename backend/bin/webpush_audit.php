<?php
/**
 * Web Push 订阅巡检脚本
 *
 * 原理：
 *   每天扫描一次长时间未活跃的 PWA 订阅，对每条发一条「ping」通知（payload 带 is_ping=true）。
 *   只要网关回 201 → endpoint 还活着 → 复活 status=0 / 续活 status=1；
 *   网关回 404/410 → endpoint 真死了 → 标记 status=0（防止误判也能兜底）；
 *   其他临时错误 → 跳过，不动数据库，下次巡检再试。
 *
 *   用户完全看不到这条 ping——Service Worker 的 push handler 开头会跳过 is_ping 通知，
 *   但浏览器/网关一定会转发（Web Push 协议不支持真正的静默推送）。
 *
 *   覆盖的盲区：
 *   - 用户一周不打开 PWA → endpoint 可能被轮换/被判死 → heartbeat 不触发 → 自愈全失效
 *   - transient 410（网关短暂故障）被误判为 endpoint 死亡 → ping 复活
 *   - 服务端 markSubscriptionInvalid 把 status 改成 0 → 等客户端上报 = 被动；ping = 主动
 *
 * 用法（必须 sudo 执行，.env 属主 www-data 且 600）：
 *   sudo php bin/webpush_audit.php                        # dry-run，不实际推送，只列出扫描结果
 *   sudo php bin/webpush_audit.php --run                  # 实际 ping（cron 每天一次）
 *   sudo php bin/webpush_audit.php --run --days=5         # 扫描最后活跃超过 5 天的（默认 3 天）
 *   sudo php bin/webpush_audit.php --device=pwa_xxx       # 只巡检指定设备（调试用）
 *   sudo php bin/webpush_audit.php --run --limit=50       # 限制最多处理 50 条（防止大批量超时）
 *
 * Cron 示例（每天凌晨 2 点执行）：
 *   0 2 * * * cd /www/push-system/backend && sudo -u www-data php bin/webpush_audit.php --run >> /var/log/webpush_audit.log 2>&1
 *
 * 退出码：
 *   0  正常完成（所有订阅都被处理或跳过）
 *   1  配置/环境错误
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "[FATAL] 请先执行 composer install 安装依赖（缺失 {$autoload}）\n");
    exit(1);
}
require $autoload;

try {
    \App\Service\Config::loadEnv();
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] 加载 .env 失败: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------- 参数解析 ----------
$args = $argv;
array_shift($args); // 去掉脚本名

$run      = false;
$days     = 3;
$limit    = 200;
$deviceId = '';

foreach ($args as $arg) {
    if ($arg === '--run') { $run = true; continue; }
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) { $days = (int)$m[1]; continue; }
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) { $limit = (int)$m[1]; continue; }
    if (preg_match('/^--device=(.+)$/', $arg, $m)) { $deviceId = trim($m[1]); continue; }
}

echo "=== Web Push 订阅巡检 ===\n";
echo "模式: " . ($run ? "实际 ping" : "dry-run（不推送）") . "\n";
echo "扫描窗口: 最后活跃超过 {$days} 天的订阅\n";
if ($deviceId) { echo "仅设备: {$deviceId}\n"; }
echo "时间: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('-', 40) . "\n";

// ---------- 扫描待巡检订阅 ----------
try {
    $where = [];
    $params = [];

    // status=1 或 status=0（跳过 status=2 管理员手动禁用的）
    $where[] = 'status IN (0, 1)';

    // 最后活跃超过 N 天
    $where[] = '(last_active_at IS NULL OR last_active_at < DATE_SUB(NOW(), INTERVAL ? DAY))';
    $params[] = $days;

    if ($deviceId) {
        $where[] = 'device_id = ?';
        $params[] = $deviceId;
    }

    $sql = 'SELECT id, device_id, push_key_id, endpoint, p256dh, auth, platform, status, last_active_at
            FROM web_push_subscriptions WHERE ' . implode(' AND ', $where) . '
            ORDER BY last_active_at ASC
            LIMIT ' . (int)$limit;

    $subs = \App\Service\Database::fetchAll($sql, $params);
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] 扫描订阅失败: " . $e->getMessage() . "\n");
    exit(1);
}

if (empty($subs)) {
    echo "✅ 没有需要巡检的订阅（所有 status=1 都在 {$days} 天内活跃过，或无 PWA 订阅）\n";
    echo "完成。\n";
    exit(0);
}

echo "待巡检: " . count($subs) . " 条\n\n";

// ---------- 逐条处理 ----------
$stats = ['total' => 0, 'alive' => 0, 'revived' => 0, 'dead' => 0, 'skipped' => 0, 'errors' => 0];

foreach ($subs as $row) {
    $stats['total']++;
    $id        = (int)$row['id'];
    $deviceId  = $row['device_id'];
    $oldStatus = (int)$row['status'];
    $endpoint  = $row['endpoint'];
    $lastActive = $row['last_active_at'] ?? '(从未)';
    $isIos     = ($row['platform'] ?? '') === 'ios';

    // iOS 的 endpoint 存进数据库被截断过（早期版本 LEFT(endpoint, 100)），
    // 要取完整的；minishlink/web-push 会从完整值里解析 auth token
    // ——不过 subscribe upsert 里直接存 sub.endpoint 完整值，现在数据库应该是完整的。
    // 这里取 endpoint 前 50 字符用于日志输出
    $epPreview = substr($endpoint, 0, 50);

    echo "  [{$id}] {$deviceId} status={$oldStatus} last_active={$lastActive}\n";
    echo "      endpoint: {$epPreview}...\n";

    if (!$run) {
        echo "      (dry-run，跳过实际推送)\n\n";
        continue;
    }

    // 构造 subscription 数组给 WebPushService::send()
    $sub = [
        'id'       => $id,
        'endpoint' => $endpoint,
        'p256dh'   => $row['p256dh'],
        'auth'     => $row['auth'],
        'device_id' => $deviceId,
    ];

    // ping 通知：标题为空 + is_ping=true payload。SW 端收到后直接跳过，
    // 用户完全看不到，但网关一定会转发。
    $result = \App\Service\WebPushService::send(
        $sub,
        '',            // 空标题
        '',            // 空正文
        ['is_ping' => true, 'ping_id' => bin2hex(random_bytes(8))]
    );

    $success = !empty($result['success']);
    $expired = !empty($result['expired']);
    $msg     = $result['message'] ?? '';

    if ($success) {
        // endpoint 还活着 ✅
        // status=0 的 → 复活；status=1 的 → 续活
        if ($oldStatus === 0) {
            // 复活 + 续活
            try {
                \App\Service\Database::execute(
                    'UPDATE web_push_subscriptions SET status = 1, last_active_at = NOW(), updated_at = NOW() WHERE id = ?',
                    [$id]
                );
                echo "      ✅ ping 成功 → status=0 已复活为 1\n";
                $stats['revived']++;
            } catch (Throwable $e) {
                echo "      ✅ ping 成功但复活失败: {$e->getMessage()}\n";
                $stats['errors']++;
            }
        } else {
            // 续活（status 保持 1）
            try {
                \App\Service\Database::execute(
                    'UPDATE web_push_subscriptions SET last_active_at = NOW() WHERE id = ?',
                    [$id]
                );
            } catch (Throwable $e) {
                // 续活失败不影响状态，静默记个 error_log 就行
                error_log("[WebPushAudit] 续活失败 id={$id}: " . $e->getMessage());
            }
            echo "      ✅ ping 成功 → 续活（last_active_at 已更新）\n";
            $stats['alive']++;
        }
    } elseif ($expired) {
        // endpoint 真死了 ❌（网关回 404/410）
        try {
            \App\Service\Database::execute(
                'UPDATE web_push_subscriptions SET status = 0 WHERE id = ?',
                [$id]
            );
        } catch (Throwable $e) {
            error_log("[WebPushAudit] 标记失效失败 id={$id}: " . $e->getMessage());
        }
        echo "      ❌ endpoint 已失效（{$msg}）→ status=0\n";
        error_log("[WebPushAudit] endpoint 失效 id={$id} device={$deviceId} platform=" . ($row['platform'] ?? '') . " reason={$msg}");
        $stats['dead']++;
    } else {
        // 临时错误（网络瞬断、网关 5xx 等）→ 跳过，不动数据库
        echo "      ⏭ 临时错误（{$msg}）→ 跳过，下次巡检再试\n";
        $stats['skipped']++;
    }

    echo "\n";
}

// ---------- 汇总 ----------
echo str_repeat('-', 40) . "\n";
echo "=== 巡检汇总 ===\n";
echo "  扫描: {$stats['total']} 条\n";
if ($run) {
    echo "  ✅ 存活续活: {$stats['alive']}\n";
    echo "  🔄 复活: {$stats['revived']}\n";
    echo "  ❌ 确认失效: {$stats['dead']}\n";
    echo "  ⏭ 跳过（临时错误）: {$stats['skipped']}\n";
    if ($stats['errors']) {
        echo "  ⚠️ DB错误: {$stats['errors']}\n";
    }
} else {
    echo "  (dry-run，未执行实际 ping)\n";
}
echo "完成。\n";

// 有错误或有临时跳过 → 退出码 1（让 cron/alerta 能感知）
$hasProblems = ($stats['dead'] > 0 || $stats['errors'] > 0);
if ($run && $hasProblems) {
    exit(1);
}
exit(0);
