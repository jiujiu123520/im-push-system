#!/bin/bash
# ============================================================
# 即时消息推送系统 - 一键安装脚本（国内服务器专用）
#
# 用法:
#   sudo bash deploy/install.sh
#
# 功能:
#   1. 安装系统依赖（PHP 8.2 + Swoole + MySQL + Redis + Nginx + Composer + Node）
#   2. 创建数据库并执行迁移
#   3. 安装后端依赖（composer install）
#   4. 构建管理后台（npm install && npm run build）
#   5. 复制 systemd 服务文件
#   6. 复制 Nginx 配置
#   7. 启动服务
#
# 注意:
#   - 脚本必须在 root 或具有 sudo 权限的用户下执行
#   - 默认项目路径为 /www/push-system，可通过 PROJECT_DIR 环境变量覆盖
#   - 数据库名/用户/密码可通过环境变量覆盖（DB_NAME/DB_USER/DB_PASS）
#   - 国内服务器自动使用阿里云镜像加速
# ============================================================

set -e

# ------------------------------------------------------------
# 配置项（可通过环境变量覆盖）
# ------------------------------------------------------------
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
DB_NAME="${DB_NAME:-im_push}"
DB_USER="${DB_USER:-im_push}"
DB_PASS="${DB_PASS:-ImPush@2024}"
DB_HOST="${DB_HOST:-127.0.0.1}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
HTTP_PORT="${HTTP_PORT:-9501}"
WEBSOCKET_PORT="${WEBSOCKET_PORT:-9502}"

# 颜色输出
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_RESET='\033[0m'

info()  { echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }
step()  { echo -e "\n${COLOR_GREEN}===== [$1] $2 =====${COLOR_RESET}"; }

# ------------------------------------------------------------
# 前置检查
# ------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    error "此脚本必须以 root 权限运行，请使用 sudo 或切换到 root 用户。"
    exit 1
fi

# 检测包管理器
if command -v apt-get >/dev/null 2>&1; then
    PKG_MANAGER="apt-get"
elif command -v yum >/dev/null 2>&1; then
    PKG_MANAGER="yum"
else
    error "不支持的系统：未找到 apt-get 或 yum。"
    exit 1
fi

info "检测到包管理器: ${PKG_MANAGER}"
info "项目目录: ${PROJECT_DIR}"

# 获取脚本所在目录（用于定位 deploy/ 下的配置文件）
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
info "脚本目录: ${SCRIPT_DIR}"

# ============================================================
# 步骤 1: 安装系统依赖
# ============================================================
step "1/7" "安装系统依赖"

if [[ "$PKG_MANAGER" == "apt-get" ]]; then
    # 添加 PHP 8.2 PPA 源（Ubuntu）
    if ! command -v add-apt-repository >/dev/null 2>&1; then
        apt-get update -y
        apt-get install -y software-properties-common
    fi
    add-apt-repository -y ppa:ondrej/php
    apt-get update -y

    # 安装 PHP 8.2 及所需扩展
    apt-get install -y \
        php8.2 php8.2-cli php8.2-common \
        php8.2-mysql php8.2-redis php8.2-curl \
        php8.2-mbstring php8.2-xml php8.2-zip \
        php8.2-bcmath php8.2-gd php8.2-intl \
        php-pear php8.2-dev libssl-dev

    # 安装 Swoole 扩展
    if ! php -m | grep -q '^swoole$'; then
        info "正在编译安装 Swoole 扩展..."
        pecl install swoole
        echo "extension=swoole.so" > /etc/php/8.2/mods-available/swoole.ini
        php phpenmod -v 8.2 swoole
    fi

    # 安装 MySQL / Redis / Nginx / Node.js
    apt-get install -y mysql-server redis-server nginx
    # 安装 Node.js 18.x LTS
    if ! command -v node >/dev/null 2>&1; then
        curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
        apt-get install -y nodejs
    fi

    # 安装 Composer
    if ! command -v composer >/dev/null 2>&1; then
        info "安装 Composer..."
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    fi

elif [[ "$PKG_MANAGER" == "yum" ]]; then
    # 安装 EPEL 和 Remi 源（CentOS/RHEL）
    yum install -y epel-release
    yum install -y "https://rpms.remirepo.net/enterprise/remi-release-8.rpm" || true
    yum module reset -y php || true
    yum module enable -y php:remi-8.2

    yum install -y \
        php php-cli php-common \
        php-mysqlnd php-pecl-redis5 php-curl \
        php-mbstring php-xml php-zip \
        php-bcmath php-gd php-intl \
        php-pear php-devel openssl-devel

    # 安装 Swoole 扩展
    if ! php -m | grep -q '^swoole$'; then
        info "正在编译安装 Swoole 扩展..."
        yes '' | pecl install swoole
        echo "extension=swoole.so" > /etc/php.d/50-swoole.ini
    fi

    # 安装 MySQL / Redis / Nginx / Node.js
    yum install -y mysql-server redis nginx || yum install -y mariadb-server redis nginx
    if ! command -v node >/dev/null 2>&1; then
        curl -fsSL https://rpm.nodesource.com/setup_18.x | bash -
        yum install -y nodejs
    fi

    # 安装 Composer
    if ! command -v composer >/dev/null 2>&1; then
        info "安装 Composer..."
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    fi
fi

info "PHP 版本: $(php -v | head -n1)"
info "Node 版本: $(node -v)"
info "npm 版本: $(npm -v)"

# ============================================================
# 步骤 2: 创建项目目录与数据库
# ============================================================
step "2/7" "创建项目目录与数据库"

# 创建项目根目录
mkdir -p "${PROJECT_DIR}"

# 启动 MySQL（如未运行）
if ! systemctl is-active --quiet mysql 2>/dev/null \
    && ! systemctl is-active --quiet mysqld 2>/dev/null \
    && ! systemctl is-active --quiet mariadb 2>/dev/null; then
    info "启动 MySQL 服务..."
    systemctl start mysql 2>/dev/null || systemctl start mysqld 2>/dev/null || systemctl start mariadb
    systemctl enable mysql 2>/dev/null || systemctl enable mysqld 2>/dev/null || systemctl enable mariadb
fi

# 创建数据库与用户
info "创建数据库 ${DB_NAME} 和用户 ${DB_USER}..."
mysql -uroot <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
info "数据库与用户创建完成。"

# 启动 Redis
if ! systemctl is-active --quiet redis-server 2>/dev/null \
    && ! systemctl is-active --quiet redis 2>/dev/null; then
    info "启动 Redis 服务..."
    systemctl start redis-server 2>/dev/null || systemctl start redis
    systemctl enable redis-server 2>/dev/null || systemctl enable redis
fi

# 启动 Nginx
if ! systemctl is-active --quiet nginx; then
    info "启动 Nginx 服务..."
    systemctl start nginx
    systemctl enable nginx
fi

# ============================================================
# 步骤 3: 复制项目代码并执行迁移
# ============================================================
step "3/7" "复制项目代码并执行迁移"

# 如果脚本所在目录不是项目目录，则复制项目文件到 PROJECT_DIR
if [[ "${SCRIPT_DIR%/deploy}" != "${PROJECT_DIR}" ]]; then
    info "从 ${SCRIPT_DIR%/deploy} 复制项目到 ${PROJECT_DIR}..."
    rsync -a --exclude='*.git*' --exclude='node_modules' --exclude='vendor' \
        "${SCRIPT_DIR%/deploy}/" "${PROJECT_DIR}/"
fi

# 复制 .env 配置（如不存在则从 .env.example 创建）
if [[ ! -f "${PROJECT_DIR}/backend/.env" ]]; then
    info "从 .env.example 创建 .env..."
    cp "${PROJECT_DIR}/backend/.env.example" "${PROJECT_DIR}/backend/.env"

    # 生成随机的 JWT_SECRET 和 AES_KEY
    JWT_SECRET_NEW="$(openssl rand -hex 32)"
    AES_KEY_NEW="$(openssl rand -hex 32)"
    sed -i "s/^JWT_SECRET=.*/JWT_SECRET=${JWT_SECRET_NEW}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^AES_KEY=.*/AES_KEY=${AES_KEY_NEW}/" "${PROJECT_DIR}/backend/.env"
    # 写入数据库配置
    sed -i "s/^DB_NAME=.*/DB_NAME=${DB_NAME}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^DB_USER=.*/DB_USER=${DB_USER}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^DB_PASS=.*/DB_PASS=${DB_PASS}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^REDIS_HOST=.*/REDIS_HOST=${REDIS_HOST}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^REDIS_PORT=.*/REDIS_PORT=${REDIS_PORT}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^HTTP_PORT=.*/HTTP_PORT=${HTTP_PORT}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^WEBSOCKET_PORT=.*/WEBSOCKET_PORT=${WEBSOCKET_PORT}/" "${PROJECT_DIR}/backend/.env"
    info "已生成随机 JWT_SECRET 与 AES_KEY 并写入 .env"
fi

# 执行数据库迁移脚本（按文件名顺序）
MIGRATIONS_DIR="${PROJECT_DIR}/backend/database/migrations"
if [[ -d "${MIGRATIONS_DIR}" ]]; then
    info "执行数据库迁移..."
    for sql_file in $(ls -1 "${MIGRATIONS_DIR}"/*.sql 2>/dev/null | sort); do
        info "  执行: $(basename "${sql_file}")"
        mysql -uroot "${DB_NAME}" < "${sql_file}"
    done
    info "数据库迁移完成。"
else
    warn "未找到迁移脚本目录: ${MIGRATIONS_DIR}"
fi

# ============================================================
# 步骤 4: 安装后端依赖（composer install）
# ============================================================
step "4/7" "安装后端依赖"

cd "${PROJECT_DIR}/backend"
composer install --no-dev --optimize-autoloader
info "后端依赖安装完成。"

# ============================================================
# 步骤 5: 构建管理后台（npm install && npm run build）
# ============================================================
step "5/7" "构建管理后台"

cd "${PROJECT_DIR}/admin"
# 使用国内镜像加速（淘宝镜像）
npm config set registry https://registry.npmmirror.com
npm install
npm run build
info "管理后台构建完成。"

# ============================================================
# 步骤 6: 配置 systemd 服务与 Nginx
# ============================================================
step "6/7" "配置 systemd 服务与 Nginx"

# 复制 systemd 服务文件
SYSTEMD_SRC="${PROJECT_DIR}/deploy/systemd"
SYSTEMD_DST="/etc/systemd/system"
if [[ -d "${SYSTEMD_SRC}" ]]; then
    for svc_file in "${SYSTEMD_SRC}"/*.service; do
        [[ -f "${svc_file}" ]] || continue
        info "安装 systemd 服务: $(basename "${svc_file}")"
        cp "${svc_file}" "${SYSTEMD_DST}/$(basename "${svc_file}")"
    done
    systemctl daemon-reload
    info "systemd 服务已安装。"
fi

# 复制 Nginx 配置
NGINX_SRC="${PROJECT_DIR}/deploy/nginx/push.conf"
if [[ -f "${NGINX_SRC}" ]]; then
    if [[ -d "/etc/nginx/sites-available" ]]; then
        cp "${NGINX_SRC}" /etc/nginx/sites-available/push.conf
        ln -sf /etc/nginx/sites-available/push.conf /etc/nginx/sites-enabled/push.conf
        # 移除默认站点（避免端口冲突）
        rm -f /etc/nginx/sites-enabled/default
    elif [[ -d "/etc/nginx/conf.d" ]]; then
        cp "${NGINX_SRC}" /etc/nginx/conf.d/push.conf
    else
        cp "${NGINX_SRC}" /etc/nginx/push.conf
    fi
    info "Nginx 配置已安装。"
    # 校验并重新加载
    if nginx -t; then
        systemctl reload nginx
        info "Nginx 已重新加载。"
    else
        error "Nginx 配置校验失败，请检查 /etc/nginx/sites-available/push.conf"
        error "请根据实际域名修改 server_name 后重试。"
    fi
fi

# 设置项目目录权限（确保 www-data 可读写）
chown -R www-data:www-data "${PROJECT_DIR}" 2>/dev/null || chown -R nginx:nginx "${PROJECT_DIR}"
find "${PROJECT_DIR}" -type d -exec chmod 755 {} \;
find "${PROJECT_DIR}" -type f -exec chmod 644 {} \;
# 存储目录与缓存目录需要写权限
chmod -R 775 "${PROJECT_DIR}/backend/storage" 2>/dev/null || true
chmod -R 775 "${PROJECT_DIR}/build/output" 2>/dev/null || true
info "目录权限已设置。"

# ============================================================
# 步骤 7: 启动服务
# ============================================================
step "7/7" "启动服务"

# 启动 PHP-FPM（用于 opcache 管理）
if systemctl list-unit-files | grep -q 'php8.2-fpm'; then
    systemctl restart php8.2-fpm
    systemctl enable php8.2-fpm
    info "php8.2-fpm 已启动。"
elif systemctl list-unit-files | grep -q 'php-fpm'; then
    systemctl restart php-fpm
    systemctl enable php-fpm
    info "php-fpm 已启动。"
fi

# 启动推送服务
systemctl enable push-http push-websocket push-build-worker
systemctl restart push-http
sleep 1
systemctl restart push-websocket
sleep 1
systemctl restart push-build-worker

# 查看服务状态
info "服务状态："
for svc in push-http push-websocket push-build-worker; do
    if systemctl is-active --quiet "${svc}"; then
        echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc}    [运行中]"
    else
        echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc}    [未运行，请使用 journalctl -u ${svc} 查看日志]"
    fi
done

# ============================================================
# 安装完成
# ============================================================
echo ""
echo -e "${COLOR_GREEN}============================================================${COLOR_RESET}"
echo -e "${COLOR_GREEN}  即时消息推送系统 安装完成！${COLOR_RESET}"
echo -e "${COLOR_GREEN}============================================================${COLOR_RESET}"
echo ""
echo "项目目录:    ${PROJECT_DIR}"
echo "数据库:      ${DB_NAME}（用户: ${DB_USER}）"
echo "HTTP API:    http://127.0.0.1:${HTTP_PORT}"
echo "WebSocket:   ws://127.0.0.1:${WEBSOCKET_PORT}/ws"
echo ""
echo "默认管理员账号: admin"
echo "默认管理员密码: admin123"
echo ""
warn "请尽快修改默认管理员密码与数据库密码！"
warn "如需启用 HTTPS，请参考 deploy/nginx/push.conf 中的 SSL 配置示例。"
echo ""
info "常用命令："
echo "  查看服务状态: systemctl status push-http push-websocket push-build-worker"
echo "  查看服务日志: journalctl -u push-http -f"
echo "  版本检查:     sudo bash ${PROJECT_DIR}/deploy/check-version.sh"
echo "  更新代码:     sudo bash ${PROJECT_DIR}/deploy/update.sh"
echo "  回滚代码:     sudo bash ${PROJECT_DIR}/deploy/rollback.sh"
