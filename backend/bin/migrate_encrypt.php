<?php
/**
 * 一次性迁移脚本：将 admin_settings 中的敏感字段（SMTP 密码、APNS .p8 私钥）
 * 从明文存储迁移为 AES-256-CBC 加密存储（ENC: 前缀标识）。
 *
 * 用法：
 *   php bin/migrate_encrypt.php           运行迁移（干跑模式，不实际写库）
 *   php bin/migrate_encrypt.php --apply   运行迁移并实际写入数据库
 *   php bin/migrate_encrypt.php --status  只检查当前状态，不做任何修改
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "请先执行 composer install 安装依赖\n");
    exit(1);
}
require $autoload;

\App\Service\Config::loadEnv();

$apply = in_array('--apply', $argv, true);
$statusOnly = in_array('--status', $argv, true);

echo "========================================\n";
echo " 敏感字段加密迁移工具\n";
echo " 模式: " . ($apply ? '实际写入' : ($statusOnly ? '状态检查' : '干跑（不写库）')) . "\n";
echo "========================================\n\n";

try {
    \App\Service\Database::connect();
} catch (\Throwable $e) {
    fwrite(STDERR, "数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

// 检查 AES_KEY
try {
    $aesKey = \App\Service\Aes::encryptString('test');
    echo "[OK] AES_KEY 已配置，加密服务可用\n\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "[FAIL] AES_KEY 未配置或无效: " . $e->getMessage() . "\n");
    exit(1);
}

$migrated = 0;
$skipped  = 0;
$alreadyEnc = 0;

// ============================================================
// 1. mail_config.password
// ============================================================
echo "--- mail_config (SMTP 配置) ---\n";
$row = \App\Service\Database::fetch(
    'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
    ['mail_config']
);
if ($row !== false) {
    $config = json_decode((string)$row['config_value'], true);
    if (is_array($config) && !empty($config['password'])) {
        $pwd = (string)$config['password'];
        if (strpos($pwd, 'ENC:') === 0) {
            echo "  [SKIP] password 已加密 (ENC: 前缀)\n";
            $alreadyEnc++;
        } else {
            echo "  [PLAIN] password 是明文，长度=" . strlen($pwd) . "\n";
            if (!$statusOnly) {
                $encrypted = 'ENC:' . \App\Service\Aes::encryptString($pwd);
                $config['password'] = $encrypted;
                $json = json_encode($config, JSON_UNESCAPED_UNICODE);
                if ($apply) {
                    \App\Service\Database::execute(
                        'UPDATE admin_settings SET config_value = ?, updated_at = NOW() WHERE config_key = ?',
                        [$json, 'mail_config']
                    );
                    echo "  [OK] password 已加密并写入数据库\n";
                } else {
                    echo "  [DRY-RUN] 将写入加密值 (长度=" . strlen($encrypted) . ")\n";
                }
                $migrated++;
            } else {
                echo "  [STATUS] 将被迁移（加 --apply 实际执行）\n";
                $skipped++;
            }
        }
    } else {
        echo "  [SKIP] 无 password 字段或为空\n";
        $skipped++;
    }
} else {
    echo "  [SKIP] mail_config 配置不存在\n";
    $skipped++;
}

// ============================================================
// 2. settings_apns.auth_key
// ============================================================
echo "\n--- settings_apns (APNS 推送配置) ---\n";
$row = \App\Service\Database::fetch(
    'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
    ['settings_apns']
);
if ($row !== false) {
    $config = json_decode((string)$row['config_value'], true);
    if (is_array($config) && !empty($config['auth_key'])) {
        $key = (string)$config['auth_key'];
        if (strpos($key, 'ENC:') === 0) {
            echo "  [SKIP] auth_key 已加密 (ENC: 前缀)\n";
            $alreadyEnc++;
        } else {
            echo "  [PLAIN] auth_key 是明文，长度=" . strlen($key) . "\n";
            if (!$statusOnly) {
                $encrypted = 'ENC:' . \App\Service\Aes::encryptString($key);
                $config['auth_key'] = $encrypted;
                $json = json_encode($config, JSON_UNESCAPED_UNICODE);
                if ($apply) {
                    \App\Service\Database::execute(
                        'UPDATE admin_settings SET config_value = ?, updated_at = NOW() WHERE config_key = ?',
                        [$json, 'settings_apns']
                    );
                    echo "  [OK] auth_key 已加密并写入数据库\n";
                } else {
                    echo "  [DRY-RUN] 将写入加密值 (长度=" . strlen($encrypted) . ")\n";
                }
                $migrated++;
            } else {
                echo "  [STATUS] 将被迁移（加 --apply 实际执行）\n";
                $skipped++;
            }
        }
    } else {
        echo "  [SKIP] 无 auth_key 字段或为空（APNS 未配置）\n";
        $skipped++;
    }
} else {
    echo "  [SKIP] settings_apns 配置不存在\n";
    $skipped++;
}

// ============================================================
// 3. settings_fcm（如有 token/server_key 等敏感字段，暂保留明文）
// ============================================================
echo "\n--- settings_fcm / settings_feijipan ---\n";
foreach (['settings_fcm', 'settings_feijipan'] as $key) {
    $row = \App\Service\Database::fetch(
        'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
        [$key]
    );
    if ($row !== false) {
        echo "  [INFO] {$key} 存在，内容长度=" . strlen((string)$row['config_value']) . "（暂不加密，无敏感密码类字段）\n";
        $skipped++;
    }
}

// ============================================================
// 汇总
// ============================================================
echo "\n========================================\n";
echo " 迁移汇总\n";
echo "========================================\n";
echo " 已加密（跳过）: {$alreadyEnc}\n";
echo " 本次迁移:       {$migrated}\n";
echo " 无需迁移:       {$skipped}\n";
echo "========================================\n";

if (!$apply && $migrated > 0) {
    echo "\n提示：加 --apply 参数可实际写入数据库\n";
    echo "  php bin/migrate_encrypt.php --apply\n";
}

echo "\n⚠️  关于 api_keys.key_value 和 push_keys.key_value：\n";
echo "    这两个字段用于 WHERE key_value = ? 精确匹配鉴权，\n";
echo "    AES 加密后无法直接查询（相同明文每次密文不同）。\n";
echo "    如需加密需要双字段方案（key_hash + key_encrypted），\n";
echo "    涉及 migration + 11+ 处查询逻辑改动，建议后续评估。\n\n";
