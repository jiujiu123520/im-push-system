#!/bin/bash
echo '===DB 字段（sudo）==='
sudo mysql push_system -e "SHOW COLUMNS FROM push_keys LIKE 'notify_offline_minutes'"
echo '===dist vs src 时间==='
stat -c '%y %n' /www/push-system/user/dist/index.html /www/push-system/user/src/views/keys/index.vue
echo '===dist 中搜索新字段（源码/转义两种形式）==='
grep -rl 'notify_offline_minutes' /www/push-system/user/dist/assets/ 2>/dev/null | head -2 || echo '未找到 notify_offline_minutes'
grep -rl '\\\\u6389\\\\u7ebf\\\\u9608\\\\u503c' /www/push-system/user/dist/assets/ 2>/dev/null | head -2 || echo '未找到unicode转义掉线阈值'
echo '===服务器代码版本==='
cd /www/push-system && git log --oneline -2
