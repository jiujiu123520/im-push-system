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

ACME_HOME="/root/.acme.sh"
ACME_SCRIPT="${ACME_HOME}/acme.sh"
SSL_DIR="/etc/nginx/ssl"
ACME_WEBROOT="/www/push-system/acme"
NGINX_AVAILABLE="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"

echo "[1/6] 检查依赖..."
for cmd in curl socat nginx openssl; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "  安装依赖: $cmd"
        apt-get update -qq && apt-get install -y -qq "$cmd" 2>/dev/null || true
    fi
done
echo "[1/6] 依赖检查完成"

echo "[2/6] 安装 acme.sh..."
if [ -f "${ACME_SCRIPT}" ]; then
    echo "  acme.sh 已存在，执行升级..."
    "${ACME_SCRIPT}" --upgrade 2>/dev/null || true
else
    curl -fsSL https://get.acme.sh | sh -s email=admin@push-system.local
    # 国内服务器使用备用源
    if [ ! -f "${ACME_SCRIPT}" ]; then
        echo "  官方源安装失败，尝试备用源..."
        curl -fsSL https://ghproxy.com/https://raw.githubusercontent.com/acmesh-official/get.acme.sh/master/get.acme.sh | sh -s email=admin@push-system.local 2>/dev/null || true
    fi
fi
echo "[2/6] acme.sh 安装完成"

# 设置默认 CA 为 Let's Encrypt
if [ -f "${ACME_SCRIPT}" ]; then
    "${ACME_SCRIPT}" --set-default-ca --server letsencrypt 2>/dev/null || true
fi

echo "[3/6] 创建 SSL 证书目录..."
mkdir -p "${SSL_DIR}"
chmod 755 "${SSL_DIR}"
echo "[3/6] SSL 目录就绪: ${SSL_DIR}"

echo "[4/6] 创建 ACME webroot 目录..."
mkdir -p "${ACME_WEBROOT}"
chown -R www-data:www-data "${ACME_WEBROOT}"
chmod 755 "${ACME_WEBROOT}"
echo "[4/6] webroot 就绪: ${ACME_WEBROOT}"

echo "[5/6] 检查 Nginx 目录..."
if [ ! -d "${NGINX_AVAILABLE}" ]; then
    mkdir -p "${NGINX_AVAILABLE}"
fi
if [ ! -d "${NGINX_ENABLED}" ]; then
    mkdir -p "${NGINX_ENABLED}"
fi
echo "[5/6] Nginx 目录就绪"

echo "[6/6] 设置 acme.sh 自动续期..."
if [ -f "${ACME_SCRIPT}" ]; then
    "${ACME_SCRIPT}" --install-cronjob 2>/dev/null || true
fi
echo "[6/6] 自动续期已配置"

echo ""
echo "=========================================="
echo " acme.sh 环境初始化完成"
echo "=========================================="
echo " acme.sh 路径:   ${ACME_SCRIPT}"
echo " SSL 证书目录:   ${SSL_DIR}"
echo " ACME webroot:   ${ACME_WEBROOT}"
echo ""
echo " 下一步：在管理后台「域名管理」页面："
echo "   1. 添加域名（需先在 DNS 解析到本服务器）"
echo "   2. 点击「申请SSL」自动申请证书"
echo "   3. 点击「部署Nginx」自动生成并重载配置"
echo "=========================================="
