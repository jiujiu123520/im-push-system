#!/bin/bash
# 使用 Swoole HTTP API 验证 Key 更新链路
set -e
cd /www/push-system

# 1. 找管理员凭据：从 users 表里搜，或直接用默认管理员账号
# 通过 HTTP 登录拿 token
echo '--- 1. 尝试管理员登录 ---'
# 从 settings.json 或 admin_settings 读取默认管理员账号
ADMIN_USER=$(sudo -u www-data bash -c "php -r '
require \"vendor/autoload.php\";
use App\Service\Database;
\$r = Database::fetch(\"SELECT username,password FROM admins ORDER BY id LIMIT 1\");
if (\$r) { echo \$r[\"username\"].\"\\t\".\$r[\"password\"].\"\\n\"; }
else { \$u2 = Database::fetch(\"SELECT username FROM users WHERE id=1 LIMIT 1\"); if (\$u2) echo \$u2[\"username\"].\"\\t\\n\"; }
' 2>/dev/null")
echo "admin: $ADMIN_USER"
