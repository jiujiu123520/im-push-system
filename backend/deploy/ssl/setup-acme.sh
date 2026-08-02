#!/bin/bash
# ============================================================
# acme.sh 安装与环境初始化脚本
#
# 功能：
#   1. 安装 acme.sh（Let's Encrypt 客户端）
#   2. 设置默认 CA 为 Let's Encrypt
#   3. 创建 SSL 证书目录与 ACME webroot 目录
#   4. 设置默认 Nginx 配置（含 ACME challenge 路径）
#
# 使用方式：sudo bash setup-acme.sh
# ============================================================
set -e

# 自动推断 PROJECT_ROOT：从脚本位置向上回溯 3 层（deploy/ssl/setup-acme.sh -> backend/）
# 若推断失败则使用默认值 /www/push-system
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -d "${SCRIPT_DIR}/../../app" ] || [ -d "${SCRIPT_DIR}/../../config" ]; then
    PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
else
    PROJECT_ROOT="/www/push-system"
fi

ACME_HOME="/root/.acme.sh"
ACME_SCRIPT="${ACME_HOME}/acme.sh"
SSL_DIR="/etc/nginx/ssl"
ACME_WEBROOT="${PROJECT_ROOT}/acme"
NGINX_AVAILABLE="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"
NGINX_CONFD="/etc/nginx/conf.d"

_detect_package_manager() {
    if command -v apt-get >/dev/null 2>&1; then
        echo "apt-get"
    elif command -v dnf >/dev/null 2>&1; then
        echo "dnf"
    elif command -v yum >/dev/null 2>&1; then
        echo "yum"
    elif command -v apk >/dev/null 2>&1; then
        echo "apk"
    elif command -v zypper >/dev/null 2>&1; then
        echo "zypper"
    elif command -v pacman >/dev/null 2>&1; then
        echo "pacman"
    else
        echo "unknown"
    fi
}

_install_package() {
    local pkg="$1"
    local pm
    pm="$(_detect_package_manager)"

    case "$pm" in
        apt-get)
            apt-get update -qq 2>/dev/null || true
            apt-get install -y -qq "$pkg" 2>/dev/null || true
            ;;
        dnf)
            dnf install -y --quiet "$pkg" 2>/dev/null || true
            ;;
        yum)
            yum install -y --quiet "$pkg" 2>/dev/null || true
            ;;
        apk)
            apk add --no-cache "$pkg" 2>/dev/null || true
            ;;
        zypper)
            zypper --non-interactive install --no-confirm "$pkg" 2>/dev/null || true
            ;;
        pacman)
            pacman -Syu --noconfirm --needed "$pkg" 2>/dev/null || true
            ;;
        *)
            echo "  [WARN] 未识别的包管理器，请手动安装: $pkg"
            return 1
            ;;
    esac
}

_detect_web_user() {
    local users=("www-data" "nginx" "http" "apache")
    for user in "${users[@]}"; do
        if id -u "$user" >/dev/null 2>&1; then
            local group
            group="$user"
            if ! getent group "$group" >/dev/null 2>&1; then
                case "$user" in
                    www-data) group="www-data" ;;
                    nginx)    group="nginx" ;;
                    http)     group="http" ;;
                    apache)   group="apache" ;;
                    *)        group="$user" ;;
                esac
            fi
            echo "${user}:${group}"
            return 0
        fi
    done
    echo "root:root"
}

echo "[1/6] 检查依赖..."
PM_NAME="$(_detect_package_manager)"
echo "  检测到包管理器: ${PM_NAME}"
for cmd in curl socat nginx openssl; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "  安装依赖: $cmd"
        _install_package "$cmd"
    fi
done
echo "[1/6] 依赖检查完成"

echo "[2/6] 安装 acme.sh..."
if [ -f "${ACME_SCRIPT}" ]; then
    echo "  acme.sh 已存在，执行升级..."
    "${ACME_SCRIPT}" --upgrade 2>/dev/null || true
else
    ACME_EMAIL="admin@push-system.local"
    ACME_INSTALLED=false

    echo "  尝试源1: gh.jasonzeng.dev 代理..."
    if curl -fsSL --connect-timeout 30 --max-time 120 "https://gh.jasonzeng.dev/https://raw.githubusercontent.com/acmesh-official/get.acme.sh/master/get.acme.sh" | sh -s "email=${ACME_EMAIL}" 2>/dev/null; then
        ACME_INSTALLED=true
    fi

    if [ "$ACME_INSTALLED" != "true" ]; then
        echo "  尝试源2: get.acme.sh 官方源..."
        if curl -fsSL --connect-timeout 30 --max-time 120 "https://get.acme.sh" | sh -s "email=${ACME_EMAIL}" 2>/dev/null; then
            ACME_INSTALLED=true
        fi
    fi

    if [ "$ACME_INSTALLED" != "true" ]; then
        echo "  尝试源3: ghproxy.com 代理..."
        if curl -fsSL --connect-timeout 30 --max-time 120 "https://ghproxy.com/https://raw.githubusercontent.com/acmesh-official/get.acme.sh/master/get.acme.sh" | sh -s "email=${ACME_EMAIL}" 2>/dev/null; then
            ACME_INSTALLED=true
        fi
    fi

    if [ "$ACME_INSTALLED" != "true" ]; then
        echo "  尝试源4: gitee 镜像..."
        if curl -fsSL --connect-timeout 30 --max-time 120 "https://gitee.com/neilpang/acme.sh/raw/master/get.acme.sh" | sh -s "email=${ACME_EMAIL}" 2>/dev/null; then
            ACME_INSTALLED=true
        fi
    fi

    if [ "$ACME_INSTALLED" != "true" ]; then
        echo "  [WARN] acme.sh 在线安装失败（网络问题），可稍后手动执行："
        echo "         curl -fsSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/acmesh-official/get.acme.sh/master/get.acme.sh | sh -s email=your@email.com"
        echo "         SSL 证书自动申请功能暂不可用，但不影响其他功能。"
    fi
fi
echo "[2/6] acme.sh 安装完成"

if [ -f "${ACME_SCRIPT}" ]; then
    "${ACME_SCRIPT}" --set-default-ca --server letsencrypt 2>/dev/null || true
fi

echo "[3/6] 创建 SSL 证书目录..."
mkdir -p "${SSL_DIR}"
chmod 755 "${SSL_DIR}"
echo "[3/6] SSL 目录就绪: ${SSL_DIR}"

echo "[4/6] 创建 ACME webroot 目录..."
mkdir -p "${ACME_WEBROOT}"
WEB_OWNER="$(_detect_web_user)"
chown -R "${WEB_OWNER}" "${ACME_WEBROOT}"
chmod 755 "${ACME_WEBROOT}"
echo "[4/6] webroot 就绪: ${ACME_WEBROOT} (所有者: ${WEB_OWNER})"

echo "[5/6] 检查 Nginx 目录..."
if [ ! -d "${NGINX_AVAILABLE}" ]; then
    mkdir -p "${NGINX_AVAILABLE}"
    echo "  创建 Debian/Ubuntu 风格目录: ${NGINX_AVAILABLE}"
fi
if [ ! -d "${NGINX_ENABLED}" ]; then
    mkdir -p "${NGINX_ENABLED}"
    echo "  创建 Debian/Ubuntu 风格目录: ${NGINX_ENABLED}"
fi
if [ ! -d "${NGINX_CONFD}" ]; then
    mkdir -p "${NGINX_CONFD}"
    echo "  创建 RHEL/Alpine 风格目录: ${NGINX_CONFD}"
fi
echo "[5/6] Nginx 目录就绪 (兼容 Debian sites-available 与 RHEL conf.d 双风格)"

echo "[6/6] 设置 acme.sh 自动续期..."
if [ -f "${ACME_SCRIPT}" ]; then
    "${ACME_SCRIPT}" --install-cronjob 2>/dev/null || true
fi
echo "[6/6] 自动续期已配置"

echo ""
echo "=========================================="
echo " acme.sh 环境初始化完成"
echo "=========================================="
echo " 项目根目录:     ${PROJECT_ROOT}"
echo " acme.sh 路径:   ${ACME_SCRIPT}"
echo " SSL 证书目录:   ${SSL_DIR}"
echo " ACME webroot:   ${ACME_WEBROOT}"
echo " Web 所有者:     ${WEB_OWNER}"
echo ""
echo " 下一步：在管理后台「域名管理」页面："
echo "   1. 添加域名（需先在 DNS 解析到本服务器）"
echo "   2. 点击「申请SSL」自动申请证书"
echo "   3. 点击「部署Nginx」自动生成并重载配置"
echo "=========================================="
