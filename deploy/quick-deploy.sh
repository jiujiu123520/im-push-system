#!/bin/bash
# ============================================================
# 即时消息推送系统 - 一键部署/更新脚本（独立版）
#
# 基于 backend/deploy/update.sh 精简而成，可在任意目录直接运行。
# 自动检测项目目录、拉取代码、安装依赖、构建前端、迁移数据库、重启服务。
#
# 用法:
#   sudo bash quick-deploy.sh                    # 正常更新（含确认）
#   sudo bash quick-deploy.sh --yes              # 跳过所有确认
#   sudo bash quick-deploy.sh --gh-proxy         # 使用 GitHub 代理加速
#   sudo bash quick-deploy.sh --proxy=http://127.0.0.1:7890  # 自定义 HTTP 代理
#   sudo bash quick-deploy.sh --skip-build       # 跳过前端构建
#   sudo bash quick-deploy.sh --skip-migration   # 跳过数据库迁移
#   sudo bash quick-deploy.sh --skip-version-check  # 跳过版本检测
#   sudo bash quick-deploy.sh --force            # 强制完整更新（不等版本检测）
#   sudo bash quick-deploy.sh --project-dir=/opt/push  # 指定项目目录
#
# 首次安装:
#   sudo bash quick-deploy.sh --install          # 先执行 deploy/install.sh 完整安装
# ============================================================

set -e
# ------------------------------------------------------------
# 跨系统：依赖检查（bash/mysql/git/curl）
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
    echo "[ERROR] quick-deploy 缺少依赖：${miss[*]}" >&2
    local p
    for p in "${miss[@]}"; do _distro_install_hint "$p" >&2; done
    exit 1
  fi
  if ! _need_cmd python3; then
    echo "[WARN] 未检测到 python3，小飞机网盘上传脚本将降级使用 grep/sed 解析 JSON，Alpine/BSD 环境可能失效。推荐安装 python3 以提升兼容性。" >&2
    _distro_install_hint "python3" >&2
  fi
  if ! _need_cmd systemctl; then
    echo "[WARN] 当前环境未检测到 systemctl（Docker 容器 / macOS），服务重启步骤将改为提示手动执行 bin/start.sh / bin/stop.sh。" >&2
  fi
}
assert_deps

# ------------------------------------------------------------
# 配置项
# ------------------------------------------------------------
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
MIGRATIONS_TABLE="schema_migrations"

# ------------------------------------------------------------
# 参数解析
# ------------------------------------------------------------
SKIP_CONFIRM=""
SKIP_BUILD=""
SKIP_MIGRATION=""
SKIP_VERSION_CHECK=""
GH_PROXY=""
GIT_PROXY=""
FORCE_UPDATE=""
INSTALL_MODE=""

for arg in "$@"; do
    case $arg in
        --yes)                  SKIP_CONFIRM="1" ;;
        --gh-proxy)             GH_PROXY="1" ;;
        --proxy=*)              GIT_PROXY="${arg#*=}" ;;
        --skip-build)           SKIP_BUILD="1" ;;
        --skip-migration)       SKIP_MIGRATION="1" ;;
        --skip-version-check)   SKIP_VERSION_CHECK="1" ;;
        --force)                FORCE_UPDATE="1" ;;
        --install)              INSTALL_MODE="1" ;;
        --project-dir=*)        PROJECT_DIR="${arg#*=}" ;;
        -h|--help)
            head -n 22 "$0"
            exit 0
            ;;
        *)
            echo "未知参数: $arg" >&2
            exit 1
            ;;
    esac
done

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
# 前置检查：必须 root 权限
# ------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    error "此脚本必须以 root 权限运行，请使用 sudo 或切换到 root 用户。"
    exit 1
fi

# ============================================================
# 首次安装模式 (--install)
# ============================================================
if [[ "${INSTALL_MODE}" == "1" ]]; then
    echo "========================================"
    echo "  即时消息推送系统 - 首次安装"
    echo "========================================"
    echo ""

    # 如果项目目录不存在，先 clone
    if [[ ! -d "${PROJECT_DIR}" ]]; then
        info "项目目录不存在，开始克隆..."
        mkdir -p "$(dirname "${PROJECT_DIR}")"

        REPO_URL="https://github.com/jiujiu123520/im-push-system.git"
        if [[ "${GH_PROXY}" == "1" ]]; then
            REPO_URL="https://gh.jasonzeng.dev/${REPO_URL}"
            info "使用 GitHub 代理加速: ${REPO_URL}"
        fi

        # 确保 git 已安装（最小化系统可能没有）
        if ! command -v git >/dev/null 2>&1; then
            info "检测到未安装 git，正在安装..."
            if command -v apt-get >/dev/null 2>&1; then
                export DEBIAN_FRONTEND=noninteractive
                apt-get update -qq 2>/dev/null || true
                apt-get install -y -qq git curl 2>/dev/null || true
            elif command -v dnf >/dev/null 2>&1; then
                dnf install -y -q git curl 2>/dev/null || true
            elif command -v yum >/dev/null 2>&1; then
                yum install -y -q git curl 2>/dev/null || true
            elif command -v apk >/dev/null 2>&1; then
                apk add --no-cache git curl 2>/dev/null || true
            elif command -v zypper >/dev/null 2>&1; then
                zypper install -y git curl 2>/dev/null || true
            elif command -v pacman >/dev/null 2>&1; then
                pacman -S --noconfirm git curl 2>/dev/null || true
            fi
        fi

        git clone "${REPO_URL}" "${PROJECT_DIR}"
        info "代码克隆完成。"
    fi

    cd "${PROJECT_DIR}"
    info "执行安装脚本 deploy/install.sh ..."
    bash deploy/install.sh
    exit $?
fi

# ============================================================
# 更新模式
# ============================================================
cd "${PROJECT_DIR}" 2>/dev/null || {
    error "项目目录不存在: ${PROJECT_DIR}"
    echo ""
    echo "如果是首次安装，请使用:"
    echo "  sudo bash quick-deploy.sh --install"
    echo ""
    echo "或先手动克隆项目:"
    echo "  git clone https://github.com/jiujiu123520/im-push-system.git ${PROJECT_DIR}"
    exit 1
}

# Git 安全目录配置，避免在 root 下操作时 git 报错
git config --global --add safe.directory "$PROJECT_DIR" 2>/dev/null || true

# ------------------------------------------------------------
# 检测 Web 运行用户（不同发行版不同）
# ------------------------------------------------------------
if id -u www-data >/dev/null 2>&1; then
    WEB_USER="www-data"
elif id -u nginx >/dev/null 2>&1; then
    WEB_USER="nginx"
elif id -u http >/dev/null 2>&1; then
    WEB_USER="http"
else
    WEB_USER="www-data"
    warn "未检测到 www-data/nginx/http 用户，将默认使用 www-data（如果不存在会自动创建）"
fi
info "检测到 Web 运行用户: ${WEB_USER}"

# ------------------------------------------------------------
# 代理配置函数
# ------------------------------------------------------------
ORIGINAL_ORIGIN_URL=""
PROXY_REPLACED=""

setup_git_proxy() {
    if [[ -n "${GIT_PROXY}" ]]; then
        # 使用自定义 HTTP 代理
        info "配置 Git 代理: ${GIT_PROXY}"
        git config --global http.proxy "${GIT_PROXY}" 2>/dev/null || true
        git config --global https.proxy "${GIT_PROXY}" 2>/dev/null || true
        export HTTP_PROXY="${GIT_PROXY}"
        export HTTPS_PROXY="${GIT_PROXY}"
    elif [[ "${GH_PROXY}" == "1" ]]; then
        # 使用 GitHub 代理 gh.jasonzeng.dev
        info "使用 GitHub 代理加速 (gh.jasonzeng.dev)..."
        local remote_url
        remote_url="$(git remote get-url origin 2>/dev/null || echo '')"
        if [[ -n "${remote_url}" && "${remote_url}" =~ github\.com ]] && [[ ! "${remote_url}" =~ gh\.jasonzeng\.dev ]]; then
            local new_url="${remote_url/github.com/gh.jasonzeng.dev\/https:\/\/github.com}"
            info "  替换远程地址: ${remote_url} -> ${new_url}"
            git remote set-url origin "${new_url}" 2>/dev/null || true
            ORIGINAL_ORIGIN_URL="${remote_url}"
            PROXY_REPLACED="1"
        elif [[ "${remote_url}" =~ gh\.jasonzeng\.dev ]]; then
            info "  远程地址已包含代理前缀，无需替换"
        fi
    fi
}

restore_git_proxy() {
    if [[ -n "${GIT_PROXY}" ]]; then
        git config --global --unset http.proxy 2>/dev/null || true
        git config --global --unset https.proxy 2>/dev/null || true
        unset HTTP_PROXY
        unset HTTPS_PROXY
    fi
    if [[ "${GH_PROXY}" == "1" && "${PROXY_REPLACED}" == "1" && -n "${ORIGINAL_ORIGIN_URL}" ]]; then
        info "恢复原始远程地址..."
        git remote set-url origin "${ORIGINAL_ORIGIN_URL}" 2>/dev/null || true
    fi
}

# ------------------------------------------------------------
# 从 .env 读取数据库配置（关键修复：兼容 CRLF + 双引号）
# ------------------------------------------------------------
if [[ -f "${PROJECT_DIR}/backend/.env" ]]; then
    DB_NAME="$(grep -E '^DB_NAME=' "${PROJECT_DIR}/backend/.env" | tail -n1 | cut -d'=' -f2- | tr -d '"\r' || echo '')"
    DB_USER="$(grep -E '^DB_USER=' "${PROJECT_DIR}/backend/.env" | tail -n1 | cut -d'=' -f2- | tr -d '"\r' || echo '')"
    DB_PASS="$(grep -E '^DB_PASS=' "${PROJECT_DIR}/backend/.env" | tail -n1 | cut -d'=' -f2- | tr -d '"\r' || echo '')"
    DB_HOST="$(grep -E '^DB_HOST=' "${PROJECT_DIR}/backend/.env" | tail -n1 | cut -d'=' -f2- | tr -d '"\r' || echo '')"
    DB_PORT="$(grep -E '^DB_PORT=' "${PROJECT_DIR}/backend/.env" | tail -n1 | cut -d'=' -f2- | tr -d '"\r' || echo '')"
fi
DB_NAME="${DB_NAME:-im_push}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"

# Ubuntu/MariaDB 兼容：root+空密码 unix_socket 认证自动切换 sudo mysql
MYSQL_CMD=(mysql)
if [[ "${DB_USER}" == "root" && -z "${DB_PASS}" ]]; then
    if ! mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -e "SELECT 1" &>/dev/null; then
        if command -v sudo >/dev/null 2>&1 && sudo mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -e "SELECT 1" &>/dev/null; then
            MYSQL_CMD=(sudo mysql)
            echo -e "\033[0;32m[INFO]\033[0m 检测到 root 使用 unix_socket 认证，已自动切换为 sudo mysql 连接"
        fi
    fi
fi
# ============================================================
# 主流程
# ============================================================
echo "========================================"
echo "  即时消息推送系统 - 一键部署/更新"
echo "========================================"
echo "项目目录: ${PROJECT_DIR}"
echo "Web 用户: ${WEB_USER}"
echo "数据库:   ${DB_NAME} (用户: ${DB_USER}@${DB_HOST}:${DB_PORT})"
echo ""

# 确认步骤（--yes 跳过）
if [[ "${SKIP_CONFIRM}" != "1" ]]; then
    warn "即将开始更新，更新过程中服务可能短暂中断。"
    info "提示：直接回车即开始更新（默认为 Y），输入 n 取消"
    read -r -p "确认开始更新？[Y/n]（默认 Y）: " CONFIRM
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
# [1/7] 版本检测（可选）
# ============================================================
SKIP_PULL_CODE=""
if [[ "${SKIP_VERSION_CHECK}" != "1" && "${FORCE_UPDATE}" != "1" ]]; then
    step "[1/7] 版本检测..."

    setup_git_proxy

    LOCAL_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
    LOCAL_SHORT="${LOCAL_COMMIT:0:8}"

    # 浅 fetch 加速，失败则完整 fetch
    if ! git fetch origin --depth=50 2>/dev/null; then
        git fetch origin 2>/dev/null || {
            warn "无法连接到远程仓库，跳过版本检测。"
            restore_git_proxy
            SKIP_VERSION_CHECK="1"
        }
    fi

    if [[ "${SKIP_VERSION_CHECK}" != "1" ]]; then
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
            REMOTE_SUBJECT="$(git log -1 "origin/${REMOTE_BRANCH}" --format='%s' 2>/dev/null || echo '')"

            echo ""
            echo "  本地版本:  ${LOCAL_SHORT}"
            echo "  云端版本:  ${REMOTE_SHORT} - ${REMOTE_SUBJECT}"

            if [[ "${LOCAL_COMMIT}" == "${REMOTE_COMMIT}" ]]; then
                echo -e "  版本状态:  ${COLOR_GREEN}✓ 已是最新版本${COLOR_RESET}"
                # 已是最新时询问是否仍要完整更新（默认仅重启）
                if [[ "${SKIP_CONFIRM}" != "1" ]]; then
                    read -r -p "版本已是最新，是否仍要完整更新？[y/N]（默认仅重启）: " FORCE_CONFIRM
                    if [[ ! "${FORCE_CONFIRM}" =~ ^[Yy]$ ]]; then
                        info "跳过代码拉取，仅执行构建与重启。"
                        SKIP_PULL_CODE="1"
                    fi
                fi
            else
                AHEAD_COUNT="$(git rev-list --count "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null || echo 0)"
                echo -e "  版本状态:  ${COLOR_YELLOW}↑ 本地落后 ${AHEAD_COUNT} 个提交${COLOR_RESET}"
                echo ""
                info "云端新增提交（最近 5 条）："
                git log --oneline "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null | head -n 5 | while read -r line; do
                    echo -e "    ${COLOR_YELLOW}↑${COLOR_RESET} ${line}"
                done
            fi
            echo ""
        fi

        restore_git_proxy
    fi
fi

# ============================================================
# [2/7] 拉取最新代码
# ============================================================
if [[ "${SKIP_PULL_CODE}" != "1" ]]; then
    step "[2/7] 拉取最新代码..."

    # 修复 .git 属主（避免 git fetch/reset 报错）
    info "修复项目目录权限..."
    CURRENT_OWNER="$(stat -c '%U' "${PROJECT_DIR}/.git" 2>/dev/null || echo "$(whoami)")"
    if [[ "${CURRENT_OWNER}" != "$(whoami)" ]]; then
        chown -R "$(whoami):$(whoami)" "${PROJECT_DIR}/.git" 2>/dev/null || true
    fi

    setup_git_proxy

    info "强制拉取远程代码..."
    # 1. 清除本地未提交的修改（保留 composer.lock/package-lock.json/.env）
    git checkout -- . 2>/dev/null || true
    git clean -fd -e 'composer.lock' -e 'package-lock.json' -e 'backend/composer.lock' -e 'admin/package-lock.json' -e 'backend/.env' 2>/dev/null || true
    # 2. 强制 fetch 最新远程引用
    git fetch --force --all 2>/dev/null || true
    # 3. 确定远端分支
    if [[ -z "${REMOTE_BRANCH}" ]]; then
        if git rev-parse --verify origin/main >/dev/null 2>&1; then
            REMOTE_BRANCH="main"
        else
            REMOTE_BRANCH="master"
        fi
    fi
    # 4. 硬重置到远程分支
    git reset --hard "origin/${REMOTE_BRANCH}" 2>/dev/null || git reset --hard HEAD 2>/dev/null || true
    info "当前 commit: $(git log -1 --pretty=format:'%h %s (%an, %ad)' --date=short 2>/dev/null || echo 'unknown')"

    restore_git_proxy

    info "代码拉取完成。"
fi

# ============================================================
# [3/7] 更新后端依赖
# ============================================================
step "[3/7] 更新后端依赖..."

cd "${PROJECT_DIR}/backend"
export COMPOSER_ALLOW_SUPERUSER=1
# 关闭安全公告阻断（Composer 2.4+ 和旧版都配置）
composer config --global --no-interaction audit.block-insecure false 2>/dev/null || true
composer config --global --no-interaction policy.advisories.block false 2>/dev/null || true
composer config --no-interaction audit.block-insecure false 2>/dev/null || true
composer config --no-interaction policy.advisories.block false 2>/dev/null || true
# 配置 Packagist 阿里云镜像（国内加速）
composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/ 2>/dev/null || true
composer config repo.packagist composer https://mirrors.aliyun.com/composer/ 2>/dev/null || true

# 确保 composer 已安装
if ! command -v composer >/dev/null 2>&1; then
    info "检测到未安装 composer，正在快速安装..."
    curl -fsSL https://mirrors.aliyun.com/composer/composer.phar -o /usr/local/bin/composer 2>/dev/null || \
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    chmod +x /usr/local/bin/composer
fi

# 同步 composer.lock（超时 120s，失败不阻断）
timeout 120 composer update --lock --no-interaction --no-dev 2>/dev/null || warn "composer update --lock 超时或失败，继续 install..."
# 安装依赖（超时 600s）
timeout 600 composer install --no-dev --optimize-autoloader --no-interaction || {
    error "composer install 失败（超时或网络问题）"
    warn "将尝试使用已有的 vendor 目录继续（如果存在）..."
    if [[ ! -d "vendor" ]]; then
        error "vendor 目录不存在，无法继续。请手动执行: cd ${PROJECT_DIR}/backend && composer install"
        exit 1
    fi
}
cd "${PROJECT_DIR}"
info "后端依赖更新完成。"
# ============================================================
# [4/7] 构建前端
# ============================================================
if [[ "${SKIP_BUILD}" == "1" ]]; then
    warn "已跳过前端构建 (--skip-build)"
elif [[ -d "${PROJECT_DIR}/admin" ]]; then
    step "[4/7] 构建管理后台..."

    cd "${PROJECT_DIR}/admin"

    # 确保 Node.js 和 npm 已安装
    if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
        error "未检测到 Node.js 或 npm，跳过前端构建！"
        error "请先安装 Node.js 16+，或使用 --skip-build 跳过构建"
        cd "${PROJECT_DIR}"
    else
        # 配置 npm 国内镜像
        npm config set registry https://registry.npmmirror.com 2>/dev/null || true

        # 检测 node_modules/.bin 权限，若不可执行则删除 node_modules 重装
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

        info "执行 npm install（超时 10 分钟）..."
        timeout 600 npm install --no-audit --no-fund --loglevel=error 2>&1 || {
            warn "npm install 失败，尝试清理后重试..."
            rm -rf node_modules package-lock.json
            timeout 600 npm install --no-audit --no-fund --loglevel=error || {
                error "npm install 再次失败！管理后台将使用旧版本 dist（如果存在）"
            }
        }

        # 修复 .bin 权限（跟随符号链接修复目标文件权限）
        if [[ -d node_modules/.bin ]]; then
            find node_modules/.bin -type l | while read -r link; do
                target=$(readlink -f "$link" 2>/dev/null)
                [[ -f "$target" ]] && chmod +x "$target" 2>/dev/null || true
            done
            find node_modules/.bin -type f -exec chmod +x {} \; 2>/dev/null || true
        fi

        # 前端构建：标准构建 -> vite build（跳过类型检查）-> 加内存限制重试
        info "构建管理后台 (npm run build)（超时 5 分钟）..."
        BUILD_SUCCESS=false
        if timeout 300 npm run build 2>&1; then
            BUILD_SUCCESS=true
            info "前端构建完成。"
        else
            warn "标准构建失败（可能是 TypeScript 类型检查或内存不足），降级为直接 vite build..."
            if timeout 300 npx vite build 2>&1; then
                BUILD_SUCCESS=true
                warn "前端已构建完成（跳过了类型检查）。"
            else
                warn "vite build 也失败，尝试增加内存限制..."
                # 小内存服务器常见问题
                export NODE_OPTIONS="--max-old-space-size=2048"
                if timeout 300 npx vite build 2>&1; then
                    BUILD_SUCCESS=true
                    warn "前端已构建完成（增加内存限制至 2G）。"
                else
                    error "前端构建失败！后端更新将继续，但管理后台可能仍是旧版本。"
                    error "请手动检查: cd ${PROJECT_DIR}/admin && npm run build"
                fi
            fi
        fi
        cd "${PROJECT_DIR}"
    fi
else
    warn "未找到 admin 目录，跳过前端构建。"
fi

# ============================================================
# [5/7] 数据库迁移（关键修复：MYSQL_CMD 统一前缀 + MYSQL_PWD 环境变量）
# ============================================================
if [[ "${SKIP_MIGRATION}" == "1" ]]; then
    warn "已跳过数据库迁移 (--skip-migration)"
else
    step "[5/7] 数据库迁移..."

    MIGRATIONS_DIR="${PROJECT_DIR}/backend/database/migrations"
    MYSQL_OPTS=("-h${DB_HOST}" "-P${DB_PORT}" "-u${DB_USER}")
    if [[ -n "${DB_PASS}" ]]; then
        # 使用 MYSQL_PWD 环境变量传递密码，避免命令行明文（mysql 客户端自动读取）
        export MYSQL_PWD="${DB_PASS}"
    fi

    # 确保 mysql 客户端可用
    if ! command -v mysql >/dev/null 2>&1; then
        error "未安装 mysql 客户端，跳过数据库迁移！"
        error "请手动安装 mysql 客户端后执行迁移，或使用 --skip-migration 跳过"
    elif [[ -d "${MIGRATIONS_DIR}" ]]; then
        # 创建迁移记录表（如不存在）
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
                        warn "将跳过此迁移继续（如需手动修复，请查看 SQL 文件内容）"
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
    # 清理密码环境变量
    unset MYSQL_PWD
fi
# ============================================================
# [6/7] 修复权限 + systemd 服务 + Nginx 配置
# ============================================================
step "[6/7] 修复权限与服务配置..."

cd "${PROJECT_DIR}"

# --- 6.1 修复项目运行时目录权限（给 Web 用户可写） ---
info "修复运行时目录权限 (Web 用户: ${WEB_USER})..."

# 如果 WEB_USER 不存在，尝试创建（罕见场景）
if ! id -u "${WEB_USER}" >/dev/null 2>&1; then
    warn "用户 ${WEB_USER} 不存在，尝试创建..."
    if command -v useradd >/dev/null 2>&1; then
        useradd -r -s /usr/sbin/nologin "${WEB_USER}" 2>/dev/null || true
    fi
fi

mkdir -p "${PROJECT_DIR}/backend/runtime/logs" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/backend/storage" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/build/logs" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/build/output" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/app/src/main/assets" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/.gradle" 2>/dev/null || true

# 仅 chown 运行时目录，不碰 .git / vendor / node_modules（避免破坏 git 权限）
chown -R "${WEB_USER}:${WEB_USER}" \
    "${PROJECT_DIR}/backend/storage" \
    "${PROJECT_DIR}/backend/runtime" \
    "${PROJECT_DIR}/build" \
    "${PROJECT_DIR}/app" \
    "${PROJECT_DIR}/.gradle" \
    2>/dev/null || true

# 权限位修正（目录 775 / 文件 664）
find "${PROJECT_DIR}/backend/storage" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/backend/runtime" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/backend/storage" -type f -exec chmod 664 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/backend/runtime" -type f -exec chmod 664 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/build" -type f -name "*.sh" -exec chmod 755 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/deploy" "${PROJECT_DIR}/backend/bin" -type f -name "*.sh" -exec chmod 755 {} \; 2>/dev/null || true

# .env 文件让 Web 用户可读（root:WEB_USER 640）
if [[ -f "${PROJECT_DIR}/backend/.env" ]]; then
    chown "root:${WEB_USER}" "${PROJECT_DIR}/backend/.env" 2>/dev/null || true
    chmod 640 "${PROJECT_DIR}/backend/.env" 2>/dev/null || true
fi

info "运行时目录权限修复完成。"

# --- 6.2 重新生成 systemd 服务文件（PROJECT_DIR 或 WEB_USER 变化时） ---
if command -v systemctl >/dev/null 2>&1; then
    info "检查 systemd 服务文件..."

    SYSTEMD_SRC="${PROJECT_DIR}/deploy/systemd"
    SYSTEMD_DST="/etc/systemd/system"
    # 检测 systemd 版本兼容性（CentOS 7 = 219）
    SYSTEMD_VERSION=$(systemctl --version 2>/dev/null | head -n1 | awk '{print $2}' || echo 0)

    if [[ -d "${SYSTEMD_SRC}" ]]; then
        for svc_file in "${SYSTEMD_SRC}"/*.service; do
            [[ -f "${svc_file}" ]] || continue
            svc_name="$(basename "${svc_file}")"
            DST_FILE="${SYSTEMD_DST}/${svc_name}"

            # 检查是否需要更新（未安装 / 路径变化 / 用户变化）
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
                # 动态替换 User/Group 和硬编码路径
                sed "s/^User=www-data$/User=${WEB_USER}/g; \
s/^Group=www-data$/Group=${WEB_USER}/g; \
s|/www/push-system|${PROJECT_DIR}|g" \
                    "${svc_file}" > "$DST_FILE"

                # systemd < 227: 移除不支持的 cgroup 指令
                if [[ "$SYSTEMD_VERSION" -gt 0 && "$SYSTEMD_VERSION" -lt 227 ]]; then
                    sed -i '/^MemoryMax=/d; /^MemoryHigh=/d; /^TasksMax=/d; /^CPUQuota=/d' "$DST_FILE"
                fi
                # systemd < 230: StartLimitIntervalSec -> StartLimitInterval
                if [[ "$SYSTEMD_VERSION" -gt 0 && "$SYSTEMD_VERSION" -lt 230 ]]; then
                    sed -i 's/^StartLimitIntervalSec=/StartLimitInterval=/' "$DST_FILE"
                fi
            fi
        done
        systemctl daemon-reload 2>/dev/null || true
        info "systemd 服务文件检查完成。"
    fi

    # --- 6.3 废弃 push-build-worker 服务清理（APP 打包已迁移到 GitHub Actions） ---
    if systemctl list-unit-files 2>/dev/null | grep -q 'push-build-worker'; then
        info "检测到废弃的 push-build-worker 服务，停止并禁用（APP 打包已迁移到 GitHub Actions）..."
        systemctl stop push-build-worker 2>/dev/null || true
        systemctl disable push-build-worker 2>/dev/null || true
        rm -f "/etc/systemd/system/push-build-worker.service" 2>/dev/null || true
        systemctl daemon-reload 2>/dev/null || true
    fi
fi

# --- 6.4 Nginx 配置（关键修复：备份到 /etc/nginx/backup/ + 自动注入 captcha/auth/health rewrite） ---
if command -v nginx >/dev/null 2>&1; then
    NGINX_SRC="${PROJECT_DIR}/deploy/nginx/push-system.conf"
    if [[ -f "${NGINX_SRC}" ]]; then
        # 查找 Nginx 配置实际存放的位置（不同发行版不同）
        NGINX_DST=""
        for dir in /etc/nginx/sites-available /etc/nginx/conf.d /etc/nginx/http.d /etc/nginx/vhosts.d /etc/nginx; do
            if [[ -d "$dir" ]]; then
                if [[ -f "$dir/push-system.conf" ]]; then
                    NGINX_DST="$dir/push-system.conf"
                    break
                elif [[ "$dir" == "/etc/nginx/sites-available" && -L "/etc/nginx/sites-enabled/push-system.conf" ]]; then
                    NGINX_DST="$dir/push-system.conf"
                    break
                elif [[ -z "$NGINX_DST" ]]; then
                    # 记录第一个可用目录作为 fallback
                    NGINX_DST="$dir/push-system.conf"
                fi
            fi
        done

        if [[ -n "$NGINX_DST" ]]; then
            # 检测 PROJECT_DIR 路径是否变化导致静态文件路径失效
            NEED_NGINX_UPDATE=false
            if grep -q "/www/push-system" "${NGINX_DST}" 2>/dev/null && [[ "${PROJECT_DIR}" != "/www/push-system" ]]; then
                NEED_NGINX_UPDATE=true
                info "Nginx 配置中硬编码路径与实际项目路径不一致，将更新 (${NGINX_DST})"
            fi
            if [[ "${NEED_NGINX_UPDATE}" == "true" ]]; then
                # 统一使用 /etc/nginx/backup/ 存放备份，避免在 sites-enabled / conf.d 下残留 *.bak
                mkdir -p /etc/nginx/backup
                if [[ -f "${NGINX_DST}" ]]; then
                    mv "${NGINX_DST}" "/etc/nginx/backup/push-system.conf.$(date +%Y%m%d%H%M%S)"
                fi
                NGINX_TMP=$(mktemp)
                sed "s|/www/push-system|${PROJECT_DIR}|g" "${NGINX_SRC}" > "${NGINX_TMP}"
                cp "${NGINX_TMP}" "${NGINX_DST}"
                rm -f "${NGINX_TMP}"
                # 清理 sites-enabled / conf.d 下的 *.bak* / *~ 文件到 backup
                find /etc/nginx/sites-enabled /etc/nginx/conf.d -maxdepth 1 -type f \( -name "*.bak*" -o -name "*~" \) -exec mv -t /etc/nginx/backup/ {} + 2>/dev/null || true
                # 如果是 sites-available，确保 sites-enabled 有软链
                if [[ "${NGINX_DST}" == "/etc/nginx/sites-available/push-system.conf" ]]; then
                    ln -sf /etc/nginx/sites-available/push-system.conf /etc/nginx/sites-enabled/push-system.conf 2>/dev/null || true
                    rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
                fi
                # 检测如果缺少 captcha/auth/health 三段 rewrite location，自动注入
                if ! grep -q "location ^~ /api/captcha/" "${NGINX_DST}" 2>/dev/null; then
                    CAPTCHA_BLOCK='
    # 3.5 前端 axios.baseURL=/api，后端路由本身不带 /api 前缀的模块：/captcha、/auth、/health
    #     必须 rewrite 去掉 /api 前缀再转发到 Swoole，否则路由 404
    location ^~ /api/captcha/ {
        rewrite ^/api/(.*)$ /$1 break;
        proxy_pass http://push_http;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
        proxy_buffering on;
        proxy_buffer_size 128k;
        proxy_buffers 4 256k;
    }

    location ^~ /api/auth/ {
        rewrite ^/api/(.*)$ /$1 break;
        proxy_pass http://push_http;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
        proxy_buffering on;
        proxy_buffer_size 128k;
        proxy_buffers 4 256k;
    }

    location ^~ /api/health/ {
        rewrite ^/api/(.*)$ /$1 break;
        proxy_pass http://push_http;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
        proxy_send_timeout 60s;
        proxy_buffering on;
    }
'
                    # 在 "location /api/apk-distribution/download/" 行之前插入 3 段 rewrite location
                    if command -v python3 >/dev/null 2>&1; then
                        python3 << 'PY_INSERT' "${NGINX_DST}" "${CAPTCHA_BLOCK}"
import sys
path = sys.argv[1]
block = sys.argv[2]
with open(path, 'r') as f:
    content = f.read()
marker = 'location /api/apk-distribution/download/'
if marker in content:
    indent = '    '
    content = content.replace(marker, block + indent + marker)
    with open(path, 'w') as f:
        f.write(content)
PY_INSERT
                    elif command -v awk >/dev/null 2>&1; then
                        AWK_INSERT_BEFORE="location /api/apk-distribution/download/"
                        AWK_BLOCK_FILE=$(mktemp)
                        printf '%s\n' "$CAPTCHA_BLOCK" > "$AWK_BLOCK_FILE"
                        AWK_TMP=$(mktemp)
                        awk -v marker="$AWK_INSERT_BEFORE" -v blockfile="$AWK_BLOCK_FILE" '
BEGIN { while ((getline line < blockfile) > 0) block = block line "\n" }
{
    if ($0 ~ marker) {
        printf "%s", block
    }
    print $0
}
' "${NGINX_DST}" > "$AWK_TMP" && mv "$AWK_TMP" "${NGINX_DST}"
                        rm -f "$AWK_BLOCK_FILE" 2>/dev/null || true
                    fi
                fi
                info "Nginx 配置已更新，测试语法..."
                if nginx -t 2>&1; then
                    systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
                    info "Nginx 已重新加载。"
                else
                    warn "Nginx 语法检查失败，请手动检查: ${NGINX_DST}"
                fi
            fi
        fi
    fi
fi
# ============================================================
# [7/7] 重启服务
# ============================================================
step "[7/7] 重启服务..."

cd "${PROJECT_DIR}"

if command -v systemctl >/dev/null 2>&1 && systemctl list-unit-files --type=service 2>/dev/null | grep -q push-http; then
    # 先检测 PHP 路径，更新 service 文件 ExecStart（不同发行版 PHP 路径可能不同）
    PHP_BIN_PATH="$(command -v php || echo /usr/bin/php)"
    for svc in push-http push-websocket; do
        SVC_FILE="/etc/systemd/system/${svc}.service"
        if [[ -f "$SVC_FILE" ]]; then
            # 提取当前服务中的 PHP 路径（ExecStart=/usr/bin/php ...）
            CURRENT_PHP_IN_SVC=$(grep '^ExecStart=' "$SVC_FILE" 2>/dev/null | awk '{print $1}' | cut -d= -f2 || echo '')
            if [[ -n "$CURRENT_PHP_IN_SVC" && "$CURRENT_PHP_IN_SVC" != "$PHP_BIN_PATH" ]]; then
                info "服务 ${svc} 中 PHP 路径 ${CURRENT_PHP_IN_SVC} 与实际 ${PHP_BIN_PATH} 不一致，更新..."
                sed -i "s|^ExecStart=${CURRENT_PHP_IN_SVC} |ExecStart=${PHP_BIN_PATH} |" "$SVC_FILE"
                systemctl daemon-reload 2>/dev/null || true
            fi
        fi
    done

    info "重启 push-http..."
    systemctl restart push-http || warn "push-http 重启失败，请用 journalctl -u push-http 查看日志"
    info "push-http 已触发重启。"

    sleep 1

    info "重启 push-websocket..."
    systemctl restart push-websocket || warn "push-websocket 重启失败，请用 journalctl -u push-websocket 查看日志"
    info "push-websocket 已触发重启。"

    sleep 2

    # 健康检查：is-active 显示运行状态
    echo ""
    for svc in push-http push-websocket; do
        status_output="$(systemctl is-active ${svc} 2>/dev/null || echo '')"
        if [[ "${status_output}" == "active" ]]; then
            echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc}    [运行中]"
        else
            echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc}    [未运行]"
            warn "服务 ${svc} 未正常运行，请检查日志:"
            warn "  sudo journalctl -u ${svc} --no-pager -n 50"
        fi
    done
else
    # 回退到 bin/stop.sh + start.sh（没有 systemd 或服务未安装）
    info "未检测到 systemd push-http 服务，使用 backend/bin/stop.sh / start.sh..."
    warn "提示: 建议首次安装后使用 deploy/install.sh 安装 systemd 服务，以便自动重启和日志管理"
    cd "${PROJECT_DIR}/backend"

    bash bin/stop.sh 2>/dev/null || true
    sleep 1

    mkdir -p runtime/logs
    if [[ -f bin/start.sh ]]; then
        bash bin/start.sh || warn "bin/start.sh 启动失败"
    else
        warn "未找到 bin/start.sh，尝试直接启动..."
        PHP_BIN="$(command -v php || echo php)"
        # 兜底：直接后台启动
        nohup "${PHP_BIN}" public/index.php --daemon > runtime/logs/http.out.log 2> runtime/logs/http.err.log &
        nohup "${PHP_BIN}" public/index.php --ws --daemon > runtime/logs/ws.out.log 2> runtime/logs/ws.err.log &
        info "服务已通过 nohup 启动（无自动重启）"
    fi
    cd "${PROJECT_DIR}"
fi

# ============================================================
# 完成
# ============================================================
echo ""
echo "========================================"
echo -e "  ${COLOR_GREEN}✓ 部署/更新完成${COLOR_RESET}"
echo "========================================"
echo ""
info "当前版本: $(git log -1 --pretty=format:'%h %s (%an, %ad)' --date=short 2>/dev/null || echo 'unknown')"
echo ""
HOST_IP=$(hostname -I 2>/dev/null | awk '{print $1}' || echo 'localhost')
echo "  管理后台:  http://${HOST_IP}/"
echo "  HTTP API:  http://${HOST_IP}:9501/"
echo "  WebSocket: ws://${HOST_IP}:9502/"
echo ""
echo "  管理后台域名访问: 请先在管理后台「域名管理」添加域名并部署 Nginx + SSL"
echo ""
warn "如果管理后台显示异常，请强制刷新浏览器（Ctrl+Shift+R）清除缓存。"
echo ""
info "常用命令："
echo "  查看服务状态:  systemctl status push-http push-websocket"
echo "  查看服务日志:  journalctl -u push-http -f"
echo "  下次更新:     sudo bash ${PROJECT_DIR}/deploy/quick-deploy.sh"
echo "  完整安装:     sudo bash ${PROJECT_DIR}/deploy/quick-deploy.sh --install"
echo ""
