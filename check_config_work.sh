#!/bin/bash
set -e
cd /www/push-system
DB=$(sudo grep '^DB_NAME=' backend/.env | cut -d= -f2 | tr -d '\r')
DU=$(sudo grep '^DB_USERNAME=' backend/.env | cut -d= -f2 | tr -d '\r')
DP=$(sudo grep '^DB_PASSWORD=' backend/.env | cut -d= -f2 | tr -d '\r')

echo '--- DB: 列结构 (push_keys) ---'
sudo mysql -u"$DU" -p"$DP" "$DB" -e "SHOW COLUMNS FROM push_keys WHERE Field IN ('notify_enabled','notify_email','notify_interval','notify_offline_minutes')\G" 2>&1 | grep -v Warning

echo '--- DB: 前3条样例记录 ---'
sudo mysql -u"$DU" -p"$DP" "$DB" -e "SELECT id,name,notify_enabled,notify_interval,notify_offline_minutes FROM push_keys ORDER BY id DESC LIMIT 3" 2>&1 | grep -v Warning

echo '--- LIST API ---'
# 先用内部 HTTP (9501) 调用接口，需要 admin token。退而求其次用一段 PHP 脚本直接调用 Controller 逻辑
cat > /tmp/check_keys.php <<'PHP'
<?php
require __DIR__ . '/../www/push-system/backend/vendor/autoload.php';
// 直接测 DB 和 PushKeyController appendNotifyFields 逻辑
use App\Service\Database;
$_ENV = [];
$env = parse_ini_file(__DIR__ . '/../www/push-system/backend/.env');
foreach ($env as $k => $v) $_ENV[$k] = $v;
Database::init();
$rows = Database::fetchAll("SELECT id,name,status FROM push_keys ORDER BY id DESC LIMIT 1");
$ctrl = new \App\Controller\PushKeyController();
$rf = new ReflectionMethod($ctrl, 'appendNotifyFields');
$rf->setAccessible(true);
$withNotify = $rf->invoke($ctrl, $rows);
echo json_encode($withNotify, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
PHP
echo '(appendNotifyFields not called, using direct sql instead)'
sudo mysql -u"$DU" -p"$DP" "$DB" -e "SELECT id,notify_email,notify_enabled,notify_interval,notify_offline_minutes FROM push_keys ORDER BY id DESC LIMIT 1\G" 2>&1 | grep -v Warning

echo '--- 尝试构造一次 UPDATE 调用（通过 HTTP 127.0.0.1:9501）---'
# 尝试不带 token 发 PUT 看 401 还是能到 Controller
echo 'PUT without token should return 401:'
curl -s -o /dev/null -w 'http=%{http_code}\n' -X PUT http://127.0.0.1:9501/admin/keys/1 -H 'Content-Type: application/json' -d '{"notify_offline_minutes":15}'
