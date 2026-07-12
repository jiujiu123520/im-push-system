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

PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
BACKUP_FILE="${PROJECT_DIR}/.last-update-backup"

cd "$PROJECT_DIR" || exit 1

# ------------------------------------------------------------
# 颜色输出
# ------------------------------------------------------------
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_RESET='\033[0m'

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

# 重启 PHP-FPM 清除 opcache
if systemctl list-unit-files | grep -q 'php8.2-fpm'; then
    systemctl restart php8.2-fpm
    info "php8.2-fpm 已重启（opcache 已清除）。"
elif systemctl list-unit-files | grep -q 'php-fpm'; then
    systemctl restart php-fpm
    info "php-fpm 已重启（opcache 已清除）。"
else
    warn "未找到 php8.2-fpm 或 php-fpm 服务，跳过 PHP-FPM 重启。"
fi

# 重启推送服务
systemctl restart push-http
sleep 1
info "push-http 已重启。"

systemctl restart push-websocket
sleep 1
info "push-websocket 已重启。"

systemctl restart push-build-worker
info "push-build-worker 已重启。"

# ------------------------------------------------------------
# 健康检查
# ------------------------------------------------------------
echo ""
info "服务状态："
for svc in push-http push-websocket push-build-worker; do
    if systemctl is-active --quiet "${svc}"; then
        echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc}    [运行中]"
    else
        echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc}    [未运行]"
        error "服务 ${svc} 未正常运行，请使用 journalctl -u ${svc} 查看日志"
    fi
done

echo ""
echo -e "${COLOR_GREEN}========================================${COLOR_RESET}"
echo -e "${COLOR_GREEN}  回滚完成！${COLOR_RESET}"
echo -e "${COLOR_GREEN}========================================${COLOR_RESET}"
info "回滚前版本: ${CURRENT_SHORT}"
info "当前版本:   $(git rev-parse --short HEAD)"
echo ""
warn "如需再次更新到最新版本，请执行: bash deploy/update.sh"
