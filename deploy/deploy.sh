#!/bin/bash
# ============================================================
# 即时消息推送系统 - 一键部署脚本（统一入口）
#
# 功能:
#   1. 从 GitHub 克隆代码（如本地无代码）
#   2. 调用 deploy/install.sh 交互式安装
#   3. 安装全部环境（核心服务 + Android 打包 + SSL + sudoers）
#
# 用法:
#   方式1: 从 GitHub 直接部署（国内服务器推荐，使用 gh.jasonzeng.dev 代理）
#     curl -sSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh | bash
#
#   方式2: 直接从 GitHub 部署（需能直连 GitHub）
#     curl -sSL https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh | bash
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

    # 安装 git
    if ! command -v git >/dev/null 2>&1; then
        info "安装 git..."
        if command -v apt-get >/dev/null 2>&1; then
            apt-get update -qq && apt-get install -y -qq git
        elif command -v yum >/dev/null 2>&1; then
            yum install -y -q git
        fi
    fi

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
    info "本地已有代码，跳过克隆"
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
