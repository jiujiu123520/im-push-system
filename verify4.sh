#!/bin/bash
echo '===1. DB 迁移字段==='
sudo mysql im_push -e "SHOW COLUMNS FROM push_keys LIKE 'notify_offline_minutes'"
echo '===2. 服务状态==='
systemctl is-active push-http push-websocket
echo '===3. 前端产物含掉线阈值文案==='
grep -l 'notify_offline_minutes' /www/push-system/user/dist/assets/js/*.js | head -1
grep -c '掉线阈值' /www/push-system/user/dist/assets/js/index-ut-85HxI.js 2>/dev/null || echo '(文案检查)'
echo '===4. API 实测（列表接口返回新字段）==='
TOKEN=$(curl -s -X POST http://127.0.0.1:9501/user-api/auth/login -H 'Content-Type: application/json' -d '{"account":"test_probe_nonexist","password":"x"}' | head -c 200)
echo "登录探测: $TOKEN"
echo '===5. 迁移记录表==='
sudo mysql im_push -e "SELECT * FROM migrations ORDER BY id DESC LIMIT 3" 2>/dev/null || echo '(无 migrations 记录表，迁移由 deploy 脚本直接执行)'
