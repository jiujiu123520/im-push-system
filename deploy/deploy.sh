#!/bin/bash
# ============================================================
# 即时消息推送系统 - 一键部署脚本（统一入口）
#
# 功能:
#   1. 从 GitHub 克隆代码（如本地无代码）
#   2. 调用 deploy/install.sh 交互式安装
#   3. 安装全部环境（核心服务 + Android 打包 + SSL + sudoers）
#
# 支持的 Linux 发行版:
#   - Ubuntu / Debian（apt-get + PPA/Sury）
#   - CentOS / RHEL / Rocky / AlmaLinux / Fedora（dnf/yum + Remi）
#   - Alpine Linux（apk）
#   - openSUSE / SLES（zypper）
#   - Arch / Manjaro（pacman）
#   安装时自动检测系统类型并适配对应的环境
#
# 用法:
#   方式1: 从 GitHub 直接部署（国内服务器推荐，使用 gh.jasonzeng.dev 代理）
#     curl -sSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh | sudo bash
#
#   方式2: 直接从 GitHub 部署（需能直连 GitHub）
#     curl -sSL https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh | sudo bash
#
#   方式3: 本地执行（已有代码）
#     sudo bash deploy/deploy.sh
#
#   方式4: 自定义参数
#     sudo bash deploy/deploy.sh --project-dir=/www/push-system --db-pass=YourPass
#
#   方式5: 跳过可选组件（仅安装核心服务）
#     sudo INSTALL_ANDROID=0 INSTALL_SSL=0 bash deploy/deploy.sh
#
#   注意: 复制粘贴命令时，URL 前后不要包含任何符号（如反引号 ` 或引号），
#         直接复制整条 curl 命令即可。
#
# 环境变量:
#   PROJECT_DIR     - 项目目录（默认 /www/push-system）
#   DB_NAME         - 数据库名（默认 im_push）
#   DB_USER         - 数据库用户（默认 im_push）
#   DB_PASS         - 数据库密码（默认 ImPush@2024）
#   HTTP_PORT       - HTTP端口（默认 9501）
#   WEBSOCKET_PORT  - WebSocket端口（默认 9502）
#   INSTALL_ANDROID - 是否安装Android打包环境（默认 1=安装）
#   INSTALL_SSL     - 是否安装SSL环境（默认 1=安装）
#   INSTALL_SUDOERS - 是否配置sudoers（默认 1=配置）
#   GH_PROXY        - 是否使用GitHub代理（1=启用 gh.jasonzeng.dev）
# ============================================================

set -e

# 颜色输出
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_CYAN='\033[0;36m'
COLOR_RESET='\033[0m'

info()  { echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }

# ------------------------------------------------------------
# 安装 git（支持所有主流 Linux 发行版）
# ------------------------------------------------------------
install_git() {
    if command -v git >/dev/null 2>&1; then
        return 0
    fi
    info "安装 git..."
    if command -v apt-get >/dev/null 2>&1; then
        apt-get update -qq && apt-get install -y -qq git
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y -q git
    elif command -v yum >/dev/null 2>&1; then
        yum install -y -q git
    elif command -v apk >/dev/null 2>&1; then
        apk add --no-cache git
    elif command -v zypper >/dev/null 2>&1; then
        zypper install -y git
    elif command -v pacman >/dev/null 2>&1; then
        pacman -S --noconfirm git
    else
        error "无法安装 git：未识别的包管理器，请手动安装 git 后重试。"
        exit 1
    fi
}

# 默认配置
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
REPO_URL="https://github.com/jiujiu123520/im-push-system.git"
GH_PROXY_URL="https://gh.jasonzeng.dev/https://github.com/jiujiu123520/im-push-system.git"

# ============================================================
# 步骤 0: 前置检查
# ============================================================
if [[ $EUID -ne 0 ]]; then
    error "此脚本必须以 root 权限运行，请使用 sudo 或切换到 root 用户。"
    exit 1
fi

# 解析命令行参数
while [[ $# -gt 0 ]]; do
    case "$1" in
        --project-dir=*)  PROJECT_DIR="${1#*=}" ;;
        --db-name=*)      DB_NAME="${1#*=}" ;;
        --db-user=*)      DB_USER="${1#*=}" ;;
        --db-pass=*)      DB_PASS="${1#*=}" ;;
        --http-port=*)    HTTP_PORT="${1#*=}" ;;
        --ws-port=*)      WEBSOCKET_PORT="${1#*=}" ;;
        --gh-proxy)       GH_PROXY=1 ;;
        --skip-app-build) INSTALL_ANDROID=0 ;;
        --help|-h)
            echo "用法: sudo bash deploy/deploy.sh [选项]"
            echo ""
            echo "选项:"
            echo "  --project-dir=DIR   项目目录（默认 /www/push-system）"
            echo "  --db-name=NAME      数据库名（默认 im_push）"
            echo "  --db-user=USER      数据库用户（默认 im_push）"
            echo "  --db-pass=PASS      数据库密码"
            echo "  --http-port=PORT    HTTP端口（默认 9501）"
            echo "  --ws-port=PORT      WebSocket端口（默认 9502）"
            echo "  --gh-proxy          使用 gh.jasonzeng.dev 代理"
            echo "  --skip-app-build    跳过 Android 打包环境安装"
            echo ""
            echo "环境变量:"
            echo "  INSTALL_ANDROID=0   跳过 Android 环境"
            echo "  INSTALL_SSL=0       跳过 SSL 环境"
            echo "  INSTALL_SUDOERS=0   跳过 sudoers 配置"
            exit 0
            ;;
    esac
    shift
done

echo ""
echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
echo -e "${COLOR_CYAN}  即时消息推送系统 - 一键部署${COLOR_RESET}"
echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
echo ""
info "项目目录: ${PROJECT_DIR}"
info "数据库:   ${DB_NAME:-im_push}（用户: ${DB_USER:-im_push}）"
info "HTTP:     ${HTTP_PORT:-9501}  WebSocket: ${WEBSOCKET_PORT:-9502}"
echo ""

# ============================================================
# 步骤 1: 克隆代码（如果本地无代码）
# ============================================================
if [ ! -f "${PROJECT_DIR}/deploy/install.sh" ]; then
    info "本地无代码，开始从 GitHub 克隆..."

    # 安装 git（支持所有发行版）
    install_git

    mkdir -p "$(dirname "${PROJECT_DIR}")"

    # 选择克隆 URL（国内服务器使用代理）
    CLONE_URL="${REPO_URL}"
    if [[ "${GH_PROXY}" == "1" ]] || curl -s --connect-timeout 5 https://github.com >/dev/null 2>&1; then
        if [[ "${GH_PROXY}" == "1" ]]; then
            CLONE_URL="${GH_PROXY_URL}"
            info "使用 gh.jasonzeng.dev 代理加速"
        fi
    else
        warn "无法直连 GitHub，自动切换到 gh.jasonzeng.dev 代理"
        CLONE_URL="${GH_PROXY_URL}"
    fi

    info "克隆代码: ${CLONE_URL}"
    git clone --depth 1 "${CLONE_URL}" "${PROJECT_DIR}"
    info "代码克隆完成"
else
    info "本地已有代码，检测云端最新版本..."

    # 确保 git 已安装（支持所有发行版）
    install_git

    # 检测是否 git 仓库
    if [ -d "${PROJECT_DIR}/.git" ]; then
        cd "${PROJECT_DIR}"

        # 获取当前本地 HEAD commit
        LOCAL_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "")
        LOCAL_SHORT=$(git rev-parse --short HEAD 2>/dev/null || echo "unknown")
        info "  本地版本: ${LOCAL_SHORT}"

        # 尝试获取云端最新 commit（不修改本地代码）
        REMOTE_COMMIT=""
        REMOTE_SHORT=""
        for URL in \
            "https://gh.jasonzeng.dev/https://github.com/jiujiu123520/im-push-system.git" \
            "https://github.com/jiujiu123520/im-push-system.git"; do
            REMOTE_COMMIT=$(git ls-remote "${URL}" refs/heads/main 2>/dev/null | awk '{print $1}')
            if [[ -n "$REMOTE_COMMIT" ]]; then
                REMOTE_SHORT=$(echo "$REMOTE_COMMIT" | cut -c1-7)
                info "  云端版本: ${REMOTE_SHORT}"
                break
            fi
        done

        if [[ -z "$REMOTE_COMMIT" ]]; then
            warn "  无法获取云端版本（网络问题），继续使用本地代码"
        elif [[ "$LOCAL_COMMIT" == "$REMOTE_COMMIT" ]]; then
            info "  本地已是最新版本"
        else
            warn "  本地代码不是最新版本"
            info "  本地: ${LOCAL_SHORT}"
            info "  云端: ${REMOTE_SHORT}"

            # 获取新增的 commit 列表（仅显示最近5条）
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
            read -p "是否拉取最新代码？[Y/n] " -r reply < /dev/tty
            case "$reply" in
                [Nn]* )
                    warn "跳过代码更新，继续使用本地版本"
                    ;;
                * )
                    info "拉取最新代码..."

                    # 确保有 remote origin（兼容旧版 git，不使用 git remote get-url）
                    if ! git remote | grep -q '^origin$'; then
                        git remote add origin "${REPO_URL}"
                    fi

                    # 选择 URL（国内服务器使用代理）
                    FETCH_URL="${REPO_URL}"
                    if [[ "${GH_PROXY}" == "1" ]]; then
                        FETCH_URL="${GH_PROXY_URL}"
                    fi
                    git remote set-url origin "${FETCH_URL}"

                    # 拉取最新代码（保留本地 .env 等修改）
                    git fetch origin main
                    git merge origin/main --no-edit || {
                        warn "自动合并失败，可能有本地修改冲突"
                        read -p "是否强制使用云端版本？[y/N] " -r force_reply < /dev/tty
                        case "$force_reply" in
                            [Yy]* )
                                warn "强制重置为云端版本（本地修改将丢失，.env 除外）"
                                # 备份 .env
                                [[ -f backend/.env ]] && cp backend/.env /tmp/.env.backup
                                git merge --abort 2>/dev/null || true
                                git reset --hard origin/main
                                # 恢复 .env
                                [[ -f /tmp/.env.backup ]] && cp /tmp/.env.backup backend/.env && rm /tmp/.env.backup
                                info "已恢复 .env 配置"
                                ;;
                            * )
                                error "代码合并失败，请手动解决冲突后重新运行"
                                exit 1
                                ;;
                        esac
                    }
                    info "代码更新完成"
                    ;;
            esac
        fi
    else
        warn "  本地目录不是 git 仓库，无法检测版本，继续使用本地代码"
    fi
fi

# ============================================================
# 步骤 2: 执行交互式安装
# ============================================================
info "开始交互式安装..."
INSTALL_SCRIPT="${PROJECT_DIR}/deploy/install.sh"

if [ ! -f "${INSTALL_SCRIPT}" ]; then
    error "安装脚本不存在: ${INSTALL_SCRIPT}"
    exit 1
fi

# 传递环境变量给 install.sh
export PROJECT_DIR DB_NAME DB_USER DB_PASS HTTP_PORT WEBSOCKET_PORT
export INSTALL_ANDROID INSTALL_SSL INSTALL_SUDOERS

bash "${INSTALL_SCRIPT}"

# ============================================================
# 部署完成提示由 install.sh 输出
# ============================================================
