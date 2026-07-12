#!/bin/bash
# 服务器更新脚本
# 用法: bash deploy/update.sh
set -e

# ============================================================
# 即时消息推送系统 - 服务器更新脚本
#
# 功能：
#   1. 拉取最新代码（git pull origin main）
#   2. 更新后端依赖（composer install --no-dev）
#      构建管理后台（npm install && npm run build）
#   3. 执行数据库迁移（按文件名顺序执行未应用的 .sql）
#   4. 重启服务（重启 PHP-FPM 清 opcache + 重启推送服务）
#
# 错误处理：
#   - set -e 任意步骤失败立即退出
#   - 更新前自动备份当前 commit hash 到 .last-update-backup
#   - 失败时输出回滚提示
# ============================================================

PROJECT_DIR="/www/push-system"
DB_NAME="${DB_NAME:-im_push}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
MIGRATIONS_TABLE="schema_migrations"

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
# 失败时输出回滚提示（trap）
# ------------------------------------------------------------
update_failed() {
    error "========================================"
    error "  更新失败！"
    error "========================================"
    error "失败发生在步骤: ${CURRENT_STEP:-未知}"
    warn  "如需回滚到更新前的版本，请执行："
    echo  ""
    echo  -e "  ${COLOR_YELLOW}bash deploy/rollback.sh${COLOR_RESET}"
    echo  ""
    warn  "回滚前已自动备份的 commit: $(cat ${PROJECT_DIR}/.last-update-backup 2>/dev/null || echo '未找到备份')"
}
trap update_failed ERR

# ------------------------------------------------------------
# 记录更新前的 commit hash（用于回滚）
# ------------------------------------------------------------
PREV_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
if [[ -n "${PREV_COMMIT}" ]]; then
    echo "${PREV_COMMIT}" > "${PROJECT_DIR}/.last-update-backup"
    info "已备份当前 commit: ${PREV_COMMIT:0:8}"
fi

echo "===== 开始更新 ====="
echo "项目目录: ${PROJECT_DIR}"
echo "更新前版本: ${PREV_COMMIT:0:8}"
echo ""

# ============================================================
# 1. 拉取最新代码
# ============================================================
CURRENT_STEP="[1/4] 拉取最新代码"
echo "[1/4] 拉取最新代码..."
git pull origin main
info "代码拉取完成。"

# 输出本次更新涉及的提交日志
NEW_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
if [[ -n "${PREV_COMMIT}" && "${PREV_COMMIT}" != "${NEW_COMMIT}" ]]; then
    info "本次更新包含以下提交："
    git log --oneline "${PREV_COMMIT}..HEAD" | head -n 20
fi

# ============================================================
# 2. 安装/更新依赖
# ============================================================
CURRENT_STEP="[2/4] 更新依赖"
echo ""
echo "[2/4] 更新依赖..."

# 后端依赖（生产环境，优化自动加载）
info "安装后端依赖 (composer install --no-dev --optimize-autoloader)..."
cd backend && composer install --no-dev --optimize-autoloader && cd ..

# 前端依赖与构建
info "构建管理后台 (npm install && npm run build)..."
cd admin && npm install && npm run build && cd ..

info "依赖更新完成。"

# ============================================================
# 3. 执行数据库迁移
# ============================================================
CURRENT_STEP="[3/4] 执行数据库迁移"
echo ""
echo "[3/4] 执行数据库迁移..."

MIGRATIONS_DIR="${PROJECT_DIR}/backend/database/migrations"

# 构造 MySQL 连接参数
MYSQL_OPTS=("-h${DB_HOST:-127.0.0.1}" "-u${DB_USER}")
if [[ -n "${DB_PASS}" ]]; then
    MYSQL_OPTS+=("-p${DB_PASS}")
fi

if [[ -d "${MIGRATIONS_DIR}" ]]; then
    # 创建迁移记录表（如不存在）
    mysql "${MYSQL_OPTS[@]}" "${DB_NAME}" <<EOF
CREATE TABLE IF NOT EXISTS \`${MIGRATIONS_TABLE}\` (
    \`id\` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    \`filename\` VARCHAR(255) NOT NULL UNIQUE,
    \`applied_at\` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF

    APPLIED_COUNT=0
    SKIPPED_COUNT=0

    # 按文件名顺序执行迁移
    for sql_file in $(ls -1 "${MIGRATIONS_DIR}"/*.sql 2>/dev/null | sort); do
        filename="$(basename "${sql_file}")"

        # 检查是否已执行
        ALREADY_APPLIED=$(mysql "${MYSQL_OPTS[@]}" "${DB_NAME}" -sN -e \
            "SELECT COUNT(*) FROM \`${MIGRATIONS_TABLE}\` WHERE filename='${filename}';" 2>/dev/null || echo 0)

        if [[ "${ALREADY_APPLIED}" -gt 0 ]]; then
            info "  跳过(已应用): ${filename}"
            SKIPPED_COUNT=$((SKIPPED_COUNT + 1))
        else
            info "  执行: ${filename}"
            if mysql "${MYSQL_OPTS[@]}" "${DB_NAME}" < "${sql_file}"; then
                mysql "${MYSQL_OPTS[@]}" "${DB_NAME}" -e \
                    "INSERT INTO \`${MIGRATIONS_TABLE}\` (filename) VALUES ('${filename}');"
                APPLIED_COUNT=$((APPLIED_COUNT + 1))
            else
                error "迁移失败: ${filename}"
                exit 1
            fi
        fi
    done

    info "数据库迁移完成（本次应用 ${APPLIED_COUNT} 个，跳过 ${SKIPPED_COUNT} 个）。"
else
    warn "未找到迁移脚本目录: ${MIGRATIONS_DIR}"
fi

# ============================================================
# 4. 重启服务（清opcache）
# ============================================================
CURRENT_STEP="[4/4] 重启服务"
echo ""
echo "[4/4] 重启服务..."

# 重启 PHP-FPM 清除 opcache（避免旧代码缓存导致错误）
if systemctl list-unit-files | grep -q 'php8.2-fpm'; then
    systemctl restart php8.2-fpm
    info "php8.2-fpm 已重启（opcache 已清除）。"
elif systemctl list-unit-files | grep -q 'php-fpm'; then
    systemctl restart php-fpm
    info "php-fpm 已重启（opcache 已清除）。"
else
    warn "未找到 php8.2-fpm 或 php-fpm 服务，跳过 PHP-FPM 重启。"
fi

# 重启推送服务（按依赖顺序：HTTP → WebSocket → 打包工作进程）
systemctl restart push-http
sleep 1
info "push-http 已重启。"

systemctl restart push-websocket
sleep 1
info "push-websocket 已重启。"

# 检查 build/ 目录是否有变更，决定是否重启打包工作进程
if git diff --quiet "${PREV_COMMIT}" HEAD -- build/ 2>/dev/null; then
    info "build/ 目录无变更，跳过 push-build-worker 重启。"
else
    systemctl restart push-build-worker
    info "push-build-worker 已重启（build/ 目录有变更）。"
fi

# ------------------------------------------------------------
# 服务健康检查
# ------------------------------------------------------------
info "服务状态："
for svc in push-http push-websocket push-build-worker; do
    if systemctl is-active --quiet "${svc}"; then
        echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc}    [运行中]"
    else
        echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc}    [未运行]"
        error "服务 ${svc} 未正常运行，请使用 journalctl -u ${svc} 查看日志"
    fi
done

# 取消 trap（更新成功）
trap - ERR

echo ""
echo "===== 更新完成 ====="
info "更新前版本: ${PREV_COMMIT:0:8}"
info "当前版本:   ${NEW_COMMIT:0:8}"
echo ""
warn "如遇异常，可执行回滚: bash deploy/rollback.sh"
