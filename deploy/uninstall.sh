#!/bin/bash
# ============================================================
# 即时消息推送系统 - 卸载脚本
#
# 用法:
#   bash deploy/uninstall.sh              # 交互式选择卸载模式
#   bash deploy/uninstall.sh --env         # 仅卸载环境(保留源码)
#   bash deploy/uninstall.sh --source      # 仅卸载源码(保留环境)
#   bash deploy/uninstall.sh --all         # 完全卸载(环境+源码)
#   bash deploy/uninstall.sh --yes         # 跳过确认
#
# 卸载内容:
#   环境: 停止并禁用 systemd 服务、删除服务文件、卸载 PHP/Swoole/
#         MySQL/Redis/Nginx/Node/Composer/JDK/Android SDK/Gradle
#   源码: 删除项目目录 /www/push-system
#   配置: Nginx 配置、sudoers、cron、swap(可选)
# ============================================================
set -e

# ------------------------------------------------------------
# 配置项
# ------------------------------------------------------------
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
UNINSTALL_MODE=""
SKIP_CONFIRM=""

# 解析参数
for arg in "$@"; do
    case $arg in
        --env)    UNINSTALL_MODE="env" ;;
        --source) UNINSTALL_MODE="source" ;;
        --all)    UNINSTALL_MODE="all" ;;
        --yes)    SKIP_CONFIRM="1" ;;
        -h|--help)
            head -n 20 "$0"
            exit 0
            ;;
        *) echo "未知参数: $arg" >&2; exit 1 ;;
    esac
done

# ------------------------------------------------------------
# 前置检查:必须 root
# ------------------------------------------------------------
if [[ $EUID -ne 0 ]]; then
    echo "[ERROR] 此脚本必须以 root 权限运行,请使用 sudo"
    exit 1
fi

# ------------------------------------------------------------
# 颜色输出
# ------------------------------------------------------------
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_CYAN='\033[0;36m'
COLOR_RESET='\033[0m'

info()  { echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }
step()  { echo -e "\n${COLOR_GREEN}===== [$1] $2 =====${COLOR_RESET}"; }

# ------------------------------------------------------------
# 交互式选择卸载模式(未通过参数指定时)
# ------------------------------------------------------------
if [[ -z "$UNINSTALL_MODE" ]]; then
    echo ""
    echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
    echo -e "${COLOR_CYAN}  即时消息推送系统 - 卸载向导${COLOR_RESET}"
    echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
    echo ""
    echo "请选择卸载模式:"
    echo ""
    echo -e "  ${COLOR_GREEN}1.${COLOR_RESET} 仅卸载环境(保留源码)   - 停止服务+卸载 PHP/MySQL/Redis/Nginx 等"
    echo -e "  ${COLOR_GREEN}2.${COLOR_RESET} 仅卸载源码(保留环境)   - 删除 /www/push-system 项目目录"
    echo -e "  ${COLOR_GREEN}3.${COLOR_RESET} 完全卸载(环境+源码)   - 彻底清除所有内容"
    echo -e "  ${COLOR_GREEN}0.${COLOR_RESET} 取消"
    echo ""
    read -p "请输入选项 [0-3]: " choice < /dev/tty
    case "$choice" in
        1) UNINSTALL_MODE="env" ;;
        2) UNINSTALL_MODE="source" ;;
        3) UNINSTALL_MODE="all" ;;
        *) echo "已取消"; exit 0 ;;
    esac
fi

# ------------------------------------------------------------
# 二次确认(危险操作)
# ------------------------------------------------------------
if [[ "$SKIP_CONFIRM" != "1" ]]; then
    echo ""
    case "$UNINSTALL_MODE" in
        env)
            warn "即将卸载运行环境(将停止并删除以下服务/软件):"
            echo "  - systemd 服务: push-http, push-websocket, push-build-worker"
            echo "  - PHP 8.x + Swoole 扩展"
            echo "  - MySQL/MariaDB(数据库数据将保留)"
            echo "  - Redis"
            echo "  - Nginx(配置文件将删除)"
            echo "  - Node.js + npm"
            echo "  - Composer"
            echo "  - JDK + Android SDK + Gradle(如已安装)"
            echo "  - 系统配置: sudoers、cron、swap"
            echo "  源码目录 ${PROJECT_DIR} 将保留"
            ;;
        source)
            warn "即将删除源码目录: ${PROJECT_DIR}"
            echo "  - 项目所有代码、配置、.env 将被删除"
            echo "  - 数据库数据保留(MySQL 服务不卸载)"
            echo "  - 运行环境(PHP/MySQL/Redis/Nginx 等)保留"
            ;;
        all)
            error "⚠️  警告: 完全卸载将删除以下所有内容:"
            echo "  - 源码目录: ${PROJECT_DIR}"
            echo "  - 数据库: im_push(包含所有用户、消息、设备数据)"
            echo "  - 运行环境: PHP/Swoole/MySQL/Redis/Nginx/Node/Composer"
            echo "  - APP 构建环境: JDK/Android SDK/Gradle"
            echo "  - 系统配置: systemd 服务、Nginx 配置、sudoers、cron、swap"
            echo "  此操作不可逆!"
            ;;
    esac
    echo ""
    read -p "确认执行卸载? 输入 'yes' 继续,其他取消: " reply < /dev/tty
    if [[ "$reply" != "yes" ]]; then
        echo "已取消"
        exit 0
    fi
fi

# ============================================================
# 卸载环境(停止服务 + 卸载软件包)
# ============================================================
uninstall_env() {
    step "1/6" "停止并禁用 systemd 服务"

    for svc in push-http push-websocket push-build-worker; do
        if systemctl list-unit-files 2>/dev/null | grep -q "$svc"; then
            systemctl stop "$svc" 2>/dev/null || true
            systemctl disable "$svc" 2>/dev/null || true
            rm -f "/etc/systemd/system/${svc}.service"
            info "已移除服务: $svc"
        fi
    done
    systemctl daemon-reload
    systemctl reset-failed 2>/dev/null || true
    info "systemd 服务已清理"

    # ------------------------------------------------------------
    step "2/6" "删除 Nginx 配置"
    rm -f /etc/nginx/sites-enabled/push.conf 2>/dev/null || true
    rm -f /etc/nginx/sites-available/push.conf 2>/dev/null || true
    rm -f /etc/nginx/conf.d/push.conf 2>/dev/null || true
    rm -f /etc/nginx/http.d/push.conf 2>/dev/null || true
    rm -f /etc/nginx/vhosts.d/push.conf 2>/dev/null || true
    rm -f /etc/nginx/push.conf 2>/dev/null || true
    if command -v nginx >/dev/null 2>&1; then
        nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null || true
    fi
    info "Nginx 配置已删除"

    # ------------------------------------------------------------
    step "3/6" "删除 sudoers 与 cron 配置"
    rm -f /etc/sudoers.d/push-system 2>/dev/null || true
    rm -f /etc/sudoers.d/push-system-ssl 2>/dev/null || true
    rm -f /etc/cron.d/push-ssl-renew 2>/dev/null || true
    info "sudoers 与 cron 已清理"

    # ------------------------------------------------------------
    step "4/6" "删除 swap(如由安装脚本创建)"
    if swapon --show 2>/dev/null | grep -q '/swapfile'; then
        swapoff /swapfile 2>/dev/null || true
        rm -f /swapfile 2>/dev/null || true
        sed -i '/\/swapfile/d' /etc/fstab 2>/dev/null || true
        sed -i '/vm.swappiness/d' /etc/sysctl.conf 2>/dev/null || true
        info "swap 已删除"
    else
        info "未检测到项目 swap,跳过"
    fi

    # ------------------------------------------------------------
    step "5/6" "卸载运行环境软件包"
    # 检测包管理器
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
    fi

    if [[ -n "$PKG_MANAGER" ]]; then
        info "使用包管理器: $PKG_MANAGER"

        # 询问是否卸载数据库(默认保留,避免误删数据)
        REMOVE_DB="n"
        if [[ "$UNINSTALL_MODE" == "all" ]]; then
            # 完全卸载时询问是否删除数据库
            read -p "是否同时卸载数据库 MySQL/MariaDB 并删除数据库文件?(危险! 输入 'yes' 确认) [no]: " reply < /dev/tty
            [[ "$reply" == "yes" ]] && REMOVE_DB="y"
        fi

        case "$PKG_MANAGER" in
            apt-get)
                # 卸载 PHP 及扩展
                apt-get purge -y \
                    php8.2* php8.1* php8.0* \
                    php-pear php-dev \
                    libssl-dev zlib1g-dev libcurl4-openssl-dev 2>/dev/null || true
                # 卸载 Composer
                rm -f /usr/local/bin/composer 2>/dev/null || true
                # 卸载 Node.js
                rm -rf /usr/local/lib/nodejs 2>/dev/null || true
                rm -f /usr/local/bin/node /usr/local/bin/npm /usr/local/bin/npx 2>/dev/null || true
                # 卸载 Android 构建环境
                rm -rf /opt/android-sdk /opt/gradle-8.7 2>/dev/null || true
                # 卸载 JDK(谨慎,其他软件可能依赖)
                if [[ "$UNINSTALL_MODE" == "all" ]]; then
                    apt-get purge -y openjdk-17-* openjdk-11-* 2>/dev/null || true
                fi
                # 卸载 Nginx
                if [[ "$UNINSTALL_MODE" == "all" ]]; then
                    apt-get purge -y nginx nginx-common nginx-core 2>/dev/null || true
                else
                    info "保留 Nginx(仅删除项目配置)"
                fi
                # 卸载数据库
                if [[ "$REMOVE_DB" == "y" ]]; then
                    apt-get purge -y mysql-server mysql-client mariadb-server 2>/dev/null || true
                    rm -rf /var/lib/mysql /var/log/mysql /etc/mysql 2>/dev/null || true
                    warn "数据库已完全卸载(数据已删除)"
                else
                    info "保留数据库(数据未删除)"
                fi
                # 卸载 Redis
                if [[ "$UNINSTALL_MODE" == "all" ]]; then
                    apt-get purge -y redis-server redis-tools 2>/dev/null || true
                fi
                apt-get autoremove -y 2>/dev/null || true
                ;;
            dnf|yum)
                $PKG_MANAGER remove -y \
                    php* php-* php-pecl-redis5 \
                    php-pear php-devel 2>/dev/null || true
                rm -f /usr/local/bin/composer 2>/dev/null || true
                rm -rf /usr/local/lib/nodejs /usr/local/bin/node /usr/local/bin/npm /usr/local/bin/npx 2>/dev/null || true
                rm -rf /opt/android-sdk /opt/gradle-8.7 2>/dev/null || true
                if [[ "$UNINSTALL_MODE" == "all" ]]; then
                    $PKG_MANAGER remove -y java-17-openjdk java-17-openjdk-devel nginx 2>/dev/null || true
                    if [[ "$REMOVE_DB" == "y" ]]; then
                        $PKG_MANAGER remove -y mysql-server mariadb-server redis 2>/dev/null || true
                        rm -rf /var/lib/mysql /var/log/mysqld.log /etc/my.cnf 2>/dev/null || true
                    fi
                fi
                $PKG_MANAGER autoremove -y 2>/dev/null || true
                ;;
            apk)
                apk del --no-cache \
                    php82* php82-pecl-* php-pear php82-dev \
                    composer 2>/dev/null || true
                rm -rf /usr/local/lib/nodejs /usr/local/bin/node /usr/local/bin/npm 2>/dev/null || true
                rm -rf /opt/android-sdk /opt/gradle-8.7 2>/dev/null || true
                if [[ "$UNINSTALL_MODE" == "all" ]]; then
                    apk del --no-cache nginx openjdk17 2>/dev/null || true
                    if [[ "$REMOVE_DB" == "y" ]]; then
                        apk del --no-cache mariadb redis 2>/dev/null || true
                        rm -rf /var/lib/mysql 2>/dev/null || true
                    fi
                fi
                ;;
            zypper)
                zypper remove -y \
                    php8* php8-* php8-pear php8-devel \
                    composer 2>/dev/null || true
                rm -rf /usr/local/lib/nodejs /usr/local/bin/node /usr/local/bin/npm 2>/dev/null || true
                rm -rf /opt/android-sdk /opt/gradle-8.7 2>/dev/null || true
                if [[ "$UNINSTALL_MODE" == "all" ]]; then
                    zypper remove -y nginx java-17-openjdk 2>/dev/null || true
                    if [[ "$REMOVE_DB" == "y" ]]; then
                        zypper remove -y mariadb redis 2>/dev/null || true
                        rm -rf /var/lib/mysql 2>/dev/null || true
                    fi
                fi
                ;;
        esac
        info "软件包卸载完成"
    else
        warn "未检测到包管理器,跳过软件包卸载"
    fi

    # ------------------------------------------------------------
    step "6/6" "清理残留目录"
    rm -rf /var/www/.gradle 2>/dev/null || true
    rm -rf /var/www/.npm 2>/dev/null || true
    rm -rf /var/www/.composer 2>/dev/null || true
    rm -rf /tmp/swoole-src 2>/dev/null || true
    rm -rf /root/.npm 2>/dev/null || true
    rm -rf /root/.composer 2>/dev/null || true
    info "残留目录已清理"
}

# ============================================================
# 卸载源码(删除项目目录)
# ============================================================
uninstall_source() {
    step "1/1" "删除项目源码目录"

    if [[ ! -d "$PROJECT_DIR" ]]; then
        warn "项目目录不存在: $PROJECT_DIR"
        return 0
    fi

    # 删除数据库(完全卸载时)
    if [[ "$UNINSTALL_MODE" == "all" ]]; then
        # 读取 .env 获取数据库名
        ENV_FILE="${PROJECT_DIR}/backend/.env"
        DB_NAME="im_push"
        if [[ -f "$ENV_FILE" ]]; then
            ENV_DB_NAME=$(grep -E "^DB_NAME=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' || echo "")
            [[ -n "$ENV_DB_NAME" ]] && DB_NAME="$ENV_DB_NAME"
        fi

        if command -v mysql >/dev/null 2>&1; then
            if mysql -uroot -e "SELECT 1" >/dev/null 2>&1 || \
               sudo mysql -e "SELECT 1" >/dev/null 2>&1; then
                MYSQL_CMD="mysql -uroot"
                sudo mysql -e "SELECT 1" >/dev/null 2>&1 && MYSQL_CMD="sudo mysql"

                info "删除数据库: $DB_NAME"
                $MYSQL_CMD -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" 2>/dev/null || true
                # 删除数据库用户
                $MYSQL_CMD -e "DROP USER IF EXISTS 'im_push'@'localhost';" 2>/dev/null || true
                $MYSQL_CMD -e "DROP USER IF EXISTS 'im_push'@'127.0.0.1';" 2>/dev/null || true
                info "数据库已删除"
            else
                warn "无法连接 MySQL,跳过数据库删除(需手动执行: DROP DATABASE $DB_NAME;)"
            fi
        fi
    fi

    # 删除项目目录
    info "删除项目目录: $PROJECT_DIR"
    rm -rf "$PROJECT_DIR"
    info "源码已删除"
}

# ============================================================
# 执行卸载
# ============================================================
case "$UNINSTALL_MODE" in
    env)
        uninstall_env
        ;;
    source)
        uninstall_source
        ;;
    all)
        uninstall_env
        uninstall_source
        ;;
esac

# ============================================================
# 完成
# ============================================================
echo ""
echo -e "${COLOR_GREEN}============================================================${COLOR_RESET}"
echo -e "${COLOR_GREEN}  卸载完成${COLOR_RESET}"
echo -e "${COLOR_GREEN}============================================================${COLOR_RESET}"
echo ""
case "$UNINSTALL_MODE" in
    env)
        echo "已卸载:运行环境(服务+软件包)"
        echo "已保留:源码目录 $PROJECT_DIR"
        echo "已保留:数据库数据"
        echo ""
        info "如需重新安装环境,执行: sudo bash ${PROJECT_DIR}/deploy/install.sh"
        ;;
    source)
        echo "已删除:源码目录 $PROJECT_DIR"
        echo "已保留:运行环境(PHP/MySQL/Redis/Nginx 等)"
        echo "已保留:数据库数据"
        ;;
    all)
        echo "已完全卸载所有内容"
        echo "  - 源码、配置、环境、数据库全部删除"
        echo ""
        warn "如需重新部署,需重新克隆代码并执行安装"
    ;;
esac
echo ""
