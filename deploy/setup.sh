#!/bin/bash
# ============================================================
# 即时消息推送系统 - 统一部署与管理脚本（交互式）
#
# 用法:
#   bash deploy/setup.sh              # 启动主菜单（数字选择交互）
#   bash deploy/setup.sh --install    # 非交互：直接执行一键安装
#   bash deploy/setup.sh --update     # 非交互：直接执行更新
#   bash deploy/setup.sh --optimize   # 非交互：直接执行系统优化
#   bash deploy/setup.sh --uninstall=env|source|all   # 非交互：卸载
#   bash deploy/setup.sh --yes        # 配合以上参数跳过所有确认
#
# 功能菜单:
#   [1] 系统检测与优化   - 识别发行版/CPU/内存/磁盘/网络，配置国内镜像/sysctl/swap/句柄/时区/NTP
#   [2] 一键安装部署     - PHP+Swoole/MySQL/Redis/Nginx/Node/Composer → 拉代码→.env→建库→迁移→构建前端→服务配置→启动
#   [3] 更新系统代码     - 强制同步代码(reset --hard)→composer→迁移→(可选)构建前端→重启服务
#   [4] 服务管理         - 启动/停止/重启/状态/查看日志（push-http、push-websocket、nginx、mysql、redis）
#   [5] 卸载             - 仅卸环境/仅删源码/完全卸载（二次确认）
#   [6] 重装             - 完全卸载后重新安装（二次确认）
#   [0] 退出
# ============================================================
set -u

# =========== 全局配置 ===========
export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export DEBCONF_NONINTERACTIVE_SEEN=true

PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
GH_PROXY="${GH_PROXY:-https://gh.jasonzeng.dev/}"
GITHUB_REPO="https://github.com/jiujiu123520/im-push-system.git"

# =========== 颜色 ===========
C_G='\033[0;32m'; C_Y='\033[1;33m'; C_R='\033[0;31m'
C_B='\033[0;34m'; C_C='\033[0;36m'; C_W='\033[1;37m'; C_0='\033[0m'
info()  { echo -e "${C_G}[INFO]${C_0}  $*"; }
warn()  { echo -e "${C_Y}[WARN]${C_0}  $*"; }
error() { echo -e "${C_R}[FAIL]${C_0}  $*" >&2; }
ok()    { echo -e "${C_G}[ OK ]${C_0}  $*"; }
step()  { echo -e "\n${C_B}========== $* ==========${C_0}"; }
title() { echo -e "\n${C_W}╔══════════════════════════════════════════╗${C_0}"; echo -e "${C_W}║  $(printf '%-40s' "$*")║${C_0}"; echo -e "${C_W}╚══════════════════════════════════════════╝${C_0}"; }

# =========== 通用工具函数 ===========
_need_cmd() { command -v "$1" >/dev/null 2>&1; }
_sudo() {
    if [ "$(id -u)" = "0" ]; then "$@"
    elif _need_cmd sudo; then sudo "$@"
    else "$@"; fi
}
_safe_read() {
    local prompt="$1" varname="$2" secret="${3:-}" reply="" flags="-r"
    [ "$secret" = "secret" ] && flags="-r -s"
    if [ -t 0 ]; then
        printf '%s' "$prompt"; IFS= read $flags reply
        [ "$secret" = "secret" ] && echo ""
    elif [ -r /dev/tty ]; then
        printf '%s' "$prompt" > /dev/tty; IFS= read $flags reply < /dev/tty
        [ "$secret" = "secret" ] && echo "" > /dev/tty
    fi
    eval "$varname=\"\$reply\""
}
_confirm() {
    local prompt="${1:-确认继续?} [Y/n] " default_yes="${2:-1}" reply
    _safe_read "$prompt" reply
    case "$reply" in
        y|Y|yes|YES|"") [ -z "$reply" ] && [ "$default_yes" = "0" ] && return 1; return 0 ;;
        *) return 1 ;;
    esac
}

# =========== 系统探测 ===========
declare -g DISTRO="" DISTRO_VER="" DISTRO_FAMILY=""
declare -g PKG_CMD="" PKG_INSTALL="" PKG_UPDATE="" PKG_REMOVE=""
declare -g OS_USER="" OS_GROUP=""
declare -g NGINX_CONF_DIR="" NGINX_SITE_DIR=""
declare -g CPU_ARCH="" TOTAL_MEM_MB="" DISK_GB=""

_detect_distro() {
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        DISTRO="$ID"
        DISTRO_VER="${VERSION_ID:-}"
    elif [ -f /etc/redhat-release ]; then
        DISTRO="centos"
        DISTRO_VER="$(rpm -E '%{rhel}')"
    elif _need_cmd lsb_release; then
        DISTRO="$(lsb_release -si | tr '[:upper:]' '[:lower:]')"
        DISTRO_VER="$(lsb_release -sr)"
    else
        DISTRO="$(uname -s | tr '[:upper:]' '[:lower:]')"
    fi
    case "$DISTRO" in
        ubuntu|debian|linuxmint|pop|elementary|kali|devuan)
            DISTRO_FAMILY="debian"
            PKG_CMD="apt-get"; PKG_INSTALL="apt-get install -y"
            PKG_UPDATE="apt-get update -y"; PKG_REMOVE="apt-get purge -y"
            OS_USER="www-data"; OS_GROUP="www-data"
            NGINX_CONF_DIR="/etc/nginx"
            NGINX_SITE_DIR="/etc/nginx/sites-available"
            ;;
        centos|rhel|rocky|almalinux|ol|anolis|fedora)
            DISTRO_FAMILY="rhel"
            if _need_cmd dnf; then PKG_CMD="dnf"; PKG_INSTALL="dnf install -y"; PKG_UPDATE="dnf makecache -y"; PKG_REMOVE="dnf remove -y"
            else PKG_CMD="yum"; PKG_INSTALL="yum install -y"; PKG_UPDATE="yum makecache -y"; PKG_REMOVE="yum remove -y"; fi
            OS_USER="nginx"; OS_GROUP="nginx"
            NGINX_CONF_DIR="/etc/nginx"
            NGINX_SITE_DIR="/etc/nginx/conf.d"
            ;;
        alpine)
            DISTRO_FAMILY="alpine"
            PKG_CMD="apk"; PKG_INSTALL="apk add --no-cache"
            PKG_UPDATE="apk update"; PKG_REMOVE="apk del"
            OS_USER="www-data"; OS_GROUP="www-data"
            NGINX_CONF_DIR="/etc/nginx"
            NGINX_SITE_DIR="/etc/nginx/http.d"
            ;;
        arch|manjaro|endeavouros|garuda|artix)
            DISTRO_FAMILY="arch"
            PKG_CMD="pacman"; PKG_INSTALL="pacman -S --noconfirm"
            PKG_UPDATE="pacman -Sy --noconfirm"; PKG_REMOVE="pacman -Rns --noconfirm"
            OS_USER="http"; OS_GROUP="http"
            NGINX_CONF_DIR="/etc/nginx"
            NGINX_SITE_DIR="/etc/nginx/conf.d"
            ;;
        opensuse*|sles|suse)
            DISTRO_FAMILY="suse"
            PKG_CMD="zypper"; PKG_INSTALL="zypper install -y"
            PKG_UPDATE="zypper refresh"; PKG_REMOVE="zypper remove -y"
            OS_USER="wwwrun"; OS_GROUP="www"
            NGINX_CONF_DIR="/etc/nginx"
            NGINX_SITE_DIR="/etc/nginx/vhosts.d"
            ;;
        *)
            DISTRO_FAMILY="unknown"
            OS_USER="www-data"; OS_GROUP="www-data"
            NGINX_CONF_DIR="/etc/nginx"
            NGINX_SITE_DIR="/etc/nginx/conf.d"
            ;;
    esac
    CPU_ARCH="$(uname -m 2>/dev/null || echo unknown)"
    TOTAL_MEM_MB="$(awk '/MemTotal/ {printf "%d",$2/1024}' /proc/meminfo 2>/dev/null || echo 0)"
    DISK_GB="$(df -Pk / 2>/dev/null | awk 'NR==2 {printf "%d",$4/1024/1024}' || echo 0)"
    # 若 web 用户不存在则回退为 www-data
    if ! id -u "$OS_USER" >/dev/null 2>&1; then
        if id -u www-data >/dev/null 2>&1; then OS_USER="www-data"; OS_GROUP="www-data"
        elif id -u nginx >/dev/null 2>&1; then OS_USER="nginx"; OS_GROUP="nginx"; fi
    fi
}

_print_sysinfo() {
    local pubip="" hostname_s=""
    hostname_s="$(hostname 2>/dev/null || echo unknown)"
    pubip="$(curl -s --max-time 5 ifconfig.me 2>/dev/null || curl -s --max-time 5 https://api.ipify.org 2>/dev/null || echo "未知")"
    echo ""
    echo -e "${C_C}┌───────────────────────────────────────────────────────┐${C_0}"
    echo -e "${C_C}│                    系统信息概览                        │${C_0}"
    echo -e "${C_C}├───────────────┬───────────────────────────────────────┤${C_0}"
    printf  "${C_C}│${C_0} 发行版/版本   ${C_C}│${C_0} %-37s ${C_C}│${C_0}\n" "${DISTRO} ${DISTRO_VER}"
    printf  "${C_C}│${C_0} 系列          ${C_C}│${C_0} %-37s ${C_C}│${C_0}\n" "$DISTRO_FAMILY ($PKG_CMD)"
    printf  "${C_C}│${C_0} 内核/架构     ${C_C}│${C_0} %-37s ${C_C}│${C_0}\n" "$(uname -r | cut -c-24) / $CPU_ARCH"
    printf  "${C_C}│${C_0} 主机名         ${C_C}│${C_0} %-37s ${C_C}│${C_0}\n" "$hostname_s"
    printf  "${C_C}│${C_0} 公网IP        ${C_C}│${C_0} %-37s ${C_C}│${C_0}\n" "$pubip"
    printf  "${C_C}│${C_0} 内存           ${C_C}│${C_0} %-37s ${C_C}│${C_0}\n" "$( [[ $TOTAL_MEM_MB -gt 1024 ]] && echo "$((TOTAL_MEM_MB/1024)) GB ($TOTAL_MEM_MB MB)" || echo "$TOTAL_MEM_MB MB")"
    printf  "${C_C}│${C_0} 根分区剩余     ${C_C}│${C_0} %-37s ${C_C}│${C_0}\n" "${DISK_GB} GB"
    printf  "${C_C}│${C_0} Web 运行用户  ${C_C}│${C_0} %-37s ${C_C}│${C_0}\n" "$OS_USER:$OS_GROUP"
    printf  "${C_C}│${C_0} 项目目录      ${C_C}│${C_0} %-37s ${C_C}│${C_0}\n" "$PROJECT_DIR"
    echo -e "${C_C}└───────────────┴───────────────────────────────────────┘${C_0}"
    echo ""
}

# =========== [1] 系统检测与优化 ===========
do_optimize() {
    title "系统检测与优化"
    _detect_distro
    _print_sysinfo

    if [ "$DISTRO_FAMILY" = "unknown" ]; then
        warn "未能识别您的 Linux 发行版，镜像切换和部分优化步骤将跳过"
    else
        step "1/6 切换国内镜像源（加速包管理器）"
        _optimize_mirrors
    fi

    step "2/6 系统包缓存更新"
    _sudo bash -c "$PKG_UPDATE 2>&1 | tail -5" || warn "包缓存更新失败，可能网络或源配置问题，仍继续后续步骤"

    step "3/6 安装基础工具（curl/git/wget/ca-certificates/unzip/vim）"
    _install_base_packages

    step "4/6 Swap 配置（内存 <=2GB 时自动开启）"
    _ensure_swap

    step "5/6 系统参数优化（文件句柄 / sysctl）"
    _sysctl_tuning

    step "6/6 时区与 NTP"
    _set_timezone

    ok "系统优化完成"
}

_optimize_mirrors() {
    case "$DISTRO_FAMILY" in
        debian)
            if grep -qi "ubuntu" /etc/os-release 2>/dev/null; then
                # Ubuntu: 切换到阿里云
                if [ -f /etc/apt/sources.list ] && ! grep -q "aliyun.com" /etc/apt/sources.list 2>/dev/null; then
                    _sudo cp -a /etc/apt/sources.list /etc/apt/sources.list.bak.$(date +%Y%m%d) 2>/dev/null || true
                    _sudo sed -i -E 's#http(s)?://(archive|security)\.ubuntu\.com#http://mirrors.aliyun.com#g' /etc/apt/sources.list 2>/dev/null || true
                fi
                # 清华 PPA 索引（Sury PHP 源）
                if [ ! -f /etc/apt/trusted.gpg.d/sury-php.gpg ] && ! grep -rq "packages.sury.org" /etc/apt/sources.list.d/ 2>/dev/null; then
                    _sudo apt-get install -y ca-certificates curl gnupg >/dev/null 2>&1 || true
                    curl -fsSL https://packages.sury.org/php/apt.gpg 2>/dev/null | _sudo gpg --dearmor -o /etc/apt/trusted.gpg.d/sury-php.gpg >/dev/null 2>&1 || true
                    if echo "deb https://mirrors.tuna.tsinghua.edu.cn/sury/php/ $(lsb_release -sc 2>/dev/null || grep VERSION_CODENAME /etc/os-release | cut -d= -f2) main" | _sudo tee /etc/apt/sources.list.d/sury-php.list >/dev/null 2>&1; then
                        :
                    else
                        echo "deb https://packages.sury.org/php/ $(lsb_release -sc 2>/dev/null || grep VERSION_CODENAME /etc/os-release | cut -d= -f2) main" | _sudo tee /etc/apt/sources.list.d/sury-php.list >/dev/null 2>&1 || true
                    fi
                fi
            else
                # Debian: 切换到阿里云
                if [ -f /etc/apt/sources.list ] && ! grep -q "aliyun.com" /etc/apt/sources.list 2>/dev/null; then
                    _sudo cp -a /etc/apt/sources.list /etc/apt/sources.list.bak.$(date +%Y%m%d) 2>/dev/null || true
                    _sudo sed -i -E 's#http(s)?://(deb|security)\.debian\.org#http://mirrors.aliyun.com#g' /etc/apt/sources.list 2>/dev/null || true
                fi
                if [ ! -f /etc/apt/trusted.gpg.d/sury-php.gpg ] && ! grep -rq "packages.sury.org" /etc/apt/sources.list.d/ 2>/dev/null; then
                    _sudo apt-get install -y ca-certificates curl gnupg >/dev/null 2>&1 || true
                    curl -fsSL https://packages.sury.org/php/apt.gpg 2>/dev/null | _sudo gpg --dearmor -o /etc/apt/trusted.gpg.d/sury-php.gpg >/dev/null 2>&1 || true
                    echo "deb https://packages.sury.org/php/ $(lsb_release -sc 2>/dev/null || grep VERSION_CODENAME /etc/os-release | cut -d= -f2) main" | _sudo tee /etc/apt/sources.list.d/sury-php.list >/dev/null 2>&1 || true
                fi
            fi
            ;;
        rhel)
            # 切换 CentOS/Alma/Rocky 到阿里云 EPEL + Remi 源（CentOS7 Vault 特殊处理）
            local major="${DISTRO_VER%%.*}"
            if _need_cmd dnf; then _sudo dnf install -y epel-release >/dev/null 2>&1 || true
            else _sudo yum install -y epel-release >/dev/null 2>&1 || true; fi
            if [ "$major" = "7" ]; then
                # CentOS 7 Vault + Aliyun archive
                [ -f /etc/yum.repos.d/CentOS-Base.repo ] && _sudo sed -i -E 's|^#?baseurl=http://mirror\.centos\.org|baseurl=http://mirrors.aliyun.com|; s|^mirrorlist=|#mirrorlist=|' /etc/yum.repos.d/CentOS-Base.repo 2>/dev/null || true
                [ -f /etc/yum.repos.d/epel.repo ] && _sudo sed -i -E 's|^#?baseurl=https?://download\.fedoraproject\.org|baseurl=http://mirrors.aliyun.com|; s|^mirrorlist=|#mirrorlist=|' /etc/yum.repos.d/epel.repo 2>/dev/null || true
            fi
            if ! rpm -q remi-release >/dev/null 2>&1; then
                local remi_url="https://mirrors.aliyun.com/remi"
                local remi_pkg=""
                if [ "$major" = "7" ]; then remi_pkg="remi-release-7.rpm"
                elif [ "$major" = "8" ]; then remi_pkg="remi-release-8.rpm"
                elif [ "$major" = "9" ]; then remi_pkg="remi-release-9.rpm"
                fi
                if [ -n "$remi_pkg" ]; then
                    _sudo $PKG_INSTALL "${remi_url}/enterprise/${major}/x86_64/${remi_pkg}" >/dev/null 2>&1 || true
                fi
            fi
            ;;
        alpine)
            if ! grep -q "mirrors.aliyun.com" /etc/apk/repositories 2>/dev/null; then
                _sudo cp -a /etc/apk/repositories /etc/apk/repositories.bak.$(date +%Y%m%d) 2>/dev/null || true
                _sudo sed -i 's#http(s)?://dl-cdn.alpinelinux.org#http://mirrors.aliyun.com#g' /etc/apk/repositories 2>/dev/null || true
            fi
            ;;
        arch)
            if [ -f /etc/pacman.conf ] && ! grep -q "mirrors.aliyun.com/archlinux" /etc/pacman.conf 2>/dev/null; then
                _sudo cp -a /etc/pacman.d/mirrorlist /etc/pacman.d/mirrorlist.bak.$(date +%Y%m%d) 2>/dev/null || true
                _sudo sed -i '1i Server = http://mirrors.aliyun.com/archlinux/$repo/os/$arch' /etc/pacman.d/mirrorlist 2>/dev/null || true
            fi
            ;;
    esac
    ok "镜像源已配置"
}

_install_base_packages() {
    local base_pkgs="curl git wget ca-certificates unzip vim tar gzip"
    # BusyBox/Alpine bash 默认缺的
    case "$DISTRO_FAMILY" in
        debian) base_pkgs="$base_pkgs lsb-release gnupg apt-transport-https software-properties-common" ;;
        rhel)   base_pkgs="$base_pkgs yum-utils" ;;
        alpine) base_pkgs="$base_pkgs bash shadow" ;;
    esac
    # shellcheck disable=SC2086
    _sudo bash -c "DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a $PKG_INSTALL $base_pkgs 2>&1 | tail -10" || true
    ok "基础工具已安装"
}

_ensure_swap() {
    if free -m 2>/dev/null | awk 'NR==2 {exit !($2<=2048)}'; then
        if [ ! -f /swapfile ]; then
            info "检测到内存较小（≤2GB），创建 2GB Swap..."
            _sudo fallocate -l 2G /swapfile 2>/dev/null || _sudo dd if=/dev/zero of=/swapfile bs=1M count=2048 status=none
            _sudo chmod 600 /swapfile
            _sudo mkswap /swapfile >/dev/null 2>&1
            _sudo swapon /swapfile 2>/dev/null || true
            if ! grep -q "/swapfile" /etc/fstab 2>/dev/null; then
                echo '/swapfile none swap sw 0 0' | _sudo tee -a /etc/fstab >/dev/null
            fi
            ok "Swap 2GB 已启用"
        else
            ok "Swap 文件已存在"
        fi
    else
        info "内存充足（>2GB），跳过 Swap 配置"
    fi
}

_sysctl_tuning() {
    # 文件句柄
    if ! grep -q "655350" /etc/security/limits.conf 2>/dev/null; then
        _sudo tee -a /etc/security/limits.conf >/dev/null <<-'EOF'
* soft nofile 655350
* hard nofile 655350
* soft nproc  655350
* hard nproc  655350
EOF
    fi
    # sysctl 网络与文件系统优化
    local need_write=0
    if [ ! -f /etc/sysctl.d/99-push-system.conf ]; then need_write=1; else
        if ! grep -q "fs.file-max" /etc/sysctl.d/99-push-system.conf 2>/dev/null; then need_write=1; fi
    fi
    if [ "$need_write" = "1" ]; then
        _sudo tee /etc/sysctl.d/99-push-system.conf >/dev/null <<-'EOF'
fs.file-max               = 2097152
fs.inotify.max_user_watches= 524288
net.core.somaxconn         = 65535
net.core.netdev_max_backlog= 65535
net.ipv4.tcp_max_syn_backlog= 65535
net.ipv4.tcp_fin_timeout   = 15
net.ipv4.tcp_tw_reuse      = 1
net.ipv4.tcp_keepalive_time= 600
net.ipv4.tcp_keepalive_intvl= 30
net.ipv4.tcp_keepalive_probes= 5
net.ipv4.ip_local_port_range= 1024 65535
vm.swappiness              = 10
vm.overcommit_memory       = 1
EOF
        _sudo sysctl --system >/dev/null 2>&1 || _sudo sysctl -p /etc/sysctl.d/99-push-system.conf >/dev/null 2>&1 || true
    fi
    ok "内核参数已优化"
}

_set_timezone() {
    if _need_cmd timedatectl; then
        _sudo timedatectl set-timezone Asia/Shanghai 2>/dev/null || true
    elif [ -f /usr/share/zoneinfo/Asia/Shanghai ]; then
        _sudo ln -sf /usr/share/zoneinfo/Asia/Shanghai /etc/localtime 2>/dev/null || true
        echo "Asia/Shanghai" | _sudo tee /etc/timezone >/dev/null 2>&1 || true
    fi
    # NTP / Chrony
    if _need_cmd chronyd; then
        _sudo systemctl enable --now chronyd >/dev/null 2>&1 || true
    elif _need_cmd systemctl && systemctl list-unit-files 2>/dev/null | grep -q "systemd-timesyncd"; then
        _sudo systemctl enable --now systemd-timesyncd >/dev/null 2>&1 || true
    fi
    ok "时区已设置为 Asia/Shanghai"
}

# =========== [2] 一键安装 ===========
do_install() {
    title "一键安装部署"
    _detect_distro
    [ -z "$DISTRO_FAMILY" ] || [ "$DISTRO_FAMILY" = "unknown" ] && { error "未识别的发行版，请手动安装后重试"; return 1; }

    [ "$(id -u)" != "0" ] && { warn "建议使用 root 权限运行安装（或自动提权）"; }

    step "1/10 系统优化（基础工具/镜像/Swap/内核参数）"
    do_optimize 2>/dev/null || true

    step "2/10 安装核心运行环境（PHP 8.3 + 扩展 + Swoole / MySQL / Redis / Nginx / Node / Composer）"
    _install_runtime

    step "3/10 获取最新代码（GitHub → $PROJECT_DIR）"
    _clone_code

    step "4/10 生成 .env 配置文件"
    _write_env

    step "5/10 安装 PHP 依赖（composer install）"
    _composer_install

    step "6/10 初始化数据库与迁移"
    _setup_database

    step "7/10 构建前端（管理后台 + 用户端）"
    _build_frontend

    step "8/10 部署 Nginx 配置与 systemd 服务"
    _deploy_services

    step "9/10 权限修正"
    _fix_permissions

    step "10/10 启动全部服务"
    _start_all

    _print_install_summary
}

_install_runtime() {
    local php_ver="8.3"
    case "$DISTRO_FAMILY" in
        debian)
            _sudo bash -c "DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get update >/dev/null 2>&1; apt-get install -y \
                php${php_ver}-cli php${php_ver}-fpm php${php_ver}-mysql php${php_ver}-redis \
                php${php_ver}-curl php${php_ver}-gd php${php_ver}-mbstring php${php_ver}-xml \
                php${php_ver}-zip php${php_ver}-bcmath php${php_ver}-intl php${php_ver}-opcache \
                php-pear php${php_ver}-dev php-swoole \
                mysql-server redis-server nginx composer nodejs npm \
                pkg-config libcurl4-openssl-dev libssl-dev libnghttp2-dev \
                2>&1 | tail -15" || true
            ;;
        rhel)
            _sudo bash -c "$PKG_UPDATE >/dev/null 2>&1; $PKG_INSTALL \
                php${php_ver/./} php${php_ver/./}-php-mysqlnd php${php_ver/./}-php-redis \
                php${php_ver/./}-php-gd php${php_ver/./}-php-mbstring php${php_ver/./}-php-xml \
                php${php_ver/./}-php-pecl-zip php${php_ver/./}-php-bcmath php${php_ver/./}-php-intl \
                php${php_ver/./}-php-opcache php${php_ver/./}-php-devel php${php_ver/./}-php-pecl-swoole \
                mariadb-server redis nginx nodejs npm libnghttp2-devel \
                2>&1 | tail -15" || true
            _sudo systemctl enable --now mariadb redis nginx >/dev/null 2>&1 || true
            ;;
        alpine)
            _sudo bash -c "apk update >/dev/null 2>&1; apk add --no-cache \
                php${php_ver/./} php${php_ver/./}-mysqli php${php_ver/./}-redis php${php_ver/./}-curl \
                php${php_ver/./}-gd php${php_ver/./}-mbstring php${php_ver/./}-xml php${php_ver/./}-zip \
                php${php_ver/./}-bcmath php${php_ver/./}-intl php${php_ver/./}-opcache php${php_ver/./}-pecl-swoole \
                php${php_ver/./}-dev php${php_ver/./}-phar composer \
                mysql mysql-client redis nginx nodejs npm \
                build-base openssl-dev curl-dev nghttp2-dev php${php_ver/./}-sockets \
                2>&1 | tail -15" || true
            _sudo rc-update add mariadb default >/dev/null 2>&1; _sudo rc-service mariadb start >/dev/null 2>&1 || true
            _sudo rc-update add redis default   >/dev/null 2>&1; _sudo rc-service redis start   >/dev/null 2>&1 || true
            _sudo rc-update add nginx default   >/dev/null 2>&1; _sudo rc-service nginx start   >/dev/null 2>&1 || true
            ;;
        arch)
            _sudo bash -c "pacman -Sy --noconfirm >/dev/null 2>&1; pacman -S --noconfirm \
                php php-swoole php-fpm mariadb redis nginx composer nodejs npm \
                php-gd php-mbstring php-intl zip unzip curl base-devel libnghttp2 \
                2>&1 | tail -15" || true
            ;;
        *)
            error "不支持的发行版: $DISTRO_FAMILY，请手动安装 PHP 8.3 + Swoole / MySQL / Redis / Nginx / Node.js"; return 1 ;;
    esac

    # Swoole 兜底：若包管理器未带，则尝试 pecl 源码安装
    if ! php -m 2>/dev/null | grep -qi swoole; then
        warn "包管理器未提供 php-swoole，正在 pecl 编译安装（约 5-15 分钟）..."
        _sudo bash -c "printf '\n\n\nyes\n' | pecl install swoole >/dev/null 2>&1" || {
            # 尝试降级源码编译
            warn "pecl 失败，改为通过 gh-proxy 下载 Swoole 源码编译..."
            local src_ver="6.0.1" sw_url="https://gh.jasonzeng.dev/https://github.com/swoole/swoole-src/archive/refs/tags/v${src_ver}.tar.gz"
            (cd /tmp && curl -fsSL "$sw_url" -o swoole.tar.gz && tar -xf swoole.tar.gz && cd "swoole-src-${src_ver}" && phpize >/dev/null 2>&1 && ./configure --enable-openssl --enable-http2 --enable-swoole-curl >/dev/null 2>&1 && make -j"$(nproc 2>/dev/null || echo 1)" >/dev/null 2>&1 && _sudo make install >/dev/null 2>&1) || { error "Swoole 编译失败，请手动安装 php-swoole 扩展"; }
        }
        # 写 ini
        local ini_dir="$(php -i 2>/dev/null | awk -F'=>' '/^Scan this dir/ {gsub(/^ +/,"",$2);print $2;exit}')"
        if [ -n "$ini_dir" ]; then
            echo "extension=swoole.so" | _sudo tee "${ini_dir}/50-swoole.ini" >/dev/null 2>&1 || true
        fi
    fi

    # Node 兜底（Ubuntu/Debian 旧版可能 node<18）
    if _need_cmd node; then
        local node_maj
        node_maj="$(node -v 2>/dev/null | sed 's/v//' | cut -d. -f1 || echo 0)"
        if [ "$node_maj" -lt 18 ] 2>/dev/null; then
            warn "Node.js 版本过低(v$node_maj < 18)，切换 NodeSource 安装最新 LTS..."
            curl -fsSL https://deb.nodesource.com/setup_20.x 2>/dev/null | _sudo bash - >/dev/null 2>&1 || true
            _sudo $PKG_INSTALL nodejs >/dev/null 2>&1 || true
        fi
    fi
    # npm 国内源
    _sudo npm config set registry https://registry.npmmirror.com >/dev/null 2>&1 || true
    # composer 国内源
    composer config -g repos.packagist composer https://mirrors.aliyun.com/composer/ >/dev/null 2>&1 || true

    # ===== APNS 必需：检查 PHP curl 是否支持 HTTP/2（依赖 libnghttp2）=====
    local http2_ok
    http2_ok="$(php -r 'echo (defined("CURL_VERSION_HTTP2") && (curl_version()["features"] & CURL_VERSION_HTTP2)) ? 1 : 0;' 2>/dev/null || echo 0)"
    if [ "$http2_ok" = "1" ]; then
        ok "PHP curl 已支持 HTTP/2（APNS 推送必需）"
    else
        warn "PHP curl 当前不支持 HTTP/2（APNS 推送必需），正在尝试重装 php-curl + libnghttp2..."
        case "$DISTRO_FAMILY" in
            debian)
                _sudo bash -c "apt-get update >/dev/null 2>&1 && apt-get install -y --reinstall libnghttp2-dev php${php_ver}-curl libcurl4 2>&1 | tail -5" || true
                ;;
            rhel)
                _sudo bash -c "$PKG_INSTALL libnghttp2-devel 2>&1 | tail -5" || true
                ;;
            alpine)
                _sudo bash -c "apk add --no-cache --upgrade nghttp2-dev curl php${php_ver/./}-curl 2>&1 | tail -5" || true
                ;;
            arch)
                _sudo bash -c "pacman -S --noconfirm libnghttp2 curl php-curl 2>&1 | tail -5" || true
                ;;
        esac
        # 再次自检
        http2_ok="$(php -r 'echo (defined("CURL_VERSION_HTTP2") && (curl_version()["features"] & CURL_VERSION_HTTP2)) ? 1 : 0;' 2>/dev/null || echo 0)"
        if [ "$http2_ok" = "1" ]; then
            ok "PHP curl 重装后已支持 HTTP/2"
        else
            error "PHP curl 仍缺少 HTTP/2 支持。iOS APNS 推送要求 PHP 的 curl 扩展编译时启用 libnghttp2。
  请手动执行：
    1) 确认已装 libnghttp2(-dev) 系统包
    2) 重装 php-curl 或重新编译 PHP 带 --with-curl 并确保链接到 libnghttp2
    3) 运行 php -r 'var_dump(curl_version()[\"features\"] \\& CURL_VERSION_HTTP2);' 验证输出 int(1)"
        fi
    fi

    # 确认核心依赖
    local need_report=()
    _need_cmd php    || need_report+=("php")
    _need_cmd mysql  || need_report+=("mysql-client")
    _need_cmd redis-cli || need_report+=("redis-cli")
    _need_cmd nginx  || need_report+=("nginx")
    _need_cmd node   || need_report+=("node")
    _need_cmd npm    || need_report+=("npm")
    _need_cmd composer || need_report+=("composer")
    if [ ${#need_report[@]} -gt 0 ]; then
        warn "未检测到依赖: ${need_report[*]}，请手动安装后再执行；其它正常项仍继续"
    else
        ok "核心运行环境已就绪"
    fi
}

_clone_code() {
    if [ -d "$PROJECT_DIR" ] && [ -f "$PROJECT_DIR/backend/public/index.php" ]; then
        info "$PROJECT_DIR 已存在项目源码，跳过拉取"
        return 0
    fi
    _sudo mkdir -p "$PROJECT_DIR"
    _sudo chown -R "$(id -un):$(id -gn)" "$PROJECT_DIR" 2>/dev/null || true
    local url="$GITHUB_REPO"
    # 尝试直连，失败则代理
    if ! git clone --depth 1 "$url" "$PROJECT_DIR" 2>&1 | tail -3; then
        info "直连 GitHub 失败，切换到 gh-proxy..."
        git clone --depth 1 "${GH_PROXY}${GITHUB_REPO}" "$PROJECT_DIR" 2>&1 | tail -3 || {
            error "代码拉取失败，请检查网络"; return 1
        }
    fi
    git -C "$PROJECT_DIR" config --global --add safe.directory "$PROJECT_DIR" >/dev/null 2>&1 || true
    ok "代码已拉取到 $PROJECT_DIR"
}

_write_env() {
    local envf="$PROJECT_DIR/backend/.env"
    if [ -f "$envf" ]; then
        info ".env 已存在，跳过生成（如需重配请先删除）"
        return 0
    fi
    # DB root 密码，尝试用 socket 免密或用户输入
    local db_root_pass="" db_user="${DB_USER:-im_push}" db_pass="${DB_PASS:-ImPush@$(date +%Y)}" db_name="${DB_NAME:-im_push}"
    local jwt_sec aes_key
    jwt_sec="$(openssl rand -base64 48 2>/dev/null || php -r 'echo bin2hex(random_bytes(32));' 2>/dev/null || echo change_me_change_me_change_me_change_me_)"
    aes_key="$(openssl rand -hex 32 2>/dev/null || php -r 'echo bin2hex(random_bytes(32));' 2>/dev/null || printf '0%.0s' {1..64})"
    cat > /tmp/push.env.tmp <<-EOF
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=${db_name}
DB_USER=${db_user}
DB_PASS=${db_pass}
TIMEZONE=Asia/Shanghai
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
WEBSOCKET_HOST=0.0.0.0
WEBSOCKET_PORT=9502
HTTP_HOST=0.0.0.0
HTTP_PORT=9501
JWT_SECRET=${jwt_sec}
JWT_EXPIRE=7200
JWT_BLACKLIST_ENABLED=1
JWT_ALLOW_WEAK_SECRET=0
AES_KEY=${aes_key}
SMS_API_KEY=your_sms_api_key
SMS_API_URL=https://sms.example.com/send
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=no-reply@example.com
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_SENDER_NAME="Push 推送服务"
OFFLINE_MESSAGE_TTL=86400
DEFAULT_HEARTBEAT_INTERVAL=30
PASSWORD_POLICY_STRICT=1
SYSTEM_UPDATE_ALLOW_REMOTE=0
GITHUB_TOKEN=
GITHUB_OWNER=jiujiu123520
GITHUB_REPO=im-push-system
GITHUB_WORKFLOW_FILE=build-apk.yml
GITHUB_API_PROXY=https://gh.jasonzeng.dev/
GITHUB_API_TIMEOUT=30
EOF
    _sudo cp /tmp/push.env.tmp "$envf"
    _sudo chown "$OS_USER:$OS_GROUP" "$envf"
    _sudo chmod 600 "$envf"
    rm -f /tmp/push.env.tmp
    ok ".env 已生成（数据库用户=${db_user} 密码=${db_pass}）"
}

_composer_install() {
    [ -d "$PROJECT_DIR/backend" ] || { error "backend/ 目录不存在"; return 1; }
    cd "$PROJECT_DIR/backend" || return 1
    export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_AUDIT_BLOCK_INSECURE=false
    if [ -f composer.json ]; then
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader 2>&1 | tail -10 || {
            warn "首次 composer install 失败，自动重试（忽略平台 req）..."
            composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs 2>&1 | tail -5 || { error "composer install 失败"; return 1; }
        }
    fi
    ok "PHP 依赖安装完成"
}

_setup_database() {
    [ -f "$PROJECT_DIR/backend/.env" ] || { error ".env 不存在，无法读取数据库配置"; return 1; }
    cd "$PROJECT_DIR/backend" || return 1
    # 读 DB 配置
    local db_user db_pass db_name
    db_user="$(grep -E '^DB_USER=' .env | tail -1 | cut -d= -f2)"
    db_pass="$(grep -E '^DB_PASS=' .env | tail -1 | cut -d= -f2)"
    db_name="$(grep -E '^DB_NAME=' .env | tail -1 | cut -d= -f2)"
    # 尝试连接（优先 socket root，失败时用配置用户）
    local try_cnf=""
    if echo "SELECT 1;" | mysql -uroot --protocol=socket --connect-timeout=3 -N >/dev/null 2>&1; then
        try_cnf="root-socket"
    elif echo "SELECT 1;" | mysql -u"$db_user" -p"$db_pass" --connect-timeout=3 -N >/dev/null 2>&1; then
        try_cnf="env-user"
    fi
    if [ "$try_cnf" = "root-socket" ]; then
        # 创建数据库和用户
        mysql -uroot --protocol=socket <<-EOF
CREATE DATABASE IF NOT EXISTS \`${db_name}\` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${db_user}'@'127.0.0.1' IDENTIFIED BY '${db_pass}';
GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
EOF
    fi
    # 迁移
    php bin/migrate_sql.php 2>&1 | tail -10 || warn "数据库迁移执行失败，请手动执行: cd $PROJECT_DIR/backend && php bin/migrate_sql.php"
    ok "数据库与迁移执行完成"
}

_build_frontend() {
    cd "$PROJECT_DIR" || return 1
    # admin
    if [ -d admin ] && [ -f admin/package.json ]; then
        step "  构建管理后台 (admin/)"
        (cd admin && npm ci --prefer-offline 2>&1 | tail -3 && npm run build 2>&1 | tail -5) || warn "admin 构建失败，可稍后执行: cd $PROJECT_DIR/admin && npm ci && npm run build"
    fi
    if [ -d user ] && [ -f user/package.json ]; then
        step "  构建用户端 (user/)"
        (cd user && npm ci --prefer-offline 2>&1 | tail -3 && npm run build 2>&1 | tail -5) || warn "user 构建失败"
    fi
    ok "前端构建完成"
}

_deploy_services() {
    cd "$PROJECT_DIR" || return 1
    local php_bin web_user_safe
    php_bin="$(command -v php 2>/dev/null || echo /usr/bin/php)"
    web_user_safe="$OS_USER"
    # systemd 服务
    if _need_cmd systemctl; then
        for svc in push-http push-websocket; do
            local dst="/etc/systemd/system/${svc}.service"
            if [ -f "deploy/systemd/${svc}.service" ]; then
                _sudo cp "deploy/systemd/${svc}.service" "$dst"
                _sudo sed -i "s|/www/push-system|${PROJECT_DIR}|g; s|/usr/bin/php|${php_bin}|g; s|User=www-data|User=${web_user_safe}|g; s|Group=www-data|Group=${web_user_safe}|g" "$dst"
                _sudo systemctl daemon-reload >/dev/null 2>&1
                _sudo systemctl enable "$svc" >/dev/null 2>&1 || true
            fi
        done
        ok "systemd 服务已注册并启用开机自启"
    else
        warn "未检测到 systemd，请手动启动: cd $PROJECT_DIR/backend && php public/index.php （HTTP）/ php public/index.php --ws（WS）"
    fi
    # nginx
    if [ -d "$NGINX_CONF_DIR" ]; then
        local conf_path=""
        if   [ -d "$NGINX_SITE_DIR" ]; then conf_path="$NGINX_SITE_DIR/push.conf"
        else conf_path="$NGINX_CONF_DIR/conf.d/push.conf"; fi
        _sudo cp deploy/nginx/push-system.conf "$conf_path" 2>/dev/null || true
        if [ -f "$conf_path" ]; then
            _sudo sed -i "s|server_name push\.example\.com|server_name _;|; s|root /www/push-system/admin/dist;|root ${PROJECT_DIR}/admin/dist;|; s|alias /www/push-system/user/dist;|alias ${PROJECT_DIR}/user/dist;|; s|root /www/push-system/acme;|root ${PROJECT_DIR}/acme;|g" "$conf_path" 2>/dev/null || true
            # Debian: 软链接到 sites-enabled
            if [ -d /etc/nginx/sites-enabled ] && [ ! -f /etc/nginx/sites-enabled/push.conf ]; then
                _sudo ln -sf "$conf_path" /etc/nginx/sites-enabled/push.conf 2>/dev/null || true
            fi
            # 先清理默认 default.conf 冲突（若 default_server 被占用）
            _sudo nginx -t >/dev/null 2>&1 && _sudo systemctl reload nginx >/dev/null 2>&1 || warn "nginx -t 失败，请手动检查 $conf_path"
            ok "Nginx 配置已写入: $conf_path"
        fi
    fi
    # sudoers 配置（用于 GitHub Actions 重启服务）
    if [ -f deploy/sudoers-push-system ]; then
        _sudo cp deploy/sudoers-push-system /etc/sudoers.d/push-system 2>/dev/null && _sudo chmod 440 /etc/sudoers.d/push-system 2>/dev/null || true
    fi
    # apk 上传脚本可执行权限
    if [ -f deploy/apk/upload-to-feijipan.sh ]; then
        _sudo chmod +x deploy/apk/upload-to-feijipan.sh 2>/dev/null || true
    fi
}

_fix_permissions() {
    cd "$PROJECT_DIR" || return 1
    _sudo mkdir -p backend/storage backend/runtime uploads acme
    _sudo chown -R "$OS_USER:$OS_GROUP" backend/storage backend/runtime uploads acme backend/.env 2>/dev/null || true
    _sudo chmod -R u+rwX backend/storage backend/runtime uploads acme 2>/dev/null || true
    _sudo chmod 600 backend/.env 2>/dev/null || true
    _sudo chown -R "$OS_USER:$OS_GROUP" admin/dist user/dist 2>/dev/null || true
    ok "权限已修正"
}

_start_all() {
    if _need_cmd systemctl; then
        _sudo systemctl reset-failed 2>/dev/null || true
        _sudo systemctl daemon-reload >/dev/null 2>&1
        for s in mysql mariadb mysqld redis redis-server nginx; do
            _sudo systemctl enable --now "$s" >/dev/null 2>&1 || true
        done
        for s in push-http push-websocket; do
            _sudo systemctl restart "$s" >/dev/null 2>&1 || _sudo systemctl start "$s" >/dev/null 2>&1 || true
            _sudo systemctl enable "$s" >/dev/null 2>&1 || true
        done
        _sudo systemctl reload nginx >/dev/null 2>&1 || true
    fi
    sleep 2
    _service_status_short || true
    ok "安装完成！默认管理员账号 admin / admin123（请立即修改）"
}

_print_install_summary() {
    echo ""
    echo -e "${C_C}┌───────────────────────────────────────────────────────────┐${C_0}"
    echo -e "${C_C}│                     安装完成                               │${C_0}"
    echo -e "${C_C}├───────────────────────────────────────────────────────────┤${C_0}"
    printf  "${C_C}│${C_0} 管理后台  http://<服务器IP>/admin              (admin/admin123) ${C_C}│${C_0}\n"
    printf  "${C_C}│${C_0} 用户端    http://<服务器IP>/user                         ${C_C}│${C_0}\n"
    printf  "${C_C}│${C_0} HTTP API  http://<服务器IP>:9501                         ${C_C}│${C_0}\n"
    printf  "${C_C}│${C_0} WebSocket ws(s)://<服务器IP>/ws/client                   ${C_C}│${C_0}\n"
    printf  "${C_C}│${C_0} 项目目录  %-46s ${C_C}│${C_0}\n" "$PROJECT_DIR"
    echo -e "${C_C}│                                                           │${C_0}"
    echo -e "${C_C}│ 后续请使用 bash deploy/setup.sh [3 更新 / 4 服务管理]     │${C_0}"
    echo -e "${C_C}└───────────────────────────────────────────────────────────┘${C_0}"
    echo ""
}

# =========== [3] 更新系统 ===========
do_update() {
    title "更新系统代码"
    _detect_distro
    if [ ! -d "$PROJECT_DIR/.git" ]; then
        error "$PROJECT_DIR 不是 Git 仓库，请先执行安装或切换为 Git 部署"
        return 1
    fi
    cd "$PROJECT_DIR" || return 1

    # 交互参数
    local skip_build="" skip_migrate="" gh_proxy=""
    echo ""
    _safe_read "是否使用 GitHub 代理（gh.jasonzeng.dev）加速国内拉取？[Y/n] " gh_proxy
    case "$gh_proxy" in n|N|no|NO) gh_proxy="";; *) gh_proxy="1";; esac
    _safe_read "是否跳过前端构建（只改了后端 PHP 时建议选 y）？[y/N] " skip_build
    case "$skip_build" in y|Y|yes|YES) skip_build="1";; *) skip_build="";; esac
    _safe_read "是否跳过数据库迁移（确认没有新 migration 文件时选 y）？[y/N] " skip_migrate
    case "$skip_migrate" in y|Y|yes|YES) skip_migrate="1";; *) skip_migrate="";; esac

    step "1/5 修权限 + 强制拉取最新代码"
    _sudo chown -R "$(id -un):$(id -gn)" "$PROJECT_DIR" 2>/dev/null || true
    git -C "$PROJECT_DIR" config --global --add safe.directory "$PROJECT_DIR" >/dev/null 2>&1 || true
    if [ -n "$gh_proxy" ]; then
        git -C "$PROJECT_DIR" remote set-url origin "${GH_PROXY}${GITHUB_REPO}" 2>/dev/null || true
    fi
    # 强制同步策略（与 backend/deploy/update.sh 一致，替代 git pull --ff-only）：
    #   checkout 还原所有已跟踪文件的本地改动 + clean 清理未跟踪文件（保留 lock 文件）
    #   + reset --hard 对齐远端。服务器上的手动改动/构建残留不会再挡住更新。
    git -C "$PROJECT_DIR" checkout -- . 2>/dev/null || true
    git -C "$PROJECT_DIR" clean -fd \
        -e 'composer.lock' -e 'package-lock.json' \
        -e 'backend/composer.lock' -e 'admin/package-lock.json' \
        -e 'user/package-lock.json' 2>/dev/null || true

    # fetch 失败必须中断（检查退出码；输出经管道会吞退出码，故用命令替换捕获）
    local fetch_out fetch_rc before after reset_out reset_rc
    fetch_out="$(git -C "$PROJECT_DIR" fetch --force origin main --prune 2>&1)"
    fetch_rc=$?
    [ -n "$fetch_out" ] && echo "$fetch_out" | tail -3
    if [ "$fetch_rc" -ne 0 ]; then
        error "git fetch 失败（退出码 $fetch_rc），更新已中止。请检查网络或 GitHub 代理设置后重试"
        return 1
    fi

    before="$(git -C "$PROJECT_DIR" rev-parse HEAD)"
    reset_out="$(git -C "$PROJECT_DIR" reset --hard origin/main 2>&1)"
    reset_rc=$?
    [ -n "$reset_out" ] && echo "$reset_out" | tail -3
    if [ "$reset_rc" -ne 0 ]; then
        error "git reset --hard 失败（退出码 $reset_rc），更新已中止"
        return 1
    fi
    after="$(git -C "$PROJECT_DIR" rev-parse HEAD)"
    if [ "$before" = "$after" ]; then
        info "代码已是最新 ($after)，仍继续后续流程"
    else
        ok "已更新: ${before:0:7} → ${after:0:7}"
        git -C "$PROJECT_DIR" log --oneline "$before..$after" 2>/dev/null | head -5
    fi

    step "2/5 更新 PHP 依赖（composer）"
    (cd "$PROJECT_DIR/backend" && export COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_AUDIT_BLOCK_INSECURE=false && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader 2>&1 | tail -5) || warn "composer install 失败"

    step "3/5 数据库迁移"
    if [ "$skip_migrate" = "1" ]; then
        info "跳过数据库迁移"
    else
        (cd "$PROJECT_DIR/backend" && _sudo php bin/migrate_sql.php 2>&1 | tail -10) || warn "迁移失败，请手动执行"
    fi

    step "4/5 构建前端"
    if [ "$skip_build" = "1" ]; then
        info "跳过前端构建"
    else
        _build_frontend
    fi

    step "5/5 修权限 + 重启服务"
    _fix_permissions
    if _need_cmd systemctl; then
        _sudo systemctl reset-failed 2>/dev/null || true
        for s in push-http push-websocket; do
            _sudo systemctl restart "$s" >/dev/null 2>&1 || true
        done
        _sudo systemctl reload nginx >/dev/null 2>&1 || true
    fi
    sleep 2
    _service_status_short
    ok "更新完成 ($(git -C "$PROJECT_DIR" rev-parse --short HEAD 2>/dev/null))"
}

# =========== [4] 服务管理 ===========
do_service_menu() {
    local reply=""
    while true; do
        title "服务管理"
        _service_status_short
        echo ""
        echo -e "  ${C_G}1.${C_0} 启动所有服务"
        echo -e "  ${C_G}2.${C_0} 停止所有服务"
        echo -e "  ${C_G}3.${C_0} 重启所有服务"
        echo -e "  ${C_G}4.${C_0} 仅启动/重启/停止/查看 push-http"
        echo -e "  ${C_G}5.${C_0} 仅启动/重启/停止/查看 push-websocket"
        echo -e "  ${C_G}6.${C_0} 查看 push-http 日志（最近 50 行）"
        echo -e "  ${C_G}7.${C_0} 查看 push-websocket 日志（最近 50 行）"
        echo -e "  ${C_G}8.${C_0} 重载 Nginx"
        echo -e "  ${C_G}0.${C_0} 返回主菜单"
        _safe_read "
请输入选项 [0-8]: " reply
        case "$reply" in
            1) _svcop_all "start" ;;
            2) _svcop_all "stop" ;;
            3) _svcop_all "restart" ;;
            4) _svcop_single push-http ;;
            5) _svcop_single push-websocket ;;
            6) _show_journal push-http ;;
            7) _show_journal push-websocket ;;
            8) if _need_cmd systemctl; then _sudo systemctl reload nginx && ok "nginx 已重载"; else warn "未检测到 systemctl"; fi ;;
            0) return ;;
            *) echo -e "${C_Y}无效选项${C_0}" ;;
        esac
        echo ""
    done
}
_svcop_all() {
    local op="$1"
    if _need_cmd systemctl; then
        for s in push-http push-websocket; do _sudo systemctl "$op" "$s" >/dev/null 2>&1 || true; done
        for s in nginx redis redis-server mysql mysqld mariadb; do
            case "$op" in restart|start) _sudo systemctl enable --now "$s" >/dev/null 2>&1 || true ;;
                                    stop) _sudo systemctl stop        "$s" >/dev/null 2>&1 || true ;;
            esac
        done
    fi
    sleep 2
    _service_status_short
}
_svcop_single() {
    local svc="$1" sub=""
    _safe_read "操作: [start/stop/restart/status]  默认 restart: " sub
    [ -z "$sub" ] && sub="restart"
    if _need_cmd systemctl; then
        case "$sub" in
            status) _sudo systemctl --no-pager status "$svc" --lines=20 | tail -20 ;;
            *)       _sudo systemctl reset-failed >/dev/null 2>&1 || true
                     _sudo systemctl daemon-reload >/dev/null 2>&1 || true
                     _sudo systemctl "$sub" "$svc" 2>&1 | tail -3
                     _sudo systemctl --no-pager status "$svc" --lines=5 | tail -5 ;;
        esac
    else
        warn "未检测到 systemctl"
    fi
}
_show_journal() {
    local svc="$1" n="${2:-50}"
    if _need_cmd journalctl; then
        _sudo journalctl -u "$svc" -n "$n" --no-pager 2>&1 | tail -"$n"
    elif [ -d "$PROJECT_DIR/backend/runtime/logs" ]; then
        find "$PROJECT_DIR/backend/runtime/logs" -type f -name "*.log" 2>/dev/null | while read -r f; do echo "--- $f ---"; tail -20 "$f"; done
    else
        warn "未找到 journalctl 或日志目录"
    fi
}
_service_status_short() {
    echo ""
    _need_cmd systemctl || { warn "未检测到 systemctl，跳过状态查询"; return; }
    local svcs=(push-http push-websocket nginx redis redis-server mysql mysqld mariadb) printed=0
    local shown=()
    for s in "${svcs[@]}"; do
        local ok=0
        for x in "${shown[@]:-}"; do [ "$x" = "$s" ] && { ok=1; break; }; done
        [ "$ok" = "1" ] && continue
        if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -q "^${s}\.service$" || systemctl status "$s" >/dev/null 2>&1; then
            local t st
            t="$(systemctl is-active "$s" 2>/dev/null || echo unknown)"
            case "$t" in
                active)   st="${C_G}● active${C_0}"; ;;
                inactive) st="${C_Y}○ inactive${C_0}"; ;;
                failed)   st="${C_R}✗ failed${C_0}"; ;;
                *)        st="$t"; ;;
            esac
            printf "  %-18s %s\n" "$s" "$st"
            shown+=("$s"); printed=1
        fi
    done
    [ "$printed" = "0" ] && warn "未找到已注册服务"
}

# =========== [5] 卸载 ===========
do_uninstall() {
    title "卸载"
    _detect_distro
    echo ""
    echo -e "  ${C_G}1.${C_0} 仅卸载环境（PHP/MySQL/Redis/Nginx/Node），保留源码和数据"
    echo -e "  ${C_G}2.${C_0} 仅删除源码（${PROJECT_DIR}），保留运行环境与数据"
    echo -e "  ${C_G}3.${C_0} 完全卸载（环境 + 源码 + 数据库）⚠️ 不可恢复"
    echo -e "  ${C_G}0.${C_0} 取消"
    local mode="" reply=""
    _safe_read "
请输入选项 [0-3]: " mode
    [ "$mode" = "0" ] || [ -z "$mode" ] && { info "已取消"; return 0; }
    case "$mode" in
        1) mode="env" ;;
        2) mode="source" ;;
        3) mode="all" ;;
        *) error "无效选项"; return 1 ;;
    esac
    _safe_read "确认继续吗？该操作不可恢复！输入 YES 继续: " reply
    [ "$reply" != "YES" ] && { info "已取消"; return 0; }
    step "停止所有相关服务"
    if _need_cmd systemctl; then
        for s in push-http push-websocket; do
            _sudo systemctl stop        "$s" 2>/dev/null || true
            _sudo systemctl disable     "$s" 2>/dev/null || true
            _sudo rm -f "/etc/systemd/system/${s}.service"
        done
        _sudo systemctl daemon-reload >/dev/null 2>&1 || true
        _sudo systemctl reset-failed   2>/dev/null || true
    fi
    case "$mode" in
        source|all)
            step "删除项目源码目录 ${PROJECT_DIR}"
            if [ -d "$PROJECT_DIR" ]; then
                _sudo rm -rf "$PROJECT_DIR"
                ok "源码已删除"
            fi
            [ "$mode" = "source" ] && return 0
            ;;
    esac
    case "$mode" in
        env|all)
            step "卸载 systemd 注册 / sudoers / nginx 配置"
            _sudo rm -f /etc/sudoers.d/push-system 2>/dev/null || true
            for d in /etc/nginx/sites-enabled /etc/nginx/sites-available /etc/nginx/conf.d /etc/nginx/http.d /etc/nginx/vhosts.d; do
                [ -d "$d" ] && _sudo rm -f "$d/push.conf" 2>/dev/null || true
            done
            step "卸载运行环境包"
            local ok=0
            case "$DISTRO_FAMILY" in
                debian)
                    _sudo bash -c "DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get purge -y php* mysql-* mysql-server redis-server redis-tools nginx* nodejs libnode* composer 2>&1 | tail -15; apt-get autoremove -y 2>&1 | tail -5" 2>/dev/null || true
                    ;;
                rhel)
                    _sudo bash -c "$PKG_REMOVE php* mariadb-server mysql-server redis nginx nodejs npm composer 2>&1 | tail -15" 2>/dev/null || true
                    ;;
                alpine)
                    _sudo bash -c "apk del php* mariadb mysql-client redis nginx nodejs npm composer 2>&1 | tail -15" 2>/dev/null || true
                    ;;
                arch)
                    _sudo bash -c "pacman -Rns --noconfirm php mariadb redis nginx nodejs npm composer 2>&1 | tail -15" 2>/dev/null || true
                    ;;
            esac
            [ "$mode" = "env" ] && { ok "环境已卸载"; return 0; }
            ;;
    esac
    if [ "$mode" = "all" ]; then
        step "清理数据库数据与日志"
        _sudo rm -rf /var/lib/mysql /var/log/mysql /var/log/nginx /var/lib/redis /etc/my.cnf.d /etc/mysql /etc/php* /etc/redis /etc/nginx /opt/remi 2>/dev/null || true
        _sudo rm -f /swapfile 2>/dev/null || true
        step "清理用户数据"
        for u in www-data nginx http wwwrun; do id "$u" >/dev/null 2>&1 && _sudo userdel -r "$u" 2>/dev/null || true; done
        ok "完全卸载完成"
    fi
}

# =========== [6] 重装 ===========
do_reinstall() {
    title "重装系统"
    echo -e "${C_Y}将先执行完全卸载（环境+源码+数据），然后重新完整安装。该操作不可恢复！${C_0}"
    local r=""
    _safe_read "输入 YES 继续，否则取消: " r
    [ "$r" != "YES" ] && { info "已取消"; return 0; }
    SKIP_CONFIRM=1
    UNINSTALL_MODE="all"
    export SKIP_CONFIRM UNINSTALL_MODE
    do_uninstall <<<"YES" 2>/dev/null || do_uninstall < /dev/null || true
    echo ""
    do_install
}

# =========== 主菜单 ===========
main_menu() {
    _detect_distro
    # 每次进入菜单清屏一次
    clear 2>/dev/null || true
    local reply=""
    while true; do
        echo ""
        echo -e "${C_W}╔══════════════════════════════════════════════════════════════╗${C_0}"
        echo -e "${C_W}║           即时消息推送系统 - 统一管理面板                    ║${C_0}"
        echo -e "${C_W}╚══════════════════════════════════════════════════════════════╝${C_0}"
        echo -e "  ${C_C}项目目录${C_0}  $PROJECT_DIR"
        echo -e "  ${C_C}当前系统${C_0}  $DISTRO $DISTRO_VER ($DISTRO_FAMILY) · $CPU_ARCH · ${TOTAL_MEM_MB}MB · ${DISK_GB}GB 剩余"
        echo ""
        echo -e "  ${C_G}[1]${C_0}  系统检测与优化      镜像切换 / Swap / sysctl / 时区 / NTP"
        echo -e "  ${C_G}[2]${C_0}  一键安装部署        环境 + 代码 + 数据库 + 构建 + 服务"
        echo -e "  ${C_G}[3]${C_0}  更新系统            git pull → composer → 迁移 → 构建 → 重启"
        echo -e "  ${C_G}[4]${C_0}  服务管理            启动 / 停止 / 重启 / 日志 / 状态"
        echo -e "  ${C_G}[5]${C_0}  卸载                环境 / 源码 / 完全卸载"
        echo -e "  ${C_G}[6]${C_0}  重装                完全卸载 → 重新安装"
        echo -e "  ${C_G}[0]${C_0}  退出"
        echo ""
        _safe_read "请输入选项 [0-6]: " reply
        case "$reply" in
            1) do_optimize ;;
            2) do_install ;;
            3) do_update ;;
            4) do_service_menu ;;
            5) do_uninstall ;;
            6) do_reinstall ;;
            0) echo -e "${C_C}再见${C_0}"; exit 0 ;;
            *) echo -e "${C_Y}无效选项 $reply${C_0}" ;;
        esac
        echo ""
        sleep 1
    done
}

# =========== 非交互参数入口 ===========
direct_mode="" skip_confirm_flag=""
for arg in "$@"; do
    case "$arg" in
        --install)    direct_mode="install" ;;
        --update)     direct_mode="update" ;;
        --optimize)   direct_mode="optimize" ;;
        --uninstall=*) UNINSTALL_MODE="${arg#*=}"; direct_mode="uninstall" ;;
        --yes)        skip_confirm_flag="1" ;;
        --project-dir=*) PROJECT_DIR="${arg#*=}" ;;
        -h|--help)
            head -n 26 "$0"
            exit 0
            ;;
        *)
            echo "未知参数: $arg" >&2; exit 1
            ;;
    esac
done

# 自动 sudo 提权（非 root 且有 sudo 时重新执行；curl|bash 管道模式下给出提示）
if [ "$(id -u)" != "0" ]; then
    if [[ "$0" == "bash" || "$0" == "-bash" || "$0" == "sh" || "$0" == "-sh" ]]; then
        echo "[ERROR] 请使用 sudo 执行（管道模式无法自动提权），例如:"
        echo "  curl -sSL https://gh.jasonzeng.dev/https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/deploy/setup.sh -o /tmp/setup.sh && sudo bash /tmp/setup.sh"
        exit 1
    fi
    echo "[INFO] 正在通过 sudo 获取 root 权限..."
    exec sudo -E bash "$0" "$@"
fi

case "$direct_mode" in
    install)    SKIP_CONFIRM="$skip_confirm_flag" do_install ;;
    update)     SKIP_CONFIRM="$skip_confirm_flag" do_update ;;
    optimize)   SKIP_CONFIRM="$skip_confirm_flag" do_optimize ;;
    uninstall)
        SKIP_CONFIRM="$skip_confirm_flag"
        case "${UNINSTALL_MODE:-}" in
            env|source|all) ;;
            *) echo "[ERROR] --uninstall=env|source|all"; exit 1 ;;
        esac
        # 非交互：模拟 YES 输入
        printf 'YES\n' | UNINSTALL_MODE="$UNINSTALL_MODE" SKIP_CONFIRM=1 bash -c '
            f() { read -r _; }; export -f f
            source <(sed -n "/^do_uninstall/,/^}$/p" '"$0"') || true
            do_uninstall
        ' 2>/dev/null || {
            # 简化：直接执行
            _detect_distro
            step "停止所有相关服务"
            if _need_cmd systemctl; then
                for s in push-http push-websocket; do
                    _sudo systemctl stop "$s" 2>/dev/null || true
                    _sudo systemctl disable "$s" 2>/dev/null || true
                    _sudo rm -f "/etc/systemd/system/${s}.service"
                done
                _sudo systemctl daemon-reload >/dev/null 2>&1 || true
                _sudo systemctl reset-failed 2>/dev/null || true
            fi
            _sudo rm -f /etc/sudoers.d/push-system 2>/dev/null || true
            for d in /etc/nginx/sites-enabled /etc/nginx/sites-available /etc/nginx/conf.d /etc/nginx/http.d /etc/nginx/vhosts.d; do
                [ -d "$d" ] && _sudo rm -f "$d/push.conf" 2>/dev/null || true
            done
            case "$UNINSTALL_MODE" in
                source|all) _sudo rm -rf "$PROJECT_DIR" && ok "源码已删除"; [ "$UNINSTALL_MODE" = "source" ] && exit 0 ;;
            esac
            case "$UNINSTALL_MODE" in
                env|all)
                    case "$DISTRO_FAMILY" in
                        debian) _sudo bash -c "DEBIAN_FRONTEND=noninteractive apt-get purge -y php* mysql-* redis-server nginx* nodejs libnode* composer 2>&1 | tail -10; apt-get autoremove -y 2>&1 | tail -3" 2>/dev/null || true ;;
                        rhel)   _sudo bash -c "$PKG_REMOVE php* mariadb-server mysql-server redis nginx nodejs npm composer 2>&1 | tail -10" 2>/dev/null || true ;;
                        alpine) _sudo bash -c "apk del php* mariadb redis nginx nodejs npm composer 2>&1 | tail -10" 2>/dev/null || true ;;
                        arch)   _sudo bash -c "pacman -Rns --noconfirm php mariadb redis nginx nodejs npm composer 2>&1 | tail -10" 2>/dev/null || true ;;
                    esac
                    [ "$UNINSTALL_MODE" = "env" ] && exit 0
                    ;;
            esac
            if [ "$UNINSTALL_MODE" = "all" ]; then
                _sudo rm -rf /var/lib/mysql /var/log/mysql /var/log/nginx /var/lib/redis /etc/mysql /etc/php* /etc/nginx /etc/redis 2>/dev/null || true
                _sudo rm -f /swapfile 2>/dev/null || true
                for u in www-data nginx http wwwrun; do id "$u" >/dev/null 2>&1 && _sudo userdel -r "$u" 2>/dev/null || true; done
                ok "完全卸载完成"
            fi
            exit 0
        }
        ;;
    "") main_menu ;;
esac
