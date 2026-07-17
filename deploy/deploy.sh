#!/bin/bash
# ============================================================
# 即时消息推送系统 - 一键部署脚本(统一入口)
#
# 特性:
#   1. 自动 sudo 提权(无需用户手动添加 sudo)
#   2. 自动检测网络,优先使用 gh.jasonzeng.dev 代理(国内服务器友好)
#   3. 从 GitHub 克隆代码(如本地无代码)
#   4. 调用 deploy/install.sh 交互式安装
#   5. 安装全部环境(核心服务 + Android 打包 + SSL + sudoers)
#
# 支持的 Linux 发行版:
#   - Ubuntu / Debian (apt-get + PPA/Sury)
#   - CentOS / RHEL / Rocky / AlmaLinux / Fedora (dnf/yum + Remi)
#   - Alpine Linux (apk)
#   - openSUSE / SLES (zypper)
#   - Arch / Manjaro (pacman)
#
# 用法:
#   方式1: 从 GitHub 直接部署(国内服务器推荐)
#     curl -sSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh | bash
#
#   方式2: 直接从 GitHub 部署(需能直连 GitHub)
#     curl -sSL https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh | bash
#
#   方式3: 本地执行(已有代码)
#     bash deploy/deploy.sh
#
#   方式4: 自定义参数
#     bash deploy/deploy.sh --project-dir=/www/push-system --db-pass=YourPass
#
#   方式5: 跳过可选组件(仅安装核心服务)
#     INSTALL_ANDROID=0 INSTALL_SSL=0 bash deploy/deploy.sh
#
# 环境变量:
#   PROJECT_DIR     - 项目目录(默认 /www/push-system)
#   DB_NAME         - 数据库名(默认 im_push)
#   DB_USER         - 数据库用户(默认 im_push)
#   DB_PASS         - 数据库密码(默认 ImPush@2024)
#   HTTP_PORT       - HTTP端口(默认 9501)
#   WEBSOCKET_PORT  - WebSocket端口(默认 9502)
#   INSTALL_ANDROID - 是否安装Android打包环境(默认 1=安装)
#   INSTALL_SSL     - 是否安装SSL环境(默认 1=安装)
#   INSTALL_SUDOERS - 是否配置sudoers(默认 1=配置)
#   GH_PROXY        - 强制使用GitHub代理(1=启用 gh.jasonzeng.dev, 0=自动检测)
# ============================================================

# ------------------------------------------------------------
# 自动 sudo 提权(如果非 root,自动用 sudo 重新执行)
# 这样用户无需手动加 sudo,curl | bash 即可
# ------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    # 检查是否有 sudo 权限
    if ! sudo -n true 2>/dev/null; then
        echo "[INFO] 此脚本需要 root 权限,正在通过 sudo 提权..."
        echo "[INFO] 可能需要输入当前用户密码"
    fi
    # 使用 sudo 重新执行,保留所有参数和环境变量
    exec sudo -E bash "$0" "$@"
fi

set -e

# ------------------------------------------------------------
# 颜色输出
# ------------------------------------------------------------
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[0;33m'
COLOR_RED='\033[0;31m'
COLOR_CYAN='\033[0;36m'
COLOR_BLUE='\033[0;34m'
COLOR_RESET='\033[0m'

info()  { echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }
step()  { echo -e "\n${COLOR_BLUE}===== [$1] $2 =====${COLOR_RESET}"; }

# ------------------------------------------------------------
# 默认配置
# ------------------------------------------------------------
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
DB_NAME="${DB_NAME:-im_push}"
DB_USER="${DB_USER:-im_push}"
DB_PASS="${DB_PASS:-ImPush@2024}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
HTTP_PORT="${HTTP_PORT:-9501}"
WEBSOCKET_PORT="${WEBSOCKET_PORT:-9502}"
INSTALL_ANDROID="${INSTALL_ANDROID:-1}"
INSTALL_SSL="${INSTALL_SSL:-1}"
INSTALL_SUDOERS="${INSTALL_SUDOERS:-1}"

# GitHub 仓库配置
REPO_URL="https://github.com/jiujiu123520/im-push-system.git"
GH_PROXY_URL="https://gh.jasonzeng.dev/https://github.com/jiujiu123520/im-push-system.git"
RAW_BASE="https://raw.githubusercontent.com/jiujiu123520/im-push-system/main"
GH_PROXY_RAW_BASE="https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main"

# GitHub 代理开关(默认自动检测)
GH_PROXY="${GH_PROXY:-0}"

# 解析命令行参数
while [[ $# -gt 0 ]]; do
    case "$1" in
        --project-dir=*)  PROJECT_DIR="${1#*=}" ;;
        --db-name=*)      DB_NAME="${1#*=}" ;;
        --db-user=*)      DB_USER="${1#*=}" ;;
        --db-pass=*)      DB_PASS="${1#*=}" ;;
        --db-host=*)      DB_HOST="${1#*=}" ;;
        --db-port=*)      DB_PORT="${1#*=}" ;;
        --http-port=*)    HTTP_PORT="${1#*=}" ;;
        --ws-port=*)      WEBSOCKET_PORT="${1#*=}" ;;
        --gh-proxy)       GH_PROXY=1 ;;
        --no-gh-proxy)    GH_PROXY=0 ;;
        --skip-app-build) INSTALL_ANDROID=0 ;;
        --install-dir=*)  PROJECT_DIR="${1#*=}" ;;
        --help|-h)
            echo "用法: bash deploy/deploy.sh [选项]"
            echo ""
            echo "选项:"
            echo "  --project-dir=DIR   项目目录(默认 /www/push-system)"
            echo "  --db-name=NAME      数据库名(默认 im_push)"
            echo "  --db-user=USER      数据库用户(默认 im_push)"
            echo "  --db-pass=PASS      数据库密码"
            echo "  --http-port=PORT    HTTP端口(默认 9501)"
            echo "  --ws-port=PORT      WebSocket端口(默认 9502)"
            echo "  --gh-proxy          强制使用 gh.jasonzeng.dev 代理"
            echo "  --no-gh-proxy       不使用代理(直连 GitHub)"
            echo "  --skip-app-build    跳过 Android 打包环境安装"
            echo ""
            echo "环境变量:"
            echo "  INSTALL_ANDROID=0   跳过 Android 环境"
            echo "  INSTALL_SSL=0       跳过 SSL 环境"
            echo "  INSTALL_SUDOERS=0   跳过 sudoers 配置"
            echo ""
            echo "示例:"
            echo "  # 国内服务器一键部署(无需手动 sudo)"
            echo "  curl -sSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh | bash"
            echo ""
            echo "  # 本地执行"
            echo "  bash deploy/deploy.sh --gh-proxy"
            exit 0
            ;;
        *) warn "未知参数: $1" ;;
    esac
    shift
done

# ============================================================
# 显示横幅
# ============================================================
echo ""
echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
echo -e "${COLOR_CYAN}  即时消息推送系统 - 一键部署${COLOR_RESET}"
echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
echo ""
info "项目目录: ${PROJECT_DIR}"
info "数据库:   ${DB_NAME}(用户: ${DB_USER})"
info "HTTP:     ${HTTP_PORT}  WebSocket: ${WEBSOCKET_PORT}"
info "组件:     Android=${INSTALL_ANDROID}  SSL=${INSTALL_SSL}  sudoers=${INSTALL_SUDOERS}"
echo ""

# ------------------------------------------------------------
# 检测网络连通性,自动选择 GitHub 代理
# ------------------------------------------------------------
detect_network() {
    step "0/3" "网络检测"

    if [[ "$GH_PROXY" == "1" ]]; then
        info "已强制启用 gh.jasonzeng.dev 代理"
        return 0
    fi

    # 测试 GitHub 连通性(5秒超时)
    info "测试 GitHub 连通性(超时 5 秒)..."
    if curl -s --connect-timeout 5 --max-time 10 https://github.com >/dev/null 2>&1; then
        info "  ✓ 可直连 GitHub,无需代理"
        GH_PROXY=0
    else
        warn "  ✗ 无法直连 GitHub,自动启用 gh.jasonzeng.dev 代理"
        GH_PROXY=1
    fi

    # 测试代理可用性(如果启用)
    if [[ "$GH_PROXY" == "1" ]]; then
        info "测试 gh.jasonzeng.dev 代理可用性(超时 5 秒)..."
        if curl -s --connect-timeout 5 --max-time 10 https://gh.jasonzeng.dev/https://github.com >/dev/null 2>&1; then
            info "  ✓ 代理可用"
        else
            warn "  ✗ 代理不可用,但仍将尝试使用(可能需要等待)"
        fi
    fi
}
detect_network

# ------------------------------------------------------------
# 安装 git(支持所有主流 Linux 发行版)
# ------------------------------------------------------------
install_git() {
    if command -v git >/dev/null 2>&1; then
        return 0
    fi

    step "1a" "安装 git"

    # 先检测包管理器
    PKG_MANAGER=""
    if command -v apt-get >/dev/null 2>&1; then
        PKG_MANAGER="apt-get"
    elif command -v dnf >/dev/null 2>&1; then
        PKG_MANAGER="dnf"
    elif command -v yum >/dev/null 2>&1; then
        PKG_MANAGER="yum"
    elif command -v apk >/dev/null 2>&1; then
        PKG_MANAGER="apk"
    elif command -v zypper >/dev/null 2>&1; then
        PKG_MANAGER="zypper"
    elif command -v pacman >/dev/null 2>&1; then
        PKG_MANAGER="pacman"
    else
        error "无法安装 git:未识别的包管理器"
        error "请手动安装 git 后重试"
        exit 1
    fi

    info "使用 $PKG_MANAGER 安装 git..."
    case "$PKG_MANAGER" in
        apt-get)
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -qq 2>/dev/null || true
            timeout 120 apt-get install -y -qq git 2>/dev/null || {
                # 主源失败时尝试阿里云镜像
                warn "主源安装失败,尝试阿里云镜像..."
                sed -i 's|archive.ubuntu.com|mirrors.aliyun.com|g; s|security.ubuntu.com|mirrors.aliyun.com|g' /etc/apt/sources.list 2>/dev/null || true
                apt-get update -qq 2>/dev/null || true
                apt-get install -y -qq git
            }
            ;;
        dnf)    timeout 120 dnf install -y -q git ;;
        yum)    timeout 120 yum install -y -q git ;;
        apk)    timeout 120 apk add --no-cache git ;;
        zypper) timeout 120 zypper install -y git ;;
        pacman) timeout 120 pacman -S --noconfirm git ;;
    esac

    if ! command -v git >/dev/null 2>&1; then
        error "git 安装失败"
        exit 1
    fi
    info "  ✓ git 已安装: $(git --version)"
}

# ============================================================
# 步骤 1: 克隆代码(如果本地无代码)
# ============================================================
clone_code() {
    if [ -f "${PROJECT_DIR}/deploy/install.sh" ]; then
        info "本地已有代码,跳过克隆"
        return 0
    fi

    step "1/3" "从 GitHub 克隆代码"

    install_git

    mkdir -p "$(dirname "${PROJECT_DIR}")"

    # 选择克隆 URL
    CLONE_URL="${REPO_URL}"
    if [[ "$GH_PROXY" == "1" ]]; then
        CLONE_URL="${GH_PROXY_URL}"
        info "使用 gh.jasonzeng.dev 代理加速"
    fi

    info "克隆代码: $CLONE_URL"
    info "(超时 5 分钟,如失败请检查网络或重试)"

    # 设置 git 超时参数,防止网络问题导致无限卡住
    git config --global http.lowSpeedLimit 1000 2>/dev/null || true
    git config --global http.lowSpeedTime 60 2>/dev/null || true
    git config --global http.postBuffer 524288000 2>/dev/null || true  # 500MB

    # 使用 timeout 命令兜底,最多等待 5 分钟
    if timeout 300 git clone --depth 1 "${CLONE_URL}" "${PROJECT_DIR}"; then
        info "  ✓ 代码克隆完成"
    else
        error "代码克隆失败或超时(可能是网络问题)"
        echo ""
        info "诊断信息:"
        info "  1. 测试 GitHub 连通性: curl -I https://github.com"
        info "  2. 测试代理连通性: curl -I https://gh.jasonzeng.dev"
        info "  3. 尝试手动克隆:"
        echo "     git clone --depth 1 ${CLONE_URL} ${PROJECT_DIR}"
        echo ""
        info "如果网络持续不稳定,建议:"
        info "  - 在本地电脑下载代码后上传到服务器"
        info "  - 或使用 SCP/SFTP 上传代码到 ${PROJECT_DIR}"
        exit 1
    fi
}
clone_code

# ============================================================
# 步骤 2: 检测并更新代码(如本地已有代码)
# ============================================================
check_update() {
    if [ ! -d "${PROJECT_DIR}/.git" ]; then
        return 0
    fi

    step "2/3" "检测代码版本"

    cd "${PROJECT_DIR}"

    # 获取当前本地 HEAD commit
    LOCAL_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "")
    LOCAL_SHORT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
    info "  本地版本: ${LOCAL_SHORT}"

    # 尝试获取云端最新 commit
    REMOTE_COMMIT=""
    REMOTE_SHORT=""
    WORKING_URL=""

    for URL in \
        "https://gh.jasonzeng.dev/https://github.com/jiujiu123520/im-push-system.git" \
        "https://github.com/jiujiu123520/im-push-system.git"; do
        info "  尝试: ${URL}"
        REMOTE_COMMIT=$(timeout 30 git ls-remote "${URL}" refs/heads/main 2>/dev/null | awk '{print $1}')
        if [[ -n "$REMOTE_COMMIT" ]]; then
            REMOTE_SHORT=$(echo "$REMOTE_COMMIT" | cut -c1-7)
            info "  ✓ 云端版本: ${REMOTE_SHORT}"
            WORKING_URL="${URL}"
            break
        fi
    done

    if [[ -z "$REMOTE_COMMIT" ]]; then
        warn "  无法获取云端版本(网络问题),继续使用本地代码"
        return 0
    fi

    if [[ "$LOCAL_COMMIT" == "$REMOTE_COMMIT" ]]; then
        info "  ✓ 本地已是最新版本"
        return 0
    fi

    warn "  本地代码不是最新版本"
    info "  本地: ${LOCAL_SHORT}"
    info "  云端: ${REMOTE_SHORT}"

    # 获取新增 commit 列表
    COMMITS_AHEAD=$(git log --oneline "${LOCAL_COMMIT}..${REMOTE_COMMIT}" 2>/dev/null | head -5)
    if [[ -n "$COMMITS_AHEAD" ]]; then
        info "  云端新增更新:"
        echo "$COMMITS_AHEAD" | while read -r line; do
            echo "    ${line}"
        done
        TOTAL=$(git log --oneline "${LOCAL_COMMIT}..${REMOTE_COMMIT}" 2>/dev/null | wc -l)
        [[ "$TOTAL" -gt 5 ]] && echo "    ... 等共 ${TOTAL} 个更新"
    fi

    echo ""
    read -p "是否拉取最新代码? [Y/n] " -r reply < /dev/tty
    case "$reply" in
        [Nn]*)
            warn "跳过代码更新,继续使用本地版本"
            return 0
            ;;
    esac

    info "拉取最新代码..."
    if ! git remote | grep -q '^origin$'; then
        git remote add origin "${REPO_URL}"
    fi

    FETCH_URL="${WORKING_URL:-${GH_PROXY_URL}}"
    git remote set-url origin "${FETCH_URL}"
    info "  使用: ${FETCH_URL}"

    # 设置超时
    git config --local http.lowSpeedLimit 1000 2>/dev/null || true
    git config --local http.lowSpeedTime 60 2>/dev/null || true

    # 拉取代码
    if ! timeout 300 git fetch origin main; then
        error "拉取代码超时或失败"
        error "请手动执行: cd ${PROJECT_DIR} && git fetch ${FETCH_URL} main"
        return 1
    fi

    git merge origin/main --no-edit || {
        warn "自动合并失败,可能有本地修改冲突"
        read -p "是否强制使用云端版本? [y/N] " -r force_reply < /dev/tty
        case "$force_reply" in
            [Yy]*)
                warn "强制重置为云端版本(本地修改将丢失,.env 除外)"
                [[ -f backend/.env ]] && cp backend/.env /tmp/.env.backup
                git merge --abort 2>/dev/null || true
                git reset --hard origin/main
                [[ -f /tmp/.env.backup ]] && cp /tmp/.env.backup backend/.env && rm /tmp/.env.backup
                info "已恢复 .env 配置"
                ;;
            *)
                error "代码合并失败,请手动解决冲突后重新运行"
                return 1
                ;;
        esac
    }
    info "  ✓ 代码更新完成"
}
check_update

# ============================================================
# 步骤 3: 执行交互式安装
# ============================================================
run_install() {
    step "3/3" "执行安装"

    INSTALL_SCRIPT="${PROJECT_DIR}/deploy/install.sh"
    if [ ! -f "${INSTALL_SCRIPT}" ]; then
        error "安装脚本不存在: ${INSTALL_SCRIPT}"
        exit 1
    fi

    # 传递环境变量给 install.sh
    export PROJECT_DIR DB_NAME DB_USER DB_PASS DB_HOST DB_PORT HTTP_PORT WEBSOCKET_PORT
    export INSTALL_ANDROID INSTALL_SSL INSTALL_SUDOERS

    info "调用 install.sh ..."
    echo ""
    bash "${INSTALL_SCRIPT}"
}
run_install

# ============================================================
# 部署完成提示由 install.sh 输出
# ============================================================
