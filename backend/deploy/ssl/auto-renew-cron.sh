#!/bin/bash
# ============================================================
# SSL 证书自动续费脚本（由 cron 定时调用）
#
# 功能：
#   1. 查询数据库中所有 ssl_auto_renew=1 的域名
#   2. 检查证书过期时间，30天内到期则自动续费
#   3. 续费后自动重载 Nginx
#   4. 记录日志到 /var/log/push-ssl-renew.log
#
# 安装方式（加到 crontab）：
#   # 每天凌晨 3 点执行一次
#   echo "0 3 * * * /www/push-system/backend/deploy/ssl/auto-renew-cron.sh" | sudo tee /etc/cron.d/push-ssl-renew
#   sudo chmod 644 /etc/cron.d/push-ssl-renew
# ============================================================

set -e

# 项目根目录：优先从脚本位置推断，兜底使用默认路径
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="${PROJECT_ROOT:-$(dirname "$(dirname "$(dirname "$SCRIPT_DIR")")")}"
if [ ! -f "${PROJECT_ROOT}/backend/.env" ]; then
    PROJECT_ROOT="/www/push-system"
fi
LOG_FILE="/var/log/push-ssl-renew.log"
SSL_DIR="/etc/nginx/ssl"

_date_to_ts() {
    local date_str="$1"
    local ts=0

    ts=$(date -d "$date_str" +%s 2>/dev/null)
    if [ -n "$ts" ] && [ "$ts" -gt 0 ] 2>/dev/null; then
        echo "$ts"
        return 0
    fi

    local clean_str="${date_str% GMT}"
    clean_str="${clean_str% UTC}"
    ts=$(date -j -f "%b %d %T %Y" "$clean_str" +%s 2>/dev/null)
    if [ -n "$ts" ] && [ "$ts" -gt 0 ] 2>/dev/null; then
        echo "$ts"
        return 0
    fi

    echo "0"
    echo "[WARN] _date_to_ts: 无法解析日期 '$date_str'" >&2
    return 1
}

_now_ts() {
    local ts
    ts=$(date +%s 2>/dev/null)
    if [ -n "$ts" ]; then
        echo "$ts"
    else
        echo "0"
        echo "[WARN] _now_ts: date +%s 失败" >&2
    fi
}

_detect_web_user() {
    local web_user=""

    for candidate in www-data nginx apache nobody httpd; do
        if id "$candidate" >/dev/null 2>&1; then
            web_user="$candidate"
            break
        fi
    done

    if [ -z "$web_user" ]; then
        web_user="$(whoami 2>/dev/null || echo "root")"
    fi

    if [ -n "$SSL_DIR" ] && [ -d "$SSL_DIR" ]; then
        if [ ! -w "$SSL_DIR" ]; then
            echo "[INFO] _detect_web_user: $web_user 对 $SSL_DIR 无写权限，当前用户: $(whoami)" >&2
        fi
    fi

    echo "[INFO] _detect_web_user: 检测到 web 用户=$web_user，当前用户=$(whoami 2>/dev/null)" >&2
    echo "$web_user"
}

_log() {
    local msg="$1"
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "unknown-time")
    local formatted="[$timestamp] $msg"

    {
        echo "$formatted" >> "$LOG_FILE"
    } 2>/dev/null || {
        echo "$formatted" >&2
    }
}

# 多路径检测 acme.sh 安装位置
ACME_SH=""
for candidate in /root/.acme.sh/acme.sh /home/ubuntu/.acme.sh/acme.sh /usr/local/bin/acme.sh "$HOME/.acme.sh/acme.sh"; do
    if [ -x "$candidate" ]; then
        ACME_SH="$candidate"
        break
    fi
done
if [ -z "$ACME_SH" ]; then
    ACME_SH="$(which acme.sh 2>/dev/null || echo '')"
fi
if [ -z "$ACME_SH" ]; then
    _log "[ERROR] 未找到 acme.sh，请先安装"
    exit 1
fi

_detect_web_user >/dev/null 2>&1 || true

# 读取数据库配置
ENV_FILE="${PROJECT_ROOT}/backend/.env"
if [ ! -f "$ENV_FILE" ]; then
    _log "[ERROR] .env 文件不存在"
    exit 1
fi

# 解析 .env
DB_HOST=$(grep -E '^DB_HOST=' "$ENV_FILE" | cut -d= -f2)
DB_PORT=$(grep -E '^DB_PORT=' "$ENV_FILE" | cut -d= -f2)
DB_NAME=$(grep -E '^DB_NAME=' "$ENV_FILE" | cut -d= -f2)
DB_USER=$(grep -E '^DB_USER=' "$ENV_FILE" | cut -d= -f2)
DB_PASS=$(grep -E '^DB_PASS=' "$ENV_FILE" | cut -d= -f2)

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-im_push}"

_log "===== 开始自动续费检查 ====="

# 查询需要续费的域名（30天内到期）
QUERY="SELECT domain FROM domains WHERE ssl_enabled=1 AND ssl_status='issued' AND ssl_auto_renew=1 AND status=1"

DOMAINS=$(mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -N -e "${QUERY}" 2>/dev/null || echo "")

if [ -z "$DOMAINS" ]; then
    _log "没有需要检查的域名"
    exit 0
fi

RENEWED=0
FAILED=0

for DOMAIN in $DOMAINS; do
    DOMAIN=$(echo "$DOMAIN" | tr -d '[:space:]')
    [ -z "$DOMAIN" ] && continue

    CERT_FILE="${SSL_DIR}/${DOMAIN}.crt"
    KEY_FILE="${SSL_DIR}/${DOMAIN}.key"

    if [ ! -f "$CERT_FILE" ]; then
        _log "[SKIP] ${DOMAIN}: 证书文件不存在"
        continue
    fi

    EXPIRE_DATE=$(openssl x509 -in "$CERT_FILE" -noout -enddate 2>/dev/null | cut -d= -f2)
    if [ -z "$EXPIRE_DATE" ]; then
        _log "[SKIP] ${DOMAIN}: 无法读取过期时间"
        continue
    fi

    EXPIRE_TS=$(_date_to_ts "$EXPIRE_DATE")
    NOW_TS=$(_now_ts)
    DAYS_LEFT=$(( (EXPIRE_TS - NOW_TS) / 86400 ))

    _log "[CHECK] ${DOMAIN}: 剩余 ${DAYS_LEFT} 天"

    if [ "$DAYS_LEFT" -lt 30 ]; then
        _log "[RENEW] ${DOMAIN}: 开始续费..."

        RENEW_OUTPUT=$("$ACME_SH" --renew -d "$DOMAIN" --ecc --force 2>&1) || true

        if [ -f "$CERT_FILE" ]; then
            "$ACME_SH" --install-cert -d "$DOMAIN" --ecc \
                --key-file "$KEY_FILE" \
                --fullchain-file "$CERT_FILE" \
                --reloadcmd "nginx -s reload" 2>&1 | while IFS= read -r line; do _log "$line"; done || true
        fi

        NEW_EXPIRE=$(openssl x509 -in "$CERT_FILE" -noout -enddate 2>/dev/null | cut -d= -f2)
        if [ -n "$NEW_EXPIRE" ]; then
            NEW_TS=$(_date_to_ts "$NEW_EXPIRE")
            NEW_DAYS=$(( (NEW_TS - NOW_TS) / 86400 ))
            if [ "$NEW_DAYS" -gt 30 ]; then
                _log "[OK] ${DOMAIN}: 续费成功，新过期时间 ${NEW_EXPIRE}（剩余 ${NEW_DAYS} 天）"
                mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
                    -e "UPDATE domains SET ssl_expire_at='${NEW_EXPIRE}', ssl_last_renew_at=NOW(), ssl_status='issued', ssl_error='' WHERE domain='${DOMAIN}'" 2>/dev/null || true
                RENEWED=$((RENEWED + 1))
            else
                _log "[FAIL] ${DOMAIN}: 续费后剩余天数不足"
                mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
                    -e "UPDATE domains SET ssl_error='续费失败' WHERE domain='${DOMAIN}'" 2>/dev/null || true
                FAILED=$((FAILED + 1))
            fi
        else
            _log "[FAIL] ${DOMAIN}: 续费后无法读取证书"
            FAILED=$((FAILED + 1))
        fi
    fi
done

_log "===== 续费完成：成功 ${RENEWED} 个，失败 ${FAILED} 个 ====="
_log ""
