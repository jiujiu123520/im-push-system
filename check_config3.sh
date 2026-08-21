#!/bin/bash
set -e
# 用 Dotenv 类读取 (syntax 是 YAML-like not standard ini)
sudo -u www-data bash -c 'cd /www/push-system/backend && php -r "
require __DIR__.\"/vendor/autoload.php\";
\$c = file_get_contents(__DIR__.\"/.env\");
preg_match(\"/^DB_NAME=(.+)$/m\", \$c, \$m1);
preg_match(\"/^DB_USERNAME=(.+)$/m\", \$c, \$m2);
preg_match(\"/^DB_PASSWORD=(.+)$/m\", \$c, \$m3);
\$n=trim(\$m1[1]); \$u=trim(\$m2[1]); \$p=trim(\$m3[1]);
echo \"DB_NAME=\".\$n.\"\nDB_USER=\".\$u.\"\nDB_PASS=\".escapeshellarg(\$p).\"\n\";
"'
