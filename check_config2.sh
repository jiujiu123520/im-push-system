#!/bin/bash
set -e
# 用 sudo 直接从 PHP 环境变量拿
ENV=$(sudo -u www-data bash -c 'cd /www/push-system/backend && php -r "
require __DIR__.\"/vendor/autoload.php\";
\$db=parse_ini_file(__DIR__.\"/.env\");
echo \"DB_NAME=\".\$db[\"DB_NAME\"].\"\\n\";
echo \"DB_USER=\".\$db[\"DB_USERNAME\"].\"\\n\";
echo \"DB_PASS=\".\$db[\"DB_PASSWORD\"].\"\\n\";
"')
eval "$ENV"
echo "DB_USER=$DB_USER DB_NAME=$DB_NAME"

echo '--- SHOW COLUMNS ---'
sudo -u www-data bash -c "mysql -u'$DB_USER' -p'$DB_PASS' '$DB_NAME' -e \"SHOW COLUMNS FROM push_keys WHERE Field IN ('notify_enabled','notify_email','notify_interval','notify_offline_minutes')\" 2>&1 | grep -v Warning"

echo '--- Sample rows ---'
sudo -u www-data bash -c "mysql -u'$DB_USER' -p'$DB_PASS' '$DB_NAME' -e \"SELECT id,name,notify_enabled,notify_interval,notify_offline_minutes FROM push_keys ORDER BY id DESC LIMIT 4\" 2>&1 | grep -v Warning"
