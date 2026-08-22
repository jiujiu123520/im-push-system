<?php
/**
 * SQL 迁移执行器：按顺序执行 database/migrations/*.sql，
 * 已执行的记录在 schema_migrations 表中（幂等，可重复运行）。
 *
 * 由 GitHub Actions deploy.yml 在每次部署时自动调用，
 * 也可手动执行：php bin/migrate_sql.php
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

$cfg = \App\Service\Config::get('database');
if (empty($cfg['host']) || empty($cfg['database'])) {
    fwrite(STDERR, "数据库配置缺失（.env）\n");
    exit(1);
}

try {
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset=utf8mb4";
    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL UNIQUE,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$dir = BASE_PATH . '/database/migrations';
if (!is_dir($dir)) {
    echo "skip (no migrations dir)\n";
    exit(0);
}

$files = glob($dir . '/*.sql');
sort($files);

$applied = 0;
$skipped = 0;
foreach ($files as $f) {
    $name = basename($f);

    $st = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE filename = ?");
    $st->execute([$name]);
    if ($st->fetchColumn() > 0) {
        $skipped++;
        continue;
    }

    $sql = file_get_contents($f);
    if ($sql === false) {
        fwrite(STDERR, "无法读取迁移文件: {$name}\n");
        exit(1);
    }

    // PDO::exec 只执行首条语句，多语句迁移用 mysqli multi_query 兜底
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        $mysqli = @new mysqli($cfg['host'], $cfg['username'], $cfg['password'], $cfg['database'], (int)$cfg['port']);
        if ($mysqli->connect_errno) {
            fwrite(STDERR, "mysqli 连接失败: " . $mysqli->connect_error . "\n");
            exit(1);
        }
        $mysqli->set_charset('utf8mb4');
        $ok = $mysqli->multi_query($sql);
        if ($ok) {
            while ($mysqli->more_results() && $mysqli->next_result()) {
                $mysqli->store_result();
            }
        }
        if ($mysqli->error) {
            fwrite(STDERR, "迁移失败 {$name}: " . $mysqli->error . "\n");
            $mysqli->close();
            exit(1);
        }
        $mysqli->close();
    }

    $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?)")->execute([$name]);
    $applied++;
    echo "  applied: {$name}\n";
}

echo "migrations: applied={$applied}, skipped={$skipped}\n";
exit(0);
