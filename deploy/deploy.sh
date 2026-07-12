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
#     sudo bash deploy/deploy.sh --project-dir=/www/push-system --domain=push.example.com
#
#   方式5: 断点续装（从上次失败的步骤继续）
#     sudo bash deploy/deploy.sh --resume
#
#   方式6: 重新开始（清除进度记录）
#     sudo bash deploy/deploy.sh --restart
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
# 禁用交互式弹窗（避免 needrestart 等对话框阻塞部署）
# ------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export NEEDRESTART_SUSPEND=1

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
# 断点续装：进度文件 & 辅助函数
# ------------------------------------------------------------
PROGRESS_FILE="/tmp/push-deploy-progress.env"
RESUME_MODE=""

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

# 清除进度记录
clear_progress() {
    rm -f "${PROGRESS_FILE}"
}

# 部署失败时输出恢复提示
deploy_failed() {
    local exit_code=$?
    if [[ ${exit_code} -ne 0 ]]; then
        echo ""
        error "============================================================"
        error "  部署失败！错误码: ${exit_code}"
        error "============================================================"
        warn  "已完成的步骤已保存，修复问题后可断点续装："
        echo  ""
        echo  -e "  ${COLOR_YELLOW}sudo bash deploy/deploy.sh --resume${COLOR_RESET}"
        echo  ""
        warn  "或重新开始完整部署："
        echo  -e "  ${COLOR_YELLOW}sudo bash deploy/deploy.sh --restart${COLOR_RESET}"
        echo  ""
        info  "进度文件: ${PROGRESS_FILE}"
        cat "${PROGRESS_FILE}" 2>/dev/null | grep '=done$' | while read -r line; do
            echo -e "  ${COLOR_GREEN}✓${COLOR_RESET} ${line%%=*}"
        done
        echo  ""
    fi
}
trap deploy_failed EXIT

# ------------------------------------------------------------
# 解析命令行参数（环境变量优先级：命令行 > 环境变量 > 默认值）
# ------------------------------------------------------------
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
DB_NAME="${DB_NAME:-im_push}"
DB_USER="${DB_USER:-im_push}"
DB_PASS="${DB_PASS:-ImPush@2024}"
DB_HOST="${DB_HOST:-127.0.0.1}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"
HTTP_PORT="${HTTP_PORT:-9501}"
WS_PORT="${WS_PORT:-9502}"
DOMAIN="${DOMAIN:-}"
SKIP_APP_BUILD="${SKIP_APP_BUILD:-}"

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
        --resume) RESUME_MODE="1" ;;
        --restart) clear_progress; RESUME_MODE="" ;;
    esac
done

# 断点续装：检查是否有进度记录
if [[ "${RESUME_MODE}" == "1" ]]; then
    if [[ ! -f "${PROGRESS_FILE}" ]]; then
        warn "未找到进度文件，将从头开始部署"
        RESUME_MODE=""
    else
        info "断点续装模式：以下步骤已完成，将跳过"
        grep '=done$' "${PROGRESS_FILE}" 2>/dev/null | while read -r line; do
            echo -e "  ${COLOR_GREEN}✓${COLOR_RESET} ${line%%=*}"
        done
    fi
elif [[ -f "${PROGRESS_FILE}" ]]; then
    info "检测到上次部署的进度记录，使用 --resume 断点续装或 --restart 重新开始"
    info "本次将重新开始完整部署"
    clear_progress
fi

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
if step_done "step1_system_deps"; then
    info "跳过步骤 1: 系统基础依赖（已完成）"
else
    step "1/8" "安装系统基础依赖"

    # 检测系统版本代号
    if [[ "$PKG_MANAGER" == "apt-get" ]]; then
        if [[ -f /etc/os-release ]]; then
            . /etc/os-release
            OS_CODENAME="${VERSION_CODENAME:-}"
            OS_ID="${ID:-}"
        fi
        if [[ -z "${OS_CODENAME}" ]]; then
            OS_CODENAME="$(lsb_release -cs 2>/dev/null || echo "jammy")"
        fi
        info "系统版本代号: ${OS_CODENAME}"

        info "配置阿里云镜像源..."
        cp /etc/apt/sources.list /etc/apt/sources.list.bak 2>/dev/null || true
        if [[ "${OS_ID}" == "debian" ]]; then
            cat > /etc/apt/sources.list << EOF
deb http://mirrors.aliyun.com/debian/ ${OS_CODENAME} main contrib non-free
deb http://mirrors.aliyun.com/debian/ ${OS_CODENAME}-updates main contrib non-free
deb http://mirrors.aliyun.com/debian/ ${OS_CODENAME}-backports main contrib non-free
deb http://mirrors.aliyun.com/debian-security ${OS_CODENAME}-security main contrib non-free
EOF
        else
            cat > /etc/apt/sources.list << EOF
deb http://mirrors.aliyun.com/ubuntu/ ${OS_CODENAME} main restricted universe multiverse
deb http://mirrors.aliyun.com/ubuntu/ ${OS_CODENAME}-updates main restricted universe multiverse
deb http://mirrors.aliyun.com/ubuntu/ ${OS_CODENAME}-backports main restricted universe multiverse
deb http://mirrors.aliyun.com/ubuntu/ ${OS_CODENAME}-security main restricted universe multiverse
EOF
        fi
        apt-get update -y
        apt-get install -y software-properties-common ca-certificates curl wget git unzip tar

    elif [[ "$PKG_MANAGER" == "yum" ]]; then
        info "配置阿里云 CentOS 镜像源..."
        cp /etc/yum.repos.d/CentOS-Base.repo /etc/yum.repos.d/CentOS-Base.repo.bak 2>/dev/null || true
        curl -sSL http://mirrors.aliyun.com/repo/Centos-8.repo > /etc/yum.repos.d/CentOS-Base.repo 2>/dev/null || true
        yum clean all
        yum makecache -y
        yum install -y epel-release ca-certificates curl wget git unzip tar
    fi
    mark_done "step1_system_deps"
fi

# ============================================================
# 步骤 2: 安装 PHP 8.2 + Swoole
# ============================================================
if step_done "step2_php_swoole"; then
    info "跳过步骤 2: PHP 8.2 + Swoole（已完成）"
else
    step "2/8" "安装 PHP 8.2 + Swoole 扩展"

    if [[ "$PKG_MANAGER" == "apt-get" ]]; then
        add-apt-repository -y ppa:ondrej/php
        apt-get update -y

        apt-get install -y \
            php8.2 php8.2-cli php8.2-common \
            php8.2-mysql php8.2-redis php8.2-curl \
            php8.2-mbstring php8.2-xml php8.2-zip \
            php8.2-bcmath php8.2-gd php8.2-intl \
            php8.2-swoole php-pear php8.2-dev libssl-dev

    elif [[ "$PKG_MANAGER" == "yum" ]]; then
        yum install -y "https://rpms.remirepo.net/enterprise/remi-release-8.rpm" || true
        yum module reset -y php || true
        yum module enable -y php:remi-8.2

        yum install -y \
            php php-cli php-common \
            php-mysqlnd php-pecl-redis5 php-curl \
            php-mbstring php-xml php-zip \
            php-bcmath php-gd php-intl \
            php-pecl-swoole5 php-pear php-devel openssl-devel
    fi

    info "PHP 版本: $(php -v | head -n1)"
    info "Swoole 版本: $(php -r "echo swoole_version();")"
    mark_done "step2_php_swoole"
fi

# ============================================================
# 步骤 3: 安装 MySQL / Redis / Nginx
# ============================================================
if step_done "step3_mysql_redis_nginx"; then
    info "跳过步骤 3: MySQL / Redis / Nginx（已完成）"
else
    step "3/8" "安装 MySQL / Redis / Nginx"

if [[ "$PKG_MANAGER" == "apt-get" ]]; then
    apt-get install -y mysql-server redis-server nginx

    systemctl enable mysql 2>/dev/null || true
    systemctl start mysql 2>/dev/null || true
    sleep 2

    # MySQL 安全配置（兼容三种情况：无密码、密码已匹配、密码不匹配）
    if mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
        # 首次安装，root 无密码
        info "配置 MySQL root 密码..."
        mysql -uroot <<EOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
FLUSH PRIVILEGES;
EOF
    elif mysql -uroot -p"${DB_PASS}" -e "SELECT 1" >/dev/null 2>&1; then
        # 密码已设置且与 DB_PASS 一匹配
        info "MySQL root 密码已配置，跳过"
    else
        # 密码不匹配，可能是用户之前设置了不同密码
        warn "MySQL root 密码验证失败，请确保 DB_PASS 与实际密码一致"
        warn "当前 DB_PASS: ${DB_PASS}"
        warn "如需重置: sudo mysql -uroot -e \"ALTER USER 'root'@'localhost' IDENTIFIED BY '${DB_PASS}';\""
    fi

elif [[ "$PKG_MANAGER" == "yum" ]]; then
    yum install -y mariadb-server redis nginx

    systemctl enable mariadb 2>/dev/null || true
    systemctl start mariadb 2>/dev/null || true
    sleep 2

    # MySQL 安全配置
    if mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
        info "配置 MySQL root 密码..."
        mysql -uroot <<EOF
SET PASSWORD FOR 'root'@'localhost' = PASSWORD('${DB_PASS}');
FLUSH PRIVILEGES;
EOF
    elif mysql -uroot -p"${DB_PASS}" -e "SELECT 1" >/dev/null 2>&1; then
        info "MySQL root 密码已配置，跳过"
    else
        warn "MySQL root 密码验证失败，请确保 DB_PASS 与实际密码一致"
        warn "当前 DB_PASS: ${DB_PASS}"
    fi
fi

# 启动并开机自启
systemctl enable --now redis-server 2>/dev/null || systemctl enable --now redis

# 检查 80 端口是否被占用，尝试停止冲突服务
if ! ss -tlnp | grep -q ':80 '; then
    systemctl enable --now nginx
else
    # 端口被占用，尝试停止 Apache 等冲突服务
    PORT_80_PID="$(ss -tlnp | grep ':80 ' | grep -oP 'pid=\K[0-9]+' | head -1)"
    PORT_80_PROC="$(cat /proc/${PORT_80_PID}/comm 2>/dev/null || echo 'unknown')"
    warn "80 端口已被 ${PORT_80_PROC} (PID: ${PORT_80_PID}) 占用"

    if [[ "${PORT_80_PROC}" == "apache2" ]]; then
        info "停止 Apache2 以释放 80 端口..."
        systemctl stop apache2 2>/dev/null || true
        systemctl disable apache2 2>/dev/null || true
    elif [[ "${PORT_80_PROC}" == "httpd" ]]; then
        info "停止 httpd 以释放 80 端口..."
        systemctl stop httpd 2>/dev/null || true
        systemctl disable httpd 2>/dev/null || true
    fi

    sleep 1
    if systemctl start nginx; then
        systemctl enable nginx
        info "Nginx 已启动"
    else
        warn "Nginx 启动失败，端口 80 可能仍被占用，请手动排查后运行: systemctl start nginx"
    fi
fi

info "MySQL 版本: $(mysql -V)"
info "Redis 版本: $(redis-server --version | head -n1)"
info "Nginx 版本: $(nginx -v 2>&1)"
    mark_done "step3_mysql_redis_nginx"
fi

# ============================================================
# 步骤 4: 安装 Node.js + Composer（国内镜像加速）
# ============================================================
if step_done "step4_nodejs_composer"; then
    info "跳过步骤 4: Node.js + Composer（已完成）"
else
    step "4/8" "安装 Node.js + Composer"

# 安装 Node.js 20.x LTS（使用 npmmirror 二进制镜像）
if ! command -v node >/dev/null 2>&1; then
    info "安装 Node.js 20.x LTS..."
    NODE_VERSION="v20.18.1"
    NODE_MIRROR="https://npmmirror.com/mirrors/node"
    if [[ "$PKG_MANAGER" == "apt-get" ]]; then
        curl -fsSL "${NODE_MIRROR}/${NODE_VERSION}/node-${NODE_VERSION}-linux-x64.tar.gz" -o /tmp/node.tar.gz
        tar -xzf /tmp/node.tar.gz -C /usr/local --strip-components=1
        rm -f /tmp/node.tar.gz
    else
        curl -fsSL "${NODE_MIRROR}/${NODE_VERSION}/node-${NODE_VERSION}-linux-x64.tar.gz" -o /tmp/node.tar.gz
        tar -xzf /tmp/node.tar.gz -C /usr/local --strip-components=1
        rm -f /tmp/node.tar.gz
    fi
    info "Node.js 安装完成: $(node -v)"
fi

# 配置 npm 淘宝镜像
npm config set registry https://registry.npmmirror.com
info "npm 镜像源: $(npm config get registry)"

# 安装 Composer
if ! command -v composer >/dev/null 2>&1; then
    info "安装 Composer..."
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f composer-setup.php
fi
export COMPOSER_ALLOW_SUPERUSER=1
composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/
info "Composer 镜像源: $(composer config -g repo.packagist --list 2>/dev/null | grep url | awk '{print $3}' || echo '阿里云镜像')"

info "Node 版本: $(node -v)"
info "npm 版本: $(npm -v)"
info "Composer 版本: $(composer -V)"
    mark_done "step4_nodejs_composer"
fi

# ============================================================
# 步骤 5: 安装 APP 构建环境（JDK + Gradle + Android SDK）
# ============================================================
if step_done "step5_app_build_env"; then
    info "跳过步骤 5: APP 构建环境（已完成）"
else
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

    # 安装 Gradle 8.x
    if ! command -v gradle >/dev/null 2>&1; then
        info "安装 Gradle 8.10..."
        GRADLE_VERSION="8.10"
        mkdir -p /opt/gradle

        # 多源尝试下载（阿里云镜像 -> 腾讯云镜像 -> 官方源）
        GRADLE_URLS=(
            "https://mirrors.aliyun.com/macports/distfiles/gradle/gradle-${GRADLE_VERSION}-bin.zip"
            "https://mirrors.cloud.tencent.com/gradle/gradle-${GRADLE_VERSION}-bin.zip"
            "https://services.gradle.org/distributions/gradle-${GRADLE_VERSION}-bin.zip"
        )

        GRADLE_DOWNLOADED=false
        for url in "${GRADLE_URLS[@]}"; do
            info "尝试下载: ${url}"
            if curl -fsSL "${url}" -o /tmp/gradle.zip && unzip -tq /tmp/gradle.zip >/dev/null 2>&1; then
                GRADLE_DOWNLOADED=true
                info "下载成功"
                break
            fi
            warn "下载失败或文件损坏，尝试下一个源..."
            rm -f /tmp/gradle.zip
        done

        if [[ "${GRADLE_DOWNLOADED}" == "true" ]]; then
            unzip -q /tmp/gradle.zip -d /opt/gradle
            rm -f /tmp/gradle.zip
            ln -sf "/opt/gradle/gradle-${GRADLE_VERSION}/bin/gradle" /usr/local/bin/gradle
        else
            error "Gradle 下载失败，请手动安装后重新运行部署"
            exit 1
        fi
    fi
    info "Gradle 版本: $(gradle -v 2>&1 | head -n1)"

    # 配置 Gradle 阿里云镜像
    mkdir -p ~/.gradle
    cat > ~/.gradle/init.gradle << 'EOF'
allprojects {
    repositories {
        maven { url 'https://maven.aliyun.com/repository/public' }
        maven { url 'https://maven.aliyun.com/repository/google' }
        maven { url 'https://maven.aliyun.com/repository/gradle-plugin' }
        mavenLocal()
        mavenCentral()
        google()
    }
}
EOF
    info "Gradle 镜像配置完成"

    # 安装 Android SDK（多源尝试，统一路径 /opt/android-sdk）
    ANDROID_SDK_ROOT="/opt/android-sdk"
    if [[ ! -d "${ANDROID_SDK_ROOT}" ]]; then
        info "安装 Android SDK..."
        mkdir -p "${ANDROID_SDK_ROOT}/cmdline-tools"

        # 备用下载源列表（国内优先，官方最后）
        ANDROID_SDK_URLS=(
            "https://mirrors.aliyun.com/android/repository/commandlinetools-linux-11076708_latest.zip"
            "https://mirrors.cloud.tencent.com/android/repository/commandlinetools-linux-11076708_latest.zip"
            "https://mirrors.huaweicloud.com/android/repository/commandlinetools-linux-11076708_latest.zip"
            "https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip"
        )

        # 依次尝试各镜像源
        DOWNLOAD_OK=false
        for url in "${ANDROID_SDK_URLS[@]}"; do
            info "  尝试下载: ${url}"
            rm -f /tmp/android-sdk.zip
            curl -fsSL --connect-timeout 10 --max-time 180 "${url}" -o /tmp/android-sdk.zip 2>/dev/null
            if [[ $? -ne 0 ]]; then
                warn "  下载失败，尝试下一个源..."
                continue
            fi
            # 用 unzip -tq 校验 zip 完整性（比检查文件头更可靠）
            if unzip -tq /tmp/android-sdk.zip >/dev/null 2>&1; then
                FILE_SIZE=$(stat -c%s /tmp/android-sdk.zip 2>/dev/null || echo 0)
                info "  下载成功，文件大小: $((FILE_SIZE / 1024 / 1024)) MB"
                DOWNLOAD_OK=true
                break
            else
                warn "  文件损坏，尝试下一个源..."
            fi
        done

        if [[ "${DOWNLOAD_OK}" != "true" ]]; then
            error "Android SDK 下载失败，所有镜像源均不可用"
            error "请手动下载 commandlinetools-linux 并解压到 ${ANDROID_SDK_ROOT}/cmdline-tools/latest/"
            rm -f /tmp/android-sdk.zip
            exit 1
        fi

        # 解压
        unzip -q /tmp/android-sdk.zip -d "${ANDROID_SDK_ROOT}/cmdline-tools"
        rm -f /tmp/android-sdk.zip

        # 重命名 cmdline-tools 到 latest
        mv "${ANDROID_SDK_ROOT}/cmdline-tools/cmdline-tools" "${ANDROID_SDK_ROOT}/cmdline-tools/latest" 2>/dev/null || true

        # 配置环境变量（全局）
        cat > /etc/profile.d/android-sdk.sh << 'EOF'
# Android SDK Environment
export ANDROID_HOME=/opt/android-sdk
export ANDROID_SDK_ROOT=/opt/android-sdk
export PATH=$PATH:/opt/android-sdk/cmdline-tools/latest/bin:/opt/android-sdk/platform-tools
EOF
        chmod +x /etc/profile.d/android-sdk.sh
        source /etc/profile.d/android-sdk.sh

        # 接受 SDK 许可证（使用 expect 或重定向 stdin 避免管道模式问题）
        info "接受 Android SDK 许可证..."
        yes "y" 2>/dev/null | "${ANDROID_SDK_ROOT}/cmdline-tools/latest/bin/sdkmanager" --sdk_root="${ANDROID_SDK_ROOT}" --licenses >/dev/null 2>&1 || true

        # 安装基础组件
        info "安装 Android SDK 基础组件..."
        "${ANDROID_SDK_ROOT}/cmdline-tools/latest/bin/sdkmanager" --sdk_root="${ANDROID_SDK_ROOT}" \
            "platform-tools" "platforms;android-34" "build-tools;34.0.0"
    fi
    info "Android SDK 路径: ${ANDROID_SDK_ROOT}"
else
    step "5/8" "跳过 APP 构建环境安装"
    warn "如需构建 Android APP，请手动安装 JDK 17 + Gradle + Android SDK"
fi
    mark_done "step5_app_build_env"
fi

# ============================================================
# 步骤 6: 创建数据库与项目目录
# ============================================================
if step_done "step6_database_project"; then
    info "跳过步骤 6: 创建数据库与项目目录（已完成）"
else
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
    mark_done "step6_database_project"
fi

# ============================================================
# 步骤 7: 拉取代码并配置
# ============================================================
if step_done "step7_code_config"; then
    info "跳过步骤 7: 拉取代码并配置（已完成）"
else
    step "7/8" "拉取代码并配置"

# 拉取代码（使用 HTTPS）
if [[ ! -d "${PROJECT_DIR}/.git" ]]; then
    info "从 GitHub 拉取代码..."
    git clone https://gh.jasonzeng.dev/https://github.com/jiujiu123520/im-push-system.git "${PROJECT_DIR}"
fi

cd "${PROJECT_DIR}"
git fetch origin
git reset --hard origin/main
git clean -fd

# 配置 .env
if [[ ! -f "${PROJECT_DIR}/backend/.env" ]]; then
    info "配置 .env 文件..."
    cp "${PROJECT_DIR}/backend/.env.example" "${PROJECT_DIR}/backend/.env"

    JWT_SECRET_NEW="$(openssl rand -hex 32)"
    AES_KEY_NEW="$(openssl rand -hex 32)"

    export _ENV_PATH="${PROJECT_DIR}"
    export _ENV_DB_NAME="${DB_NAME}"
    export _ENV_DB_USER="${DB_USER}"
    export _ENV_DB_PASS="${DB_PASS}"
    export _ENV_REDIS_HOST="${REDIS_HOST}"
    export _ENV_REDIS_PORT="${REDIS_PORT}"
    export _ENV_HTTP_PORT="${HTTP_PORT}"
    export _ENV_WS_PORT="${WS_PORT}"
    export _ENV_JWT_SECRET="${JWT_SECRET_NEW}"
    export _ENV_AES_KEY="${AES_KEY_NEW}"

    php -r '
$envFile = getenv("_ENV_PATH") . "/backend/.env";
$content = file_get_contents($envFile);
$replacements = [
    "DB_NAME" => getenv("_ENV_DB_NAME"),
    "DB_USER" => getenv("_ENV_DB_USER"),
    "DB_PASS" => getenv("_ENV_DB_PASS"),
    "REDIS_HOST" => getenv("_ENV_REDIS_HOST"),
    "REDIS_PORT" => getenv("_ENV_REDIS_PORT"),
    "HTTP_PORT" => getenv("_ENV_HTTP_PORT"),
    "WEBSOCKET_PORT" => getenv("_ENV_WS_PORT"),
    "JWT_SECRET" => getenv("_ENV_JWT_SECRET"),
    "AES_KEY" => getenv("_ENV_AES_KEY"),
];
foreach ($replacements as $key => $value) {
    $content = preg_replace("/^" . preg_quote($key, "/") . "=.*/m", $key . "=" . $value, $content);
}
file_put_contents($envFile, $content);
'
    unset _ENV_DB_NAME _ENV_DB_USER _ENV_DB_PASS _ENV_REDIS_HOST _ENV_REDIS_PORT
    unset _ENV_HTTP_PORT _ENV_WS_PORT _ENV_JWT_SECRET _ENV_AES_KEY _ENV_PATH

    info "已生成随机密钥并配置数据库"
fi

# 安装后端依赖
info "安装后端依赖..."
cd "${PROJECT_DIR}/backend"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader --no-audit

# 构建管理后台
info "构建管理后台..."
cd "${PROJECT_DIR}/admin"
npm install
npm run build

# 执行数据库迁移
info "执行数据库迁移..."
cd "${PROJECT_DIR}"
shopt -s nullglob
sql_files=("${PROJECT_DIR}/backend/database/migrations"/*.sql)
shopt -u nullglob
if [[ ${#sql_files[@]} -gt 0 ]]; then
    IFS=$'\n' sorted_sql_files=($(sort <<<"${sql_files[*]}"))
    unset IFS
    for sql_file in "${sorted_sql_files[@]}"; do
        [[ -f "${sql_file}" ]] || continue
        info "  执行: $(basename "${sql_file}")"
        mysql -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${sql_file}"
    done
fi
    mark_done "step7_code_config"
fi

# ============================================================
# 步骤 8: 配置服务并启动
# ============================================================
if step_done "step8_services_start"; then
    info "跳过步骤 8: 配置服务并启动（已完成）"
else
    step "8/8" "配置服务并启动"

# 检测运行用户
if [[ "$PKG_MANAGER" == "apt-get" ]]; then
    WEB_USER="www-data"
    WEB_GROUP="www-data"
else
    WEB_USER="nginx"
    WEB_GROUP="nginx"
fi

# 配置 systemd 服务（替换路径为实际 PROJECT_DIR）
SYSTEMD_DST="/etc/systemd/system"
for svc_file in "${PROJECT_DIR}/deploy/systemd"/*.service; do
    [[ -f "${svc_file}" ]] || continue
    svc_name="$(basename "${svc_file}")"
    info "安装服务: ${svc_name}"
    sed -e "s|/www/push-system|${PROJECT_DIR}|g" \
        -e "s|User=www-data|User=${WEB_USER}|g" \
        -e "s|Group=www-data|Group=${WEB_GROUP}|g" \
        "${svc_file}" > "${SYSTEMD_DST}/${svc_name}"
done
systemctl daemon-reload

# 配置 Nginx
NGINX_SRC="${PROJECT_DIR}/deploy/nginx/push.conf"
if [[ -f "${NGINX_SRC}" ]]; then
    info "配置 Nginx..."

    if [[ "$PKG_MANAGER" == "apt-get" ]]; then
        NGINX_SITE_AVAIL="/etc/nginx/sites-available"
        NGINX_SITE_ENABLED="/etc/nginx/sites-enabled"
        mkdir -p "${NGINX_SITE_AVAIL}" "${NGINX_SITE_ENABLED}"
        NGINX_CONF="${NGINX_SITE_AVAIL}/push.conf"
        cp "${NGINX_SRC}" "${NGINX_CONF}"
        ln -sf "${NGINX_CONF}" "${NGINX_SITE_ENABLED}/push.conf"
        # 禁用默认站点避免冲突
        rm -f "${NGINX_SITE_ENABLED}/default"
    else
        NGINX_CONF_DIR="/etc/nginx/conf.d"
        mkdir -p "${NGINX_CONF_DIR}"
        NGINX_CONF="${NGINX_CONF_DIR}/push.conf"
        cp "${NGINX_SRC}" "${NGINX_CONF}"
    fi

    # 更新项目路径
    sed -i "s|/www/push-system|${PROJECT_DIR}|g" "${NGINX_CONF}"

    # 更新域名配置
    if [[ -n "${DOMAIN}" ]]; then
        sed -i "s/server_name push.example.com;/server_name ${DOMAIN};/g" "${NGINX_CONF}"
    fi

    # 更新端口配置
    sed -i "s/9501/${HTTP_PORT}/g" "${NGINX_CONF}"
    sed -i "s/9502/${WS_PORT}/g" "${NGINX_CONF}"

    if nginx -t; then
        systemctl reload nginx
        info "Nginx 配置完成"
    else
        error "Nginx 配置校验失败"
    fi
fi

# 设置权限
chown -R "${WEB_USER}:${WEB_GROUP}" "${PROJECT_DIR}" 2>/dev/null || true
find "${PROJECT_DIR}" -type d -exec chmod 755 {} \;
find "${PROJECT_DIR}" -type f -exec chmod 644 {} \;

# 启动服务
systemctl enable push-http push-websocket push-build-worker
systemctl restart push-http
sleep 1
systemctl restart push-websocket
sleep 1
systemctl restart push-build-worker
fi

mark_done "step8_services_start"
clear_progress

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