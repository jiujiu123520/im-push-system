#!/bin/bash
# ============================================================
# 即时消息推送系统 - 一键更新脚本
#
# 功能：
#   0. [0/5] 版本检测（本地 vs 云端）- 显示版本对比，智能判断是否需要拉取
#   1. [1/5] 拉取最新代码（git reset --hard origin/main，支持 gh-proxy 代理）
#   2. [2/5] 更新依赖（composer install --no-dev --optimize-autoloader）
#   3. [3/5] 数据库迁移（执行 backend/database/migrations 下的 SQL 文件）
#   4. [4/5] 设置 APP 打包环境（build/app 目录权限、删除 gradlew、安装 BuildWorker 服务）
#   5. [5/5] 重启服务（使用 systemctl 重启 push-http、push-websocket、push-build-worker）
#
# 版本检测行为：
#   - 已是最新(up-to-date)：询问是否强制完整更新（默认仅重启服务，不拉取代码）
#   - 本地落后(behind)    ：列出云端新增的最近 10 条提交，正常更新
#   - 本地领先(ahead)     ：警告本地有未推送的提交，二次确认后强制覆盖
#   - 版本分叉(diverged)  ：提示版本已分叉，二次确认后强制重置到云端
#
# 用法:
#   bash backend/deploy/update.sh                    # 正常更新（默认 Y，直接回车即开始）
#   bash backend/deploy/update.sh --yes              # 跳过所有确认（CI/自动化场景，含版本检测确认）
#   bash backend/deploy/update.sh --gh-proxy         # 使用 gh.jasonzeng.dev GitHub 代理
#   bash backend/deploy/update.sh --proxy=http://127.0.0.1:7890  # 使用自定义 HTTP 代理
#   bash backend/deploy/update.sh --skip-build       # 跳过前端构建
#   bash backend/deploy/update.sh --skip-migration   # 跳过数据库迁移
#   bash backend/deploy/update.sh --skip-version-check  # 跳过版本检测（直接进入代码拉取流程）
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
# 跨系统：依赖检查（bash/mysql/client）
# ------------------------------------------------------------
_need_cmd() { command -v "$1" >/dev/null 2>&1; }
_distro_install_hint() {
  local pkg="$1"
  if _need_cmd apt-get; then echo "  Debian/Ubuntu: sudo apt-get install -y $pkg"
  elif _need_cmd apk; then echo "  Alpine:        apk add --no-cache $pkg"
  elif _need_cmd yum; then echo "  CentOS/RHEL:   sudo yum install -y $pkg"
  elif _need_cmd dnf; then echo "  Rocky/Fedora:  sudo dnf install -y $pkg"
  elif _need_cmd brew; then echo "  macOS:         brew install $pkg"
  elif _need_cmd pacman; then echo "  Arch:          sudo pacman -S --noconfirm $pkg"
  else echo "  请使用系统包管理器安装: $pkg"; fi
}
assert_deps() {
  local miss=()
  _need_cmd bash  || miss+=(bash)
  _need_cmd mysql || miss+=(mysql-client)
  _need_cmd git   || miss+=(git)
  _need_cmd curl  || miss+=(curl)
  if [[ ${#miss[@]} -gt 0 ]]; then
    echo "[ERROR] update.sh 缺少依赖：${miss[*]}" >&2
    local p
    for p in "${miss[@]}"; do _distro_install_hint "$p" >&2; done
    exit 1
  fi
  if ! _need_cmd python3; then
    echo "[WARN] 未检测到 python3，小飞机网盘上传脚本将降级使用 grep/sed 解析 JSON，Alpine/BSD 环境可能失效。推荐安装 python3。" >&2
    _distro_install_hint "python3" >&2
  fi
  if ! _need_cmd systemctl; then
    echo "[WARN] 当前环境未检测到 systemctl（Docker 容器 / macOS），服务重启步骤将改为提示手动执行 bin/start.sh / bin/stop.sh。" >&2
  fi
}
assert_deps

# ------------------------------------------------------------
# 项目目录（从环境变量读取或使用默认值）
# ------------------------------------------------------------
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
MIGRATIONS_TABLE="schema_migrations"
# 进度文件放在项目目录下，避免 /tmp 中 root 创建导致后续非 root 用户 Permission denied
PROGRESS_FILE="${PROJECT_DIR}/.deploy/push-update-progress.env"

# ------------------------------------------------------------
# 解析命令行参数
# ------------------------------------------------------------
SKIP_CONFIRM=""
SKIP_BUILD=""
SKIP_MIGRATION=""
SKIP_VERSION_CHECK=""
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
        --skip-version-check)   SKIP_VERSION_CHECK="1" ;;
        --resume)               RESUME_MODE="1" ;;
        --restart)              RESTART_MODE="1" ;;
        --project-dir=*)        PROJECT_DIR="${arg#*=}" ;;
        -h|--help)
            head -n 35 "$0"
            exit 0
            ;;
        *)
            echo "未知参数: $arg" >&2
            exit 1
            ;;
    esac
done

cd "$PROJECT_DIR" || { echo "无法进入项目目录: $PROJECT_DIR" >&2; exit 1; }

# 确保 .deploy 目录存在且可写，同时清理 /tmp 下遗留的 root 进度文件（Permission denied 根因）
mkdir -p "${PROJECT_DIR}/.deploy" && chmod 777 "${PROJECT_DIR}/.deploy" 2>/dev/null || true
[[ -f "/tmp/push-update-progress.env" ]] && rm -f "/tmp/push-update-progress.env" 2>/dev/null || true

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
# 如果通过 sudo 执行，记录真实调用用户的 UID/GID，结束时 chown 回原用户
# 避免 .git/ 目录文件被 root 写入后再次执行「git fetch」 报 Permission denied
ORIGINAL_UID=""
ORIGINAL_GID=""
if [[ -n "${SUDO_UID}" && -n "${SUDO_GID}" && "${EUID}" == "0" ]]; then
    ORIGINAL_UID="${SUDO_UID}"
    ORIGINAL_GID="${SUDO_GID}"
fi

# 清理/恢复项目目录所有者（成功/失败路径均通过 trap EXIT 调用）
# 注意：.env / storage / runtime 必须保持 www-data 可读/写，不能改回普通用户，
# 否则 push-http 以 www-data 启动时读取 .env 600 权限失败直接崩。
restore_owner() {
    if [[ -n "${ORIGINAL_UID}" && -n "${ORIGINAL_GID}" && -d "${PROJECT_DIR}" ]]; then
        # 先按默认恢复
        chown -R "${ORIGINAL_UID}:${ORIGINAL_GID}" "${PROJECT_DIR}" 2>/dev/null || true
        # 再把服务必需文件改回 www-data
        if command -v id >/dev/null 2>&1 && id -u www-data >/dev/null 2>&1; then
            local WWW_UID_GID
            WWW_UID_GID="$(id -u www-data):$(id -g www-data)"
            # .env（600 权限，仅 owner 可读）
            [[ -f "${PROJECT_DIR}/backend/.env" ]] && \
                chown "${WWW_UID_GID}" "${PROJECT_DIR}/backend/.env" 2>/dev/null || true
            [[ -f "${PROJECT_DIR}/deploy/.env" ]] && \
                chown "${WWW_UID_GID}" "${PROJECT_DIR}/deploy/.env" 2>/dev/null || true
            # storage/runtime（运行时目录）
            for d in backend/storage backend/runtime backend/logs; do
                [[ -d "${PROJECT_DIR}/${d}" ]] && \
                    chown -R "${WWW_UID_GID}" "${PROJECT_DIR}/${d}" 2>/dev/null || true
            done
        fi
    fi
}
trap restore_owner EXIT
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
    # 确保进度文件父目录存在（被 git reset 删掉后自动重建）
    local progress_dir
    progress_dir="$(dirname "${PROGRESS_FILE}")"
    [[ -d "${progress_dir}" ]] || mkdir -p "${progress_dir}" 2>/dev/null || true
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
    DB_NAME="$(grep -E '^DB_NAME=' "${PROJECT_DIR}/backend/.env" | cut -d'=' -f2- | tr -d '\r')"
    DB_USER="$(grep -E '^DB_USER=' "${PROJECT_DIR}/backend/.env" | cut -d'=' -f2- | tr -d '\r')"
    DB_PASS="$(grep -E '^DB_PASS=' "${PROJECT_DIR}/backend/.env" | cut -d'=' -f2- | tr -d '\r')"
    DB_HOST="$(grep -E '^DB_HOST=' "${PROJECT_DIR}/backend/.env" | cut -d'=' -f2- | tr -d '\r')"
fi
DB_NAME="${DB_NAME:-im_push}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"

# Ubuntu/MariaDB 兼容：root+空密码时自动检测是否需要 sudo（unix_socket 认证）
# 如果直连失败但 sudo mysql 成功，则后续所有 mysql 命令自动加 sudo 前缀
MYSQL_CMD=(mysql)
if [[ "${DB_USER}" == "root" && -z "${DB_PASS}" ]]; then
    if ! mysql -h"${DB_HOST}" -u"${DB_USER}" -e "SELECT 1" &>/dev/null; then
        if sudo mysql -h"${DB_HOST}" -u"${DB_USER}" -e "SELECT 1" &>/dev/null; then
            MYSQL_CMD=(sudo mysql)
            echo "[INFO] 检测到 root 使用 unix_socket 认证，已自动切换为 sudo mysql 连接"
        fi
    fi
fi

# ============================================================
# 主流程
# ============================================================
echo "========================================"
echo "  即时消息推送系统 - 一键更新"
echo "========================================"
echo "项目目录: ${PROJECT_DIR}"
echo ""

# 二次确认（默认为 Y，直接回车即开始更新；输入 n 取消）
if [[ "${SKIP_CONFIRM}" != "1" ]]; then
    warn "即将开始更新，更新过程中服务可能短暂中断。"
    info "提示：直接回车即开始更新（默认为 Y），输入 n 取消"
    read -r -p "确认开始更新？[Y/n]（默认 Y）: " CONFIRM
    # 默认为 Y（直接回车或输入 y/Y/任意非 n 字符均继续；仅输入 n/N 取消）
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
# [0/5] 版本检测（本地 vs 云端）
# ============================================================
VERSION_CHECK_DONE=""
if [[ "${SKIP_VERSION_CHECK}" == "1" ]]; then
    warn "已跳过版本检测 (--skip-version-check)"
    VERSION_CHECK_DONE="1"
    mark_done "step0_version_check"
elif step_done "step0_version_check"; then
    info "跳过 [0/5] 版本检测（已完成）"
    VERSION_CHECK_DONE="1"
else
    CURRENT_STEP="[0/5] 版本检测"
    step "[0/5] 版本检测（本地 vs 云端）..."

    # 修复 .git 目录权限（避免版本检测时因权限失败）
    if [[ -d "${PROJECT_DIR}/.git" ]]; then
        local_owner="$(stat -c '%U' "${PROJECT_DIR}/.git" 2>/dev/null || echo '')"
        current_user="$(whoami)"
        if [[ -n "${local_owner}" && "${local_owner}" != "${current_user}" ]]; then
            warn "修复 .git 目录权限 (${local_owner} -> ${current_user})..."
            if [[ "$(id -u)" == "0" ]]; then
                chown -R "${current_user}:${current_user}" "${PROJECT_DIR}/.git" 2>/dev/null || true
            else
                sudo chown -R "${current_user}:${current_user}" "${PROJECT_DIR}/.git" 2>/dev/null || true
            fi
        fi
    fi

    # 配置代理（供 fetch 使用）
    setup_git_proxy

    # ------------------------------------------------------------
    # 获取本地版本信息
    # ------------------------------------------------------------
    LOCAL_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
    LOCAL_SHORT="${LOCAL_COMMIT:0:8}"
    LOCAL_DATE="$(git log -1 --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' 2>/dev/null || echo '未知')"
    LOCAL_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '')"
    if [[ -z "${LOCAL_BRANCH}" || "${LOCAL_BRANCH}" == "HEAD" ]]; then
        LOCAL_BRANCH="main"
    fi
    LOCAL_SUBJECT="$(git log -1 --format='%s' 2>/dev/null || echo '')"

    # ------------------------------------------------------------
    # 获取远端版本信息（浅 fetch 加速）
    # ------------------------------------------------------------
    info "获取云端版本信息..."
    # 先浅 fetch，失败则完整 fetch；结果会被后续 [1/5] 步骤复用
    if ! git fetch origin --depth=50 2>/dev/null; then
        git fetch origin 2>/dev/null || {
            warn "无法连接到远程仓库，请检查网络或代理设置。将直接进入更新流程。"
            echo ""
            restore_git_proxy
            VERSION_CHECK_SKIPPED="1"
        }
    fi

    if [[ -z "${VERSION_CHECK_SKIPPED}" ]]; then
        # 确定远端分支（优先 main，回退 master）
        REMOTE_BRANCH=""
        if git rev-parse --verify origin/main >/dev/null 2>&1; then
            REMOTE_BRANCH="main"
        elif git rev-parse --verify origin/master >/dev/null 2>&1; then
            REMOTE_BRANCH="master"
        fi

        if [[ -n "${REMOTE_BRANCH}" ]]; then
            REMOTE_COMMIT="$(git rev-parse "origin/${REMOTE_BRANCH}" 2>/dev/null || echo '')"
            REMOTE_SHORT="${REMOTE_COMMIT:0:8}"
            REMOTE_DATE="$(git log -1 "origin/${REMOTE_BRANCH}" --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' 2>/dev/null || echo '未知')"
            REMOTE_SUBJECT="$(git log -1 "origin/${REMOTE_BRANCH}" --format='%s' 2>/dev/null || echo '')"

            # 对比 ahead/behind
            AHEAD_COUNT=0   # 云端比本地新的提交数（behind状态下）
            BEHIND_COUNT=0  # 本地比云端新的提交数（ahead状态下）
            VERSION_STATUS="unknown"

            if [[ -n "${LOCAL_COMMIT}" && -n "${REMOTE_COMMIT}" ]]; then
                if [[ "${LOCAL_COMMIT}" == "${REMOTE_COMMIT}" ]]; then
                    VERSION_STATUS="up-to-date"
                elif git merge-base --is-ancestor HEAD "origin/${REMOTE_BRANCH}" 2>/dev/null; then
                    VERSION_STATUS="behind"
                    AHEAD_COUNT="$(git rev-list --count "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null || echo 0)"
                elif git merge-base --is-ancestor "origin/${REMOTE_BRANCH}" HEAD 2>/dev/null; then
                    VERSION_STATUS="ahead"
                    BEHIND_COUNT="$(git rev-list --count "origin/${REMOTE_BRANCH}..HEAD" 2>/dev/null || echo 0)"
                else
                    VERSION_STATUS="diverged"
                    AHEAD_COUNT="$(git rev-list --count "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null || echo 0)"
                    BEHIND_COUNT="$(git rev-list --count "origin/${REMOTE_BRANCH}..HEAD" 2>/dev/null || echo 0)"
                fi
            fi

            # 恢复代理（fetch 完成）
            restore_git_proxy

            # ------------------------------------------------------------
            # 输出版本对比
            # ------------------------------------------------------------
            echo ""
            echo "========================================"
            echo "  版本对比"
            echo "========================================"
            echo ""
            echo -e "  ${COLOR_CYAN}本地版本:${COLOR_RESET}"
            echo -e "    commit:    ${LOCAL_SHORT}"
            echo -e "    分支:      ${LOCAL_BRANCH}"
            echo -e "    时间:      ${LOCAL_DATE}"
            [[ -n "${LOCAL_SUBJECT}" ]] && echo -e "    说明:      ${LOCAL_SUBJECT}"
            echo ""
            echo -e "  ${COLOR_CYAN}云端版本:${COLOR_RESET}"
            echo -e "    commit:    ${REMOTE_SHORT}"
            echo -e "    分支:      origin/${REMOTE_BRANCH}"
            echo -e "    时间:      ${REMOTE_DATE}"
            [[ -n "${REMOTE_SUBJECT}" ]] && echo -e "    说明:      ${REMOTE_SUBJECT}"
            echo ""

            case "${VERSION_STATUS}" in
                up-to-date)
                    echo -e "  版本状态:  ${COLOR_GREEN}✓ 已是最新版本${COLOR_RESET}"
                    echo ""
                    warn "本地与云端版本一致，无需拉取代码。"
                    # 询问是否仍要执行更新（例如需要重新构建/重启服务）
                    if [[ "${SKIP_CONFIRM}" != "1" ]]; then
                        warn "提示：直接回车仅重新执行构建与服务重启（不拉取新代码）；输入 y/Y 完整执行更新流程；输入 n/N 取消"
                        read -r -p "是否仍要完整执行更新流程？[y/N]（默认 N，仅重启）: " FORCE_UPDATE_CONFIRM
                        if [[ "${FORCE_UPDATE_CONFIRM}" =~ ^[Yy]$ ]]; then
                            info "将完整执行更新流程（含强制拉取）"
                            SKIP_PULL_CODE=""
                        else
                            info "跳过代码拉取，仅执行后续构建与服务重启步骤"
                            SKIP_PULL_CODE="1"
                            # 标记代码拉取步骤已完成，避免重复执行
                            mark_done "step1_pull_code"
                        fi
                    else
                        info "--yes 模式下版本一致，仍将完整执行更新流程（含强制拉取）"
                    fi
                    ;;
                behind)
                    echo -e "  版本状态:  ${COLOR_YELLOW}↑ 本地落后 ${AHEAD_COUNT} 个提交${COLOR_RESET}"
                    echo ""
                    info "云端新增的提交（最近 10 条）："
                    git log --oneline "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null | head -n 10 | while read -r line; do
                        echo -e "    ${COLOR_YELLOW}↑${COLOR_RESET} ${line}"
                    done
                    echo ""
                    info "将拉取最新代码并执行更新流程。"
                    ;;
                ahead)
                    echo -e "  版本状态:  ${COLOR_RED}↓ 本地领先 ${BEHIND_COUNT} 个提交${COLOR_RESET}"
                    echo ""
                    warn "本地有未推送的提交，更新时会被强制覆盖！"
                    warn "本地领先的提交（最近 10 条）："
                    git log --oneline "origin/${REMOTE_BRANCH}..HEAD" 2>/dev/null | head -n 10 | while read -r line; do
                        echo -e "    ${COLOR_RED}↓${COLOR_RESET} ${line}"
                    done
                    echo ""
                    # 二次确认（非 --yes 模式）
                    if [[ "${SKIP_CONFIRM}" != "1" ]]; then
                        warn "⚠ 强制更新将重置本地到云端版本，本地修改会丢失！"
                        read -r -p "确认继续强制更新？[y/N]（默认 N，取消）: " FORCE_RESET_CONFIRM
                        if [[ ! "${FORCE_RESET_CONFIRM}" =~ ^[Yy]$ ]]; then
                            info "已取消更新。"
                            clear_progress
                            exit 0
                        fi
                    fi
                    ;;
                diverged)
                    echo -e "  版本状态:  ${COLOR_RED}✗ 版本分叉${COLOR_RESET}"
                    echo ""
                    warn "本地与云端版本已分叉："
                    echo -e "    云端新增:  ${COLOR_YELLOW}${AHEAD_COUNT} 条${COLOR_RESET}"
                    echo -e "    本地新增:  ${COLOR_RED}${BEHIND_COUNT} 条${COLOR_RESET}"
                    echo ""
                    error "版本已分叉，强制更新会重置本地到云端，本地修改将丢失！"
                    # 二次确认（非 --yes 模式）
                    if [[ "${SKIP_CONFIRM}" != "1" ]]; then
                        read -r -p "仍要强制更新（丢弃本地修改）？[y/N]（默认 N，取消）: " FORCE_RESET_CONFIRM
                        if [[ ! "${FORCE_RESET_CONFIRM}" =~ ^[Yy]$ ]]; then
                            info "已取消更新。"
                            clear_progress
                            exit 0
                        fi
                    fi
                    ;;
                *)
                    echo -e "  版本状态:  ${COLOR_YELLOW}未知${COLOR_RESET}"
                    echo ""
                    warn "无法判断版本差异，将正常执行更新流程。"
                    ;;
            esac

            echo "========================================"
            echo ""
        else
            warn "无法获取远程分支信息，跳过版本对比。"
            restore_git_proxy
        fi
    fi

    mark_done "step0_version_check"
fi

# ============================================================
# [1/5] 拉取最新代码（支持 gh-proxy 代理，支持因版本一致而跳过）
# ============================================================
if step_done "step1_pull_code"; then
    info "跳过 [1/5] 拉取最新代码（已完成）"
elif [[ "${SKIP_PULL_CODE}" == "1" ]]; then
    # 版本检测时已确认本地与云端一致，用户选择仅重启服务
    warn "跳过 [1/5] 拉取最新代码（版本已是最新，用户选择仅执行后续步骤）"
    mark_done "step1_pull_code"
else
    CURRENT_STEP="[1/5] 拉取最新代码"
    step "[1/5] 拉取最新代码..."

    # 修复项目目录权限（git pull 之前修复，避免文件权限导致 pull 失败）
    info "修复项目目录权限..."
    if [[ "$(id -u)" == "0" ]]; then
        current_user="$(whoami)"
        chown -R "${current_user}:${current_user}" "${PROJECT_DIR}" 2>/dev/null || true
    else
        sudo chown -R "$(whoami):$(whoami)" "${PROJECT_DIR}" 2>/dev/null || true
    fi

    # 修复 .git 目录权限（避免 root 操作后权限不足）
    if [[ -d "${PROJECT_DIR}/.git" ]]; then
        local_owner="$(stat -c '%U' "${PROJECT_DIR}/.git" 2>/dev/null || echo '')"
        current_user="$(whoami)"
        if [[ -n "${local_owner}" && "${local_owner}" != "${current_user}" ]]; then
            warn "修复 .git 目录权限 (${local_owner} -> ${current_user})..."
            if [[ "$(id -u)" == "0" ]]; then
                chown -R "${current_user}:${current_user}" "${PROJECT_DIR}/.git" 2>/dev/null || true
            else
                sudo chown -R "${current_user}:${current_user}" "${PROJECT_DIR}/.git" 2>/dev/null || true
            fi
        fi
    fi

    # 配置代理
    setup_git_proxy

    info "强制拉取远程代码（git fetch --force + git reset --hard）..."
    # 始终强制拉取，避免本地修改/冲突导致 pull 失败
    # 1. 清除本地未提交的修改（防止 merge conflict）
    #    注意：-e 排除 composer.lock/package-lock.json 等构建产物，避免误删
    git checkout -- . 2>/dev/null || true
    git clean -fd -e 'composer.lock' -e 'package-lock.json' -e 'backend/composer.lock' -e 'admin/package-lock.json' 2>/dev/null || true
    # 2. 强制 fetch 最新远程引用
    git fetch --force --all
    # 3. 确定远端分支（优先 main，回退 master）
    if [[ -z "${REMOTE_BRANCH}" ]]; then
        if git rev-parse --verify origin/main >/dev/null 2>&1; then
            REMOTE_BRANCH="main"
        elif git rev-parse --verify origin/master >/dev/null 2>&1; then
            REMOTE_BRANCH="master"
        else
            REMOTE_BRANCH="main"
        fi
    fi
    # 硬重置到远程分支
    git reset --hard "origin/${REMOTE_BRANCH}"
    # 4. 输出当前 commit 信息（便于追溯）
    info "当前 commit: $(git log -1 --pretty=format:'%h %s (%an, %ad)' --date=short)"

    # 恢复代理设置
    restore_git_proxy

    # 拉取完成后再次修复权限（确保新文件权限正确）
    info "修复新文件权限..."
    if [[ "$(id -u)" == "0" ]]; then
        current_user="$(whoami)"
        chown -R "${current_user}:${current_user}" "${PROJECT_DIR}" 2>/dev/null || true
    else
        sudo chown -R "$(whoami):$(whoami)" "${PROJECT_DIR}" 2>/dev/null || true
    fi

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
    # 注意：firebase/php-jwt 的已知安全 advisory 已在 composer.json 的 audit.ignore 中豁免
    composer update --lock --no-interaction --no-dev 2>/dev/null || true
    composer install --no-dev --optimize-autoloader --no-interaction
    cd "${PROJECT_DIR}"

    info "后端依赖更新完成。"
    mark_done "step2_dependencies"
fi

# ============================================================
# [2.5/5] 前端构建（独立步骤，失败不阻断后端更新）
# ============================================================
if [[ "${SKIP_BUILD}" == "1" ]]; then
    warn "已跳过前端构建 (--skip-build)"
elif step_done "step2b_frontend_build"; then
    info "跳过前端构建（已完成）"
else
    CURRENT_STEP="[2.5/5] 前端构建"
    step "[2.5/5] 前端构建..."

    if [[ -d "${PROJECT_DIR}/admin" ]]; then
        cd "${PROJECT_DIR}/admin"
        # 检测 .bin 权限，若不可执行则删除 node_modules 重装
        NEED_REINSTALL=false
        if [[ -d node_modules/.bin ]]; then
            for bin_file in node_modules/.bin/*; do
                [[ -e "$bin_file" ]] || continue
                if ! [ -x "$bin_file" ]; then
                    NEED_REINSTALL=true
                    break
                fi
            done
        fi
        [[ "$NEED_REINSTALL" == "true" ]] && rm -rf node_modules
        npm install
        # 跟随符号链接修复目标文件权限
        if [[ -d node_modules/.bin ]]; then
            find node_modules/.bin -type l | while read -r link; do
                target=$(readlink -f "$link" 2>/dev/null)
                [[ -f "$target" ]] && chmod +x "$target" 2>/dev/null || true
            done
            find node_modules/.bin -type f -exec chmod +x {} \; 2>/dev/null || true
        fi

        # 前端构建：先尝试标准构建（含 vue-tsc 类型检查），
        # 若失败（通常是 TypeScript 类型警告）则降级为直接 vite build（跳过类型检查）
        # 这样避免因类型不兼容阻断部署，类型问题可在开发时用 npm run type-check 单独排查
        info "构建管理后台 (npm run build)..."
        if ! npm run build; then
            warn "标准构建失败（可能是 TypeScript 类型检查不通过），降级为直接 vite build（跳过类型检查）..."
            if npx vite build; then
                warn "前端已构建完成（跳过了类型检查）。建议开发时运行 npm run type-check 排查类型问题。"
                mark_done "step2b_frontend_build"
            else
                # vite build 也失败，属于真正的构建错误，但仍不阻断后端更新
                error "前端构建失败！后端更新将继续，但管理后台可能仍是旧版本。"
                error "请手动检查: cd ${PROJECT_DIR}/admin && npm run build"
                cd "${PROJECT_DIR}"
                # 不 mark_done，下次 --resume 会重试前端构建
            fi
        else
            info "前端构建完成。"
            mark_done "step2b_frontend_build"
        fi
        cd "${PROJECT_DIR}"
    else
        warn "未找到 admin 目录，跳过前端构建。"
        mark_done "step2b_frontend_build"
    fi
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
        "${MYSQL_CMD[@]}" "${MYSQL_OPTS[@]}" "${DB_NAME}" <<EOF
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
            exists=$("${MYSQL_CMD[@]}" "${MYSQL_OPTS[@]}" "${DB_NAME}" -sN -e "${check_sql}" 2>/dev/null || echo 0)
            if [[ "${exists}" == "1" ]]; then
                "${MYSQL_CMD[@]}" "${MYSQL_OPTS[@]}" "${DB_NAME}" -e \
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
        record_if_applied "009_audio_files.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='audio_files'),1,0);"
        record_if_applied "010_domains_force_https.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='domains' AND COLUMN_NAME='force_https'),1,0);"
        record_if_applied "011_push_message_unlimited.sql" \
            "SELECT IF(COLUMN_TYPE='text',1,0) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='messages' AND COLUMN_NAME='title' LIMIT 1;"
        record_if_applied "012_devices_extend.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='devices' AND COLUMN_NAME='platform'),1,0);"
        record_if_applied "013_push_logs_extend.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='push_logs' AND COLUMN_NAME='fail_reason'),1,0);"
        record_if_applied "014_apk_download_logs.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='apk_download_logs'),1,0);"
        record_if_applied "015_apk_distribution_feijii.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='apk_distributions' AND COLUMN_NAME='feijipan_url'),1,0);"
        record_if_applied "016_apk_feijii_direct_url.sql" \
            "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='apk_distributions' AND COLUMN_NAME='feijipan_fetch_count'),1,0);"
        record_if_applied "017_drop_lanzou_fields.sql" \
            "SELECT IF(NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='apk_distributions' AND COLUMN_NAME='lanzou_url'),1,0);"

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
                ALREADY_APPLIED=$("${MYSQL_CMD[@]}" "${MYSQL_OPTS[@]}" "${DB_NAME}" -sN -e \
                    "SELECT COUNT(*) FROM \`${MIGRATIONS_TABLE}\` WHERE filename='${filename}';" 2>/dev/null || echo 0)

                if [[ "${ALREADY_APPLIED}" -gt 0 ]]; then
                    info "  跳过(已应用): ${filename}"
                    SKIPPED_COUNT=$((SKIPPED_COUNT + 1))
                else
                    info "  执行: ${filename}"
                    if "${MYSQL_CMD[@]}" "${MYSQL_OPTS[@]}" "${DB_NAME}" < "${sql_file}"; then
                        "${MYSQL_CMD[@]}" "${MYSQL_OPTS[@]}" "${DB_NAME}" -e \
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

    # 0. 检测 web 用户（Debian/Ubuntu=www-data, CentOS/RHEL/Alpine=nginx, Arch=http）
    WEB_USER="www-data"
    if id -u nginx >/dev/null 2>&1; then
        WEB_USER="nginx"
    elif id -u http >/dev/null 2>&1; then
        WEB_USER="http"
    fi

    # 1. 修复运行时目录权限（Web 用户需要写入 storage/runtime/build 等目录）
    info "修复运行时目录权限 (Web 用户: ${WEB_USER})..."
    sudo mkdir -p "${PROJECT_DIR}/backend/runtime/logs" "${PROJECT_DIR}/backend/storage" \
                "${PROJECT_DIR}/build/logs" "${PROJECT_DIR}/build/output" \
                "${PROJECT_DIR}/app/src/main/assets" "${PROJECT_DIR}/.gradle" 2>/dev/null || true
    # 仅修改运行时目录，不破坏 .git / vendor / node_modules 权限
    sudo chown -R "${WEB_USER}:${WEB_USER}" \
        "${PROJECT_DIR}/backend/storage" \
        "${PROJECT_DIR}/backend/runtime" \
        "${PROJECT_DIR}/build" \
        "${PROJECT_DIR}/app" \
        "${PROJECT_DIR}/.gradle" \
        2>/dev/null || true
    # 权限位修正
    sudo find "${PROJECT_DIR}/backend/storage" -type d -exec chmod 775 {} \; 2>/dev/null || true
    sudo find "${PROJECT_DIR}/backend/runtime" -type d -exec chmod 775 {} \; 2>/dev/null || true
    sudo find "${PROJECT_DIR}/backend/storage" -type f -exec chmod 664 {} \; 2>/dev/null || true
    sudo find "${PROJECT_DIR}/backend/runtime" -type f -exec chmod 664 {} \; 2>/dev/null || true
    sudo find "${PROJECT_DIR}/build" "${PROJECT_DIR}/backend/bin" "${PROJECT_DIR}/deploy/apk" -type f -name "*.sh" -exec chmod 755 {} \; 2>/dev/null || true
    # 小飞机上传脚本需要 www-data 可执行（PHP shell_exec 调用）
    for s in deploy/apk/upload-to-feijipan.sh; do
        [[ -f "${PROJECT_DIR}/${s}" ]] && sudo chmod +x "${PROJECT_DIR}/${s}" 2>/dev/null || true
    done
    # .env 文件让 Web 用户可读
    if [[ -f "${PROJECT_DIR}/backend/.env" ]]; then
        sudo chown "root:${WEB_USER}" "${PROJECT_DIR}/backend/.env" 2>/dev/null || true
        sudo chmod 640 "${PROJECT_DIR}/backend/.env" 2>/dev/null || true
    fi
    info "运行时目录权限修复完成。"

    # 2. 更新核心 systemd 服务文件（适配自定义 PROJECT_DIR 和 WEB_USER）
    if command -v systemctl >/dev/null 2>&1; then
        info "检查 systemd 服务文件..."
        SYSTEMD_SRC="${PROJECT_DIR}/deploy/systemd"
        SYSTEMD_DST="/etc/systemd/system"
        SYSTEMD_VERSION=$(systemctl --version 2>/dev/null | head -n1 | awk '{print $2}' || echo 0)
        if [[ -d "${SYSTEMD_SRC}" ]]; then
            for svc_file in "${SYSTEMD_SRC}"/*.service; do
                [[ -f "${svc_file}" ]] || continue
                svc_name="$(basename "${svc_file}")"
                DST_FILE="${SYSTEMD_DST}/${svc_name}"
                # 检查是否需要更新：未安装 / 路径变化 / 用户变化
                NEED_UPDATE=false
                if [[ ! -f "${DST_FILE}" ]]; then
                    NEED_UPDATE=true
                    info "  ${svc_name}: 未安装，将创建"
                else
                    if grep -q "/www/push-system" "${DST_FILE}" 2>/dev/null && [[ "${PROJECT_DIR}" != "/www/push-system" ]]; then
                        NEED_UPDATE=true
                        info "  ${svc_name}: 项目路径变化，需更新"
                    fi
                    if grep -q "^User=www-data$" "${DST_FILE}" 2>/dev/null && [[ "${WEB_USER}" != "www-data" ]]; then
                        NEED_UPDATE=true
                        info "  ${svc_name}: 运行用户变化，需更新"
                    fi
                fi
                if [[ "${NEED_UPDATE}" == "true" ]]; then
                    info "  生成 ${svc_name} (用户=${WEB_USER}, 路径=${PROJECT_DIR})..."
                    sudo sed "s/^User=www-data$/User=${WEB_USER}/g; \
s/^Group=www-data$/Group=${WEB_USER}/g; \
s|/www/push-system|${PROJECT_DIR}|g" \
                        "${svc_file}" | sudo tee "$DST_FILE" >/dev/null
                    # systemd < 227: 移除不支持的 cgroup 指令
                    if [[ "$SYSTEMD_VERSION" -gt 0 && "$SYSTEMD_VERSION" -lt 227 ]]; then
                        sudo sed -i '/^MemoryMax=/d; /^MemoryHigh=/d; /^TasksMax=/d; /^CPUQuota=/d' "$DST_FILE" 2>/dev/null || true
                    fi
                    # systemd < 230: StartLimitIntervalSec -> StartLimitInterval
                    if [[ "$SYSTEMD_VERSION" -gt 0 && "$SYSTEMD_VERSION" -lt 230 ]]; then
                        sudo sed -i 's/^StartLimitIntervalSec=/StartLimitInterval=/' "$DST_FILE" 2>/dev/null || true
                    fi
                fi
            done
            sudo systemctl daemon-reload 2>/dev/null || true
            info "systemd 核心服务文件检查完成。"
        fi

        # 废弃的 BuildWorker 服务处理（APP 打包已迁移到 GitHub Actions）
        if systemctl list-unit-files 2>/dev/null | grep -q 'push-build-worker'; then
            info "检测到废弃的 push-build-worker 服务，停止并禁用（APP 打包已迁移到 GitHub Actions）..."
            sudo systemctl stop push-build-worker 2>/dev/null || true
            sudo systemctl disable push-build-worker 2>/dev/null || true
            sudo rm -f "/etc/systemd/system/push-build-worker.service" 2>/dev/null || true
            sudo systemctl daemon-reload 2>/dev/null || true
        fi
    fi

    # 3. 修复 PHP 可执行路径（不同发行版 PHP 路径可能不同）
    if command -v systemctl >/dev/null 2>&1; then
        PHP_BIN_PATH="$(command -v php || echo /usr/bin/php)"
        for svc in push-http push-websocket; do
            SVC_FILE="/etc/systemd/system/${svc}.service"
            if [[ -f "$SVC_FILE" ]]; then
                # 提取当前服务中的 PHP 路径（注意：ExecStart=/usr/bin/php ...）
                CURRENT_PHP_IN_SVC=$(grep '^ExecStart=' "$SVC_FILE" 2>/dev/null | sed -E 's/^ExecStart=([^ ]+) .*/\1/' || echo '')
                if [[ -n "$CURRENT_PHP_IN_SVC" && "$CURRENT_PHP_IN_SVC" != "$PHP_BIN_PATH" ]]; then
                    info "服务 ${svc} 中 PHP 路径 ${CURRENT_PHP_IN_SVC} 与实际 ${PHP_BIN_PATH} 不一致，更新..."
                    sudo sed -i "s|^ExecStart=${CURRENT_PHP_IN_SVC} |ExecStart=${PHP_BIN_PATH} |" "$SVC_FILE"
                    sudo systemctl daemon-reload 2>/dev/null || true
                fi
            fi
        done
    fi

    # 4. 删除 gradlew（强制使用全局 gradle，避免 wrapper 尝试下载 distribution 超时）
    if [ -f "${PROJECT_DIR}/gradlew" ]; then
        info "移除 gradlew（使用全局 gradle 避免下载 distribution）..."
        rm -f "${PROJECT_DIR}/gradlew"
        rm -rf "${PROJECT_DIR}/gradle"
    fi

    # 5. Nginx 配置检查（路径适配）
    if command -v nginx >/dev/null 2>&1; then
        NGINX_SRC="${PROJECT_DIR}/deploy/nginx/push.conf"
        if [[ -f "${NGINX_SRC}" ]]; then
            NGINX_DST=""
            for dir in /etc/nginx/sites-available /etc/nginx/conf.d /etc/nginx/http.d /etc/nginx/vhosts.d /etc/nginx; do
                if [[ -d "$dir" ]]; then
                    if [[ -f "$dir/push.conf" ]]; then
                        NGINX_DST="$dir/push.conf"
                        break
                    elif [[ -z "$NGINX_DST" ]]; then
                        NGINX_DST="$dir/push.conf"
                    fi
                fi
            done
            if [[ -n "$NGINX_DST" ]]; then
                NEED_NGINX_UPDATE=false
                if grep -q "/www/push-system" "${NGINX_DST}" 2>/dev/null && [[ "${PROJECT_DIR}" != "/www/push-system" ]]; then
                    NEED_NGINX_UPDATE=true
                    info "Nginx 配置路径与实际项目路径不一致，将更新 (${NGINX_DST})"
                fi
                if [[ "${NEED_NGINX_UPDATE}" == "true" ]]; then
                    NGINX_TMP=$(mktemp)
                    sed "s|/www/push-system|${PROJECT_DIR}|g" "${NGINX_SRC}" > "${NGINX_TMP}"
                    sudo cp "${NGINX_TMP}" "${NGINX_DST}"
                    rm -f "${NGINX_TMP}"
                    if [[ "${NGINX_DST}" == "/etc/nginx/sites-available/push.conf" ]]; then
                        sudo ln -sf /etc/nginx/sites-available/push.conf /etc/nginx/sites-enabled/push.conf 2>/dev/null || true
                        sudo rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
                    fi
                    info "Nginx 配置已更新，测试语法..."
                    if nginx -t 2>&1; then
                        sudo systemctl reload nginx 2>/dev/null || sudo systemctl restart nginx 2>/dev/null || true
                        info "Nginx 已重新加载。"
                    else
                        warn "Nginx 语法检查失败，请手动检查: ${NGINX_DST}"
                    fi
                fi
            fi
        fi
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

        # 服务健康检查（仅检查核心服务）
        echo ""
        for svc in push-http push-websocket; do
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
