#!/bin/bash
# ============================================================
# 即时消息推送系统 - 一键更新脚本
#
# 功能：
#   1. [1/5] 拉取最新代码（git pull origin main，支持 gh-proxy 代理）
#   2. [2/5] 更新依赖（composer install --no-dev --optimize-autoloader）
#   3. [3/5] 数据库迁移（执行 backend/database/migrations 下的 SQL 文件）
#   4. [4/5] 设置 APP 打包环境（build/app 目录权限、删除 gradlew、安装 BuildWorker 服务）
#   5. [5/5] 重启服务（使用 systemctl 重启 push-http、push-websocket、push-build-worker）
#
# 用法:
#   bash backend/deploy/update.sh                    # 正常更新（交互式确认）
#   bash backend/deploy/update.sh --yes              # 跳过确认（CI/自动化场景）
#   bash backend/deploy/update.sh --gh-proxy         # 使用 gh.jasonzeng.dev GitHub 代理
#   bash backend/deploy/update.sh --proxy=http://127.0.0.1:7890  # 使用自定义 HTTP 代理
#   bash backend/deploy/update.sh --skip-build       # 跳过前端构建
#   bash backend/deploy/update.sh --skip-migration   # 跳过数据库迁移
#   bash backend/deploy/update.sh --resume           # 从上次失败处继续
#   bash backend/deploy/update.sh --restart          # 清除进度记录重新开始
#
# 环境变量:
#   PROJECT_DIR            - 项目目录（默认 /www/push-system）
#   COMPOSER_ALLOW_SUPERUSER - 允许 composer 以 root 运行（脚本会自动设置）
#
# 错误处理：
#   - set -e 任意步骤失败立即退出
#   - 失败时打印 "更新失败" 及失败步骤
#   - 成功时打印 "✓ 更新完成"
# ============================================================

set -e

# ------------------------------------------------------------
# 项目目录（从环境变量读取或使用默认值）
# ------------------------------------------------------------
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
MIGRATIONS_TABLE="schema_migrations"
PROGRESS_FILE="/tmp/push-update-progress.env"

# ------------------------------------------------------------
# 解析命令行参数
# ------------------------------------------------------------
SKIP_CONFIRM=""
SKIP_BUILD=""
SKIP_MIGRATION=""
GH_PROXY=""
GIT_PROXY=""
RESUME_MODE=""
RESTART_MODE=""

for arg in "$@"; do
    case $arg in
        --yes)                  SKIP_CONFIRM="1" ;;
        --gh-proxy)             GH_PROXY="1" ;;
        --proxy=*)              GIT_PROXY="${arg#*=}" ;;
        --skip-build)           SKIP_BUILD="1" ;;
        --skip-migration)       SKIP_MIGRATION="1" ;;
        --resume)               RESUME_MODE="1" ;;
        --restart)              RESTART_MODE="1" ;;
        --project-dir=*)        PROJECT_DIR="${arg#*=}" ;;
        -h|--help)
            head -n 30 "$0"
            exit 0
            ;;
        *)
            echo "未知参数: $arg" >&2
            exit 1
            ;;
    esac
done

cd "$PROJECT_DIR" || { echo "无法进入项目目录: $PROJECT_DIR" >&2; exit 1; }

# Git 安全目录配置，避免在 root 下操作时 git 报错
git config --global --add safe.directory "$PROJECT_DIR"

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
# 断点续装：进度文件 & 辅助函数
# ------------------------------------------------------------
# 清除进度记录
clear_progress() {
    rm -f "${PROGRESS_FILE}"
}

# 检查步骤是否已完成（断点续装时跳过已完成步骤）
step_done() {
    local step_name="$1"
    [[ "${RESUME_MODE}" == "1" ]] || return 1
    [[ -f "${PROGRESS_FILE}" ]] || return 1
    grep -q "^${step_name}=done$" "${PROGRESS_FILE}" 2>/dev/null
}

# 标记步骤完成
mark_done() {
    local step_name="$1"
    echo "${step_name}=done" >> "${PROGRESS_FILE}"
}

# 处理 --restart：清除进度后正常执行
if [[ "${RESTART_MODE}" == "1" ]]; then
    info "已清除进度记录，重新开始更新..."
    clear_progress
    RESUME_MODE=""
fi

# 处理 --resume：检查进度文件
if [[ "${RESUME_MODE}" == "1" ]]; then
    if [[ ! -f "${PROGRESS_FILE}" ]]; then
        warn "未找到进度文件，将从头开始更新"
        RESUME_MODE=""
    else
        info "断点续装模式：以下步骤已完成，将跳过"
        grep '=done$' "${PROGRESS_FILE}" 2>/dev/null | while read -r line; do
            echo -e "  ${COLOR_GREEN}✓${COLOR_RESET} ${line%%=*}"
        done
    fi
fi

# ------------------------------------------------------------
# 当前执行步骤（用于失败时输出失败位置）
# ------------------------------------------------------------
CURRENT_STEP="初始化"

# ------------------------------------------------------------
# 失败处理函数（trap ERR 触发）
# ------------------------------------------------------------
update_failed() {
    local exit_code=$?
    if [[ ${exit_code} -ne 0 ]]; then
        echo ""
        error "========================================"
        error "  更新失败！"
        error "========================================"
        error "失败发生在步骤: ${CURRENT_STEP}"
        echo  ""
        warn  "已完成的步骤已保存，修复问题后可断点续装："
        echo  -e "  ${COLOR_YELLOW}bash backend/deploy/update.sh --resume${COLOR_RESET}"
        echo  ""
        # 清理代理设置
        restore_git_proxy
    fi
}
trap update_failed ERR

# ------------------------------------------------------------
# 代理配置函数
# ------------------------------------------------------------
ORIGINAL_ORIGIN_URL=""
PROXY_REPLACED=""

setup_git_proxy() {
    if [[ -n "${GIT_PROXY}" ]]; then
        # 使用自定义 HTTP 代理
        info "配置 Git 代理: ${GIT_PROXY}"
        git config --global http.proxy "${GIT_PROXY}"
        git config --global https.proxy "${GIT_PROXY}"
        export HTTP_PROXY="${GIT_PROXY}"
        export HTTPS_PROXY="${GIT_PROXY}"
    elif [[ "${GH_PROXY}" == "1" ]]; then
        # 使用 GitHub 代理 gh.jasonzeng.dev
        info "使用 GitHub 代理加速 (gh.jasonzeng.dev)..."
        local remote_url
        remote_url="$(git remote get-url origin 2>/dev/null || echo '')"
        # 检查是否已经包含代理前缀，避免重复添加
        if [[ -n "${remote_url}" && "${remote_url}" =~ github\.com ]] && [[ ! "${remote_url}" =~ gh\.jasonzeng\.dev ]]; then
            local new_url="${remote_url/github.com/gh.jasonzeng.dev\/https:\/\/github.com}"
            info "  替换远程地址: ${remote_url} -> ${new_url}"
            git remote set-url origin "${new_url}"
            ORIGINAL_ORIGIN_URL="${remote_url}"
            PROXY_REPLACED="1"
        elif [[ "${remote_url}" =~ gh\.jasonzeng\.dev ]]; then
            info "  远程地址已包含代理前缀，无需替换"
        elif [[ -z "${remote_url}" ]]; then
            # 没有 remote origin，直接设置为带代理前缀的 URL
            info "  未检测到 origin 远程地址，设置为代理 URL..."
            git remote add origin "https://gh.jasonzeng.dev/https://github.com/jiujiu123520/im-push-system.git"
            PROXY_REPLACED="1"
        fi
    fi
}

# 恢复代理设置
restore_git_proxy() {
    if [[ -n "${GIT_PROXY}" ]]; then
        git config --global --unset http.proxy 2>/dev/null || true
        git config --global --unset https.proxy 2>/dev/null || true
        unset HTTP_PROXY
        unset HTTPS_PROXY
    fi
    if [[ "${GH_PROXY}" == "1" && "${PROXY_REPLACED}" == "1" && -n "${ORIGINAL_ORIGIN_URL}" ]]; then
        info "恢复原始远程地址..."
        git remote set-url origin "${ORIGINAL_ORIGIN_URL}"
    fi
}

# ------------------------------------------------------------
# 从 .env 读取数据库配置（用于数据库迁移）
# ------------------------------------------------------------
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

# ============================================================
# 主流程
# ============================================================
echo "========================================"
echo "  即时消息推送系统 - 一键更新"
echo "========================================"
echo "项目目录: ${PROJECT_DIR}"
echo ""

# 二次确认（非交互模式可跳过）
if [[ "${SKIP_CONFIRM}" != "1" ]]; then
    warn "即将开始更新，更新过程中服务可能短暂中断。"
    read -r -p "确认开始更新？[Y/n]: " CONFIRM
    if [[ "${CONFIRM}" =~ ^[Nn]$ ]]; then
        info "已取消更新。"
        exit 0
    fi
else
    info "已跳过确认（--yes）"
fi

echo ""
info "开始更新流程..."

# ============================================================
# [1/5] 拉取最新代码（支持 gh-proxy 代理）
# ============================================================
if step_done "step1_pull_code"; then
    info "跳过 [1/5] 拉取最新代码（已完成）"
else
    CURRENT_STEP="[1/5] 拉取最新代码"
    step "[1/5] 拉取最新代码..."

    # 配置代理
    setup_git_proxy

    info "拉取远程代码..."
    # git pull origin main，如果失败则尝试 git reset --hard origin/main
    git pull origin main || {
        warn "git pull 失败，尝试 git reset --hard origin/main..."
        git fetch origin
        git reset --hard origin/main
    }

    # 恢复代理设置
    restore_git_proxy

    info "代码拉取完成。"
    mark_done "step1_pull_code"
fi

# ============================================================
# [2/5] 更新依赖（composer install --no-dev --optimize-autoloader）
# ============================================================
if step_done "step2_dependencies"; then
    info "跳过 [2/5] 更新依赖（已完成）"
else
    CURRENT_STEP="[2/5] 更新依赖"
    step "[2/5] 更新依赖..."

    info "安装后端依赖 (composer install --no-dev --optimize-autoloader)..."
    cd "${PROJECT_DIR}/backend"
    # 允许 composer 以 root 运行
    export COMPOSER_ALLOW_SUPERUSER=1
    composer config --global --no-interaction policy.advisories.block false 2>/dev/null || true
    # 同步 composer.lock（当 composer.json 变更后 lock 文件可能过期）
    composer update --lock --no-interaction --no-dev 2>/dev/null || true
    composer install --no-dev --optimize-autoloader --no-interaction
    cd "${PROJECT_DIR}"

    # 前端构建（可选跳过）
    if [[ "${SKIP_BUILD}" != "1" ]]; then
        info "构建管理后台 (npm install && npm run build)..."
        if [[ -d "${PROJECT_DIR}/admin" ]]; then
            cd "${PROJECT_DIR}/admin"
            npm install
            npm run build
            cd "${PROJECT_DIR}"
        else
            warn "未找到 admin 目录，跳过前端构建。"
        fi
    else
        warn "已跳过前端构建 (--skip-build)"
    fi

    info "依赖更新完成。"
    mark_done "step2_dependencies"
fi

# ============================================================
# [3/5] 数据库迁移（如果 backend/database/migrations 下有SQL文件则执行，可跳过）
# ============================================================
if [[ "${SKIP_MIGRATION}" == "1" ]]; then
    CURRENT_STEP="[3/5] 跳过数据库迁移"
    step "[3/5] 跳过数据库迁移..."
    warn "已跳过数据库迁移 (--skip-migration)"
elif step_done "step3_migration"; then
    info "跳过 [3/5] 数据库迁移（已完成）"
else
    CURRENT_STEP="[3/5] 数据库迁移"
    step "[3/5] 执行数据库迁移..."

    MIGRATIONS_DIR="${PROJECT_DIR}/backend/database/migrations"

    # 构造 MySQL 连接参数
    MYSQL_OPTS=("-h${DB_HOST}" "-u${DB_USER}")
    if [[ -n "${DB_PASS}" ]]; then
        MYSQL_OPTS+=("-p${DB_PASS}")
    fi

    if [[ -d "${MIGRATIONS_DIR}" ]]; then
        # 创建迁移记录表（如不存在），记录已应用的迁移文件
        mysql "${MYSQL_OPTS[@]}" "${DB_NAME}" <<EOF
CREATE TABLE IF NOT EXISTS \`${MIGRATIONS_TABLE}\` (
    \`id\` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    \`filename\` VARCHAR(255) NOT NULL UNIQUE,
    \`applied_at\` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
EOF

        # 补录已应用但未记录的迁移（兼容旧版无 schema_migrations 表的数据库升级）
        record_if_applied() {
            local filename="$1"
            local check_sql="$2"
            local exists
            exists=$(mysql "${MYSQL_OPTS[@]}" "${DB_NAME}" -sN -e "${check_sql}" 2>/dev/null || echo 0)
            if [[ "${exists}" == "1" ]]; then
                mysql "${MYSQL_OPTS[@]}" "${DB_NAME}" -e \
                    "INSERT IGNORE INTO \`${MIGRATIONS_TABLE}\` (filename) VALUES ('${filename}');" 2>/dev/null || true
                info "  补录已应用迁移: ${filename}"
            fi
        }

        record_if_applied "001_init.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='users'),1,0);"
        record_if_applied "002_add_notify_fields.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='push_keys' AND COLUMN_NAME='notify_email'),1,0);"
        record_if_applied "003_add_admin_settings.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='admin_settings'),1,0);"
        record_if_applied "004_admin_login_logs.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='admin_login_logs'),1,0);"
        record_if_applied "005_domains.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='domains'),1,0);"
        record_if_applied "006_domains_extend.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='domains' AND COLUMN_NAME='listen_port'),1,0);"
        record_if_applied "007_users_security_code.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='users' AND COLUMN_NAME='security_code_hash'),1,0);"
        record_if_applied "008_apk_distribution.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='apk_distributions'),1,0);"

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
            info "数据库迁移完成（本次应用 ${APPLIED_COUNT} 个，跳过 ${SKIPPED_COUNT} 个）。"
        else
            info "未找到待执行的迁移文件。"
        fi
    else
        warn "未找到迁移脚本目录: ${MIGRATIONS_DIR}"
    fi
    mark_done "step3_migration"
fi

# ============================================================
# [4/5] 设置 APP 打包环境（权限、gradlew、BuildWorker）
# ============================================================
if step_done "step4_build_env"; then
    info "跳过 [4/5] APP 打包环境设置（已完成）"
else
    CURRENT_STEP="[4/5] APP 打包环境设置"
    step "[4/5] 设置 APP 打包环境..."

    cd "${PROJECT_DIR}"

    # 1. 设置 build、app、.gradle 目录权限（BuildWorker 以 www-data 用户运行）
    info "设置 build/app 目录权限..."
    sudo mkdir -p "${PROJECT_DIR}/build/logs" "${PROJECT_DIR}/.gradle"
    sudo chown -R www-data:www-data "${PROJECT_DIR}/build" "${PROJECT_DIR}/app" "${PROJECT_DIR}/.gradle"
    sudo chmod -R u+rw "${PROJECT_DIR}/build" "${PROJECT_DIR}/app"

    # 2. 删除 gradlew（强制使用全局 gradle，避免 wrapper 尝试下载 distribution 超时）
    if [ -f "${PROJECT_DIR}/gradlew" ]; then
        info "移除 gradlew（使用全局 gradle 避免下载 distribution）..."
        rm -f "${PROJECT_DIR}/gradlew"
        rm -rf "${PROJECT_DIR}/gradle"
    fi

    # 3. 安装并启动 BuildWorker systemd 服务（如果 service 文件存在）
    BUILD_WORKER_SERVICE="${PROJECT_DIR}/deploy/systemd/push-build-worker.service"
    if [ -f "$BUILD_WORKER_SERVICE" ]; then
        info "安装 push-build-worker systemd 服务..."
        sudo cp "$BUILD_WORKER_SERVICE" /etc/systemd/system/
        sudo systemctl daemon-reload
        sudo systemctl enable push-build-worker 2>/dev/null || true
        sudo systemctl restart push-build-worker
        info "push-build-worker 已重启。"
    else
        warn "未找到 push-build-worker.service，跳过 BuildWorker 安装"
    fi

    mark_done "step4_build_env"
fi

# ============================================================
# [5/5] 重启服务（使用 systemctl 重启 push-http、push-websocket）
# ============================================================
if step_done "step5_restart_services"; then
    info "跳过 [5/5] 重启服务（已完成）"
else
    CURRENT_STEP="[5/5] 重启服务"
    step "[5/5] 重启服务..."

    cd "${PROJECT_DIR}"

    # 检查 systemctl 是否可用
    if command -v systemctl >/dev/null 2>&1 && systemctl list-units --type=service 2>/dev/null | grep -q push-http; then
        # 使用 systemctl 重启
        info "重启 push-http..."
        sudo systemctl restart push-http
        info "push-http 已重启。"

        sleep 1

        info "重启 push-websocket..."
        sudo systemctl restart push-websocket
        info "push-websocket 已重启。"

        sleep 2

        # 服务健康检查（包含 BuildWorker）
        echo ""
        for svc in push-http push-websocket push-build-worker; do
            status_output="$(sudo systemctl is-active ${svc} 2>/dev/null || echo '')"
            if [[ "${status_output}" == "active" ]]; then
                echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc}    [运行中]"
            else
                echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc}    [未运行]"
                warn "服务 ${svc} 未正常运行"
                warn "请使用 sudo journalctl -u ${svc} --no-pager -n 50 查看日志"
            fi
        done
    else
        # 回退：使用项目自带 bin/stop.sh / bin/start.sh
        info "未检测到 systemd 服务，使用 bin/stop.sh / bin/start.sh..."
        cd "${PROJECT_DIR}/backend"

        info "停止服务..."
        bash bin/stop.sh 2>/dev/null || true
        sleep 1

        mkdir -p runtime/logs

        info "启动服务..."
        bash bin/start.sh
        cd "${PROJECT_DIR}"
    fi

    mark_done "step5_restart_services"
fi

# ------------------------------------------------------------
# 更新完成：清除进度记录，输出成功信息
# ------------------------------------------------------------
trap - ERR
clear_progress

echo ""
echo "========================================"
echo -e "  ${COLOR_GREEN}✓ 更新完成${COLOR_RESET}"
echo "========================================"
echo ""
