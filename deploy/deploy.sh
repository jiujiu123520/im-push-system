#!/bin/bash
# ============================================================
# 即时消息推送系统 - 一键部署脚本（国内服务器专用）
#
# 功能:
#   1. 系统依赖安装（PHP 8.2 + Swoole + MySQL + Redis + Nginx）
#   2. 管理后台构建（Node.js + Vue）
#   3. APP构建环境配置（JDK 17 + Gradle + Android SDK）
#   4. 数据库初始化与迁移
#   5. systemd 服务配置与启动
#   6. Nginx 反向代理配置
#
# 用法:
#   方式1: 从 GitHub 直接部署（首次安装）
#     curl -sSL https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/deploy.sh | bash
#
#   方式2: 本地执行（已有代码）
#     sudo bash deploy/deploy.sh
#
#   方式3: 自定义参数
#     sudo bash deploy/deploy.sh --project-dir=/www/push-system --domain=push.example.com
#
# 配置参数（可通过环境变量或命令行参数）:
#   PROJECT_DIR   - 项目目录（默认 /www/push-system）
#   DB_NAME       - 数据库名（默认 im_push）
#   DB_USER       - 数据库用户（默认 im_push）
#   DB_PASS       - 数据库密码（默认 ImPush@2024）
#   DOMAIN        - 域名（默认空，使用IP）
#   HTTP_PORT     - HTTP端口（默认 9501）
#   WS_PORT       - WebSocket端口（默认 9502）
#   SKIP_APP_BUILD - 是否跳过APP构建环境安装（默认不跳过）
#
# 支持系统:
#   - Ubuntu 20.04 / 22.04 / 24.04
#   - CentOS 7 / 8 / 9
#   - Debian 10 / 11 / 12
# ============================================================

set -e

# ------------------------------------------------------------
# 颜色输出
# ------------------------------------------------------------
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_BLUE='\033[0;34m'
COLOR_RESET='\033[0m'

info()  { echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }
step()  { echo -e "\n${COLOR_BLUE}===== [$1] $2 =====${COLOR_RESET}"; }

# ------------------------------------------------------------
# 解析命令行参数
# ------------------------------------------------------------
PROJECT_DIR="/www/push-system"
DB_NAME="im_push"
DB_USER="im_push"
DB_PASS="ImPush@2024"
DB_HOST="127.0.0.1"
REDIS_HOST="127.0.0.1"
REDIS_PORT="6379"
HTTP_PORT="9501"
WS_PORT="9502"
DOMAIN=""
SKIP_APP_BUILD=""

for arg in "$@"; do
    case $arg in
        --project-dir=*) PROJECT_DIR="${arg#*=}" ;;
        --db-name=*) DB_NAME="${arg#*=}" ;;
        --db-user=*) DB_USER="${arg#*=}" ;;
        --db-pass=*) DB_PASS="${arg#*=}" ;;
        --domain=*) DOMAIN="${arg#*=}" ;;
        --http-port=*) HTTP_PORT="${arg#*=}" ;;
        --ws-port=*) WS_PORT="${arg#*=}" ;;
        --skip-app-build) SKIP_APP_BUILD="1" ;;
    esac
done

# ------------------------------------------------------------
# 前置检查
# ------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    error "此脚本必须以 root 权限运行，请使用 sudo 或切换到 root 用户。"
    exit 1
fi

if command -v apt-get >/dev/null 2>&1; then
    PKG_MANAGER="apt-get"
elif command -v yum >/dev/null 2>&1; then
    PKG_MANAGER="yum"
else
    error "不支持的系统：未找到 apt-get 或 yum。"
    exit 1
fi

info "检测到系统: $(cat /etc/os-release | grep PRETTY_NAME | sed 's/PRETTY_NAME=//g' | tr -d '"')"
info "包管理器: ${PKG_MANAGER}"
info "项目目录: ${PROJECT_DIR}"
info "数据库: ${DB_NAME} (用户: ${DB_USER})"
if [[ -n "${DOMAIN}" ]]; then
    info "域名: ${DOMAIN}"
fi

# ============================================================
# 步骤 1: 安装系统基础依赖
# ============================================================
step "1/8" "安装系统基础依赖"

# 更新源（使用国内镜像）
if [[ "$PKG_MANAGER" == "apt-get" ]]; then
    info "配置阿里云 Ubuntu 镜像源..."
    cp /etc/apt/sources.list /etc/apt/sources.list.bak
    cat > /etc/apt/sources.list << 'EOF'
deb http://mirrors.aliyun.com/ubuntu/ jammy main restricted universe multiverse
deb http://mirrors.aliyun.com/ubuntu/ jammy-updates main restricted universe multiverse
deb http://mirrors.aliyun.com/ubuntu/ jammy-backports main restricted universe multiverse
deb http://mirrors.aliyun.com/ubuntu/ jammy-security main restricted universe multiverse
EOF
    apt-get update -y
    apt-get install -y software-properties-common ca-certificates curl wget git unzip tar

elif [[ "$PKG_MANAGER" == "yum" ]]; then
    info "配置阿里云 CentOS 镜像源..."
    cp /etc/yum.repos.d/CentOS-Base.repo /etc/yum.repos.d/CentOS-Base.repo.bak
    curl -sSL http://mirrors.aliyun.com/repo/Centos-8.repo > /etc/yum.repos.d/CentOS-Base.repo
    yum clean all
    yum makecache -y
    yum install -y epel-release ca-certificates curl wget git unzip tar
fi

# ============================================================
# 步骤 2: 安装 PHP 8.2 + Swoole
# ============================================================
step "2/8" "安装 PHP 8.2 + Swoole 扩展"

if [[ "$PKG_MANAGER" == "apt-get" ]]; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -y
    
    apt-get install -y \
        php8.2 php8.2-cli php8.2-common \
        php8.2-mysql php8.2-redis php8.2-curl \
        php8.2-mbstring php8.2-xml php8.2-zip \
        php8.2-bcmath php8.2-gd php8.2-intl \
        php-pear php8.2-dev libssl-dev

    if ! php -m | grep -q '^swoole$'; then
        info "编译安装 Swoole 扩展..."
        pecl install swoole
        echo "extension=swoole.so" > /etc/php/8.2/mods-available/swoole.ini
        phpenmod -v 8.2 swoole
    fi

elif [[ "$PKG_MANAGER" == "yum" ]]; then
    yum install -y "https://rpms.remirepo.net/enterprise/remi-release-8.rpm" || true
    yum module reset -y php || true
    yum module enable -y php:remi-8.2

    yum install -y \
        php php-cli php-common \
        php-mysqlnd php-pecl-redis5 php-curl \
        php-mbstring php-xml php-zip \
        php-bcmath php-gd php-intl \
        php-pear php-devel openssl-devel

    if ! php -m | grep -q '^swoole$'; then
        info "编译安装 Swoole 扩展..."
        yes '' | pecl install swoole
        echo "extension=swoole.so" > /etc/php.d/50-swoole.ini
    fi
fi

info "PHP 版本: $(php -v | head -n1)"
info "Swoole 版本: $(php -r "echo swoole_version();")"

# ============================================================
# 步骤 3: 安装 MySQL / Redis / Nginx
# ============================================================
step "3/8" "安装 MySQL / Redis / Nginx"

if [[ "$PKG_MANAGER" == "apt-get" ]]; then
    apt-get install -y mysql-server redis-server nginx
    
    # MySQL 安全配置
    mysql -uroot <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
FLUSH PRIVILEGES;
EOF

elif [[ "$PKG_MANAGER" == "yum" ]]; then
    yum install -y mariadb-server redis nginx
    
    # MySQL 安全配置
    systemctl start mariadb
    mysql -uroot <<EOF
SET PASSWORD FOR 'root'@'localhost' = PASSWORD('${DB_PASS}');
FLUSH PRIVILEGES;
EOF
fi

# 启动并开机自启
systemctl enable --now redis-server 2>/dev/null || systemctl enable --now redis
systemctl enable --now nginx

info "MySQL 版本: $(mysql -V)"
info "Redis 版本: $(redis-server --version | head -n1)"
info "Nginx 版本: $(nginx -v 2>&1)"

# ============================================================
# 步骤 4: 安装 Node.js + Composer（国内镜像加速）
# ============================================================
step "4/8" "安装 Node.js + Composer"

# 安装 Node.js 18.x LTS（使用阿里云镜像）
if ! command -v node >/dev/null 2>&1; then
    info "安装 Node.js 18.x LTS..."
    if [[ "$PKG_MANAGER" == "apt-get" ]]; then
        curl -fsSL https://mirrors.aliyun.com/nodesource/deb/setup_18.x | bash -
        apt-get install -y nodejs
    else
        curl -fsSL https://mirrors.aliyun.com/nodesource/rpm/setup_18.x | bash -
        yum install -y nodejs
    fi
fi

# 配置 npm 淘宝镜像
npm config set registry https://registry.npmmirror.com
info "npm 镜像源: $(npm config get registry)"

# 安装 Composer（使用阿里云镜像）
if ! command -v composer >/dev/null 2>&1; then
    info "安装 Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/
info "Composer 镜像源: $(composer config -g repo.packagist --list | grep url | awk '{print $3}')"

info "Node 版本: $(node -v)"
info "npm 版本: $(npm -v)"
info "Composer 版本: $(composer -V)"

# ============================================================
# 步骤 5: 安装 APP 构建环境（JDK + Gradle + Android SDK）
# ============================================================
if [[ -z "${SKIP_APP_BUILD}" ]]; then
    step "5/8" "安装 APP 构建环境（JDK 17 + Gradle + Android SDK）"

    # 安装 JDK 17（使用 Temurin）
    if ! command -v java >/dev/null 2>&1 || ! java -version 2>&1 | grep -q '17'; then
        info "安装 JDK 17..."
        if [[ "$PKG_MANAGER" == "apt-get" ]]; then
            apt-get install -y openjdk-17-jdk
        else
            yum install -y java-17-openjdk java-17-openjdk-devel
        fi
    fi
    info "Java 版本: $(java -version 2>&1 | head -n1)"

    # 安装 Gradle 8.x（使用阿里云镜像）
    if ! command -v gradle >/dev/null 2>&1; then
        info "安装 Gradle 8.10..."
        GRADLE_VERSION="8.10"
        GRADLE_URL="https://mirrors.aliyun.com/gradle/gradle-${GRADLE_VERSION}-bin.zip"
        mkdir -p /opt/gradle
        curl -sSL "${GRADLE_URL}" -o /tmp/gradle.zip
        unzip -q /tmp/gradle.zip -d /opt/gradle
        rm -f /tmp/gradle.zip
        ln -sf "/opt/gradle/gradle-${GRADLE_VERSION}/bin/gradle" /usr/local/bin/gradle
    fi
    info "Gradle 版本: $(gradle -v 2>&1 | head -n1)"

    # 配置 Gradle 阿里云镜像
    mkdir -p ~/.gradle
    cat > ~/.gradle/init.gradle << 'EOF'
allprojects {
    repositories {
        maven { url 'https://maven.aliyun.com/repository/public' }
        maven { url 'https://maven.aliyun.com/repository/google' }
        maven { url 'https://maven.aliyun.com/repository/jcenter' }
        mavenLocal()
        mavenCentral()
        google()
    }
}
EOF
    info "Gradle 镜像配置完成"

    # 安装 Android SDK（使用阿里云镜像）
    if [[ ! -d "$HOME/Android/Sdk" ]]; then
        info "安装 Android SDK..."
        mkdir -p "$HOME/Android"
        ANDROID_SDK_URL="https://mirrors.aliyun.com/android/repository/commandlinetools-linux-11076708_latest.zip"
        curl -sSL "${ANDROID_SDK_URL}" -o /tmp/android-sdk.zip
        unzip -q /tmp/android-sdk.zip -d "$HOME/Android/cmdline-tools"
        rm -f /tmp/android-sdk.zip

        # 配置环境变量
        cat >> /etc/profile << 'EOF'

# Android SDK Environment
export ANDROID_HOME=$HOME/Android/Sdk
export PATH=$PATH:$ANDROID_HOME/cmdline-tools/latest/bin:$ANDROID_HOME/platform-tools
EOF
        source /etc/profile

        # 接受 SDK 许可证
        mkdir -p "$HOME/Android/Sdk/cmdline-tools/latest"
        mv "$HOME/Android/cmdline-tools/cmdline-tools" "$HOME/Android/Sdk/cmdline-tools/latest" 2>/dev/null || true
        
        # 安装基础组件
        yes | sdkmanager --licenses
        sdkmanager "platform-tools" "platforms;android-34" "build-tools;34.0.0" --no_https
    fi
    info "Android SDK 路径: $ANDROID_HOME"
else
    step "5/8" "跳过 APP 构建环境安装"
    warn "如需构建 Android APP，请手动安装 JDK 17 + Gradle + Android SDK"
fi

# ============================================================
# 步骤 6: 创建数据库与项目目录
# ============================================================
step "6/8" "创建数据库与项目目录"

# 创建项目目录
mkdir -p "${PROJECT_DIR}"

# 创建数据库与用户
info "创建数据库 ${DB_NAME}..."
mysql -uroot -p"${DB_PASS}" <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
FLUSH PRIVILEGES;
EOF
info "数据库创建完成。"

# ============================================================
# 步骤 7: 拉取代码并配置
# ============================================================
step "7/8" "拉取代码并配置"

# 拉取代码（使用 HTTPS）
if [[ ! -d "${PROJECT_DIR}/.git" ]]; then
    info "从 GitHub 拉取代码..."
    git clone https://github.com/jiujiu123520/im-push-system.git "${PROJECT_DIR}"
fi

cd "${PROJECT_DIR}"
git pull origin main

# 配置 .env
if [[ ! -f "${PROJECT_DIR}/backend/.env" ]]; then
    info "配置 .env 文件..."
    cp "${PROJECT_DIR}/backend/.env.example" "${PROJECT_DIR}/backend/.env"
    
    JWT_SECRET_NEW="$(openssl rand -hex 32)"
    AES_KEY_NEW="$(openssl rand -hex 32)"
    
    sed -i "s/^DB_NAME=.*/DB_NAME=${DB_NAME}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^DB_USER=.*/DB_USER=${DB_USER}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^DB_PASS=.*/DB_PASS=${DB_PASS}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^REDIS_HOST=.*/REDIS_HOST=${REDIS_HOST}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^REDIS_PORT=.*/REDIS_PORT=${REDIS_PORT}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^HTTP_PORT=.*/HTTP_PORT=${HTTP_PORT}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^WEBSOCKET_PORT=.*/WEBSOCKET_PORT=${WS_PORT}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^JWT_SECRET=.*/JWT_SECRET=${JWT_SECRET_NEW}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^AES_KEY=.*/AES_KEY=${AES_KEY_NEW}/" "${PROJECT_DIR}/backend/.env"
    
    info "已生成随机密钥并配置数据库"
fi

# 安装后端依赖
info "安装后端依赖..."
cd "${PROJECT_DIR}/backend"
composer install --no-dev --optimize-autoloader

# 构建管理后台
info "构建管理后台..."
cd "${PROJECT_DIR}/admin"
npm install
npm run build

# 执行数据库迁移
info "执行数据库迁移..."
cd "${PROJECT_DIR}"
for sql_file in $(ls -1 "${PROJECT_DIR}/backend/database/migrations"/*.sql 2>/dev/null | sort); do
    info "  执行: $(basename "${sql_file}")"
    mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${sql_file}"
done

# ============================================================
# 步骤 8: 配置服务并启动
# ============================================================
step "8/8" "配置服务并启动"

# 配置 systemd 服务
SYSTEMD_DST="/etc/systemd/system"
for svc_file in "${PROJECT_DIR}/deploy/systemd"/*.service; do
    [[ -f "${svc_file}" ]] || continue
    info "安装服务: $(basename "${svc_file}")"
    cp "${svc_file}" "${SYSTEMD_DST}/"
done
systemctl daemon-reload

# 配置 Nginx
NGINX_SRC="${PROJECT_DIR}/deploy/nginx/push.conf"
if [[ -f "${NGINX_SRC}" ]]; then
    info "配置 Nginx..."
    cp "${NGINX_SRC}" /etc/nginx/conf.d/push.conf
    
    # 更新域名配置
    if [[ -n "${DOMAIN}" ]]; then
        sed -i "s/server_name localhost;/server_name ${DOMAIN};/g" /etc/nginx/conf.d/push.conf
    fi
    
    # 更新端口配置
    sed -i "s/9501/${HTTP_PORT}/g" /etc/nginx/conf.d/push.conf
    sed -i "s/9502/${WS_PORT}/g" /etc/nginx/conf.d/push.conf
    
    if nginx -t; then
        systemctl reload nginx
        info "Nginx 配置完成"
    else
        error "Nginx 配置校验失败"
    fi
fi

# 设置权限
chown -R www-data:www-data "${PROJECT_DIR}" 2>/dev/null || chown -R nginx:nginx "${PROJECT_DIR}"
find "${PROJECT_DIR}" -type d -exec chmod 755 {} \;
find "${PROJECT_DIR}" -type f -exec chmod 644 {} \;

# 启动服务
systemctl enable push-http push-websocket push-build-worker
systemctl restart push-http
sleep 1
systemctl restart push-websocket
sleep 1
systemctl restart push-build-worker

# ============================================================
# 部署完成
# ============================================================
echo ""
echo -e "${COLOR_GREEN}============================================================${COLOR_RESET}"
echo -e "${COLOR_GREEN}  即时消息推送系统 部署完成！${COLOR_RESET}"
echo -e "${COLOR_GREEN}============================================================${COLOR_RESET}"
echo ""
echo "项目目录:    ${PROJECT_DIR}"
echo "数据库:      ${DB_NAME}（用户: ${DB_USER}）"
echo "HTTP API:    http://127.0.0.1:${HTTP_PORT}"
echo "WebSocket:   ws://127.0.0.1:${WS_PORT}/ws"
echo "管理后台:    http://127.0.0.1:${HTTP_PORT}/admin"
if [[ -n "${DOMAIN}" ]]; then
    echo "域名访问:    http://${DOMAIN}"
fi
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
echo "  更新代码:     cd ${PROJECT_DIR} && bash deploy/update.sh"
echo "  回滚代码:     cd ${PROJECT_DIR} && bash deploy/rollback.sh"
echo "  构建 APP:     cd ${PROJECT_DIR} && bash build/build_apk.sh"
echo ""