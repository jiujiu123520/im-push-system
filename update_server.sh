#!/bin/bash
# ============================================================
# 服务器代码更新脚本 - 修复 APP 构建系统
# 目标:拉取最新代码(包含 191a5cd 和 68130f5 两个关键修复)
# 修复点:
#   1. inject_config.sh 用 for 循环替代进程替换(修复 line 211 syntax error)
#   2. build_apk.sh 自动检测并设置 JAVA_HOME(修复 jlink 执行失败)
# 使用方式:
#   bash update_server.sh
# ============================================================
set -e

# ---------------- 1. 进入项目根目录 ----------------
cd /www/push-system

# ---------------- 2. 修复 .git 和目录权限 ----------------
# 上次 git checkout 失败说明权限有问题,先修复权限
sudo chown -R ubuntu:ubuntu /www/push-system/.git
sudo chown -R ubuntu:ubuntu /www/push-system/build
sudo chown -R ubuntu:ubuntu /www/push-system/app

# ---------------- 3. 丢弃本地所有修改(避免 merge 冲突) ----------------
# build/ 和 app/ 目录的注入产物、包名残留目录全部清理
git checkout -- build/ app/ 2>/dev/null || true
git clean -fd build/ app/ 2>/dev/null || true

# ---------------- 4. 拉取最新代码(使用 gh.jasonzeng.dev 代理加速) ----------------
git fetch https://gh.jasonzeng.dev/https://github.com/jiujiu123520/im-push-system.git main

# ---------------- 5. 合并代码(--no-edit 避免交互式提示) ----------------
git merge --no-edit FETCH_HEAD

# ---------------- 6. 验证代码版本(关键检查点) ----------------
echo "========== 当前代码版本 =========="
git log --oneline -5
echo ""
echo "========== 验证两个关键修复是否已包含 =========="
echo "--- 1. 检查 build_apk.sh 是否包含 JAVA_HOME 自动检测 ---"
grep -n "JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64" build/build_apk.sh && echo "[OK] build_apk.sh JAVA_HOME 修复已包含" || echo "[FAIL] build_apk.sh 修复未生效"
echo ""
echo "--- 2. 检查 inject_config.sh 是否使用 for 循环(而非进程替换 < <(find)) ---"
grep -n 'for dir in "\$JAVA_SRC_ROOT"/\*/\*/\*' build/inject_config.sh && echo "[OK] inject_config.sh for 循环修复已包含" || echo "[FAIL] inject_config.sh 修复未生效"
echo ""

# ---------------- 7. 恢复 www-data 用户对 build/app 目录的写入权限 ----------------
# build-worker 服务以 www-data 用户运行,必须有写权限
sudo chown -R www-data:www-data /www/push-system/build
sudo chown -R www-data:www-data /www/push-system/app
sudo chown -R www-data:www-data /www/push-system/.gradle 2>/dev/null || true

# ---------------- 8. 清理 Gradle 缓存中的损坏 jdkImage 变换缓存 ----------------
# 上次 jlink 失败可能留下损坏的缓存,必须清理否则可能继续报错
sudo rm -rf /var/www/.gradle/caches/transforms-3/*/jdkImage 2>/dev/null || true
sudo rm -rf /www/push-system/.gradle/caches/transforms-3/*/jdkImage 2>/dev/null || true
sudo chown -R www-data:www-data /var/www/.gradle 2>/dev/null || true

# ---------------- 9. 检查 systemd 服务的 JAVA_HOME 环境变量配置 ----------------
echo "========== 检查 push-build-worker.service 环境变量 =========="
grep -E "JAVA_HOME|PATH" /etc/systemd/system/push-build-worker.service || echo "[WARN] 未在 service 中配置 JAVA_HOME"
echo ""

# ---------------- 10. 重新加载 systemd 并重启服务 ----------------
sudo systemctl daemon-reload
sudo systemctl reset-failed push-build-worker 2>/dev/null || true
sudo systemctl reset-failed push-http 2>/dev/null || true
sudo systemctl restart push-build-worker
sudo systemctl restart push-http

# ---------------- 11. 验证服务状态 ----------------
sleep 2
echo "========== 服务状态 =========="
sudo systemctl status push-build-worker --no-pager -l | head -20
echo ""
sudo systemctl status push-http --no-pager -l | head -10
echo ""

# ---------------- 12. 验证 jlink 工具是否可用(模拟 systemd 环境执行) ----------------
echo "========== 模拟 systemd 环境测试 jlink =========="
sudo -u www-data bash -c 'export JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 && export PATH=$JAVA_HOME/bin:$PATH && /usr/lib/jvm/java-17-openjdk-amd64/bin/jlink --version' && echo "[OK] jlink 可正常执行" || echo "[FAIL] jlink 仍无法执行"
echo ""

# ---------------- 13. 提交一个测试构建任务验证 ----------------
echo "========== 提交测试构建任务 =========="
BUILD_ID="test-$(date +%s)"
sudo -u www-data php -r "
require '/www/push-system/backend/vendor/autoload.php';
\$app = require '/www/push-system/backend/bootstrap/app.php';
\$app->boot();
\$redis = new Redis();
\$redis->connect('127.0.0.1', 6379);
\$task = json_encode([
    'build_id' => '${BUILD_ID}',
    'app_name' => '测试APP',
    'default_key' => 'testkey123',
    'server_url' => 'http://124.220.64.209:7070',
    'ws_url' => 'ws://124.220.64.209:9393',
    'package_name' => 'io.test.app',
]);
\$redis->lPush('push:build:queue', \$task);
echo '已提交测试构建任务: ${BUILD_ID}\n';
"
echo ""
echo "============================================================"
echo "更新完成!请执行以下命令实时查看构建日志:"
echo "  sudo journalctl -u push-build-worker -f"
echo ""
echo "或查看构建日志文件:"
echo "  sudo tail -f /www/push-system/build/logs/${BUILD_ID}.log"
echo "============================================================"
