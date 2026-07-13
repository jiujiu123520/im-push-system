#!/bin/bash
# ============================================================
# 即时消息推送系统 - 服务器更新脚本
#
# 功能：
#   1. 版本预检：对比本地与云端版本，显示差异
#   2. 拉取最新代码（支持代理加速）
#   3. 更新后端依赖（composer install --no-dev）
#      构建管理后台（npm install && npm run build）
#   4. 执行数据库迁移（按文件名顺序执行未应用的 .sql）
#   5. 重启服务（重启 PHP-FPM 清 opcache + 重启推送服务）
#
# 用法:
#   bash deploy/update.sh                    # 正常更新
#   bash deploy/update.sh --check            # 仅检查版本，不更新
#   bash deploy/update.sh --proxy=http://127.0.0.1:7890   # 使用 HTTP 代理拉取
#   bash deploy/update.sh --gh-proxy         # 使用 gh.jasonzeng.dev GitHub 代理
#   bash deploy/update.sh --skip-check       # 跳过版本检查直接更新
#   bash deploy/update.sh --skip-build       # 跳过前端构建
#   bash deploy/update.sh --skip-migration   # 跳过数据库迁移
#
# 环境变量:
#   PROJECT_DIR    - 项目目录（默认 /www/push-system）
#   GIT_PROXY      - Git 代理地址（如 http://127.0.0.1:7890）
#   GH_PROXY       - 是否使用 GitHub 代理（1=启用）
#   SKIP_CONFIRM   - 跳过二次确认（1=跳过）
#
# 错误处理：
#   - set -e 任意步骤失败立即退出
#   - 更新前自动备份当前 commit hash 到 .last-update-backup
#   - 失败时输出回滚提示
# ============================================================

set -e

PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
MIGRATIONS_TABLE="schema_migrations"

# ------------------------------------------------------------
# 解析命令行参数
# ------------------------------------------------------------
CHECK_ONLY=""
SKIP_CHECK=""
SKIP_BUILD=""
SKIP_MIGRATION=""
GIT_PROXY="${GIT_PROXY:-}"
GH_PROXY="${GH_PROXY:-}"

for arg in "$@"; do
    case $arg in
        --check)                CHECK_ONLY="1" ;;
        --skip-check)           SKIP_CHECK="1" ;;
        --skip-build)           SKIP_BUILD="1" ;;
        --skip-migration)       SKIP_MIGRATION="1" ;;
        --proxy=*)              GIT_PROXY="${arg#*=}" ;;
        --gh-proxy)             GH_PROXY="1" ;;
        --project-dir=*)        PROJECT_DIR="${arg#*=}" ;;
        --yes)                  SKIP_CONFIRM="1" ;;
        -h|--help)
            head -n 50 "$0"
            exit 0
            ;;
    esac
done

cd "$PROJECT_DIR" || exit 1

# Git 安全目录配置
git config --global --add safe.directory "$PROJECT_DIR"

# 从 .env 读取数据库配置
if [[ -f "${PROJECT_DIR}/backend/.env" ]]; then
    DB_NAME="$(grep -E '^DB_NAME=' "${PROJECT_DIR}/backend/.env" | cut -d'=' -f2-)"
    DB_USER="$(grep -E '^DB_USER=' "${PROJECT_DIR}/backend/.env" | cut -d'=' -f2-)"
    DB_PASS="$(grep -E '^DB_PASS=' "${PROJECT_DIR}/backend/.env" | cut -d'=' -f2-)"
    DB_HOST="$(grep -E '^DB_HOST=' "${PROJECT_DIR}/backend/.env" | cut -d'=' -f2-)"
fi
DB_NAME="${DB_NAME:-im_push}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"

# ------------------------------------------------------------
# 颜色输出
# ------------------------------------------------------------
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_BLUE='\033[0;34m'
COLOR_CYAN='\033[0;36m'
COLOR_RESET='\033[0m'

info()  { echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }
step()  { echo -e "\n${COLOR_BLUE}===== $1 =====${COLOR_RESET}"; }

# ------------------------------------------------------------
# 代理配置函数
# ------------------------------------------------------------
setup_git_proxy() {
    local proxy_url="$1"
    if [[ -n "${proxy_url}" ]]; then
        info "配置 Git 代理: ${proxy_url}"
        git config --global http.proxy "${proxy_url}"
        git config --global https.proxy "${proxy_url}"
        export HTTP_PROXY="${proxy_url}"
        export HTTPS_PROXY="${proxy_url}"
    elif [[ "${GH_PROXY}" == "1" ]]; then
        info "使用 GitHub 代理加速 (gh.jasonzeng.dev)..."
        local remote_url
        remote_url="$(git remote get-url origin 2>/dev/null || echo '')"
        # 检查是否已经包含代理前缀，避免重复添加
        if [[ "${remote_url}" =~ github\.com ]] && [[ ! "${remote_url}" =~ gh\.jasonzeng\.dev ]]; then
            local new_url="${remote_url/github.com/gh.jasonzeng.dev\/https:\/\/github.com}"
            info "  替换远程地址: ${remote_url} -> ${new_url}"
            git remote set-url origin "${new_url}"
            echo "${remote_url}" > "${PROJECT_DIR}/.git-original-origin"
        elif [[ "${remote_url}" =~ gh\.jasonzeng\.dev ]]; then
            info "  远程地址已包含代理前缀，无需替换"
        fi
    fi
}

# 恢复代理设置
restore_git_proxy() {
    if [[ -n "${GIT_PROXY}" ]]; then
        info "恢复 Git 代理设置..."
        git config --global --unset http.proxy 2>/dev/null || true
        git config --global --unset https.proxy 2>/dev/null || true
        unset HTTP_PROXY
        unset HTTPS_PROXY
    fi
    if [[ "${GH_PROXY}" == "1" && -f "${PROJECT_DIR}/.git-original-origin" ]]; then
        local original_url
        original_url="$(cat "${PROJECT_DIR}/.git-original-origin")"
        if [[ -n "${original_url}" ]]; then
            info "恢复原始远程地址..."
            git remote set-url origin "${original_url}"
            rm -f "${PROJECT_DIR}/.git-original-origin"
        fi
    fi
}

# ------------------------------------------------------------
# 版本检查函数
# ------------------------------------------------------------
check_version() {
    echo ""
    step "版本一致性检查"

    local local_commit remote_commit local_short remote_short
    local ahead_count behind_count

    # 获取本地版本
    local_commit="$(git rev-parse HEAD 2>/dev/null || echo '')"
    if [[ -z "${local_commit}" ]]; then
        error "无法获取本地版本信息，不是 Git 仓库？"
        return 1
    fi
    local_short="${local_commit:0:8}"

    # 先 fetch 获取远程最新信息（浅 fetch，速度快）
    info "获取远程版本信息..."
    git fetch origin --depth=50 2>/dev/null || git fetch origin 2>/dev/null || {
        warn "无法连接到远程仓库，请检查网络或代理设置"
        return 1
    }

    # 获取远程 main 分支最新 commit
    remote_commit="$(git rev-parse origin/main 2>/dev/null || git rev-parse origin/master 2>/dev/null || echo '')"
    if [[ -z "${remote_commit}" ]]; then
        warn "无法获取远程版本信息"
        return 1
    fi
    remote_short="${remote_commit:0:8}"

    echo ""
    echo -e "  本地版本:    ${COLOR_CYAN}${local_short}${COLOR_RESET}"
    echo -e "  云端版本:    ${COLOR_CYAN}${remote_short}${COLOR_RESET}"
    echo ""

    # 对比版本
    if [[ "${local_commit}" == "${remote_commit}" ]]; then
        echo -e "  版本状态:    ${COLOR_GREEN}✓ 一致（已是最新版本）${COLOR_RESET}"
        echo ""
        return 0
    fi

    # 计算差异提交数
    ahead_count="$(git rev-list --count HEAD..origin/main 2>/dev/null || echo 0)"
    behind_count="$(git rev-list --count origin/main..HEAD 2>/dev/null || echo 0)"

    if [[ "${ahead_count}" -gt 0 ]]; then
        echo -e "  版本状态:    ${COLOR_YELLOW}落后 ${ahead_count} 个提交${COLOR_RESET}"
        echo ""
        info "云端新增的提交（最近 10 条）："
        git log --oneline "HEAD..origin/main" 2>/dev/null | head -n 10 | while read -r line; do
            echo -e "    ${COLOR_YELLOW}↑${COLOR_RESET} ${line}"
        done
        echo ""
        return 2
    elif [[ "${behind_count}" -gt 0 ]]; then
        echo -e "  版本状态:    ${COLOR_YELLOW}本地领先 ${behind_count} 个提交${COLOR_RESET}"
        echo ""
        warn "本地有未推送的提交，更新将被覆盖！"
        warn "本地领先的提交（最近 10 条）："
        git log --oneline "origin/main..HEAD" 2>/dev/null | head -n 10 | while read -r line; do
            echo -e "    ${COLOR_RED}↓${COLOR_RESET} ${line}"
        done
        echo ""
        return 3
    fi

    return 1
}

# ------------------------------------------------------------
# 失败时输出回滚提示（trap）
# ------------------------------------------------------------
update_failed() {
    local exit_code=$?
    if [[ ${exit_code} -ne 0 ]]; then
        error "========================================"
        error "  更新失败！"
        error "========================================"
        error "失败发生在步骤: ${CURRENT_STEP:-未知}"
        warn  "如需回滚到更新前的版本，请执行："
        echo  ""
        echo  -e "  ${COLOR_YELLOW}bash deploy/rollback.sh${COLOR_RESET}"
        echo  ""
        warn  "回滚前已自动备份的 commit: $(cat ${PROJECT_DIR}/.last-update-backup 2>/dev/null || echo '未找到备份')"
        # 清理代理
        restore_git_proxy
    fi
}
trap update_failed ERR

# ============================================================
# 主流程
# ============================================================
echo "========================================"
echo "  即时消息推送系统 - 一键更新"
echo "========================================"
echo "项目目录: ${PROJECT_DIR}"
echo ""

# 版本检查
if [[ "${SKIP_CHECK}" != "1" ]]; then
    # 版本检查时也使用代理
    setup_git_proxy "${GIT_PROXY}"
    check_version
    VERSION_STATUS=$?
    restore_git_proxy

    if [[ "${CHECK_ONLY}" == "1" ]]; then
        # 仅检查模式，退出
        exit 0
    fi

    # 根据版本状态决定是否继续
    if [[ ${VERSION_STATUS} -eq 0 ]]; then
        # 版本一致，询问是否继续
        if [[ "${SKIP_CONFIRM}" != "1" ]]; then
            read -r -p "已是最新版本，仍要执行完整更新流程吗？[y/N]: " CONFIRM
            if [[ ! "${CONFIRM}" =~ ^[Yy]$ ]]; then
                info "已取消更新。"
                exit 0
            fi
        else
            info "已是最新版本，继续执行更新流程..."
        fi
    elif [[ ${VERSION_STATUS} -eq 3 ]]; then
        # 本地领先，需要确认是否强制覆盖
        warn "本地版本领先于云端，继续将覆盖本地修改！"
        if [[ "${SKIP_CONFIRM}" != "1" ]]; then
            read -r -p "确认要强制更新并覆盖本地修改吗？此操作不可逆 [y/N]: " CONFIRM
            if [[ ! "${CONFIRM}" =~ ^[Yy]$ ]]; then
                info "已取消更新。"
                exit 0
            fi
        fi
    fi
else
    info "已跳过版本检查"
fi

# 二次确认（非交互模式可跳过）
if [[ "${SKIP_CONFIRM}" != "1" && "${CHECK_ONLY}" != "1" ]]; then
    echo ""
    warn "即将开始更新，更新过程中服务可能短暂中断。"
    read -r -p "确认开始更新？[Y/n]: " CONFIRM
    if [[ "${CONFIRM}" =~ ^[Nn]$ ]]; then
        info "已取消更新。"
        exit 0
    fi
fi

# ------------------------------------------------------------
# 记录更新前的 commit hash（用于回滚）
# ------------------------------------------------------------
PREV_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
if [[ -n "${PREV_COMMIT}" ]]; then
    echo "${PREV_COMMIT}" > "${PROJECT_DIR}/.last-update-backup"
    info "已备份当前 commit: ${PREV_COMMIT:0:8}"
fi

echo ""
info "开始更新流程..."

# ============================================================
# 1. 拉取最新代码（支持代理）
# ============================================================
CURRENT_STEP="[1/4] 拉取最新代码"
step "[1/4] 拉取最新代码"

# 配置代理
setup_git_proxy "${GIT_PROXY}"

info "拉取远程代码..."
git fetch origin
git reset --hard origin/main
git clean -fd

# 恢复代理设置
restore_git_proxy

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
step "[2/4] 更新依赖"

# 后端依赖（生产环境，优化自动加载）
info "安装后端依赖 (composer install --no-dev --optimize-autoloader)..."
cd backend && composer install --no-dev --optimize-autoloader && cd ..

# 前端依赖与构建（可选跳过）
if [[ "${SKIP_BUILD}" != "1" ]]; then
    info "构建管理后台 (npm install && npm run build)..."
    cd admin && npm install && npm run build && cd ..
else
    warn "已跳过前端构建 (--skip-build)"
fi

info "依赖更新完成。"

# ============================================================
# 3. 执行数据库迁移（可选跳过）
# ============================================================
if [[ "${SKIP_MIGRATION}" != "1" ]]; then
    CURRENT_STEP="[3/4] 执行数据库迁移"
    step "[3/4] 执行数据库迁移"

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

        # 按文件名顺序执行迁移（使用数组避免空格问题）
        shopt -s nullglob
        sql_files=("${MIGRATIONS_DIR}"/*.sql)
        shopt -u nullglob
        if [[ ${#sql_files[@]} -gt 0 ]]; then
            IFS=$'\n' sorted_sql_files=($(sort <<<"${sql_files[*]}"))
            unset IFS
            for sql_file in "${sorted_sql_files[@]}"; do
                [[ -f "${sql_file}" ]] || continue
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
        fi

        info "数据库迁移完成（本次应用 ${APPLIED_COUNT} 个，跳过 ${SKIPPED_COUNT} 个）。"
    else
        warn "未找到迁移脚本目录: ${MIGRATIONS_DIR}"
    fi
else
    CURRENT_STEP="[3/4] 跳过数据库迁移"
    step "[3/4] 跳过数据库迁移"
    warn "已跳过数据库迁移 (--skip-migration)"
fi

# ============================================================
# 4. 重启服务（清opcache）
# ============================================================
CURRENT_STEP="[4/4] 重启服务"
step "[4/4] 重启服务"

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
echo ""
step "服务健康检查"
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
echo "========================================"
echo -e "  ${COLOR_GREEN}✓ 更新完成！${COLOR_RESET}"
echo "========================================"
info "更新前版本: ${PREV_COMMIT:0:8}"
info "当前版本:   ${NEW_COMMIT:0:8}"
echo ""
warn "如遇异常，可执行回滚: bash deploy/rollback.sh"
echo ""
