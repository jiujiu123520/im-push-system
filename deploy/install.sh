#!/bin/bash
# ============================================================
# 即时消息推送系统 - 一键安装脚本（支持所有主流 Linux 发行版）
#
# 用法:
#   sudo bash deploy/install.sh
#
# 功能:
#   1. 自动检测系统类型（Ubuntu/Debian/CentOS/RHEL/Rocky/AlmaLinux/
#      Fedora/Alpine/openSUSE/Arch 等）并适配对应包管理器
#   2. 安装系统依赖（PHP 8.x + Swoole + MySQL + Redis + Nginx + Composer + Node）
#   3. 创建数据库并执行迁移
#   4. 安装后端依赖（composer install）
#   5. 构建管理后台（npm install && npm run build）
#   6. 复制 systemd 服务文件（自动适配 Web 用户）
#   7. 复制 Nginx 配置（自动适配配置目录）
#   8. 启动服务
#
# 注意:
#   - 脚本必须在 root 或具有 sudo 权限的用户下执行
#   - 默认项目路径为 /www/push-system，可通过 PROJECT_DIR 环境变量覆盖
#   - 数据库名/用户/密码可通过环境变量覆盖（DB_NAME/DB_USER/DB_PASS）
#   - 国内服务器自动使用阿里云镜像加速
# ============================================================

set -e

# ------------------------------------------------------------
# 禁用交互式提示（Ubuntu/Debian 安装时可能弹出 needrestart 菜单）
# ------------------------------------------------------------
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export NEEDRESTART_SUSPEND=1
# 避免 apt 安装时弹出配置菜单（如 timezone、keyboard 等）
export DEBCONF_NONINTERACTIVE_SEEN=true

# ------------------------------------------------------------
# 配置项（可通过环境变量覆盖）
# ------------------------------------------------------------
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
DB_NAME="${DB_NAME:-im_push}"
DB_USER="${DB_USER:-im_push}"
DB_PASS="${DB_PASS:-ImPush@2024}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
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

# ============================================================
# 操作系统检测（支持所有主流 Linux 发行版）
# ============================================================
OS_ID="unknown"
OS_VERSION=""
OS_MAJOR_VER=""
PKG_MANAGER="unknown"
WEB_USER="www-data"          # 运行 Web 服务的系统用户
PHP_FPM_SERVICE=""           # PHP-FPM 服务名
PHP_CONF_DIR=""              # PHP 扩展配置目录
NGINX_CONF_DIR=""            # Nginx 站点配置目录
PHP_PKG_PREFIX=""            # PHP 扩展包名前缀（如 php8.2- 或 php-）

if [[ -f /etc/os-release ]]; then
    . /etc/os-release
    OS_ID="${ID:-unknown}"
    OS_VERSION="${VERSION_ID:-}"
    OS_MAJOR_VER="${OS_VERSION%%.*}"
fi

# 兼容 ID_LIKE（如 ubuntu 的 ID_LIKE=debian）
OS_FAMILY="${ID_LIKE:-$OS_ID}"

case "$OS_ID" in
    ubuntu|debian)
        PKG_MANAGER="apt-get"
        WEB_USER="www-data"
        PHP_FPM_SERVICE="php8.2-fpm"
        PHP_CONF_DIR="/etc/php/8.2/mods-available"
        NGINX_CONF_DIR="/etc/nginx/sites-available"
        PHP_PKG_PREFIX="php8.2-"
        ;;
    centos|rhel|rocky|almalinux|fedora|ol)
        # 检测使用 dnf 还是 yum
        if command -v dnf >/dev/null 2>&1; then
            PKG_MANAGER="dnf"
        else
            PKG_MANAGER="yum"
        fi
        WEB_USER="nginx"
        PHP_FPM_SERVICE="php-fpm"
        PHP_CONF_DIR="/etc/php.d"
        NGINX_CONF_DIR="/etc/nginx/conf.d"
        PHP_PKG_PREFIX="php-"
        ;;
    alpine)
        PKG_MANAGER="apk"
        WEB_USER="nginx"
        PHP_FPM_SERVICE="php-fpm82"
        PHP_CONF_DIR="/etc/php82/conf.d"
        NGINX_CONF_DIR="/etc/nginx/http.d"
        PHP_PKG_PREFIX="php82-"
        ;;
    opensuse*|suse|sles)
        PKG_MANAGER="zypper"
        WEB_USER="nginx"
        PHP_FPM_SERVICE="php-fpm"
        PHP_CONF_DIR="/etc/php8/conf.d"
        NGINX_CONF_DIR="/etc/nginx/vhosts.d"
        PHP_PKG_PREFIX="php8-"
        ;;
    arch|manjaro)
        PKG_MANAGER="pacman"
        WEB_USER="http"
        PHP_FPM_SERVICE="php-fpm"
        PHP_CONF_DIR="/etc/php/conf.d"
        NGINX_CONF_DIR="/etc/nginx/sites-available"
        PHP_PKG_PREFIX="php-"
        ;;
    *)
        # 未知发行版，尝试通过包管理器推断
        if command -v apt-get >/dev/null 2>&1; then
            PKG_MANAGER="apt-get"
            WEB_USER="www-data"
            PHP_FPM_SERVICE="php8.2-fpm"
            PHP_CONF_DIR="/etc/php/8.2/mods-available"
            NGINX_CONF_DIR="/etc/nginx/sites-available"
            PHP_PKG_PREFIX="php8.2-"
        elif command -v dnf >/dev/null 2>&1; then
            PKG_MANAGER="dnf"
            WEB_USER="nginx"
            PHP_FPM_SERVICE="php-fpm"
            PHP_CONF_DIR="/etc/php.d"
            NGINX_CONF_DIR="/etc/nginx/conf.d"
            PHP_PKG_PREFIX="php-"
        elif command -v yum >/dev/null 2>&1; then
            PKG_MANAGER="yum"
            WEB_USER="nginx"
            PHP_FPM_SERVICE="php-fpm"
            PHP_CONF_DIR="/etc/php.d"
            NGINX_CONF_DIR="/etc/nginx/conf.d"
            PHP_PKG_PREFIX="php-"
        elif command -v apk >/dev/null 2>&1; then
            PKG_MANAGER="apk"
            WEB_USER="nginx"
            PHP_FPM_SERVICE="php-fpm82"
            PHP_CONF_DIR="/etc/php82/conf.d"
            NGINX_CONF_DIR="/etc/nginx/http.d"
            PHP_PKG_PREFIX="php82-"
        else
            error "不支持的系统：未找到 apt-get/dnf/yum/apk/zypper/pacman。"
            error "请手动安装 PHP 8.0+、MySQL、Redis、Nginx、Node.js 18+ 后重试。"
            exit 1
        fi
        warn "未识别的发行版（$OS_ID），使用包管理器 $PKG_MANAGER 推断配置"
        ;;
esac

info "操作系统: ${OS_ID} ${OS_VERSION}（主版本 ${OS_MAJOR_VER:-未知}）"
info "包管理器: ${PKG_MANAGER}"
info "Web 用户: ${WEB_USER}"
info "项目目录: ${PROJECT_DIR}"

# ============================================================
# 国内镜像源配置（所有发行版）
# 大陆服务器必须使用国内镜像，否则下载极慢或超时
# 注意：函数内所有命令必须返回 0，否则 set -e 会中断脚本
# ============================================================
configure_domestic_mirrors() {
    case "$OS_ID" in
        ubuntu)
            info "配置 Ubuntu 阿里云镜像源..."
            # 传统格式：/etc/apt/sources.list
            if [[ -f /etc/apt/sources.list ]]; then
                sed -i 's|archive.ubuntu.com|mirrors.aliyun.com|g; s|security.ubuntu.com|mirrors.aliyun.com|g' /etc/apt/sources.list || true
                info "  已替换 /etc/apt/sources.list"
            fi
            # Ubuntu 22.04+ 可能使用 sources.list.d/*.list
            if [[ -d /etc/apt/sources.list.d ]]; then
                for f in /etc/apt/sources.list.d/*.list; do
                    [[ -f "$f" ]] || continue
                    sed -i 's|archive.ubuntu.com|mirrors.aliyun.com|g; s|security.ubuntu.com|mirrors.aliyun.com|g' "$f" || true
                done
                # Ubuntu 24.04+ 使用 DEB822 格式 ubuntu.sources
                for f in /etc/apt/sources.list.d/*.sources; do
                    [[ -f "$f" ]] || continue
                    sed -i 's|archive.ubuntu.com|mirrors.aliyun.com|g; s|security.ubuntu.com|mirrors.aliyun.com|g' "$f" || true
                done
            fi
            # 更新 apt 索引
            apt-get update -y || true
            info "  Ubuntu 阿里云镜像源配置完成"
            ;;
        debian)
            info "配置 Debian 阿里云镜像源..."
            if [[ -f /etc/apt/sources.list ]]; then
                sed -i 's|deb.debian.org|mirrors.aliyun.com|g; s|security.debian.org|mirrors.aliyun.com|g' /etc/apt/sources.list || true
            fi
            if [[ -d /etc/apt/sources.list.d ]]; then
                for f in /etc/apt/sources.list.d/*.list; do
                    [[ -f "$f" ]] || continue
                    sed -i 's|deb.debian.org|mirrors.aliyun.com|g; s|security.debian.org|mirrors.aliyun.com|g' "$f" || true
                done
                for f in /etc/apt/sources.list.d/*.sources; do
                    [[ -f "$f" ]] || continue
                    sed -i 's|deb.debian.org|mirrors.aliyun.com|g; s|security.debian.org|mirrors.aliyun.com|g' "$f" || true
                done
            fi
            apt-get update -y || true
            info "  Debian 阿里云镜像源配置完成"
            ;;
    esac
    return 0
}

# ============================================================
# CentOS 7 EOL 兼容：基础源已下线，需切换到阿里云 vault 归档源
# CentOS 7 于 2024-06-30 EOL，mirrorlist.centos.org 已不可达
# EPEL 7 也已 EOL，需切换到 epel-archive
# 阿里云 centos-vault 路径需要完整版本号（如 7.6.1810, 7.9.2009）
# ============================================================
if [[ "$OS_ID" == "centos" && "$OS_MAJOR_VER" == "7" ]]; then
    info "检测到 CentOS 7（已 EOL），修复 yum 基础源（阿里云 vault 归档）..."

    # 获取完整版本号（如 7.6.1810, 7.9.2009）
    CENTOS_FULL_VER=$(cat /etc/centos-release 2>/dev/null | awk '{print $4}' || echo "7.9.2009")
    info "  CentOS 完整版本: ${CENTOS_FULL_VER}"

    # 直接重写 CentOS-Base.repo（避免 sed 替换不完整导致路径错误）
    # 阿里云 centos-vault 路径格式: /centos-vault/<full_version>/os/$basearch/
    cat > /etc/yum.repos.d/CentOS-Base.repo <<REPOEOF
[base]
name=CentOS-${CENTOS_FULL_VER} - Base
baseurl=https://mirrors.aliyun.com/centos-vault/${CENTOS_FULL_VER}/os/\$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7

[updates]
name=CentOS-${CENTOS_FULL_VER} - Updates
baseurl=https://mirrors.aliyun.com/centos-vault/${CENTOS_FULL_VER}/updates/\$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7

[extras]
name=CentOS-${CENTOS_FULL_VER} - Extras
baseurl=https://mirrors.aliyun.com/centos-vault/${CENTOS_FULL_VER}/extras/\$basearch/
gpgcheck=1
gpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-CentOS-7
REPOEOF
    info "  CentOS-Base.repo 已重写（阿里云 centos-vault 镜像）"

    # 修复 EPEL 源（如已安装 epel-release）
    if [[ -f /etc/yum.repos.d/epel.repo ]]; then
        sed -i 's|^metalink=|#metalink=|g; s|^mirrorlist=|#mirrorlist=|g' /etc/yum.repos.d/epel.repo
        sed -i 's|^#baseurl=https://download.fedoraproject.org/pub/epel|baseurl=https://mirrors.aliyun.com/epel-archive|g' /etc/yum.repos.d/epel.repo
        sed -i 's|^baseurl=https://download.fedoraproject.org/pub/epel|baseurl=https://mirrors.aliyun.com/epel-archive|g' /etc/yum.repos.d/epel.repo
        info "  EPEL 源已修复为阿里云 epel-archive 镜像"
    fi

    # 修复 Remi 源（如已安装 remi-release）
    for remi_file in /etc/yum.repos.d/remi.repo /etc/yum.repos.d/remi-*.repo; do
        if [[ -f "$remi_file" ]]; then
            sed -i 's|http://rpms.remirepo.net|https://mirrors.aliyun.com/remi|g; s|mirrorlist=|#mirrorlist=|g' "$remi_file"
        fi
    done
    info "  Remi 源已修复为阿里云镜像"

    # 清除 yum 缓存
    yum clean all 2>/dev/null || true
    yum makecache fast 2>/dev/null || yum makecache 2>/dev/null || true

    # 更新 ca-certificates 防止旧证书导致 HTTPS 失败（设置超时避免卡住）
    info "  更新 ca-certificates（超时 60 秒）..."
    timeout 60 yum update -y ca-certificates 2>/dev/null || warn "ca-certificates 更新超时或失败，继续..."
fi

# 执行国内镜像配置
configure_domestic_mirrors

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
PHP_ACTUAL_VER=""
if has_cmd php; then
    PHP_VERSION=$(php -v 2>/dev/null | head -n1 | awk '{print $2}')
    PHP_MAJOR=$(echo "$PHP_VERSION" | cut -d. -f1)
    if [[ "$PHP_MAJOR" -ge 8 ]]; then
        PHP_INSTALLED=true
        # 提取实际版本号（如 8.1 或 8.2），用于动态适配配置路径
        PHP_ACTUAL_VER=$(echo "$PHP_VERSION" | cut -d. -f1-2)
        # 若已安装 PHP 版本与默认配置不同，更新 PHP_FPM_SERVICE 和 PHP_CONF_DIR
        if [[ "$OS_ID" == "ubuntu" || "$OS_ID" == "debian" ]]; then
            PHP_FPM_SERVICE="php${PHP_ACTUAL_VER}-fpm"
            PHP_CONF_DIR="/etc/php/${PHP_ACTUAL_VER}/mods-available"
            PHP_PKG_PREFIX="php${PHP_ACTUAL_VER}-"
        fi
        info "  [已安装] PHP ${PHP_VERSION}（配置路径: ${PHP_CONF_DIR}）"
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
    ENV_DB_PORT=$(read_env_var "DB_PORT")
    ENV_REDIS_HOST=$(read_env_var "REDIS_HOST")
    ENV_REDIS_PORT=$(read_env_var "REDIS_PORT")
    ENV_HTTP_PORT=$(read_env_var "HTTP_PORT")
    ENV_WS_PORT=$(read_env_var "WEBSOCKET_PORT")

    # 仅在 .env 中的值非空时覆盖默认值
    [[ -n "$ENV_DB_NAME" ]] && DB_NAME="$ENV_DB_NAME"
    [[ -n "$ENV_DB_USER" ]] && DB_USER="$ENV_DB_USER"
    [[ -n "$ENV_DB_PASS" ]] && DB_PASS="$ENV_DB_PASS"
    [[ -n "$ENV_DB_HOST" ]] && DB_HOST="$ENV_DB_HOST"
    [[ -n "$ENV_DB_PORT" ]] && DB_PORT="$ENV_DB_PORT"
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
echo -e "  ${COLOR_GREEN}4.${COLOR_RESET} sudoers 权限配置（允许 Web 用户重启服务/部署 Nginx）"
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

# ============================================================
# 统一安装函数：根据包管理器适配不同发行版
# ============================================================

# install_pkgs <包名列表...>  —— 通用包安装函数
install_pkgs() {
    case "$PKG_MANAGER" in
        apt-get)
            DEBIAN_FRONTEND=noninteractive apt-get install -y \
                -o Dpkg::Options::="--force-confold" \
                -o Dpkg::Options::="--force-confdef" \
                -o NeedRestart::Mode=auto \
                "$@"
            ;;
        dnf|yum)
            "$PKG_MANAGER" install -y "$@"
            ;;
        apk)
            apk add --no-cache "$@"
            ;;
        zypper)
            zypper install -y "$@"
            ;;
        pacman)
            pacman -S --noconfirm "$@"
            ;;
        *)
            error "未知包管理器: $PKG_MANAGER"
            return 1
            ;;
    esac
}

# update_pkg_index  —— 更新包索引
update_pkg_index() {
    case "$PKG_MANAGER" in
        apt-get)   apt-get update -y ;;
        dnf|yum)   "$PKG_MANAGER" makecache 2>/dev/null || true ;;
        apk)       apk update ;;
        zypper)    zypper refresh ;;
        pacman)    pacman -Sy ;;
    esac
}

# ============================================================
# 安装基础工具（rsync 用于文件复制，最小化系统可能未安装）
# ============================================================
if ! command -v rsync >/dev/null 2>&1; then
    case "$PKG_MANAGER" in
        apt-get)   install_pkgs rsync ;;
        dnf|yum)   install_pkgs rsync ;;
        apk)       install_pkgs rsync ;;
        zypper)    install_pkgs rsync ;;
        pacman)    install_pkgs rsync ;;
    esac 2>/dev/null || warn "rsync 安装失败，将使用 cp -a 替代"
fi

# ============================================================
# PHP 安装（所有源均使用国内镜像）
# ============================================================
if [[ "$PHP_INSTALLED" != "true" ]]; then
    info "安装 PHP 8.x 及扩展..."

    case "$PKG_MANAGER" in
        apt-get)
            # Debian/Ubuntu: 使用 ondrej PPA（Ubuntu）或 Sury（Debian）
            if ! command -v add-apt-repository >/dev/null 2>&1; then
                apt-get update -y
                apt-get install -y software-properties-common
            fi
            # Ubuntu 用 PPA，Debian 用 Sury
            if [[ "$OS_ID" == "ubuntu" ]]; then
                add-apt-repository -y ppa:ondrej/php
                # PPA 源替换为清华镜像（Launchpad PPA 国内访问极慢）
                if [[ -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list ]]; then
                    sed -i 's|http://ppa.launchpad.net|https://mirrors.tuna.tsinghua.edu.cn|g' /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list
                    info "PPA 源已替换为清华镜像"
                fi
            else
                # Debian: 使用 Sury 源（清华镜像）
                apt-get install -y lsb-release ca-certificates curl
                curl -sSL https://mirrors.tuna.tsinghua.edu.cn/sury/php/apt.gpg -o /etc/apt/trusted.gpg.d/sury-php.gpg
                echo "deb [signed-by=/etc/apt/trusted.gpg.d/sury-php.gpg] https://mirrors.tuna.tsinghua.edu.cn/sury/php $(lsb_release -sc) main" > /etc/apt/sources.list.d/sury-php.list
                info "Sury 源已配置为清华镜像"
            fi
            apt-get update -y
            install_pkgs \
                php8.2 php8.2-cli php8.2-common \
                php8.2-mysql php8.2-redis php8.2-curl \
                php8.2-mbstring php8.2-xml php8.2-zip \
                php8.2-bcmath php8.2-gd php8.2-intl \
                php-pear php8.2-dev libssl-dev
            ;;

        dnf|yum)
            # CentOS/RHEL/Rocky/AlmaLinux: 使用 EPEL + Remi 源（阿里云镜像）
            "$PKG_MANAGER" install -y epel-release

            # EPEL 源替换为阿里云镜像（CentOS 7 EPEL 7 已 EOL，需用 epel-archive）
            if [[ "$OS_MAJOR_VER" == "7" ]]; then
                sed -i 's|^metalink=|#metalink=|g' /etc/yum.repos.d/epel*.repo 2>/dev/null || true
                sed -i 's|^#baseurl=https://download.fedoraproject.org/pub/epel|baseurl=https://mirrors.aliyun.com/epel-archive|g' /etc/yum.repos.d/epel*.repo 2>/dev/null || true
                sed -i 's|^baseurl=https://download.fedoraproject.org/pub/epel|baseurl=https://mirrors.aliyun.com/epel-archive|g' /etc/yum.repos.d/epel*.repo 2>/dev/null || true
                info "EPEL 源已替换为阿里云 epel-archive 镜像（CentOS 7 EOL）"
            else
                sed -i 's|^metalink=|#metalink=|g' /etc/yum.repos.d/epel*.repo 2>/dev/null || true
                sed -i 's|^#baseurl=https://download.fedoraproject.org/pub/epel|baseurl=https://mirrors.aliyun.com/epel|g' /etc/yum.repos.d/epel*.repo 2>/dev/null || true
                sed -i 's|^baseurl=https://download.fedoraproject.org/pub/epel|baseurl=https://mirrors.aliyun.com/epel|g' /etc/yum.repos.d/epel*.repo 2>/dev/null || true
                info "EPEL 源已替换为阿里云镜像"
            fi

            # Remi 源（阿里云镜像）
            REMI_RELEASE_RPM=""
            case "$OS_MAJOR_VER" in
                7) REMI_RELEASE_RPM="https://mirrors.aliyun.com/remi/enterprise/remi-release-7.rpm" ;;
                8) REMI_RELEASE_RPM="https://mirrors.aliyun.com/remi/enterprise/remi-release-8.rpm" ;;
                9) REMI_RELEASE_RPM="https://mirrors.aliyun.com/remi/enterprise/remi-release-9.rpm" ;;
                *) REMI_RELEASE_RPM="https://mirrors.aliyun.com/remi/enterprise/remi-release-9.rpm" ;;
            esac
            info "安装 Remi 源（阿里云镜像）: ${REMI_RELEASE_RPM}"
            "$PKG_MANAGER" install -y "$REMI_RELEASE_RPM" || {
                error "Remi 源安装失败！无法安装 PHP 8.2"
                error "请检查网络连接或手动配置 Remi 源"
                exit 1
            }

            # Remi 源替换为阿里云镜像
            if [[ -f /etc/yum.repos.d/remi.repo ]]; then
                sed -i 's|http://rpms.remirepo.net|https://mirrors.aliyun.com/remi|g; s|mirrorlist=|#mirrorlist=|g' /etc/yum.repos.d/remi.repo
            fi
            for remi_file in /etc/yum.repos.d/remi-*.repo; do
                [[ -f "$remi_file" ]] && sed -i 's|http://rpms.remirepo.net|https://mirrors.aliyun.com/remi|g; s|mirrorlist=|#mirrorlist=|g' "$remi_file"
            done
            info "Remi 源已替换为阿里云镜像"

            if [[ "$OS_MAJOR_VER" == "7" ]]; then
                "$PKG_MANAGER" install -y yum-utils
                yum-config-manager --enable remi-php82 || {
                    error "启用 remi-php82 仓库失败"
                    exit 1
                }
            else
                "$PKG_MANAGER" module reset -y php || true
                "$PKG_MANAGER" module enable -y php:remi-8.2 || warn "启用 PHP 8.2 模块失败（可能需要手动启用）"
            fi

            install_pkgs \
                php php-cli php-common \
                php-mysqlnd php-curl \
                php-mbstring php-xml php-zip \
                php-bcmath php-gd php-intl \
                php-pear php-devel openssl-devel || {
                error "PHP 包安装失败！请检查 Remi 源配置"
                exit 1
            }
            # Redis 扩展
            "$PKG_MANAGER" install -y php-pecl-redis5 2>/dev/null || "$PKG_MANAGER" install -y php-redis 2>/dev/null || warn "Redis 扩展安装失败，可后续手动安装"
            ;;

        apk)
            # Alpine Linux
            install_pkgs \
                php82 php82-cli php82-common \
                php82-mysqlnd php82-pecl-redis php82-curl \
                php82-mbstring php82-xml php82-zip \
                php82-bcmath php82-gd php82-intl \
                php82-pecl swoole php82-pear php82-dev openssl-dev
            # Alpine 的 php82 没有创建 /usr/bin/php 软链
            ln -sf /usr/bin/php82 /usr/bin/php 2>/dev/null || true
            ;;

        zypper)
            # openSUSE
            install_pkgs \
                php8 php8-cli php8-mysql php8-redis php8-curl \
                php8-mbstring php8-xml php8-zip \
                php8-bcmath php8-gd php8-intl \
                php8-pear php8-devel libopenssl-devel
            ;;

        pacman)
            # Arch Linux/Manjaro
            install_pkgs \
                php php-cli \
                php-mysql php-redis php-curl \
                php-mbstring php-xml php-zip \
                php-bcmath php-gd php-intl \
                php-pear
            ;;
    esac

    # ============================================================
    # PHP 版本校验（防止 Remi 源失败导致装到 PHP 5.4）
    # ============================================================
    PHP_MAJOR_CHECK=$(php -v 2>/dev/null | head -n1 | awk '{print $2}' | cut -d. -f1)
    if [[ -z "$PHP_MAJOR_CHECK" || "$PHP_MAJOR_CHECK" -lt 8 ]]; then
        error "PHP 版本不满足要求（需 8.0+），当前: $(php -v 2>/dev/null | head -n1)"
        error "可能是 Remi/PPA 源安装失败，fallback 到了系统默认的旧版 PHP"
        error "请检查网络连接或手动安装 PHP 8.0+"
        exit 1
    fi
    info "PHP 版本校验通过: $(php -v | head -n1)"
else
    info "PHP 已安装，跳过"
fi

# 修复 PHP-FPM 运行用户（CentOS/RHEL 默认是 apache，需改为 $WEB_USER）
# Ubuntu/Debian 的 PHP-FPM 默认就是 www-data，无需修改
if [[ "$PKG_MANAGER" == "dnf" || "$PKG_MANAGER" == "yum" ]]; then
    PHP_FPM_WWW_CONF=""
    for _conf in /etc/php-fpm.d/www.conf /etc/php.d/*/php-fpm.d/www.conf /etc/php-fpm.d/*/www.conf; do
        if [[ -f "$_conf" ]]; then
            PHP_FPM_WWW_CONF="$_conf"
            break
        fi
    done
    if [[ -n "$PHP_FPM_WWW_CONF" && -f "$PHP_FPM_WWW_CONF" ]]; then
        if grep -q '^user = apache' "$PHP_FPM_WWW_CONF" 2>/dev/null; then
            sed -i "s/^user = apache/user = ${WEB_USER}/g; s/^group = apache/group = ${WEB_USER}/g" "$PHP_FPM_WWW_CONF"
            info "PHP-FPM 用户已修改为 ${WEB_USER}: ${PHP_FPM_WWW_CONF}"
        fi
    fi
fi

# ============================================================
# Swoole 扩展（通用：pecl 编译安装，国内镜像加速）
# ============================================================
if [[ "$SWOOLE_INSTALLED" != "true" ]]; then
    info "正在编译安装 Swoole 扩展..."

    # 先安装 Swoole 编译所需的依赖（编译工具 + brotli/zlib/openssl/libcurl 开发包）
    # Swoole 5.x 默认启用 brotli 和 swoole-curl，需要 libbrotli 和 libcurl >= 7.56.0
    # 最小化系统可能没有 gcc/make/autoconf，需一并安装
    case "$PKG_MANAGER" in
        apt-get)
            install_pkgs build-essential git libbrotli-dev libssl-dev zlib1g-dev libcurl4-openssl-dev libc-ares-dev 2>/dev/null || warn "部分编译依赖安装失败"
            ;;
        dnf|yum)
            install_pkgs gcc gcc-c++ make autoconf git brotli-devel openssl-devel zlib-devel libcurl-devel c-ares-devel 2>/dev/null || warn "部分编译依赖安装失败"
            # CentOS 7 特殊处理：gcc 4.8.5 不支持 C++11，需 devtoolset
            # centos-release-scl 源也可能失效，需要修复
            if [[ "$OS_ID" == "centos" && "$OS_MAJOR_VER" == "7" ]]; then
                info "CentOS 7: 安装 devtoolset（gcc 4.8.5 不支持 C++11）..."
                # 安装 SCL 源（CentOS 7 SCL 也已 EOL，需修复源）
                yum install -y centos-release-scl 2>/dev/null || true
                # 修复 SCL 源为阿里云镜像
                if [[ -f /etc/yum.repos.d/CentOS-SCLo-scl.repo ]]; then
                    sed -i 's|^mirrorlist=|#mirrorlist=|g' /etc/yum.repos.d/CentOS-SCLo-scl.repo
                    sed -i 's|^# baseurl=http://mirror.centos.org|baseurl=https://mirrors.aliyun.com|g' /etc/yum.repos.d/CentOS-SCLo-scl.repo
                    sed -i 's|/centos/|/centos-vault/|g' /etc/yum.repos.d/CentOS-SCLo-scl.repo 2>/dev/null || true
                fi
                if [[ -f /etc/yum.repos.d/CentOS-SCLo-scl-rh.repo ]]; then
                    sed -i 's|^mirrorlist=|#mirrorlist=|g' /etc/yum.repos.d/CentOS-SCLo-scl-rh.repo
                    sed -i 's|^# baseurl=http://mirror.centos.org|baseurl=https://mirrors.aliyun.com|g' /etc/yum.repos.d/CentOS-SCLo-scl-rh.repo
                    sed -i 's|/centos/|/centos-vault/|g' /etc/yum.repos.d/CentOS-SCLo-scl-rh.repo 2>/dev/null || true
                fi
                # 安装 devtoolset（11 优先，fallback 9/7）
                yum install -y devtoolset-11 2>/dev/null || yum install -y devtoolset-9 2>/dev/null || yum install -y devtoolset-7 2>/dev/null || warn "devtoolset 安装失败，Swoole 编译可能失败"
                # 安装 OpenSSL 1.1 开发包（EPEL 提供 openssl11-devel）
                yum install -y openssl11-devel 2>/dev/null || warn "openssl11-devel 安装失败，将使用系统默认 OpenSSL 1.0.2"
            fi
            ;;
        apk)
            install_pkgs build-base git brotli-dev openssl-dev zlib-dev curl-dev c-ares-dev 2>/dev/null || warn "部分编译依赖安装失败"
            ;;
        zypper)
            install_pkgs gcc gcc-c++ make autoconf git libbrotli-devel libopenssl-devel zlib-devel libcurl-devel libcares-devel 2>/dev/null || warn "部分编译依赖安装失败"
            ;;
        pacman)
            install_pkgs base-devel git brotli zlib openssl curl c-ares 2>/dev/null || warn "部分编译依赖安装失败"
            ;;
    esac

    # CentOS 7: 启用 devtoolset（提供 gcc 11，替代系统默认 gcc 4.8.5）
    if [[ "$OS_ID" == "centos" && "$OS_MAJOR_VER" == "7" ]]; then
        if [[ -f /opt/rh/devtoolset-11/enable ]]; then
            source /opt/rh/devtoolset-11/enable
            info "已启用 devtoolset-11: $(gcc --version | head -n1)"
        elif [[ -f /opt/rh/devtoolset-9/enable ]]; then
            source /opt/rh/devtoolset-9/enable
            info "已启用 devtoolset-9: $(gcc --version | head -n1)"
        elif [[ -f /opt/rh/devtoolset-7/enable ]]; then
            source /opt/rh/devtoolset-7/enable
            info "已启用 devtoolset-7: $(gcc --version | head -n1)"
        fi
    fi

    # 检测系统 libcurl 版本（Swoole swoole-curl 需要 >= 7.56.0）
    # CentOS 7 默认 libcurl 7.29.0 太旧，需要禁用 swoole-curl
    LIBCURL_VER=$(curl-config --version 2>/dev/null | awk '{print $2}' || echo "0")
    LIBCURL_MAJOR=$(echo "$LIBCURL_VER" | cut -d. -f1)
    LIBCURL_MINOR=$(echo "$LIBCURL_VER" | cut -d. -f2)
    SWOOLE_CURL_OPT="yes"
    if [[ -n "$LIBCURL_MAJOR" && -n "$LIBCURL_MINOR" ]]; then
        if [[ "$LIBCURL_MAJOR" -lt 7 || ("$LIBCURL_MAJOR" == "7" && "$LIBCURL_MINOR" -lt 56) ]]; then
            SWOOLE_CURL_OPT="no"
            warn "系统 libcurl ${LIBCURL_VER} < 7.56.0，禁用 swoole-curl（不影响 WebSocket/HTTP 核心功能）"
        else
            info "系统 libcurl ${LIBCURL_VER} >= 7.56.0，启用 swoole-curl"
        fi
    fi

    # 优先从源码编译 Swoole（更可控，避免 pecl 交互式提示）
    # 使用 gh.jasonzeng.dev 代理加速 GitHub 克隆（国内服务器优先）
    SWOOLE_SRC="/tmp/swoole-src"
    rm -rf "$SWOOLE_SRC"
    info "从 GitHub 克隆 Swoole 源码（优先使用代理）..."
    git clone --depth 1 https://gh.jasonzeng.dev/https://github.com/swoole/swoole-src.git "$SWOOLE_SRC" 2>/dev/null || \
        git clone --depth 1 https://github.com/swoole/swoole-src.git "$SWOOLE_SRC" 2>/dev/null || true

    SWOOLE_BUILD_SUCCESS=false
    if [[ -d "$SWOOLE_SRC" ]]; then
        cd "$SWOOLE_SRC"
        phpize

        # 构造 configure 参数
        # 注意：Swoole 6.x 的 openssl 选项是 --with-openssl-dir，不是 --enable-openssl
        # cares/brotli/curl 根据系统环境动态决定
        SWOOLE_CONFIGURE_OPTS="--enable-sockets=yes --enable-mysqlnd=yes --enable-swoole-curl=${SWOOLE_CURL_OPT} --enable-cares=no"

        # 尝试 1：启用 brotli + openssl（完整功能）
        info "尝试编译 Swoole（启用 openssl + brotli）..."
        if ./configure $SWOOLE_CONFIGURE_OPTS --enable-brotli=yes; then
            if make -j"$(nproc)" && make install; then
                SWOOLE_BUILD_SUCCESS=true
                info "Swoole 源码编译安装成功"
            fi
        fi

        # 尝试 2：禁用 brotli（减少依赖）
        if [[ "$SWOOLE_BUILD_SUCCESS" != "true" ]]; then
            warn "完整编译失败，尝试禁用 brotli..."
            make clean 2>/dev/null || true
            phpize --clean 2>/dev/null || true
            phpize
            if ./configure $SWOOLE_CONFIGURE_OPTS --enable-brotli=no; then
                if make -j"$(nproc)" && make install; then
                    SWOOLE_BUILD_SUCCESS=true
                    info "Swoole 源码编译安装成功（禁用 brotli）"
                fi
            fi
        fi

        # 尝试 3：最小化编译（禁用所有可选功能，只保留核心 WebSocket/HTTP）
        if [[ "$SWOOLE_BUILD_SUCCESS" != "true" ]]; then
            warn "禁用 brotli 也失败，尝试最小化编译..."
            make clean 2>/dev/null || true
            phpize --clean 2>/dev/null || true
            phpize
            if ./configure --enable-sockets=no --enable-mysqlnd=yes --enable-swoole-curl=no --enable-cares=no --enable-brotli=no; then
                if make -j"$(nproc)" && make install; then
                    SWOOLE_BUILD_SUCCESS=true
                    info "Swoole 源码编译安装成功（最小化模式，核心 WebSocket/HTTP 功能正常）"
                fi
            fi
        fi

        cd "${PROJECT_DIR}"
    fi

    # 如果源码编译失败，尝试 pecl 安装（预答参数禁用 cares/brotli）
    if [[ "$SWOOLE_BUILD_SUCCESS" != "true" ]]; then
        warn "源码编译失败，尝试 pecl 安装 Swoole（禁用 cares/brotli）..."
        # pecl 预答参数顺序（Swoole 6.x）：
        # 1. enable sockets? [no] -> yes
        # 2. openssl dir? [no] -> no
        # 3. enable mysqlnd? [no] -> yes
        # 4. enable curl? [no] -> ${SWOOLE_CURL_OPT}
        # 5. enable cares? [no] -> no (避免 libcares 依赖)
        # 6. enable brotli? [yes] -> no (避免 libbrotli 依赖)
        # 7. brotli dir? [no] -> no
        printf "yes\nno\nyes\n${SWOOLE_CURL_OPT}\nno\nno\nno\n" | pecl install swoole || {
            warn "pecl 安装 Swoole 也失败"
        }
    fi

    # 确保创建 swoole.ini 配置文件（make install 不会自动创建）
    # 即使编译成功，如果没有 ini 文件，php -m 也检测不到 swoole 扩展
    if ! php -m 2>/dev/null | grep -q '^swoole$'; then
        info "Swoole 编译成功但未加载，创建 swoole.ini 配置文件..."

        # 查找 swoole.so 的实际路径
        SWOOLE_SO_PATH=""
        PHP_EXT_DIR=$(php-config --extension-dir 2>/dev/null || echo "")
        if [[ -n "$PHP_EXT_DIR" && -f "${PHP_EXT_DIR}/swoole.so" ]]; then
            SWOOLE_SO_PATH="${PHP_EXT_DIR}/swoole.so"
            info "  找到 swoole.so: ${SWOOLE_SO_PATH}"
        else
            warn "  未找到 swoole.so，使用相对路径 extension=swoole.so"
        fi

        # 构造 extension 配置行
        if [[ -n "$SWOOLE_SO_PATH" ]]; then
            SWOOLE_EXT_LINE="extension=${SWOOLE_SO_PATH}"
        else
            SWOOLE_EXT_LINE="extension=swoole.so"
        fi

        # Debian/Ubuntu: 创建 mods-available + phpenmod 创建软链
        # CentOS/Alpine/openSUSE: 直接创建到 conf.d
        if command -v phpenmod >/dev/null 2>&1; then
            # Debian/Ubuntu 模式
            SWOOLE_PHP_VER="${PHP_ACTUAL_VER:-8.2}"
            MODS_DIR="/etc/php/${SWOOLE_PHP_VER}/mods-available"
            mkdir -p "$MODS_DIR" 2>/dev/null || true
            echo "$SWOOLE_EXT_LINE" > "${MODS_DIR}/swoole.ini"
            info "  已创建: ${MODS_DIR}/swoole.ini"
            # 为 SAPI（cli/fpm）创建软链
            phpenmod -v "${SWOOLE_PHP_VER}" swoole 2>/dev/null || true
            # 验证软链是否创建成功，如果失败则手动创建
            for SAPI in cli fpm; do
                CONF_D="/etc/php/${SWOOLE_PHP_VER}/${SAPI}/conf.d"
                mkdir -p "$CONF_D" 2>/dev/null || true
                if [[ ! -f "${CONF_D}/20-swoole.ini" && ! -f "${CONF_D}/50-swoole.ini" ]]; then
                    ln -sf "${MODS_DIR}/swoole.ini" "${CONF_D}/20-swoole.ini" 2>/dev/null || \
                        echo "$SWOOLE_EXT_LINE" > "${CONF_D}/20-swoole.ini"
                    info "  已创建: ${CONF_D}/20-swoole.ini"
                fi
            done
        else
            # 非 Debian 系：直接创建到 PHP_CONF_DIR
            mkdir -p "$PHP_CONF_DIR" 2>/dev/null || true
            echo "$SWOOLE_EXT_LINE" > "${PHP_CONF_DIR}/50-swoole.ini"
            info "  已创建: ${PHP_CONF_DIR}/50-swoole.ini"
        fi
    fi

    # 验证 Swoole 是否安装成功
    if ! php -m 2>/dev/null | grep -q '^swoole$'; then
        error "Swoole 扩展加载失败！核心服务无法启动。"
        error "  Swoole 编译可能成功，但 ini 配置文件未生效。"
        error "  请手动检查:"
        error "    1. php-config --extension-dir 查看扩展目录"
        error "    2. ls \$(php-config --extension-dir)/swoole.so 确认 .so 文件存在"
        error "    3. 在 PHP 配置目录创建 50-swoole.ini: extension=swoole.so"
        error "    4. php -m | grep swoole 验证扩展是否加载"
        exit 1
    fi
    info "Swoole 扩展已加载: $(php -m | grep swoole)"
else
    info "Swoole 扩展已安装，跳过"
fi

# ============================================================
# MySQL/MariaDB
# ============================================================
if [[ "$MYSQL_INSTALLED" != "true" ]]; then
    info "安装 MySQL/MariaDB..."
    case "$PKG_MANAGER" in
        apt-get)
            install_pkgs mysql-server
            ;;
        dnf|yum)
            # CentOS 7: base 源的 mariadb-server 是 5.5（过旧，不支持 CREATE USER IF NOT EXISTS）
            # 优先安装 MariaDB 10.x（通过 MariaDB 官方阿里云镜像源，稳定可靠）
            # MySQL 社区版需要额外配置源且 CentOS 7 兼容性差，故直接用 MariaDB 10.11
            if [[ "$OS_ID" == "centos" && "$OS_MAJOR_VER" == "7" ]]; then
                info "CentOS 7: 安装 MariaDB 10.11（阿里云镜像源）..."
                cat > /etc/yum.repos.d/MariaDB.repo <<MARIADB_EOF
[mariadb]
name = MariaDB
baseurl = https://mirrors.aliyun.com/mariadb/yum/10.11/centos7-amd64
gpgkey = https://mirrors.aliyun.com/mariadb/yum/RPM-GPG-KEY-MariaDB
gpgcheck = 1
MARIADB_EOF
                install_pkgs MariaDB-server MariaDB-client 2>/dev/null || install_pkgs mariadb-server
            else
                # CentOS 8/9 等：直接安装 mysql-server 或 mariadb-server
                install_pkgs mysql-server 2>/dev/null || install_pkgs mariadb-server
            fi
            ;;
        apk)       install_pkgs mariadb ;;
        zypper)    install_pkgs mariadb ;;
        pacman)    install_pkgs mariadb ;;
    esac
else
    info "MySQL 已安装，跳过"
fi

# ============================================================
# Redis
# ============================================================
if [[ "$REDIS_INSTALLED" != "true" ]]; then
    info "安装 Redis..."
    case "$PKG_MANAGER" in
        apt-get)   install_pkgs redis-server ;;
        dnf|yum)   install_pkgs redis ;;
        apk)       install_pkgs redis ;;
        zypper)    install_pkgs redis ;;
        pacman)    install_pkgs redis ;;
    esac
else
    info "Redis 已安装，跳过"
fi

# ============================================================
# Nginx
# ============================================================
if [[ "$NGINX_INSTALLED" != "true" ]]; then
    info "安装 Nginx..."
    install_pkgs nginx
else
    info "Nginx 已安装，跳过"
fi

# ============================================================
# Node.js（通过 npmmirror.com 二进制包安装，兼容所有发行版含 CentOS 7）
# CentOS 7 的 glibc 2.17 无法运行 Node.js 18+ 官方预编译（需 glibc 2.28+）
# 使用 npmmirror 提供的 unofficial-builds（glibc-217 兼容版）
# ============================================================
if [[ "$NODE_INSTALLED" != "true" ]]; then
    info "安装 Node.js 20.x LTS..."

    NODE_MAJOR_VER=20
    NODE_ARCH=$(uname -m)
    case "$NODE_ARCH" in
        x86_64)  NODE_ARCH="x64" ;;
        aarch64) NODE_ARCH="arm64" ;;
        *)       NODE_ARCH="x64" ;;
    esac

    # 检测 glibc 版本（CentOS 7 = 2.17）
    GLIBC_VER=$(ldd --version 2>/dev/null | head -n1 | awk '{print $NF}')
    GLIBC_MAJOR=$(echo "$GLIBC_VER" | cut -d. -f1)
    GLIBC_MINOR=$(echo "$GLIBC_VER" | cut -d. -f2)
    NEED_GLIBC217_BUILD=false
    if [[ "$GLIBC_MAJOR" == "2" && "$GLIBC_MINOR" -lt 28 ]]; then
        # glibc < 2.28（如 CentOS 7 的 2.17），需要 unofficial-builds
        NEED_GLIBC217_BUILD=true
        info "  检测到 glibc ${GLIBC_VER} < 2.28，使用 unofficial-builds（兼容 glibc 2.17）"
    fi

    if [[ "$NEED_GLIBC217_BUILD" == "true" ]]; then
        # CentOS 7 等 glibc 2.17 系统：使用 unofficial-builds 的 glibc-217 版本
        NODE_VERSION="v20.18.0"
        NODE_FILENAME="node-${NODE_VERSION}-linux-${NODE_ARCH}-glibc-217"
        NODE_URL="https://npmmirror.com/mirrors/node/${NODE_VERSION}/${NODE_FILENAME}.tar.gz"
    else
        # 标准系统：使用官方预编译
        NODE_VERSION="v20.18.0"
        NODE_FILENAME="node-${NODE_VERSION}-linux-${NODE_ARCH}"
        NODE_URL="https://npmmirror.com/mirrors/node/${NODE_VERSION}/${NODE_FILENAME}.tar.gz"
    fi

    info "  下载: ${NODE_URL}"
    if curl -fsSL "$NODE_URL" -o /tmp/node.tar.gz; then
        rm -rf /usr/local/lib/nodejs
        mkdir -p /usr/local/lib/nodejs
        tar -xzf /tmp/node.tar.gz -C /usr/local/lib/nodejs --strip-components=1
        rm -f /tmp/node.tar.gz

        # 创建符号链接到 /usr/local/bin
        ln -sf /usr/local/lib/nodejs/bin/node /usr/local/bin/node
        ln -sf /usr/local/lib/nodejs/bin/npm /usr/local/bin/npm
        ln -sf /usr/local/lib/nodejs/bin/npx /usr/local/bin/npx

        # 配置 npm 镜像加速
        npm config set registry https://registry.npmmirror.com 2>/dev/null || true

        info "  Node.js 安装完成: $(node -v)"
    else
        error "Node.js 二进制包下载失败"
        error "请手动安装 Node.js 16+ 后重新运行"
        exit 1
    fi
else
    info "Node.js 已安装，跳过"
fi

# ============================================================
# Composer（使用国内镜像下载，与发行版无关）
# ============================================================
if [[ "$COMPOSER_INSTALLED" != "true" ]]; then
    info "安装 Composer（国内镜像）..."
    # 优先使用阿里云 Composer 镜像
    if curl -fsSL https://mirrors.aliyun.com/composer/composer.phar -o /usr/local/bin/composer 2>/dev/null; then
        chmod +x /usr/local/bin/composer
        info "Composer 安装完成（阿里云镜像）"
    else
        # Fallback: 使用官方 installer
        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
        info "Composer 安装完成（官方源）"
    fi
    # 配置 Packagist 国内镜像（阿里云）
    composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/ 2>/dev/null || true
else
    info "Composer 已安装，跳过"
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
# 检测 MySQL 还是 MariaDB（MariaDB 不支持 IDENTIFIED WITH mysql_native_password 语法）
MYSQL_IS_MARIADB=false
if $MYSQL_ROOT_CMD --version 2>/dev/null | grep -qi mariadb; then
    MYSQL_IS_MARIADB=true
fi

if [[ -f "$ENV_FILE" ]]; then
    info "检测到已有 .env 配置，跳过数据库创建（复用现有数据库 ${DB_NAME}）"
    # 仅确保数据库存在（防止被手动删除）
    $MYSQL_ROOT_CMD -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null || warn "确保数据库存在时失败"
else
    info "创建数据库 ${DB_NAME} 和用户 ${DB_USER}..."
    if [[ "$MYSQL_IS_MARIADB" == "true" ]]; then
        # MariaDB 不支持 IDENTIFIED WITH mysql_native_password 语法
        # MariaDB 默认就是 mysql_native_password，直接用 IDENTIFIED BY 即可
        $MYSQL_ROOT_CMD <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
    else
        # MySQL 8.0+：显式指定 mysql_native_password 确保 PHP PDO 兼容
        $MYSQL_ROOT_CMD <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'${DB_HOST}' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'${DB_HOST}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED WITH mysql_native_password BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
    fi
    info "数据库与用户创建完成（${MYSQL_IS_MARIADB:+MariaDB}${MYSQL_IS_MARIADB:-MySQL}）。"
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

    # 检测并停止占用 80 端口的服务（Ubuntu 22.04 可能预装 Apache2）
    if systemctl is-active --quiet apache2 2>/dev/null; then
        warn "检测到 Apache2 正在运行（占用 80 端口），停止并禁用..."
        systemctl stop apache2 2>/dev/null || true
        systemctl disable apache2 2>/dev/null || true
    fi
    if systemctl is-active --quiet httpd 2>/dev/null; then
        warn "检测到 httpd 正在运行（占用 80 端口），停止并禁用..."
        systemctl stop httpd 2>/dev/null || true
        systemctl disable httpd 2>/dev/null || true
    fi

    # 确保日志目录存在
    mkdir -p /var/log/nginx 2>/dev/null || true

    # 启动前先检测配置语法（此时可能还没有 push.conf，但默认配置应能通过）
    if ! nginx -t 2>&1; then
        warn "Nginx 配置检测未通过（可能是残留配置问题），尝试启动..."
    fi

    # 启动 Nginx（失败不退出脚本，后续 step 6 会重新配置并启动）
    if ! systemctl start nginx 2>&1; then
        warn "Nginx 启动失败，将在步骤 6 配置完成后重新启动"
        warn "可能原因: 1)端口80被占用 2)配置语法错误 3)日志目录不存在"
        # 输出详细错误信息辅助排查
        journalctl -u nginx --no-pager -n 10 2>/dev/null || true
    else
        systemctl enable nginx 2>/dev/null || true
        info "Nginx 启动成功"
    fi
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
    sed -i "s/^DB_HOST=.*/DB_HOST=${DB_HOST}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^DB_PORT=.*/DB_PORT=${DB_PORT}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^DB_NAME=.*/DB_NAME=${DB_NAME}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^DB_USER=.*/DB_USER=${DB_USER}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^DB_PASS=.*/DB_PASS=${DB_PASS}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^REDIS_HOST=.*/REDIS_HOST=${REDIS_HOST}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^REDIS_PORT=.*/REDIS_PORT=${REDIS_PORT}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^HTTP_PORT=.*/HTTP_PORT=${HTTP_PORT}/" "${PROJECT_DIR}/backend/.env"
    sed -i "s/^WEBSOCKET_PORT=.*/WEBSOCKET_PORT=${WEBSOCKET_PORT}/" "${PROJECT_DIR}/backend/.env"
    info "已生成随机 JWT_SECRET 与 AES_KEY 并写入 .env"
fi

# 修复 .env 中含空格但未加引号的值（防止 dotenv 解析失败）
# 例如 MAIL_SENDER_NAME=Push 推送服务 会导致 Parser 报错
ENV_FILE="${PROJECT_DIR}/backend/.env"
if [[ -f "${ENV_FILE}" ]]; then
    # 找到所有"值=值 值"（值中有空格但未加引号）的行，自动加双引号
    # 仅处理非注释、非空行
    while IFS= read -r line; do
        # 跳过注释和空行
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ -z "$line" ]] && continue
        # 提取 KEY=VALUE
        if [[ "$line" =~ ^([A-Za-z_][A-Za-z0-9_]*)=(.+)$ ]]; then
            key="${BASH_REMATCH[1]}"
            val="${BASH_REMATCH[2]}"
            # 如果值含空格且未被引号包裹
            if [[ "$val" == *" "* && "$val" != \"* && "$val" != \'* ]]; then
                # 用双引号包裹（转义内部双引号）
                new_val="\"${val//\"/\\\"}\""
                sed -i "s|^${key}=.*|${key}=${new_val}|" "${ENV_FILE}"
                warn "  修复 .env: ${key} 值含空格已加引号"
            fi
        fi
    done < "${ENV_FILE}"
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
# 执行 seeders（初始数据：默认管理员账号等）
# ============================================================
SEEDERS_DIR="${PROJECT_DIR}/backend/database/seeders"
if [[ -d "${SEEDERS_DIR}" ]]; then
    info "执行 seeders（初始数据）..."
    SEED_COUNT=0
    shopt -s nullglob
    seed_files=("${SEEDERS_DIR}"/*.sql)
    shopt -u nullglob
    if [[ ${#seed_files[@]} -gt 0 ]]; then
        IFS=$'\n' sorted_seed_files=($(sort <<<"${seed_files[*]}"))
        unset IFS
        for seed_file in "${sorted_seed_files[@]}"; do
            filename=$(basename "${seed_file}")
            info "  执行: ${filename}"
            if $MYSQL_ROOT_CMD "${DB_NAME}" < "${seed_file}" 2>&1; then
                SEED_COUNT=$((SEED_COUNT + 1))
            else
                warn "  seeders 执行失败: ${filename}（可能已存在，跳过）"
            fi
        done
        info "seeders 执行完成（共 ${SEED_COUNT} 个文件）。"
        info "默认管理员账号: admin / admin123（请登录后立即修改密码）"
    else
        info "未找到 seeders 文件。"
    fi
fi

# ============================================================
# 步骤 4: 安装后端依赖（composer install，使用国内镜像加速）
# ============================================================
step "4/7" "安装后端依赖"

cd "${PROJECT_DIR}/backend"
# 允许 root 用户运行 composer（仅安装环境，无安全风险）
export COMPOSER_ALLOW_SUPERUSER=1
# 关闭安全公告阻断（国内镜像可能返回安全公告）
# Composer 2.4+ 使用 audit.block-insecure（旧版使用 policy.advisories.block，已废弃）
# 注意：--no-audit 命令行参数仅 Composer 2.7+ 支持，因此依赖配置而非命令行参数
composer config --global --no-interaction audit.block-insecure false 2>/dev/null || true
composer config --global --no-interaction policy.advisories.block false 2>/dev/null || true
# 同时为当前项目配置（全局配置可能被项目级配置覆盖）
composer config --no-interaction audit.block-insecure false 2>/dev/null || true
composer config --no-interaction policy.advisories.block false 2>/dev/null || true
# 配置 Packagist 阿里云镜像（如果之前未配置）
composer config --global repo.packagist composer https://mirrors.aliyun.com/composer/ 2>/dev/null || true
# 同时为当前项目配置镜像
composer config repo.packagist composer https://mirrors.aliyun.com/composer/ 2>/dev/null || true

# 同步 composer.lock（当 composer.json 变更后 lock 文件可能过期）
# --lock 仅更新 lock 文件的内容哈希，不会升级依赖版本
# 添加超时保护（120秒），避免网络问题导致卡死
timeout 120 composer update --lock --no-interaction --no-dev 2>/dev/null || warn "composer update --lock 超时或失败，继续 install..."
# composer install 添加超时保护（600秒=10分钟）
# 安全公告阻断已通过 audit.block-insecure false 配置关闭
info "执行 composer install（超时 10 分钟）..."
timeout 600 composer install --no-dev --optimize-autoloader --no-interaction || {
    error "composer install 失败（超时或网络问题）"
    error "请手动执行: cd ${PROJECT_DIR}/backend && composer install"
    exit 1
}
info "后端依赖安装完成。"

# ============================================================
# 步骤 5: 构建管理后台（npm install && npm run build）
# ============================================================
step "5/7" "构建管理后台"

cd "${PROJECT_DIR}/admin"
# 使用国内镜像加速（淘宝镜像）
npm config set registry https://registry.npmmirror.com

# 检查 node_modules/.bin 中的可执行文件权限
# npm 在 .bin 下创建符号链接，chmod -R 不跟随符号链接，需特殊处理
NEED_REINSTALL=false
if [[ -d node_modules/.bin ]]; then
    # 检测 .bin 下任意一个符号链接的目标是否有执行权限
    for bin_file in node_modules/.bin/*; do
        [[ -e "$bin_file" ]] || continue
        # 跟随符号链接检查目标文件是否可执行
        if ! [ -x "$bin_file" ]; then
            NEED_REINSTALL=true
            warn "检测到 $bin_file 无执行权限，将重新安装 node_modules"
            break
        fi
    done
fi

if [[ "$NEED_REINSTALL" == "true" ]]; then
    info "清理旧的 node_modules 并重新安装（修复权限问题）..."
    rm -rf node_modules
fi

# npm install 添加超时保护（10 分钟），防止网络问题导致卡死
info "执行 npm install（超时 10 分钟）..."
if ! timeout 600 npm install --no-audit --no-fund --loglevel=error 2>&1; then
    error "npm install 失败（超时或网络问题）"
    error "请手动执行: cd ${PROJECT_DIR}/admin && npm install"
    exit 1
fi

# 安装后确保 .bin 中所有文件可执行（跟随符号链接修复目标文件权限）
if [[ -d node_modules/.bin ]]; then
    find node_modules/.bin -type l | while read -r link; do
        target=$(readlink -f "$link" 2>/dev/null)
        [[ -f "$target" ]] && chmod +x "$target" 2>/dev/null || true
    done
    # 直接对 .bin 目录下的非符号链接文件也设置执行权限
    find node_modules/.bin -type f -exec chmod +x {} \; 2>/dev/null || true
fi

# npm run build 添加超时保护（5 分钟），防止构建卡死
info "执行 npm run build（超时 5 分钟）..."
if ! timeout 300 npm run build 2>&1; then
    error "npm run build 失败（超时或构建错误）"
    error "请手动执行: cd ${PROJECT_DIR}/admin && npm run build"
    exit 1
fi
info "管理后台构建完成。"

# ============================================================
# 步骤 6: 配置 systemd 服务与 Nginx
# ============================================================
step "6/7" "配置 systemd 服务与 Nginx"

# 复制 systemd 服务文件
SYSTEMD_SRC="${PROJECT_DIR}/deploy/systemd"
SYSTEMD_DST="/etc/systemd/system"
# 检测 systemd 版本（CentOS 7 = 219，不支持 MemoryMax/MemoryHigh/TasksMax/StartLimitIntervalSec）
SYSTEMD_VERSION=$(systemctl --version 2>/dev/null | head -n1 | awk '{print $2}')
SYSTEMD_VERSION=${SYSTEMD_VERSION:-0}
if [[ -d "${SYSTEMD_SRC}" ]]; then
    for svc_file in "${SYSTEMD_SRC}"/*.service; do
        [[ -f "${svc_file}" ]] || continue
        info "安装 systemd 服务: $(basename "${svc_file}")"
        DST_FILE="${SYSTEMD_DST}/$(basename "${svc_file}")"
        # 动态替换 User=/Group= 为当前发行版的 Web 用户（默认 www-data）
        sed "s/^User=www-data$/User=${WEB_USER}/g; s/^Group=www-data$/Group=${WEB_USER}/g" \
            "${svc_file}" > "$DST_FILE"
        # systemd < 227: 移除 cgroup 资源限制指令（MemoryMax/MemoryHigh/TasksMax/CPUQuota）
        # systemd < 230: 将 StartLimitIntervalSec 改为 StartLimitInterval（并移到 [Unit] 段）
        if [[ "$SYSTEMD_VERSION" -gt 0 && "$SYSTEMD_VERSION" -lt 227 ]]; then
            sed -i '/^MemoryMax=/d; /^MemoryHigh=/d; /^TasksMax=/d; /^CPUQuota=/d' "$DST_FILE"
            warn "systemd ${SYSTEMD_VERSION} < 227，已移除 cgroup 资源限制指令: $(basename "${svc_file}")"
        fi
        if [[ "$SYSTEMD_VERSION" -gt 0 && "$SYSTEMD_VERSION" -lt 230 ]]; then
            # 将 StartLimitIntervalSec 替换为 StartLimitInterval（systemd 219 兼容）
            sed -i 's/^StartLimitIntervalSec=/StartLimitInterval=/' "$DST_FILE"
        fi
    done
    systemctl daemon-reload
    info "systemd 服务已安装（运行用户: ${WEB_USER}，systemd ${SYSTEMD_VERSION}）。"
fi

# 复制 Nginx 配置
NGINX_SRC="${PROJECT_DIR}/deploy/nginx/push.conf"
NGINX_INSTALLED_PATH=""
if [[ -f "${NGINX_SRC}" ]]; then
    # 根据发行版选择 Nginx 配置目录
    if [[ -d "/etc/nginx/sites-available" ]]; then
        cp "${NGINX_SRC}" /etc/nginx/sites-available/push.conf
        ln -sf /etc/nginx/sites-available/push.conf /etc/nginx/sites-enabled/push.conf
        rm -f /etc/nginx/sites-enabled/default
        NGINX_INSTALLED_PATH="/etc/nginx/sites-available/push.conf"
    elif [[ -d "/etc/nginx/conf.d" ]]; then
        cp "${NGINX_SRC}" /etc/nginx/conf.d/push.conf
        NGINX_INSTALLED_PATH="/etc/nginx/conf.d/push.conf"
    elif [[ -d "/etc/nginx/http.d" ]]; then
        # Alpine Linux
        cp "${NGINX_SRC}" /etc/nginx/http.d/push.conf
        NGINX_INSTALLED_PATH="/etc/nginx/http.d/push.conf"
    elif [[ -d "/etc/nginx/vhosts.d" ]]; then
        # openSUSE
        cp "${NGINX_SRC}" /etc/nginx/vhosts.d/push.conf
        NGINX_INSTALLED_PATH="/etc/nginx/vhosts.d/push.conf"
    else
        cp "${NGINX_SRC}" /etc/nginx/push.conf
        NGINX_INSTALLED_PATH="/etc/nginx/push.conf"
    fi
    info "Nginx 配置已安装到: ${NGINX_INSTALLED_PATH}"
    # 校验并重新加载/启动
    if nginx -t 2>&1; then
        # 如果 Nginx 未运行则启动，已运行则 reload
        if ! systemctl is-active --quiet nginx; then
            systemctl start nginx && systemctl enable nginx 2>/dev/null || true
            info "Nginx 已启动。"
        else
            systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
            info "Nginx 已重新加载。"
        fi
    else
        error "Nginx 配置校验失败，请检查 ${NGINX_INSTALLED_PATH}"
        error "请根据实际域名修改 server_name 后重试。"
        # 输出详细错误
        nginx -t 2>&1 || true
    fi
fi

# 设置项目目录权限（使用检测到的 Web 用户）
# 注意：chown 整个项目目录会破坏 .git 权限导致后续 git fetch 失败
# 需要保留 .git / node_modules / vendor 的原属主，仅 chown 运行时需要的目录
# 检测项目目录的当前属主（通常是部署用户，如 ubuntu）
PROJECT_OWNER=$(stat -c '%U' "${PROJECT_DIR}" 2>/dev/null || echo "ubuntu")
info "项目目录属主: ${PROJECT_OWNER}，Web 用户: ${WEB_USER}"

# 仅 chown 后端运行时需要的目录（保持 .git 等目录的原属主）
# 注意：app 目录需要 www-data 可写（BuildWorker 需要写入 assets 和 signing.gradle）
#       build 目录需要 www-data 可写（构建日志、输出、keystore）
chown -R "${WEB_USER}:${WEB_USER}" \
    "${PROJECT_DIR}/backend/storage" \
    "${PROJECT_DIR}/backend/runtime" \
    "${PROJECT_DIR}/build" \
    "${PROJECT_DIR}/app" \
    2>/dev/null || true

# 创建运行时所需目录（如不存在）
mkdir -p "${PROJECT_DIR}/backend/runtime/logs" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/backend/storage" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/build/logs" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/build/output" 2>/dev/null || true
mkdir -p "${PROJECT_DIR}/app/src/main/assets" 2>/dev/null || true
# Gradle 项目级缓存目录（www-data 用户需要写入权限）
mkdir -p "${PROJECT_DIR}/.gradle" 2>/dev/null || true
# Gradle 用户级缓存目录（www-data home 目录下，用于存放 native 库和全局缓存）
if [[ "${WEB_USER}" == "www-data" ]]; then
    mkdir -p /var/www/.gradle 2>/dev/null || true
    chown -R www-data:www-data /var/www 2>/dev/null || true
elif [[ "${WEB_USER}" == "nginx" ]]; then
    # CentOS/RHEL 系 nginx 用户的 home 目录
    NGINX_HOME=$(getent passwd nginx 2>/dev/null | cut -d: -f6 || echo "/var/lib/nginx")
    mkdir -p "${NGINX_HOME}/.gradle" 2>/dev/null || true
    chown -R nginx:nginx "${NGINX_HOME}" 2>/dev/null || true
fi
chown -R "${WEB_USER}:${WEB_USER}" "${PROJECT_DIR}/backend/runtime" 2>/dev/null || true
chown -R "${WEB_USER}:${WEB_USER}" "${PROJECT_DIR}/build/logs" "${PROJECT_DIR}/build/output" 2>/dev/null || true
chown -R "${WEB_USER}:${WEB_USER}" "${PROJECT_DIR}/app/src/main/assets" 2>/dev/null || true
chown -R "${WEB_USER}:${WEB_USER}" "${PROJECT_DIR}/.gradle" 2>/dev/null || true

# 设置目录权限（仅对运行时目录，避免递归整个项目）
find "${PROJECT_DIR}/backend/storage" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/backend/runtime" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/build" -type d -exec chmod 775 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/app" -type d -exec chmod 775 {} \; 2>/dev/null || true
# storage/runtime 下的文件可写
find "${PROJECT_DIR}/backend/storage" -type f -exec chmod 664 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/backend/runtime" -type f -exec chmod 664 {} \; 2>/dev/null || true
find "${PROJECT_DIR}/build" -type f -exec chmod 664 {} \; 2>/dev/null || true
# build 目录下的 .sh 脚本保持可执行
find "${PROJECT_DIR}/build" -type f -name "*.sh" -exec chmod 755 {} \; 2>/dev/null || true
# app 目录下的 .gradle / .properties 文件可读
find "${PROJECT_DIR}/app" -type f \( -name "*.gradle" -o -name "*.properties" -o -name "*.gradle.kts" \) -exec chmod 644 {} \; 2>/dev/null || true
# 保留 .sh 脚本的可执行权限
find "${PROJECT_DIR}" -type f -name "*.sh" -exec chmod 755 {} \; 2>/dev/null || true
info "目录权限已设置（保留 .git 属主: ${PROJECT_OWNER}）。"

# ============================================================
# 步骤 7: 启动服务
# ============================================================
step "7/7" "启动服务"

# 启动 PHP-FPM（用于 opcache 管理）
# 优先使用检测到的服务名，再尝试常见服务名
PHP_FPM_STARTED=false
for _svc in "${PHP_FPM_SERVICE}" php8.2-fpm php8.1-fpm php8.0-fpm php-fpm php-fpm82 php-fpm81 php-fpm80; do
    [[ -z "$_svc" ]] && continue
    if systemctl list-unit-files 2>/dev/null | grep -q "${_svc}"; then
        systemctl restart "${_svc}" 2>/dev/null || true
        systemctl enable "${_svc}" 2>/dev/null || true
        info "${_svc} 已启动。"
        PHP_FPM_STARTED=true
        break
    fi
done
if [[ "$PHP_FPM_STARTED" == "false" ]]; then
    # Alpine: OpenRC 兼容
    if command -v rc-service >/dev/null 2>&1 && rc-service php-fpm82 status >/dev/null 2>&1; then
        rc-service php-fpm82 restart
        rc-update add php-fpm82 default 2>/dev/null || true
        info "php-fpm82 已启动（OpenRC）。"
    elif command -v rc-service >/dev/null 2>&1 && rc-service php-fpm status >/dev/null 2>&1; then
        rc-service php-fpm restart
        rc-update add php-fpm default 2>/dev/null || true
        info "php-fpm 已启动（OpenRC）。"
    else
        warn "未找到 PHP-FPM 服务，跳过（Swoole 独立运行，不影响核心功能）。"
    fi
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

    # 创建 swap 交换分区（2G 内存服务器构建 Android 时防止 OOM 卡死）
    # 3G swap：JVM 堆 768m + 系统服务 ~1G，swap 兜底构建峰值内存
    SWAP_SIZE_MB=3072
    CURRENT_SWAP=$(swapon --show 2>/dev/null | tail -n +2 | wc -l)
    TOTAL_SWAP_MB=$(awk '/SwapTotal/{print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
    if [[ "${CURRENT_SWAP}" -eq 0 ]] && [[ "${TOTAL_SWAP_MB}" -lt 2048 ]]; then
        info "创建 ${SWAP_SIZE_MB}MB swap 交换分区（防止 Android 构建时 OOM）..."
        if fallocate -l "${SWAP_SIZE_MB}M" /swapfile 2>/dev/null; then
            chmod 600 /swapfile
            mkswap /swapfile
            swapon /swapfile
            # 持久化到 fstab
            if ! grep -q '/swapfile' /etc/fstab; then
                echo '/swapfile none swap sw 0 0' >> /etc/fstab
            fi
            # swappiness=20：构建期间适度使用 swap，避免 OOM 但不过度拖慢
            sysctl -w vm.swappiness=20 >/dev/null 2>&1 || true
            # 持久化 swappiness
            if ! grep -q 'vm.swappiness' /etc/sysctl.conf 2>/dev/null; then
                echo 'vm.swappiness=20' >> /etc/sysctl.conf
            fi
            info "swap 创建完成（${SWAP_SIZE_MB}MB）"
        else
            warn "swap 创建失败（fallocate 不支持），建议手动创建 swap"
        fi
    else
        info "swap 已存在（${TOTAL_SWAP_MB}MB），跳过创建"
        # 确保 swappiness 设置合理
        sysctl -w vm.swappiness=20 >/dev/null 2>&1 || true
    fi

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
        # 动态替换 www-data 为当前发行版的 Web 用户
        # 注意：先用 dos2unix 风格去除可能的 CRLF 换行符（Windows 编辑过的文件）
        # CRLF 会导致 visudo 报 "syntax error" 而 sudoers 文件被拒绝
        sed "s/\bwww-data\b/${WEB_USER}/g; s/\r$//" "${SUDOERS_FILE}" > /etc/sudoers.d/push-system
        chmod 440 /etc/sudoers.d/push-system
        # 校验语法（捕获详细错误输出便于诊断）
        SUDOERS_ERR=$(visudo -c -f /etc/sudoers.d/push-system 2>&1) || true
        if echo "${SUDOERS_ERR}" | grep -q "parsed OK\|syntax OK"; then
            info "sudoers 配置已安装（用户: ${WEB_USER}）"
        else
            warn "sudoers 语法检查失败，已移除配置"
            warn "详细错误: ${SUDOERS_ERR}"
            warn "（常见原因: CRLF 换行符、用户名不存在、命令路径不存在）"
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
echo "sudoers:    ${WEB_USER} 权限已配置  [已安装]"
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
