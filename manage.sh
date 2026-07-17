#!/bin/bash
# ============================================================
# 即时消息推送系统 - 交互式管理脚本
#
# 用法:
#   bash manage.sh               # 交互式菜单
#   bash manage.sh --menu        # 直接显示主菜单
#
# 功能:通过数字菜单管理服务器的环境、代码、服务
# ============================================================

# ------------------------------------------------------------
# 项目目录定位
# ------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="${PROJECT_DIR:-$SCRIPT_DIR}"
cd "$PROJECT_DIR" 2>/dev/null || {
    echo "[ERROR] 无法进入项目目录: $PROJECT_DIR"
    exit 1
}

# ------------------------------------------------------------
# 颜色输出
# ------------------------------------------------------------
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_BLUE='\033[0;34m'
COLOR_CYAN='\033[0;36m'
COLOR_PURPLE='\033[0;35m'
COLOR_RESET='\033[0m'

info()  { echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }

# ------------------------------------------------------------
# 检查 root 权限(部分操作需要)
# ------------------------------------------------------------
check_root() {
    if [[ $EUID -ne 0 ]]; then
        warn "此操作需要 root 权限,正在使用 sudo 重新执行..."
        exec sudo bash "$0" "$@"
    fi
}

# ------------------------------------------------------------
# 服务状态查询
# ------------------------------------------------------------
show_service_status() {
    echo ""
    echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
    echo -e "${COLOR_CYAN}  服务状态${COLOR_RESET}"
    echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
    echo ""

    # 检测 Web 用户
    WEB_USER="www-data"
    if id -u nginx >/dev/null 2>&1; then
        WEB_USER="nginx"
    fi

    # 核心服务状态
    for svc in push-http push-websocket push-build-worker; do
        if systemctl list-unit-files 2>/dev/null | grep -q "$svc"; then
            if systemctl is-active --quiet "$svc" 2>/dev/null; then
                echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc}"
            else
                echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc}  [未运行]"
            fi
        else
            echo -e "  ${COLOR_YELLOW}●${COLOR_RESET} ${svc}  [未安装]"
        fi
    done
    echo ""

    # 系统服务
    for svc in nginx redis-server redis mysqld mysql mariadb; do
        if systemctl list-unit-files 2>/dev/null | grep -q "^$svc"; then
            if systemctl is-active --quiet "$svc" 2>/dev/null; then
                echo -e "  ${COLOR_GREEN}●${COLOR_RESET} ${svc}"
            else
                echo -e "  ${COLOR_RED}●${COLOR_RESET} ${svc}  [未运行]"
            fi
        fi
    done
    echo ""

    # 磁盘空间
    echo -e "${COLOR_CYAN}----- 磁盘空间 -----${COLOR_RESET}"
    df -h "$PROJECT_DIR" | tail -1 | awk '{printf "  总计: %s  已用: %s  可用: %s  使用率: %s\n", $2, $3, $4, $5}'
    echo ""

    # 内存
    echo -e "${COLOR_CYAN}----- 内存 -----${COLOR_RESET}"
    free -m | awk 'NR==1 || NR==2' | sed 's/^/  /'
    echo ""

    # Git 版本
    if [[ -d "$PROJECT_DIR/.git" ]]; then
        echo -e "${COLOR_CYAN}----- 当前代码版本 -----${COLOR_RESET}"
        git -C "$PROJECT_DIR" log --oneline -3 | sed 's/^/  /'
        echo ""
    fi
}

# ------------------------------------------------------------
# 1. 安装环境
# ------------------------------------------------------------
menu_install() {
    echo ""
    info "即将执行环境安装脚本..."
    echo "  脚本将自动检测已安装的组件并跳过"
    echo "  支持交互式选择安装组件(核心服务/Android/SSL/sudoers)"
    echo ""
    read -p "确认开始安装? [Y/n] " reply < /dev/tty
    case "$reply" in
        [Nn]*) return 0 ;;
    esac

    bash "${PROJECT_DIR}/deploy/install.sh"
    pause
}

# ------------------------------------------------------------
# 2. 更新代码
# ------------------------------------------------------------
menu_update() {
    echo ""
    info "即将更新代码(拉取最新代码+依赖+迁移+重启服务)"
    echo "  选项:"
    echo "    1. 正常更新(默认)"
    echo "    2. 使用 GitHub 代理(国内服务器推荐)"
    echo "    3. 跳过前端构建"
    echo "    4. 跳过数据库迁移"
    echo "    5. 跳过确认(自动化)"
    echo ""
    read -p "请选择 [1-5, 默认1]: " choice < /dev/tty
    [[ -z "$choice" ]] && choice="1"

    case "$choice" in
        1) bash "${PROJECT_DIR}/deploy/update.sh ;;
        2) bash "${PROJECT_DIR}/deploy/update.sh --gh-proxy ;;
        3) bash "${PROJECT_DIR}/deploy/update.sh --skip-build ;;
        4) bash "${PROJECT_DIR}/deploy/update.sh --skip-migration ;;
        5) bash "${PROJECT_DIR}/deploy/update.sh --yes ;;
        *) warn "无效选项"; return 1 ;;
    esac
    pause
}

# ------------------------------------------------------------
# 3. 重启服务
# ------------------------------------------------------------
menu_restart() {
    echo ""
    echo -e "${COLOR_CYAN}----- 重启服务 -----${COLOR_RESET}"
    echo "  1. 重启所有推送服务(http+websocket+build-worker)"
    echo "  2. 仅重启 HTTP 服务(push-http)"
    echo "  3. 仅重启 WebSocket 服务(push-websocket)"
    echo "  4. 仅重启构建服务(push-build-worker)"
    echo "  5. 重启 Nginx"
    echo "  6. 重启 MySQL"
    echo "  7. 重启 Redis"
    echo ""
    read -p "请选择 [1-7]: " choice < /dev/tty

    case "$choice" in
        1)
            systemctl restart push-http
            sleep 1
            systemctl restart push-websocket
            sleep 1
            systemctl restart push-build-worker
            info "所有推送服务已重启"
            ;;
        2) systemctl restart push-http; info "push-http 已重启" ;;
        3) systemctl restart push-websocket; info "push-websocket 已重启" ;;
        4) systemctl restart push-build-worker; info "push-build-worker 已重启" ;;
        5) systemctl restart nginx; info "nginx 已重启" ;;
        6) systemctl restart mysql 2>/dev/null || systemctl restart mysqld 2>/dev/null || systemctl restart mariadb 2>/dev/null; info "MySQL 已重启" ;;
        7) systemctl restart redis-server 2>/dev/null || systemctl restart redis 2>/dev/null; info "Redis 已重启" ;;
        *) warn "无效选项" ;;
    esac

    sleep 2
    show_service_status
    pause
}

# ------------------------------------------------------------
# 4. 查看服务状态
# ------------------------------------------------------------
menu_status() {
    show_service_status

    echo -e "${COLOR_CYAN}----- 实时日志(可选) -----${COLOR_RESET}"
    echo "  输入数字查看对应服务的实时日志(Ctrl+C 退出):"
    echo "  1. push-http"
    echo "  2. push-websocket"
    echo "  3. push-build-worker"
    echo "  4. nginx"
    echo "  5. mysql"
    echo "  0. 返回"
    echo ""
    read -p "请选择 [0-5]: " choice < /dev/tty

    case "$choice" in
        1) journalctl -u push-http -f ;;
        2) journalctl -u push-websocket -f ;;
        3) journalctl -u push-build-worker -f ;;
        4) tail -f /var/log/nginx/error.log ;;
        5) tail -f /var/log/mysql/error.log 2>/dev/null || tail -f /var/log/mysqld.log 2>/dev/null ;;
        0) return 0 ;;
        *) return 0 ;;
    esac
}

# ------------------------------------------------------------
# 5. 清理缓存
# ------------------------------------------------------------
menu_clean() {
    echo ""
    echo -e "${COLOR_CYAN}----- 清理缓存 -----${COLOR_RESET}"
    echo "  请选择要清理的内容(可多选,空格分隔):"
    echo "  1. 构建缓存(Gradle/Android SDK 缓存)"
    echo "  2. 构建产物(旧的 APK 和日志)"
    echo "  3. PHP opcache"
    echo "  4. NPM 缓存"
    echo "  5. Composer 缓存"
    echo "  6. 系统包管理器缓存(apt/yum)"
    echo "  7. 系统日志(超过 7 天的 journal)"
    echo "  8. 全部清理"
    echo ""
    read -p "请选择 [1-8]: " choices < /dev/tty

    CLEAN_ALL="n"
    [[ "$choices" == "8" ]] && CLEAN_ALL="y"

    # 检测 Web 用户
    WEB_USER="www-data"
    if id -u nginx >/dev/null 2>&1; then
        WEB_USER="nginx"
    fi

    for choice in $choices; do
        [[ "$CLEAN_ALL" == "y" ]] && choice="all"
        case "$choice" in
            1|all)
                info "清理 Gradle/Android SDK 缓存..."
                rm -rf "${PROJECT_DIR}/.gradle/caches" 2>/dev/null || true
                rm -rf "/var/www/.gradle/caches" 2>/dev/null || true
                rm -rf "${PROJECT_DIR}/app/build" 2>/dev/null || true
                # 保留 keystore 和 output
                info "  Gradle 缓存已清理"
                ;;
        esac
        case "$choice" in
            2|all)
                info "清理旧构建产物..."
                # 仅清理 7 天前的 APK 和日志
                find "${PROJECT_DIR}/build/output" -type f -name "*.apk" -mtime +7 -delete 2>/dev/null || true
                find "${PROJECT_DIR}/build/logs" -type f -name "*.log" -mtime +7 -delete 2>/dev/null || true
                info "  7 天前的构建产物已清理"
                ;;
        esac
        case "$choice" in
            3|all)
                info "清理 PHP opcache..."
                if command -v php >/dev/null 2>&1; then
                    # 通过重启 PHP-FPM 清理 opcache
                    for svc in php8.2-fpm php8.1-fpm php8.0-fpm php-fpm; do
                        if systemctl list-unit-files 2>/dev/null | grep -q "$svc"; then
                            systemctl restart "$svc" 2>/dev/null && info "  $svc 已重启(opcache 已清理)"
                            break
                        fi
                    done
                    # Swoole 服务的 opcache 通过重启服务清理
                    systemctl restart push-http 2>/dev/null || true
                    systemctl restart push-websocket 2>/dev/null || true
                fi
                ;;
        esac
        case "$choice" in
            4|all)
                info "清理 NPM 缓存..."
                if command -v npm >/dev/null 2>&1; then
                    npm cache clean --force 2>/dev/null || true
                    rm -rf "${PROJECT_DIR}/admin/node_modules/.cache" 2>/dev/null || true
                fi
                info "  NPM 缓存已清理"
                ;;
        esac
        case "$choice" in
            5|all)
                info "清理 Composer 缓存..."
                if command -v composer >/dev/null 2>&1; then
                    composer clear-cache 2>/dev/null || true
                fi
                info "  Composer 缓存已清理"
                ;;
        esac
        case "$choice" in
            6|all)
                info "清理系统包管理器缓存..."
                if command -v apt-get >/dev/null 2>&1; then
                    apt-get clean 2>/dev/null || true
                    apt-get autoremove -y 2>/dev/null || true
                elif command -v dnf >/dev/null 2>&1; then
                    dnf clean all 2>/dev/null || true
                elif command -v yum >/dev/null 2>&1; then
                    yum clean all 2>/dev/null || true
                fi
                info "  系统缓存已清理"
                ;;
        esac
        case "$choice" in
            7|all)
                info "清理旧系统日志..."
                journalctl --vacuum-time=7d 2>/dev/null || true
                info "  7 天前的 journal 日志已清理"
                ;;
        esac
    done

    echo ""
    info "清理完成"
    df -h "$PROJECT_DIR" | tail -1 | awk '{printf "  当前磁盘: 已用 %s / 总计 %s (%s)\n", $3, $2, $5}'
    pause
}

# ------------------------------------------------------------
# 13. 修复 MySQL 安装
# 解决错误:
#   - "E: Internal Error, No file name for mysql-server:amd64"
#   - "dpkg was interrupted"
#   - MySQL 残留数据冲突
#   - 内存不足导致初始化 OOM
# ------------------------------------------------------------
menu_repair_mysql() {
    echo ""
    echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
    echo -e "${COLOR_CYAN}  MySQL 安装修复${COLOR_RESET}"
    echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
    echo ""
    echo "  适用于以下场景:"
    echo "    - 首次安装 MySQL 失败(${COLOR_YELLOW}E: Internal Error, No file name for mysql-server:amd64${COLOR_RESET})"
    echo "    - dpkg 中断导致安装包损坏"
    echo "    - MySQL 残留数据冲突导致无法重新安装"
    echo "    - 内存不足(2G 服务器)导致 MySQL 初始化 OOM"
    echo ""
    echo "  修复策略(3 次重试,自动修复):"
    echo "    1. 第 1 次: 正常安装尝试"
    echo "    2. 第 2 次: 修复 apt 缓存后重装"
    echo "       - 清理 /var/cache/apt/archives/*.deb"
    echo "       - 清理 /var/lib/apt/lists/*"
    echo "       - 清理 dpkg 锁文件 + dpkg --configure -a"
    echo "       - apt-get -f install 修复损坏依赖"
    echo "       - 切换阿里云镜像源"
    echo "    3. 第 3 次: 彻底清理残留后重装"
    echo "       - purge mysql-* / mariadb-*"
    echo "       - 清理 /var/lib/mysql、/etc/mysql、/var/log/mysql"
    echo "       - 删除 mysql 用户和组"
    echo "       - 内存不足 500MB 时创建 2G swap"
    echo ""
    echo -e "  ${COLOR_YELLOW}注意:${COLOR_RESET} 此操作需要 root 权限"
    echo ""
    read -p "确认执行 MySQL 修复? [y/N] " reply < /dev/tty
    case "$reply" in
        [Yy]*)
            # 检查 root
            if [[ $EUID -ne 0 ]]; then
                warn "需要 root 权限,使用 sudo 重新执行..."
                exec sudo bash "$0" --repair-mysql
            fi
            # 调用 install.sh 的修复模式
            if bash "${PROJECT_DIR}/deploy/install.sh" --repair-mysql; then
                echo ""
                info "MySQL 修复成功"
                echo ""
                # 验证 MySQL 服务状态
                echo -e "${COLOR_CYAN}----- MySQL 服务状态 -----${COLOR_RESET}"
                if systemctl is-active --quiet mysql 2>/dev/null \
                    || systemctl is-active --quiet mysqld 2>/dev/null \
                    || systemctl is-active --quiet mariadb 2>/dev/null; then
                    info "MySQL 服务运行中"
                else
                    warn "MySQL 服务未运行,尝试启动..."
                    systemctl start mysql 2>/dev/null \
                        || systemctl start mysqld 2>/dev/null \
                        || systemctl start mariadb 2>/dev/null || true
                fi
                # 显示 MySQL 版本
                if command -v mysql >/dev/null 2>&1; then
                    mysql --version 2>/dev/null | sed 's/^/  /'
                fi
            else
                error "MySQL 修复失败,请查看上方错误信息"
                echo ""
                echo -e "${COLOR_YELLOW}建议手动排查:${COLOR_RESET}"
                echo "  1. 查看错误日志: cat /tmp/mysql-install-err.log"
                echo "  2. 手动修复 apt: sudo apt-get update && sudo apt-get -f install -y"
                echo "  3. 手动安装: sudo apt-get install -y mysql-server"
                echo "  4. 检查内存: free -h"
                echo "  5. 查看系统日志: sudo journalctl -xe | tail -50"
            fi
            ;;
        *)
            info "已取消"
            ;;
    esac
    pause
}

# ------------------------------------------------------------
# 6. 修改环境配置
# ------------------------------------------------------------
menu_config() {
    ENV_FILE="${PROJECT_DIR}/backend/.env"

    if [[ ! -f "$ENV_FILE" ]]; then
        warn ".env 文件不存在: $ENV_FILE"
        warn "请先执行安装: bash deploy/install.sh"
        pause
        return 1
    fi

    while true; do
        echo ""
        echo -e "${COLOR_CYAN}----- 当前环境配置 -----${COLOR_RESET}"
        # 显示当前配置(隐藏密码)
        grep -E "^(DB_NAME|DB_USER|DB_HOST|DB_PORT|REDIS_HOST|REDIS_PORT|HTTP_PORT|WEBSOCKET_PORT|MAIL_HOST|MAIL_PORT|MAIL_USERNAME|MAIL_FROM|GITHUB_OWNER|GITHUB_REPO)=" "$ENV_FILE" 2>/dev/null | \
            sed 's/=/ = /' | sed 's/^/  /'
        echo ""

        echo -e "${COLOR_CYAN}----- 修改配置 -----${COLOR_RESET}"
        echo "  1. 修改 HTTP 端口"
        echo "  2. 修改 WebSocket 端口"
        echo "  3. 修改数据库连接"
        echo "  4. 修改 Redis 连接"
        echo "  5. 修改邮件配置"
        echo "  6. 修改 GitHub Actions 配置"
        echo "  7. 修改数据库密码(同步 MySQL)"
        echo "  8. 查看完整 .env(隐藏敏感信息)"
        echo "  0. 返回主菜单"
        echo ""
        read -p "请选择 [0-8]: " choice < /dev/tty

        case "$choice" in
            1) edit_env_value "HTTP_PORT" "HTTP 端口" ;;
            2) edit_env_value "WEBSOCKET_PORT" "WebSocket 端口" ;;
            3)
                edit_env_value "DB_HOST" "数据库主机"
                edit_env_value "DB_PORT" "数据库端口"
                edit_env_value "DB_NAME" "数据库名"
                edit_env_value "DB_USER" "数据库用户"
                edit_env_value "DB_PASS" "数据库密码" "secret"
                ;;
            4)
                edit_env_value "REDIS_HOST" "Redis 主机"
                edit_env_value "REDIS_PORT" "Redis 端口"
                ;;
            5)
                edit_env_value "MAIL_HOST" "SMTP 主机"
                edit_env_value "MAIL_PORT" "SMTP 端口"
                edit_env_value "MAIL_USERNAME" "SMTP 用户名"
                edit_env_value "MAIL_PASSWORD" "SMTP 密码" "secret"
                edit_env_value "MAIL_FROM" "发件邮箱"
                edit_env_value "MAIL_SENDER_NAME" "发件人名称"
                ;;
            6)
                edit_env_value "GITHUB_OWNER" "GitHub 仓库所有者"
                edit_env_value "GITHUB_REPO" "GitHub 仓库名"
                edit_env_value "GITHUB_TOKEN" "GitHub Token" "secret"
                edit_env_value "GITHUB_WORKFLOW_ID" "Workflow ID"
                edit_env_value "SERVER_SSH_HOST" "服务器 SSH 主机"
                edit_env_value "SERVER_SSH_PORT" "服务器 SSH 端口"
                edit_env_value "SERVER_SSH_USER" "服务器 SSH 用户"
                ;;
            7) change_db_password ;;
            8) view_env_file ;;
            0) return 0 ;;
            *) warn "无效选项" ;;
        esac

        # 配置修改后提示重启
        if [[ "$choice" =~ ^[1-6]$ ]]; then
            echo ""
            read -p "配置已修改,是否立即重启服务使配置生效? [Y/n] " reply < /dev/tty
            case "$reply" in
                [Nn]*) ;;
                *)
                    systemctl restart push-http
                    sleep 1
                    systemctl restart push-websocket
                    sleep 1
                    systemctl restart push-build-worker
                    info "服务已重启"
                    ;;
            esac
        fi
    done
}

# 修改 .env 中的某个值
# 用法: edit_env_value <KEY> <描述> [secret]
edit_env_value() {
    local key="$1"
    local desc="$2"
    local is_secret="${3:-}"

    local current_val
    current_val=$(grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' || echo "")

    if [[ "$is_secret" == "secret" ]]; then
        echo ""
        info "当前 $desc: ${current_val:+(已设置)}"
    else
        echo ""
        info "当前 $desc: $current_val"
    fi

    read -p "输入新的 $desc(留空保持不变): " new_val < /dev/tty
    [[ -z "$new_val" ]] && { info "未修改"; return 0; }

    # 值含空格自动加引号
    if [[ "$new_val" == *" "* && "$new_val" != \"* ]]; then
        new_val="\"$new_val\""
    fi

    if grep -qE "^${key}=" "$ENV_FILE" 2>/dev/null; then
        sed -i "s|^${key}=.*|${key}=${new_val}|" "$ENV_FILE"
    else
        echo "${key}=${new_val}" >> "$ENV_FILE"
    fi
    info "$desc 已更新"
}

# 修改数据库密码(同步 MySQL)
change_db_password() {
    echo ""
    warn "⚠️  修改数据库密码将同步更新 MySQL 用户密码"
    info "当前数据库用户: $(grep '^DB_USER=' "$ENV_FILE" | cut -d= -f2- | tr -d '\"')"
    read -p "输入新密码: " new_pass < /dev/tty
    [[ -z "$new_pass" ]] && { info "未修改"; return 0; }

    DB_USER=$(grep '^DB_USER=' "$ENV_FILE" | cut -d= -f2- | tr -d '\"')
    DB_HOST=$(grep '^DB_HOST=' "$ENV_FILE" | cut -d= -f2- | tr -d '\"')

    # 同步到 MySQL
    if mysql -uroot -e "ALTER USER '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${new_pass}';" 2>/dev/null || \
       sudo mysql -e "ALTER USER '${DB_USER}'@'${DB_HOST}' IDENTIFIED BY '${new_pass}';" 2>/dev/null; then
        info "MySQL 用户密码已同步更新"
    else
        warn "MySQL 密码更新失败(可能需要手动执行)"
    fi

    # 更新 .env
    sed -i "s|^DB_PASS=.*|DB_PASS=${new_pass}|" "$ENV_FILE"
    info ".env 中 DB_PASS 已更新"
}

view_env_file() {
    echo ""
    echo -e "${COLOR_CYAN}----- .env 配置(敏感信息已隐藏) -----${COLOR_RESET}"
    cat "$ENV_FILE" | sed -E 's/(PASS|SECRET|KEY|TOKEN|PASSWORD)=.*/\1=****[已隐藏]/' | sed 's/^/  /'
    echo ""
    pause
}

# ------------------------------------------------------------
# 7. 卸载环境
# ------------------------------------------------------------
menu_uninstall_env() {
    echo ""
    warn "即将卸载运行环境(保留源码)"
    echo "  将卸载:"
    echo "    - systemd 服务(push-http/push-websocket/push-build-worker)"
    echo "    - PHP/Swoole/Node/Composer"
    echo "    - Nginx 配置(可选删除 Nginx)"
    echo "    - JDK/Android SDK/Gradle"
    echo "    - sudoers/cron/swap"
    echo "  将保留:"
    echo "    - 源码目录 $PROJECT_DIR"
    echo "    - 数据库数据"
    echo ""
    read -p "确认卸载环境? [y/N] " reply < /dev/tty
    case "$reply" in
        [Yy]*) bash "${PROJECT_DIR}/deploy/uninstall.sh --env ;;
        *) info "已取消" ;;
    esac
    pause
}

# ------------------------------------------------------------
# 8. 卸载源码
# ------------------------------------------------------------
menu_uninstall_source() {
    echo ""
    warn "即将删除源码目录(保留环境)"
    echo "  将删除:"
    echo "    - 项目目录 $PROJECT_DIR"
    echo "    - 所有代码、配置、.env"
    echo "  将保留:"
    echo "    - 运行环境(PHP/MySQL/Redis/Nginx 等)"
    echo "    - 数据库数据"
    echo ""
    read -p "确认删除源码? [y/N] " reply < /dev/tty
    case "$reply" in
        [Yy]*) bash "${PROJECT_DIR}/deploy/uninstall.sh --source ;;
        *) info "已取消" ;;
    esac
    pause
}

# ------------------------------------------------------------
# 9. 完全卸载
# ------------------------------------------------------------
menu_uninstall_all() {
    echo ""
    error "⚠️  警告: 完全卸载将删除所有内容!"
    echo "  将删除:"
    echo "    - 源码目录 $PROJECT_DIR"
    echo "    - 数据库(所有用户/消息/设备数据)"
    echo "    - 运行环境(PHP/MySQL/Redis/Nginx/Node/Composer)"
    echo "    - APP 构建环境(JDK/Android SDK/Gradle)"
    echo "    - 系统配置(systemd/Nginx/sudoers/cron/swap)"
    echo "  此操作不可逆!"
    echo ""
    read -p "确认完全卸载? 输入 'yes' 继续: " reply < /dev/tty
    if [[ "$reply" == "yes" ]]; then
        bash "${PROJECT_DIR}/deploy/uninstall.sh --all --yes
    else
        info "已取消"
    fi
    pause
}

# ------------------------------------------------------------
# 10. 回滚代码
# ------------------------------------------------------------
menu_rollback() {
    echo ""
    info "回滚到上一次更新前的版本"
    echo "  可选:"
    echo "    1. 回滚到上次更新前(自动读取备份)"
    echo "    2. 回滚到指定 commit"
    echo ""
    read -p "请选择 [1-2]: " choice < /dev/tty
    case "$choice" in
        1) bash "${PROJECT_DIR}/deploy/rollback.sh ;;
        2)
            read -p "输入目标 commit hash: " commit < /dev/tty
            [[ -n "$commit" ]] && bash "${PROJECT_DIR}/deploy/rollback.sh "$commit"
            ;;
        *) warn "无效选项" ;;
    esac
    pause
}

# ------------------------------------------------------------
# 11. 构建前端
# ------------------------------------------------------------
menu_build_admin() {
    echo ""
    info "构建管理后台前端..."
    if [[ ! -d "${PROJECT_DIR}/admin" ]]; then
        warn "admin 目录不存在"
        pause
        return 1
    fi
    cd "${PROJECT_DIR}/admin"
    info "执行 npm install..."
    npm install --no-audit --no-fund --loglevel=error
    info "执行 npm run build..."
    npm run build
    cd "$PROJECT_DIR"
    info "前端构建完成"
    pause
}

# ------------------------------------------------------------
# 12. 生成 keystore
# ------------------------------------------------------------
menu_generate_keystore() {
    echo ""
    info "生成 APP 签名 keystore..."
    KEYSTORE_SCRIPT="${PROJECT_DIR}/build/generate_keystore.sh"
    if [[ -f "$KEYSTORE_SCRIPT" ]]; then
        bash "$KEYSTORE_SCRIPT"
    else
        warn "keystore 生成脚本不存在: $KEYSTORE_SCRIPT"
    fi
    pause
}

# ------------------------------------------------------------
# 暂停(按回车继续)
# ------------------------------------------------------------
pause() {
    echo ""
    read -p "按回车键继续..." < /dev/tty
}

# ============================================================
# 主菜单
# ============================================================
main_menu() {
    while true; do
        echo ""
        echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
        echo -e "${COLOR_CYAN}   即时消息推送系统 - 管理菜单${COLOR_RESET}"
        echo -e "${COLOR_CYAN}   项目目录: ${PROJECT_DIR}${COLOR_RESET}"
        echo -e "${COLOR_CYAN}============================================================${COLOR_RESET}"
        echo ""
        echo -e "  ${COLOR_GREEN}环境管理${COLOR_RESET}"
        echo -e "    ${COLOR_GREEN}1.${COLOR_RESET} 安装环境(首次部署)"
        echo -e "    ${COLOR_GREEN}2.${COLOR_RESET} 更新代码(拉取最新+依赖+迁移+重启)"
        echo -e "    ${COLOR_GREEN}3.${COLOR_RESET} 重启服务"
        echo -e "    ${COLOR_GREEN}4.${COLOR_RESET} 查看服务状态 + 实时日志"
        echo -e "    ${COLOR_GREEN}5.${COLOR_RESET} 清理缓存(Gradle/NPM/Composer 等)"
        echo -e "    ${COLOR_GREEN}13.${COLOR_RESET} ${COLOR_YELLOW}修复 MySQL 安装(apt 缓存损坏/残留冲突)${COLOR_RESET}"
        echo ""
        echo -e "  ${COLOR_YELLOW}配置管理${COLOR_RESET}"
        echo -e "    ${COLOR_GREEN}6.${COLOR_RESET} 修改环境配置(端口/数据库/邮件/GitHub)"
        echo ""
        echo -e "  ${COLOR_PURPLE}构建管理${COLOR_RESET}"
        echo -e "    ${COLOR_GREEN}7.${COLOR_RESET} 构建管理后台前端"
        echo -e "    ${COLOR_GREEN}8.${COLOR_RESET} 生成 APP 签名 keystore"
        echo -e "    ${COLOR_GREEN}9.${COLOR_RESET} 回滚代码"
        echo ""
        echo -e "  ${COLOR_RED}卸载管理${COLOR_RESET}"
        echo -e "    ${COLOR_GREEN}10.${COLOR_RESET} 卸载环境(保留源码)"
        echo -e "    ${COLOR_GREEN}11.${COLOR_RESET} 卸载源码(保留环境)"
        echo -e "    ${COLOR_GREEN}12.${COLOR_RESET} ${COLOR_RED}完全卸载(环境+源码+数据库)${COLOR_RESET}"
        echo ""
        echo -e "    ${COLOR_GREEN}0.${COLOR_RESET} 退出"
        echo ""
        read -p "请输入选项 [0-13]: " choice < /dev/tty

        case "$choice" in
            1) menu_install ;;
            2) menu_update ;;
            3) menu_restart ;;
            4) menu_status ;;
            5) menu_clean ;;
            6) menu_config ;;
            7) menu_build_admin ;;
            8) menu_generate_keystore ;;
            9) menu_rollback ;;
            10) menu_uninstall_env ;;
            11) menu_uninstall_source ;;
            12) menu_uninstall_all ;;
            13) menu_repair_mysql ;;
            0|q|quit|exit)
                echo ""
                info "再见!"
                exit 0
                ;;
            *)
                warn "无效选项: $choice"
                sleep 1
                ;;
        esac
    done
}

# ============================================================
# 入口
# ============================================================
main() {
    # 命令行参数支持
    for arg in "$@"; do
        case "$arg" in
            --repair-mysql)
                # 直接调用修复函数(用于 sudo 重新执行场景)
                menu_repair_mysql
                exit $?
                ;;
            --menu)
                main_menu
                exit 0
                ;;
            -h|--help)
                head -n 10 "$0"
                exit 0
                ;;
        esac
    done

    # 检查是否在项目目录中
    if [[ ! -f "${PROJECT_DIR}/deploy/install.sh" ]]; then
        warn "未检测到项目文件,请在项目根目录执行此脚本"
        read -p "是否继续? [y/N] " reply < /dev/tty
        [[ "$reply" != "y" ]] && exit 1
    fi

    main_menu
}

main "$@"
