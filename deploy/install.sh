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

# 安装选项（默认全部安装）
INSTALL_ANDROID="${INSTALL_ANDROID:-1}"
INSTALL_SSL="${INSTALL_SSL:-1}"
INSTALL_SUDOERS="${INSTALL_SUDOERS:-1}"

# 颜色输出
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_BLUE='\033[0;34m'
COLOR_CYAN='\033[0;36m'
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
# 环境检测：检测已安装的组件，已安装则跳过
# ============================================================
info "开始环境检测..."

# 检测命令是否存在
has_cmd() { command -v "$1" >/dev/null 2>&1; }

# 检测 PHP
PHP_INSTALLED=false
if has_cmd php; then
    PHP_VERSION=$(php -v 2>/dev/null | head -n1 | awk '{print $2}')
    PHP_MAJOR=$(echo "$PHP_VERSION" | cut -d. -f1)
    if [[ "$PHP_MAJOR" -ge 8 ]]; then
        PHP_INSTALLED=true
        info "  [已安装] PHP ${PHP_VERSION}"
    else
        warn "  [版本过低] PHP ${PHP_VERSION}，需要 8.0+，将重新安装"
    fi
fi

# 检测 Swoole 扩展
SWOOLE_INSTALLED=false
if [[ "$PHP_INSTALLED" == "true" ]] && php -m 2>/dev/null | grep -q '^swoole$'; then
    SWOOLE_INSTALLED=true
    info "  [已安装] Swoole 扩展"
fi

# 检测 MySQL
MYSQL_INSTALLED=false
if has_cmd mysql; then
    MYSQL_INSTALLED=true
    info "  [已安装] MySQL/MariaDB 客户端"
elif systemctl is-active --quiet mysql 2>/dev/null \
    || systemctl is-active --quiet mysqld 2>/dev/null \
    || systemctl is-active --quiet mariadb 2>/dev/null; then
    MYSQL_INSTALLED=true
    info "  [已安装] MySQL/MariaDB 服务"
fi

# 检测 Redis
REDIS_INSTALLED=false
if has_cmd redis-cli; then
    REDIS_INSTALLED=true
    info "  [已安装] Redis"
elif systemctl is-active --quiet redis-server 2>/dev/null \
    || systemctl is-active --quiet redis 2>/dev/null; then
    REDIS_INSTALLED=true
    info "  [已安装] Redis 服务"
fi

# 检测 Nginx
NGINX_INSTALLED=false
if has_cmd nginx; then
    NGINX_INSTALLED=true
    NGINX_VERSION=$(nginx -v 2>&1 | awk -F/ '{print $2}')
    info "  [已安装] Nginx ${NGINX_VERSION}"
fi

# 检测 Node.js
NODE_INSTALLED=false
if has_cmd node; then
    NODE_VERSION=$(node -v 2>/dev/null)
    NODE_MAJOR=$(echo "$NODE_VERSION" | sed 's/v//' | cut -d. -f1)
    if [[ "$NODE_MAJOR" -ge 16 ]]; then
        NODE_INSTALLED=true
        info "  [已安装] Node.js ${NODE_VERSION}"
    else
        warn "  [版本过低] Node.js ${NODE_VERSION}，需要 16+，将重新安装"
    fi
fi

# 检测 Composer
COMPOSER_INSTALLED=false
if has_cmd composer; then
    COMPOSER_INSTALLED=true
    info "  [已安装] Composer"
fi

# 统计
INSTALLED_COUNT=0
[[ "$PHP_INSTALLED" == "true" ]] && INSTALLED_COUNT=$((INSTALLED_COUNT + 1))
[[ "$SWOOLE_INSTALLED" == "true" ]] && INSTALLED_COUNT=$((INSTALLED_COUNT + 1))
[[ "$MYSQL_INSTALLED" == "true" ]] && INSTALLED_COUNT=$((INSTALLED_COUNT + 1))
[[ "$REDIS_INSTALLED" == "true" ]] && INSTALLED_COUNT=$((INSTALLED_COUNT + 1))
[[ "$NGINX_INSTALLED" == "true" ]] && INSTALLED_COUNT=$((INSTALLED_COUNT + 1))
[[ "$NODE_INSTALLED" == "true" ]] && INSTALLED_COUNT=$((INSTALLED_COUNT + 1))
[[ "$COMPOSER_INSTALLED" == "true" ]] && INSTALLED_COUNT=$((INSTALLED_COUNT + 1))

if [[ "$INSTALLED_COUNT" -eq 7 ]]; then
    info "环境检测完成：所有依赖已安装，跳过系统依赖安装步骤"
elif [[ "$INSTALLED_COUNT" -gt 0 ]]; then
    info "环境检测完成：${INSTALLED_COUNT}/7 已安装，将仅安装缺失的组件"
else
    info "环境检测完成：未检测到已安装的依赖，将执行完整安装"
fi

# ============================================================
# 读取已有配置（如果 .env 存在，从中读取数据库账号密码等）
# ============================================================
ENV_FILE="${PROJECT_DIR}/backend/.env"
if [[ -f "$ENV_FILE" ]]; then
    info "检测到已有 .env 配置文件，从中读取数据库配置..."

    # 从 .env 读取配置（仅读取非空值覆盖默认值）
    read_env_var() {
        local key="$1"
        local val
        val=$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -n1 | cut -d= -f2- | tr -d '"' || echo "")
        echo "$val"
    }

    # 读取数据库配置
    ENV_DB_NAME=$(read_env_var "DB_NAME")
    ENV_DB_USER=$(read_env_var "DB_USER")
    ENV_DB_PASS=$(read_env_var "DB_PASS")
    ENV_DB_HOST=$(read_env_var "DB_HOST")
    ENV_REDIS_HOST=$(read_env_var "REDIS_HOST")
    ENV_REDIS_PORT=$(read_env_var "REDIS_PORT")
    ENV_HTTP_PORT=$(read_env_var "HTTP_PORT")
    ENV_WS_PORT=$(read_env_var "WEBSOCKET_PORT")

    # 仅在 .env 中的值非空时覆盖默认值
    [[ -n "$ENV_DB_NAME" ]] && DB_NAME="$ENV_DB_NAME"
    [[ -n "$ENV_DB_USER" ]] && DB_USER="$ENV_DB_USER"
    [[ -n "$ENV_DB_PASS" ]] && DB_PASS="$ENV_DB_PASS"
    [[ -n "$ENV_DB_HOST" ]] && DB_HOST="$ENV_DB_HOST"
    [[ -n "$ENV_REDIS_HOST" ]] && REDIS_HOST="$ENV_REDIS_HOST"
    [[ -n "$ENV_REDIS_PORT" ]] && REDIS_PORT="$ENV_REDIS_PORT"
    [[ -n "$ENV_HTTP_PORT" ]] && HTTP_PORT="$ENV_HTTP_PORT"
    [[ -n "$ENV_WS_PORT" ]] && WEBSOCKET_PORT="$ENV_WS_PORT"

    info "  数据库名:   ${DB_NAME}"
    info "  数据库用户: ${DB_USER}"
    info "  数据库主机: ${DB_HOST}"
    info "  HTTP 端口:  ${HTTP_PORT}"
    info "  WS 端口:    ${WEBSOCKET_PORT}"
    info "已从 .env 读取配置，将复用现有数据库账号密码"
else
    info "未检测到 .env 配置文件，将使用默认或环境变量配置"
fi

# ============================================================
# 交互式安装选项（数字选择菜单）
# ============================================================
echo ""
echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
echo -e "${COLOR_CYAN}  即时消息推送系统 - 交互式安装${COLOR_RESET}"
echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
echo ""
echo "请选择安装组件（输入数字，多个用空格分隔，如: 2 3 4 或 all）："
echo ""
echo -e "  ${COLOR_GREEN}1.${COLOR_RESET} 核心服务（PHP+Swoole+MySQL+Redis+Nginx+管理后台）  [必选]"
echo -e "  ${COLOR_GREEN}2.${COLOR_RESET} Android APP 打包环境（JDK 17 + Android SDK + Gradle 8.7）"
echo -e "  ${COLOR_GREEN}3.${COLOR_RESET} SSL 证书自动申请环境（acme.sh + 自动续费 cron）"
echo -e "  ${COLOR_GREEN}4.${COLOR_RESET} sudoers 权限配置（允许 www-data 重启服务/部署 Nginx）"
echo -e "  ${COLOR_GREEN}all.${COLOR_RESET} 安装全部（1+2+3+4）"
echo -e "  ${COLOR_GREEN}0.${COLOR_RESET} 仅核心服务（不安装可选组件）"
echo ""

# 读取用户选择（使用 /dev/tty 确保管道模式下也能读取）
read -p "请输入要安装的组件编号 [默认 all]: " USER_CHOICE < /dev/tty
[[ -z "$USER_CHOICE" ]] && USER_CHOICE="all"

# 解析用户输入
SELECTED=""
case "$(echo "$USER_CHOICE" | tr '[:upper:]' '[:lower:]')" in
    all|a)
        INSTALL_ANDROID=1
        INSTALL_SSL=1
        INSTALL_SUDOERS=1
        SELECTED="2 3 4"
        ;;
    0|none|n)
        INSTALL_ANDROID=0
        INSTALL_SSL=0
        INSTALL_SUDOERS=0
        SELECTED=""
        ;;
    *)
        # 解析数字组合（如 "2 3" 或 "2,3" 或 "234"）
        INSTALL_ANDROID=0
        INSTALL_SSL=0
        INSTALL_SUDOERS=0
        # 标准化输入：逗号转空格
        CHOICE_NORMALIZED=$(echo "$USER_CHOICE" | tr ',' ' ' | tr -s ' ')
        for num in $CHOICE_NORMALIZED; do
            case "$num" in
                1) ;; # 核心服务必选，无需处理
                2) INSTALL_ANDROID=1; SELECTED="${SELECTED} 2";;
                3) INSTALL_SSL=1;     SELECTED="${SELECTED} 3";;
                4) INSTALL_SUDOERS=1; SELECTED="${SELECTED} 4";;
                [2-4][2-4]|[2-4][2-4][2-4])
                    # 支持 "234" 连续输入形式
                    for ((i=0; i<${#num}; i++)); do
                        c="${num:$i:1}"
                        case "$c" in
                            2) INSTALL_ANDROID=1;;
                            3) INSTALL_SSL=1;;
                            4) INSTALL_SUDOERS=1;;
                        esac
                    done
                    SELECTED="${SELECTED} ${num}"
                    ;;
                *)
                    warn "忽略无效输入: ${num}（有效选项: 1-4 / all / 0）"
                    ;;
            esac
        done
        ;;
esac

echo ""
info "安装选项："
echo "  核心服务:         安装 [必选]"
echo "  Android 打包:    $([[ "$INSTALL_ANDROID" == "1" ]] && echo -e "${COLOR_GREEN}安装${COLOR_RESET}" || echo '跳过')"
echo "  SSL 证书环境:    $([[ "$INSTALL_SSL" == "1" ]] && echo -e "${COLOR_GREEN}安装${COLOR_RESET}" || echo '跳过')"
echo "  sudoers 配置:    $([[ "$INSTALL_SUDOERS" == "1" ]] && echo -e "${COLOR_GREEN}配置${COLOR_RESET}" || echo '跳过')"
echo ""

read -p "确认开始安装？[Y/n] " -r reply < /dev/tty
case "$reply" in
    [Nn]* ) warn "安装已取消"; exit 0 ;;
esac

# ============================================================
# 步骤 1: 安装系统依赖（已安装的组件自动跳过）
# ============================================================
step "1/7" "安装系统依赖"

if [[ "$INSTALLED_COUNT" -eq 7 ]]; then
    info "所有依赖已安装，跳过系统依赖安装步骤"
else
    info "需要安装的组件: $((7 - INSTALLED_COUNT))/7"
fi

if [[ "$PKG_MANAGER" == "apt-get" ]]; then
    # PHP
    if [[ "$PHP_INSTALLED" != "true" ]]; then
        info "安装 PHP 8.2 及扩展..."
        if ! command -v add-apt-repository >/dev/null 2>&1; then
            apt-get update -y
            apt-get install -y software-properties-common
        fi
        add-apt-repository -y ppa:ondrej/php
        apt-get update -y
        apt-get install -y \
            php8.2 php8.2-cli php8.2-common \
            php8.2-mysql php8.2-redis php8.2-curl \
            php8.2-mbstring php8.2-xml php8.2-zip \
            php8.2-bcmath php8.2-gd php8.2-intl \
            php-pear php8.2-dev libssl-dev
    else
        info "PHP 已安装，跳过"
    fi

    # Swoole
    if [[ "$SWOOLE_INSTALLED" != "true" ]]; then
        info "正在编译安装 Swoole 扩展..."
        yes '' | pecl install swoole
        echo "extension=swoole.so" > /etc/php/8.2/mods-available/swoole.ini
        php phpenmod -v 8.2 swoole
    else
        info "Swoole 扩展已安装，跳过"
    fi

    # MySQL
    if [[ "$MYSQL_INSTALLED" != "true" ]]; then
        info "安装 MySQL..."
        apt-get install -y mysql-server
    else
        info "MySQL 已安装，跳过"
    fi

    # Redis
    if [[ "$REDIS_INSTALLED" != "true" ]]; then
        info "安装 Redis..."
        apt-get install -y redis-server
    else
        info "Redis 已安装，跳过"
    fi

    # Nginx
    if [[ "$NGINX_INSTALLED" != "true" ]]; then
        info "安装 Nginx..."
        apt-get install -y nginx
    else
        info "Nginx 已安装，跳过"
    fi

    # Node.js
    if [[ "$NODE_INSTALLED" != "true" ]]; then
        info "安装 Node.js 18.x LTS..."
        curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
        apt-get install -y nodejs
    else
        info "Node.js 已安装，跳过"
    fi

    # Composer
    if [[ "$COMPOSER_INSTALLED" != "true" ]]; then
        info "安装 Composer..."
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    else
        info "Composer 已安装，跳过"
    fi

elif [[ "$PKG_MANAGER" == "yum" ]]; then
    # PHP
    if [[ "$PHP_INSTALLED" != "true" ]]; then
        yum install -y epel-release

        # 检测系统主版本号（7/8/9）
        OS_MAJOR_VER=""
        if [[ -f /etc/os-release ]]; then
            . /etc/os-release
            OS_MAJOR_VER="${VERSION_ID%%.*}"
        elif [[ -f /etc/redhat-release ]]; then
            OS_MAJOR_VER=$(rpm -E %{rhel} 2>/dev/null || echo "")
        fi
        info "检测到系统主版本: ${OS_MAJOR_VER:-未知}"

        REMI_RELEASE_RPM=""
        case "$OS_MAJOR_VER" in
            7) REMI_RELEASE_RPM="https://rpms.remirepo.net/enterprise/remi-release-7.rpm" ;;
            8) REMI_RELEASE_RPM="https://rpms.remirepo.net/enterprise/remi-release-8.rpm" ;;
            9) REMI_RELEASE_RPM="https://rpms.remirepo.net/enterprise/remi-release-9.rpm" ;;
            *) REMI_RELEASE_RPM="https://rpms.remirepo.net/enterprise/remi-release-8.rpm" ;;
        esac

        info "安装 Remi 源: ${REMI_RELEASE_RPM}"
        yum install -y "$REMI_RELEASE_RPM" || warn "Remi 源安装失败，尝试使用本地已有的 PHP..."

        if [[ "$OS_MAJOR_VER" == "7" ]]; then
            info "CentOS 7: 通过 yum-config-manager 启用 remi-php82 仓库..."
            yum install -y yum-utils
            yum-config-manager --enable remi-php82 || warn "启用 remi-php82 仓库失败，将尝试使用默认 PHP 源"
        else
            info "CentOS ${OS_MAJOR_VER}: 通过 dnf module 启用 PHP 8.2..."
            yum module reset -y php || true
            yum module enable -y php:remi-8.2 || warn "启用 PHP 8.2 模块失败，将尝试使用默认 PHP 源"
        fi

        info "安装 PHP 及扩展..."
        yum install -y \
            php php-cli php-common \
            php-mysqlnd php-curl \
            php-mbstring php-xml php-zip \
            php-bcmath php-gd php-intl \
            php-pear php-devel openssl-devel || warn "部分 PHP 包安装失败，请检查 yum 源配置"

        # Redis 扩展
        yum install -y php-pecl-redis5 2>/dev/null || yum install -y php-redis 2>/dev/null || warn "Redis 扩展安装失败，可后续手动安装"
    else
        info "PHP 已安装，跳过"
    fi

    # Swoole
    if [[ "$SWOOLE_INSTALLED" != "true" ]]; then
        info "正在编译安装 Swoole 扩展..."
        yes '' | pecl install swoole
        echo "extension=swoole.so" > /etc/php.d/50-swoole.ini
    else
        info "Swoole 扩展已安装，跳过"
    fi

    # MySQL
    if [[ "$MYSQL_INSTALLED" != "true" ]]; then
        info "安装 MySQL/MariaDB..."
        yum install -y mysql-server redis nginx || yum install -y mariadb-server redis nginx
    else
        info "MySQL 已安装，跳过"
    fi

    # Redis
    if [[ "$REDIS_INSTALLED" != "true" ]]; then
        info "安装 Redis..."
        yum install -y redis
    else
        info "Redis 已安装，跳过"
    fi

    # Nginx
    if [[ "$NGINX_INSTALLED" != "true" ]]; then
        info "安装 Nginx..."
        yum install -y nginx
    else
        info "Nginx 已安装，跳过"
    fi

    # Node.js
    if [[ "$NODE_INSTALLED" != "true" ]]; then
        info "安装 Node.js 18.x LTS..."
        curl -fsSL https://rpm.nodesource.com/setup_18.x | bash -
        yum install -y nodejs
    else
        info "Node.js 已安装，跳过"
    fi

    # Composer
    if [[ "$COMPOSER_INSTALLED" != "true" ]]; then
        info "安装 Composer..."
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    else
        info "Composer 已安装，跳过"
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

# ------------------------------------------------------------
# 检测 MySQL root 连接方式（自动尝试多种方式，最后才询问密码）
# 注意：密码通过 MYSQL_PWD 环境变量传递（而非拼接到命令字符串中）
# ------------------------------------------------------------
info "检测 MySQL root 连接方式..."
MYSQL_ROOT_CMD=""
MYSQL_ROOT_PASS=""

# 方式1: mysql -uroot（无密码，某些系统默认）
if mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_ROOT_CMD="mysql -uroot"
    info "  MySQL root 可无密码连接"

# 方式2: sudo mysql（Ubuntu auth_socket 插件）
elif sudo mysql -e "SELECT 1" >/dev/null 2>&1; then
    MYSQL_ROOT_CMD="sudo mysql"
    info "  MySQL root 通过 sudo 连接（auth_socket）"

else
    # 方式3-6: 自动读取密码（无需用户输入）
    AUTO_FOUND_PASS=""

    # 方式3: 从 /root/.mysql.cnf 或 ~/.my.cnf 读取（Debian/Ubuntu 包安装时可能生成）
    for CNF_FILE in /root/.mysql.cnf /root/.my.cnf ~/.mysql.cnf ~/.my.cnf /etc/mysql/debian.cnf; do
        if [[ -f "$CNF_FILE" ]]; then
            CNF_PASS=$(awk -F'=' '/^\[client\]/,/^$/ { if ($1 ~ /password/) { sub(/^[ \t]+/, "", $2); sub(/[ \t]+$/, "", $2); gsub(/^"|"$/, "", $2); print $2; exit } }' "$CNF_FILE" 2>/dev/null)
            if [[ -n "$CNF_PASS" ]] && mysql -uroot -p"${CNF_PASS}" -e "SELECT 1" >/dev/null 2>&1; then
                AUTO_FOUND_PASS="$CNF_PASS"
                info "  从 ${CNF_FILE} 读取到 MySQL root 密码"
                break
            fi
            CNF_USER=$(awk -F'=' '/^[[:space:]]*user/ {gsub(/[ \t]/, "", $2); print $2; exit}' "$CNF_FILE" 2>/dev/null)
            CNF_PASS=$(awk -F'=' '/^[[:space:]]*password/ {gsub(/[ \t]/, "", $2); print $2; exit}' "$CNF_FILE" 2>/dev/null)
            if [[ -n "$CNF_PASS" ]] && [[ "$CNF_USER" == "root" || -z "$CNF_USER" ]]; then
                if mysql -uroot -p"${CNF_PASS}" -e "SELECT 1" >/dev/null 2>&1; then
                    AUTO_FOUND_PASS="$CNF_PASS"
                    info "  从 ${CNF_FILE} 读取到 MySQL root 密码"
                    break
                fi
            fi
        fi
    done

    # 方式4: 从 MySQL 错误日志读取临时密码（MySQL 8 首次安装）
    if [[ -z "$AUTO_FOUND_PASS" ]]; then
        for LOG_FILE in /var/log/mysqld.log /var/log/mysql/error.log /var/log/mysql/mysql.log; do
            if [[ -f "$LOG_FILE" ]]; then
                TMP_PASS=$(grep -oP 'temporary password is generated for root@localhost: \K\S+' "$LOG_FILE" 2>/dev/null | tail -1)
                if [[ -n "$TMP_PASS" ]] && mysql -uroot -p"${TMP_PASS}" -e "SELECT 1" >/dev/null 2>&1; then
                    AUTO_FOUND_PASS="$TMP_PASS"
                    info "  从 MySQL 日志 ${LOG_FILE} 读取到 root 临时密码"
                    break
                fi
            fi
        done
    fi

    # 方式5: 从 .env 文件读取之前配置的数据库密码（复用）
    if [[ -z "$AUTO_FOUND_PASS" ]] && [[ -f "$ENV_FILE" ]]; then
        ENV_PASS=$(grep -E "^DB_PASS=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' || echo "")
        if [[ -n "$ENV_PASS" ]] && mysql -uroot -p"${ENV_PASS}" -e "SELECT 1" >/dev/null 2>&1; then
            AUTO_FOUND_PASS="$ENV_PASS"
            info "  从 .env 配置读取到数据库密码（可能与 root 密码相同）"
        fi
    fi

    # 方式6: 通过环境变量 MYSQL_ROOT_PASSWORD 传入
    if [[ -z "$AUTO_FOUND_PASS" ]] && [[ -n "${MYSQL_ROOT_PASSWORD:-}" ]]; then
        if mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; then
            AUTO_FOUND_PASS="${MYSQL_ROOT_PASSWORD}"
            info "  从环境变量 MYSQL_ROOT_PASSWORD 读取到 root 密码"
        fi
    fi

    # 如果自动获取到密码
    if [[ -n "$AUTO_FOUND_PASS" ]]; then
        MYSQL_ROOT_PASS="$AUTO_FOUND_PASS"
        MYSQL_ROOT_CMD="mysql -uroot"
        info "  MySQL root 密码验证成功"

    # 方式7: 所有自动方式失败，交互式询问
    else
        warn "  无法自动获取 MySQL root 密码，需要手动输入"
        info "  提示：可通过以下方式预设密码避免交互："
        info "    1. 创建 /root/.my.cnf 包含 [client] password=xxx"
        info "    2. 设置环境变量: sudo MYSQL_ROOT_PASSWORD=xxx bash deploy.sh"
        echo ""
        read -s -p "请输入 MySQL root 密码: " INPUT_PASS < /dev/tty
        echo ""

        # 测试密码是否正确
        if mysql -uroot -p"${INPUT_PASS}" -e "SELECT 1" >/dev/null 2>&1; then
            MYSQL_ROOT_PASS="$INPUT_PASS"
            MYSQL_ROOT_CMD="mysql -uroot"
            info "  MySQL root 密码验证成功"
        else
            error "MySQL root 密码错误，无法连接数据库"
            error "请确认 MySQL root 密码后重新运行安装脚本"
            error "如果忘记 root 密码，可重置：https://dev.mysql.com/doc/refman/8.0/en/resetting-permissions.html"
            exit 1
        fi
    fi
fi

# 将密码导出为 MYSQL_PWD 环境变量（mysql 客户端会自动读取）
# 这样 $MYSQL_ROOT_CMD 命令字符串中不需要包含密码，避免 command not found 错误
if [[ -n "$MYSQL_ROOT_PASS" ]]; then
    export MYSQL_PWD="$MYSQL_ROOT_PASS"
fi

# 创建数据库与用户（如果 .env 已存在则跳过，数据库已创建过）
if [[ -f "$ENV_FILE" ]]; then
    info "检测到已有 .env 配置，跳过数据库创建（复用现有数据库 ${DB_NAME}）"
    # 仅确保数据库存在（防止被手动删除）
    $MYSQL_ROOT_CMD -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || warn "确保数据库存在时失败"
else
    info "创建数据库 ${DB_NAME} 和用户 ${DB_USER}..."
    $MYSQL_ROOT_CMD <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
    info "数据库与用户创建完成。"
fi

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

# 执行数据库迁移脚本（幂等：已应用的自动跳过，与 update.sh 使用同一张 schema_migrations 表）
MIGRATIONS_DIR="${PROJECT_DIR}/backend/database/migrations"
MIGRATIONS_TABLE="schema_migrations"
if [[ -d "${MIGRATIONS_DIR}" ]]; then
    info "执行数据库迁移..."

    # 创建迁移记录表（如不存在），记录已应用的迁移文件
    $MYSQL_ROOT_CMD "${DB_NAME}" <<EOF
CREATE TABLE IF NOT EXISTS \`${MIGRATIONS_TABLE}\` (
    \`id\` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    \`filename\` VARCHAR(255) NOT NULL UNIQUE,
    \`applied_at\` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='迁移记录表';
EOF

    # 补录已应用但未记录的迁移（兼容旧版无 schema_migrations 表的数据库升级）
    # 通过检测各迁移对应的表/列/索引是否已存在来判断是否已应用
    record_if_applied() {
        local filename="$1"
        local check_sql="$2"
        local exists
        exists=$($MYSQL_ROOT_CMD "${DB_NAME}" -sN -e "${check_sql}" 2>/dev/null || echo 0)
        if [[ "${exists}" == "1" ]]; then
            $MYSQL_ROOT_CMD "${DB_NAME}" -e \
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
            filename=$(basename "${sql_file}")

            # 检查是否已执行（避免重复执行报错，如 Duplicate column name）
            ALREADY_APPLIED=$($MYSQL_ROOT_CMD "${DB_NAME}" -sN -e \
                "SELECT COUNT(*) FROM \`${MIGRATIONS_TABLE}\` WHERE filename='${filename}';" 2>/dev/null || echo 0)

            if [[ "${ALREADY_APPLIED}" -gt 0 ]]; then
                info "  跳过(已应用): ${filename}"
                SKIPPED_COUNT=$((SKIPPED_COUNT + 1))
            else
                info "  执行: ${filename}"
                if $MYSQL_ROOT_CMD "${DB_NAME}" < "${sql_file}"; then
                    $MYSQL_ROOT_CMD "${DB_NAME}" -e \
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

# ============================================================
# 步骤 4: 安装后端依赖（composer install）
# ============================================================
step "4/7" "安装后端依赖"

cd "${PROJECT_DIR}/backend"
# 允许 root 用户运行 composer（仅安装环境，无安全风险）
export COMPOSER_ALLOW_SUPERUSER=1
# 关闭安全公告阻断（国内镜像可能返回安全公告）
composer config --global --no-interaction policy.advisories.block false 2>/dev/null || true

# 同步 composer.lock（当 composer.json 变更后 lock 文件可能过期）
# --lock 仅更新 lock 文件的内容哈希，不会升级依赖版本
composer update --lock --no-interaction --no-dev 2>/dev/null || true
composer install --no-dev --optimize-autoloader --no-interaction
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
info "核心服务状态："
for svc in push-http push-websocket push-build-worker; do
    if systemctl is-active --quiet "${svc}"; then
        echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc}    [运行中]"
    else
        echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc}    [未运行，请使用 journalctl -u ${svc} 查看日志]"
    fi
done

# ============================================================
# 步骤 8: 安装 Android APP 打包环境（可选）
# ============================================================
if [[ "$INSTALL_ANDROID" == "1" ]]; then
    step "8" "安装 Android APP 打包环境"
    ANDROID_SETUP="${PROJECT_DIR}/build/setup.sh"
    if [ -f "${ANDROID_SETUP}" ]; then
        bash "${ANDROID_SETUP}" || warn "Android 环境安装部分失败，可稍后手动执行 build/setup.sh"
    else
        warn "Android 打包环境脚本不存在: ${ANDROID_SETUP}"
    fi
fi

# ============================================================
# 步骤 9: 安装 SSL 证书自动申请环境（可选）
# ============================================================
if [[ "$INSTALL_SSL" == "1" ]]; then
    step "9" "安装 SSL 证书自动申请环境"
    ACME_SETUP="${PROJECT_DIR}/backend/deploy/ssl/setup-acme.sh"
    if [ -f "${ACME_SETUP}" ]; then
        bash "${ACME_SETUP}" || warn "acme.sh 安装部分失败，可稍后手动执行"
    else
        warn "SSL 安装脚本不存在: ${ACME_SETUP}"
    fi

    # 安装自动续费 cron
    RENEW_SCRIPT="${PROJECT_DIR}/backend/deploy/ssl/auto-renew-cron.sh"
    if [ -f "${RENEW_SCRIPT}" ]; then
        chmod +x "${RENEW_SCRIPT}"
        echo "0 3 * * * root ${RENEW_SCRIPT}" > /etc/cron.d/push-ssl-renew
        chmod 644 /etc/cron.d/push-ssl-renew
        info "SSL 自动续费 cron 已安装（每天凌晨 3 点执行）"
    fi
fi

# ============================================================
# 步骤 10: 配置 sudoers 权限（可选）
# ============================================================
if [[ "$INSTALL_SUDOERS" == "1" ]]; then
    step "10" "配置 sudoers 权限"
    SUDOERS_FILE="${PROJECT_DIR}/deploy/sudoers-push-system-ssl"
    if [ -f "${SUDOERS_FILE}" ]; then
        cp "${SUDOERS_FILE}" /etc/sudoers.d/push-system
        chmod 440 /etc/sudoers.d/push-system
        if visudo -c >/dev/null 2>&1; then
            info "sudoers 配置已安装"
        else
            warn "sudoers 语法检查失败，已移除配置"
            rm -f /etc/sudoers.d/push-system
        fi
    else
        warn "sudoers 配置文件不存在: ${SUDOERS_FILE}"
    fi
fi

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
if [[ "$INSTALL_ANDROID" == "1" ]]; then
echo "Android:    JDK 17 + Android SDK 34 + Gradle 8.7  [已安装]"
fi
if [[ "$INSTALL_SSL" == "1" ]]; then
echo "SSL:        acme.sh + 自动续费 cron  [已安装]"
fi
if [[ "$INSTALL_SUDOERS" == "1" ]]; then
echo "sudoers:    www-data 权限已配置  [已安装]"
fi
echo ""
echo "默认管理员账号: admin"
echo "默认管理员密码: admin123"
echo ""
warn "请尽快修改默认管理员密码与数据库密码！"
echo ""
info "常用命令："
echo "  查看服务状态: systemctl status push-http push-websocket push-build-worker"
echo "  查看服务日志: journalctl -u push-http -f"
echo "  版本检查:     sudo bash ${PROJECT_DIR}/backend/deploy/check-version.sh"
echo "  更新代码:     sudo bash ${PROJECT_DIR}/backend/deploy/update.sh"
echo "  回滚代码:     sudo bash ${PROJECT_DIR}/deploy/rollback.sh"
echo ""
info "管理后台操作："
echo "  域名与SSL:    添加域名 → 申请SSL → 部署Nginx"
echo "  系统设置:     修改端口/地址/SSL开关（自动重启服务）"
echo "  APP打包:      管理后台「APP生成」页面在线打包"
echo ""
