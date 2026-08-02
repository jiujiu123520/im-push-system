#!/bin/bash
# ============================================================
# 即时消息推送系统 - 回滚脚本
#
# 用法:
#   bash deploy/rollback.sh [commit-hash]
#
# 功能:
#   - 回滚到上一次更新前的版本（自动读取 .last-update-backup）
#   - 或回滚到指定的 commit（通过参数传入）
#   - 重启所有服务使回滚生效
#
# 工作流程:
#   1. 确定回滚目标 commit
#   2. 显示待回滚的变更摘要，请求二次确认
#   3. git reset --hard 到目标 commit
#   4. 重新安装后端依赖（composer install）
#   5. 重新构建管理后台（npm run build）
#   6. 重启服务（PHP-FPM 清 opcache + 推送服务）
# ============================================================

set -e

# ------------------------------------------------------------
# 跨平台辅助函数
# ------------------------------------------------------------

# 智能 sudo 包装：已是 root 则直接执行，否则使用 sudo（若可用），否则直接执行
_sudo() {
    if [ "$(id -u)" = "0" ]; then
        "$@"
    elif command -v sudo >/dev/null 2>&1; then
        sudo "$@"
    else
        "$@"
    fi
}

# 检测是否有 systemd/systemctl
_has_systemctl() {
    command -v systemctl >/dev/null 2>&1
}

# 跨平台 CPU 核心数获取
get_nproc() {
    local n
    if command -v nproc >/dev/null 2>&1; then
        n=$(nproc 2>/dev/null || echo 1)
    elif [ -f /proc/cpuinfo ]; then
        n=$(grep -c '^processor' /proc/cpuinfo 2>/dev/null || echo 1)
    elif command -v sysctl >/dev/null 2>&1; then
        n=$(sysctl -n hw.ncpu 2>/dev/null || echo 1)
    else
        n=1
    fi
    [ -z "$n" ] || [ "$n" -lt 1 ] 2>/dev/null && n=1
    echo "$n"
}

PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
# 如果脚本存在，优先从脚本位置推断项目目录
SCRIPT_DIR=""
if [ -n "$0" ] && [ -f "$0" ]; then
    SCRIPT_DIR="$(cd "$(dirname "$0")" 2>/dev/null && pwd)"
    if [ -n "$SCRIPT_DIR" ] && [ -d "${SCRIPT_DIR}/../backend" ]; then
        PROJECT_DIR="$(cd "${SCRIPT_DIR}/.." 2>/dev/null && pwd)"
    fi
fi
BACKUP_FILE="${PROJECT_DIR}/.last-update-backup"

cd "$PROJECT_DIR" || exit 1

# ------------------------------------------------------------
# 颜色输出（禁用自动检测不支持时降级为纯文本）
# ------------------------------------------------------------
if [ -t 1 ]; then
    COLOR_GREEN='\033[0;32m'
    COLOR_YELLOW='\033[1;33m'
    COLOR_RED='\033[0;31m'
    COLOR_RESET='\033[0m'
else
    COLOR_GREEN=''
    COLOR_YELLOW=''
    COLOR_RED=''
    COLOR_RESET=''
fi

info()  { echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }

# ------------------------------------------------------------
# 确定回滚目标 commit
# ------------------------------------------------------------
# 优先使用参数指定的 commit
TARGET_COMMIT="$1"

# 如果未指定，尝试从 .last-update-backup 读取
if [[ -z "${TARGET_COMMIT}" ]]; then
    if [[ -f "${BACKUP_FILE}" ]]; then
        TARGET_COMMIT="$(cat "${BACKUP_FILE}")"
        info "从 .last-update-backup 读取到回滚目标: ${TARGET_COMMIT:0:8}"
    else
        warn "未找到 .last-update-backup 文件，将回滚到上一个 commit (HEAD~1)。"
        TARGET_COMMIT="HEAD~1"
    fi
fi

# 校验目标 commit 是否存在
if [[ "${TARGET_COMMIT}" != "HEAD~1" ]]; then
    if ! git rev-parse --verify "${TARGET_COMMIT}" >/dev/null 2>&1; then
        error "目标 commit 不存在: ${TARGET_COMMIT}"
        exit 1
    fi
fi

# 当前版本与目标版本
CURRENT_COMMIT="$(git rev-parse HEAD)"
CURRENT_SHORT="${CURRENT_COMMIT:0:8}"
TARGET_SHORT="$(git rev-parse --short "${TARGET_COMMIT}" 2>/dev/null || echo "${TARGET_COMMIT:0:8}")"

echo "========================================"
echo "  即时消息推送系统 - 回滚操作"
echo "========================================"
echo "项目目录:    ${PROJECT_DIR}"
echo "当前版本:    ${CURRENT_SHORT}"
echo "回滚目标:    ${TARGET_SHORT}"
echo ""

# 显示将要回滚的提交（用于二次确认）
info "本次回滚将丢弃以下提交："
git log --oneline "${TARGET_COMMIT}..HEAD" 2>/dev/null | head -n 20 || warn "无法获取提交日志"
echo ""

# 二次确认（非交互模式可通过环境变量 SKIP_CONFIRM=1 跳过）
if [[ "${SKIP_CONFIRM}" != "1" ]]; then
    read -r -p "确认回滚到 ${TARGET_SHORT}？此操作不可逆 [y/N]: " CONFIRM
    if [[ ! "${CONFIRM}" =~ ^[Yy]$ ]]; then
        warn "已取消回滚。"
        exit 0
    fi
fi

# ------------------------------------------------------------
# 1. 备份当前版本信息（便于排查）
# ------------------------------------------------------------
echo ""
echo "===== [1/5] 备份当前版本信息 ====="
echo "rollback_from=${CURRENT_COMMIT}" > "${PROJECT_DIR}/.last-rollback-from"
info "已记录回滚前版本: ${CURRENT_SHORT}"

# ------------------------------------------------------------
# 2. 执行 git reset --hard
# ------------------------------------------------------------
echo ""
echo "===== [2/5] 回滚代码 ====="
git reset --hard "${TARGET_COMMIT}"
info "代码已回滚到: $(git rev-parse --short HEAD)"

# ------------------------------------------------------------
# 3. 重新安装后端依赖
# ------------------------------------------------------------
echo ""
echo "===== [3/5] 重新安装后端依赖 ====="
cd backend
composer install --no-dev --optimize-autoloader
cd ..
info "后端依赖已重新安装。"

# ------------------------------------------------------------
# 4. 重新构建管理后台
# ------------------------------------------------------------
echo ""
echo "===== [4/5] 重新构建管理后台 ====="
cd admin
npm install
npm run build
cd ..
info "管理后台已重新构建。"

# ------------------------------------------------------------
# 5. 重启服务（清 opcache）
# ------------------------------------------------------------
echo ""
echo "===== [5/5] 重启服务 ====="

# 重启 PHP-FPM 清除 opcache（跨发行版适配，支持 systemd/sysvinit/service）
PHP_FPM_RESTARTED=false
if _has_systemctl; then
    for svc in php8.3-fpm php8.2-fpm php8.1-fpm php8.0-fpm php-fpm php-fpm83 php-fpm82 php-fpm81 php-fpm80; do
        if systemctl list-unit-files --type=service 2>/dev/null | grep -q "^${svc}"; then
            _sudo systemctl restart "${svc}" 2>/dev/null || true
            info "${svc} 已重启（opcache 已清除）。"
            PHP_FPM_RESTARTED=true
            break
        fi
    done
elif command -v service >/dev/null 2>&1; then
    for svc in php8.3-fpm php8.2-fpm php8.1-fpm php8.0-fpm php-fpm php-fpm83 php-fpm82 php-fpm81 php-fpm80; do
        if service --status-all 2>/dev/null | grep -q " ${svc} "; then
            _sudo service "${svc}" restart 2>/dev/null || true
            info "${svc} 已重启（opcache 已清除）。"
            PHP_FPM_RESTARTED=true
            break
        fi
    done
fi
[ "${PHP_FPM_RESTARTED}" != "true" ] && warn "未找到 PHP-FPM 服务，跳过 PHP-FPM 重启（如果使用 Swoole 常驻则无需此步）。"

# 重启推送服务（兼容非 systemd 环境）
if _has_systemctl; then
    _sudo systemctl restart push-http 2>/dev/null || warn "push-http 重启失败，请手动执行: systemctl restart push-http"
    sleep 1
    info "push-http 已触发重启。"

    _sudo systemctl restart push-websocket 2>/dev/null || warn "push-websocket 重启失败，请手动执行: systemctl restart push-websocket"
    sleep 1
    info "push-websocket 已触发重启。"
else
    warn "未检测到 systemctl，尝试使用 bin/stop.sh / start.sh 重启..."
    if [ -f backend/bin/stop.sh ] && [ -f backend/bin/start.sh ]; then
        (cd backend && bash bin/stop.sh 2>/dev/null; sleep 1; bash bin/start.sh 2>/dev/null)
    else
        warn "未找到 start/stop 脚本，请手动重启服务。"
    fi
fi

# 注意:APP 打包已迁移到 GitHub Actions,不再需要 push-build-worker 服务
# 如果存在遗留的 push-build-worker 服务,停止并禁用
if _has_systemctl; then
    if systemctl list-unit-files 2>/dev/null | grep -q 'push-build-worker'; then
        _sudo systemctl stop push-build-worker 2>/dev/null || true
        _sudo systemctl disable push-build-worker 2>/dev/null || true
        info "已停止并禁用遗留的 push-build-worker 服务"
    fi
fi

# ------------------------------------------------------------
# 健康检查
# ------------------------------------------------------------
echo ""
info "服务状态："
if _has_systemctl; then
    for svc in push-http push-websocket; do
        if systemctl is-active --quiet "${svc}" 2>/dev/null; then
            echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc}    [运行中]"
        else
            echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc}    [未运行]"
            if command -v journalctl >/dev/null 2>&1; then
                error "服务 ${svc} 未正常运行，请使用 journalctl -u ${svc} 查看日志"
            else
                error "服务 ${svc} 未正常运行，请查看服务日志"
            fi
        fi
    done
else
    # 非 systemd 环境，通过端口检测服务是否存活
    HTTP_PORT=$(grep -E '^HTTP_PORT=' backend/.env 2>/dev/null | cut -d= -f2 | tr -d '"' || echo "9501")
    WS_PORT=$(grep -E '^WEBSOCKET_PORT=' backend/.env 2>/dev/null | cut -d= -f2 | tr -d '"' || echo "9502")
    info "非 systemd 环境，通过端口检测服务存活:"
    for pair in "push-http:${HTTP_PORT}" "push-websocket:${WS_PORT}"; do
        svc="${pair%%:*}"
        port="${pair##*:}"
        if command -v ss >/dev/null 2>&1; then
            if ss -ltn 2>/dev/null | grep -q ":${port} "; then
                echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc} (端口 ${port})    [运行中]"
            else
                echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc} (端口 ${port})    [未监听]"
            fi
        elif command -v netstat >/dev/null 2>&1; then
            if netstat -ltn 2>/dev/null | grep -q ":${port} "; then
                echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc} (端口 ${port})    [运行中]"
            else
                echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc} (端口 ${port})    [未监听]"
            fi
        else
            info "  ${svc}: 请手动检查 PHP 进程: ps aux | grep index.php"
        fi
    done
fi

echo ""
echo -e "${COLOR_GREEN}========================================${COLOR_RESET}"
echo -e "${COLOR_GREEN}  回滚完成！${COLOR_RESET}"
echo -e "${COLOR_GREEN}========================================${COLOR_RESET}"
info "回滚前版本: ${CURRENT_SHORT}"
info "当前版本:   $(git rev-parse --short HEAD)"
echo ""
warn "如需再次更新到最新版本，请执行: bash deploy/update.sh"
