#!/bin/bash
echo '===真实数据库名==='
sudo grep -E '^DB_(DATABASE|USERNAME)=' /www/push-system/backend/.env
DB=$(sudo grep '^DB_DATABASE=' /www/push-system/backend/.env | cut -d= -f2)
echo "DB=$DB"
echo '===DB 字段检查==='
sudo mysql "$DB" -e "SHOW COLUMNS FROM push_keys LIKE 'notify_offline_minutes'"
echo '===dist 时间再次检查（Actions是否刚完成构建）==='
stat -c '%y' /www/push-system/user/dist/index.html
echo '===deploy 进程是否在跑==='
ps aux | grep -E 'npm|vite|update.sh' | grep -v grep | head -5 || echo '无构建进程'
