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

# ---------------- 工具函数与环境检测 ----------------

# 检测是否有 sudo 可用
has_sudo() {
    command -v sudo >/dev/null 2>&1
}

# 检测是否有 systemctl 可用
has_systemctl() {
    command -v systemctl >/dev/null 2>&1
}

# 安全调用 sudo:有 sudo 就用 sudo,否则直接执行
run_sudo() {
    if has_sudo && [ "$(id -u)" -ne 0 ]; then
        sudo "$@"
    else
        "$@"
    fi
}

# 安全调用 systemctl:先检测是否存在
run_systemctl() {
    if has_systemctl; then
        run_sudo systemctl "$@"
    else
        echo "[WARN] systemctl 不可用,跳过: systemctl $*"
        return 0
    fi
}

# 推断 PROJECT_DIR:从脚本位置向上找包含 backend/.env 的目录,兜底 /www/push-system
find_project_dir() {
    local script_dir
    script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    local current_dir="$script_dir"
    while [ "$current_dir" != "/" ]; do
        if [ -f "$current_dir/backend/.env" ]; then
            echo "$current_dir"
            return 0
        fi
        current_dir="$(dirname "$current_dir")"
    done
    echo "/www/push-system"
}

# 动态检测当前操作用户(用于 git 等操作的权限)
# - 优先使用 SUDO_USER 反推
# - 否则当前用户
# - 注意:如果已是 root 且没有 SUDO_USER,则仍用当前 root(谨慎跳过)
detect_owner_user() {
    if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
        local group
        group="$(id -gn "$SUDO_USER" 2>/dev/null || echo "$SUDO_USER")"
        echo "${SUDO_USER}:${group}"
    else
        echo "$(id -un):$(id -gn)"
    fi
}

# 动态检测 web 用户(www-data/nginx/http/apache)
detect_web_user() {
    for user in www-data nginx http apache; do
        if id "$user" >/dev/null 2>&1; then
            local group
            group="$(id -gn "$user" 2>/dev/null || echo "$user")"
            echo "${user}:${group}"
            return 0
        fi
    done
    echo "www-data:www-data"
}

# 仅当非 root(或有指定 SUDO_USER)时才执行 chown
safe_chown() {
    local owner="$1"
    shift
    local target="$1"
    if [ "$(id -u)" -eq 0 ] && [ -z "${SUDO_USER:-}" ]; then
        return 0
    fi
    run_sudo chown -R "$owner" "$target"
}

# ---------------- 初始化变量 ----------------
PROJECT_DIR="$(find_project_dir)"
OWNER_USER="$(detect_owner_user)"
WEB_USER="$(detect_web_user)"
WEB_USER_NAME="${WEB_USER%%:*}"

echo "检测到项目目录: $PROJECT_DIR"
echo "检测到操作用户: $OWNER_USER"
echo "检测到 Web 用户: $WEB_USER"
echo ""

# ---------------- 1. 进入项目根目录 ----------------
cd "$PROJECT_DIR"

# ---------------- 2. 修复 .git 和目录权限 ----------------
# 上次 git checkout 失败说明权限有问题,先修复权限
safe_chown "$OWNER_USER" "$PROJECT_DIR/.git"
safe_chown "$OWNER_USER" "$PROJECT_DIR/build"
safe_chown "$OWNER_USER" "$PROJECT_DIR/app"

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

# ---------------- 7. 恢复 Web 用户对 build/app 目录的写入权限 ----------------
# build-worker 服务以 Web 用户运行,必须有写权限
safe_chown "$WEB_USER" "$PROJECT_DIR/build"
safe_chown "$WEB_USER" "$PROJECT_DIR/app"
if [ -d "$PROJECT_DIR/.gradle" ]; then
    safe_chown "$WEB_USER" "$PROJECT_DIR/.gradle"
fi

# ---------------- 8. 清理 Gradle 缓存中的损坏 jdkImage 变换缓存 ----------------
# 上次 jlink 失败可能留下损坏的缓存,必须清理否则可能继续报错
run_sudo rm -rf "/var/www/.gradle/caches/transforms-3/"*"/jdkImage" 2>/dev/null || true
run_sudo rm -rf "$PROJECT_DIR/.gradle/caches/transforms-3/"*"/jdkImage" 2>/dev/null || true
if [ -d "/var/www/.gradle" ]; then
    safe_chown "$WEB_USER" "/var/www/.gradle"
fi

# ---------------- 9. 检查 systemd 服务的 JAVA_HOME 环境变量配置 ----------------
echo "========== 检查 push-build-worker.service 环境变量 =========="
grep -E "JAVA_HOME|PATH" /etc/systemd/system/push-build-worker.service 2>/dev/null || echo "[WARN] 未在 service 中配置 JAVA_HOME"
echo ""

# ---------------- 10. 重新加载 systemd 并重启服务 ----------------
run_systemctl daemon-reload
run_systemctl reset-failed push-build-worker 2>/dev/null || true
run_systemctl reset-failed push-http 2>/dev/null || true
run_systemctl restart push-build-worker
run_systemctl restart push-http

# ---------------- 11. 验证服务状态 ----------------
sleep 2
echo "========== 服务状态 =========="
if has_systemctl; then
    run_sudo systemctl status push-build-worker --no-pager -l 2>/dev/null | head -20
    echo ""
    run_sudo systemctl status push-http --no-pager -l 2>/dev/null | head -10
else
    echo "[WARN] systemctl 不可用,跳过服务状态检查"
fi
echo ""

# ---------------- 12. 验证 jlink 工具是否可用(模拟 systemd 环境执行) ----------------
echo "========== 模拟 systemd 环境测试 jlink =========="
if id "$WEB_USER_NAME" >/dev/null 2>&1; then
    run_sudo -u "$WEB_USER_NAME" bash -c 'export JAVA_HOME=/usr/lib/jvm/java-17-openjdk-amd64 && export PATH=$JAVA_HOME/bin:$PATH && /usr/lib/jvm/java-17-openjdk-amd64/bin/jlink --version' && echo "[OK] jlink 可正常执行" || echo "[FAIL] jlink 仍无法执行"
else
    echo "[WARN] 未检测到 Web 用户 $WEB_USER_NAME,跳过 jlink 测试"
fi
echo ""

# ---------------- 13. 提交一个测试构建任务验证 ----------------
echo "========== 提交测试构建任务 =========="
BUILD_ID="test-$(date +%s)"
if id "$WEB_USER_NAME" >/dev/null 2>&1; then
    run_sudo -u "$WEB_USER_NAME" php -r "
require '$PROJECT_DIR/backend/vendor/autoload.php';
\$app = require '$PROJECT_DIR/backend/bootstrap/app.php';
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
else
    echo "[WARN] 未检测到 Web 用户 $WEB_USER_NAME,跳过提交测试任务"
fi
echo ""
echo "============================================================"
echo "更新完成!请执行以下命令实时查看构建日志:"
echo "  $(has_sudo && [ "$(id -u)" -ne 0 ] && echo "sudo ")journalctl -u push-build-worker -f"
echo ""
echo "或查看构建日志文件:"
echo "  $(has_sudo && [ "$(id -u)" -ne 0 ] && echo "sudo ")tail -f $PROJECT_DIR/build/logs/${BUILD_ID}.log"
echo "============================================================"
