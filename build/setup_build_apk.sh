#!/bin/bash
# ============================================================
# GitHub Actions APK 构建 — 一键配置脚本
# 功能：
#   1. 生成 release keystore（如果不存在）
#   2. 生成专用 SSH 密钥（如果不存在）
#   3. 追加服务器 .env 的 GITHUB_* 配置
#   4. 输出需要填到 GitHub Secrets 的所有值
# 用法：
#   curl -sL https://raw.githubusercontent.com/jiujiu123520/im-push-system/main/build/setup_build_apk.sh | bash
#   或者：bash build/setup_build_apk.sh
# ============================================================
set -e

PROJECT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$PROJECT_DIR/backend/.env"
KEYSTORE_DIR="$PROJECT_DIR/build/keystore"
SSH_KEY_PATH="$HOME/.ssh/github_actions_build"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; BLUE='\033[0;34m'; NC='\033[0m'
info()  { echo -e "${GREEN}[OK]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
error() { echo -e "${RED}[X]${NC} $*" >&2; }
step()  { echo -e "\n${BLUE}==== $* ====${NC}"; }

echo -e "${BLUE}"
echo "╔══════════════════════════════════════════════╗"
echo "║   PushApp — GitHub Actions APK 构建配置向导  ║"
echo "╚══════════════════════════════════════════════╝"
echo -e "${NC}"
echo "项目路径: $PROJECT_DIR"
echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"

# ---------------- 1. 生成 keystore ----------------
step "1/4 生成 release keystore"

if [ ! -d "$KEYSTORE_DIR" ]; then
    mkdir -p "$KEYSTORE_DIR"
    info "已创建 $KEYSTORE_DIR"
fi

if [ -f "$KEYSTORE_DIR/release.keystore" ]; then
    warn "已存在 release.keystore，跳过生成"
    echo -e "  如要重新生成：rm -f $KEYSTORE_DIR/release.keystore"
else
    if command -v keytool >/dev/null 2>&1; then
        STORE_PWD="pushapp_release_$(date +%Y%m%d)"
        KEY_PWD="$STORE_PWD"
        ALIAS="release"
        keytool -genkeypair \
            -v \
            -keystore "$KEYSTORE_DIR/release.keystore" \
            -storepass "$STORE_PWD" \
            -alias "$ALIAS" \
            -keypass "$KEY_PWD" \
            -keyalg RSA \
            -keysize 2048 \
            -validity 36500 \
            -dname "CN=PushApp, OU=PushApp, O=PushApp Inc., L=Beijing, ST=Beijing, C=CN" \
            2>&1 | tail -3
        chmod 600 "$KEYSTORE_DIR/release.keystore"
        info "release.keystore 已生成"
        echo "  store_password: $STORE_PWD"
        echo "  key_password:   $KEY_PWD"
        echo "  alias:          $ALIAS"
        echo ""
        echo -n "APK_KEYSTORE_BASE64 = "
        base64 -w 0 "$KEYSTORE_DIR/release.keystore"
        echo ""
    else
        warn "未找到 keytool，跳过 keystore 生成"
        echo "  build-apk.yml 会自动生成临时签名（仅用于测试）"
        STORE_PWD=""
        KEY_PWD=""
        ALIAS=""
    fi
fi

# ---------------- 2. 生成 SSH 密钥 ----------------
step "2/4 生成 GitHub Actions 专用 SSH 密钥"

if [ -f "$SSH_KEY_PATH" ]; then
    warn "已存在 $SSH_KEY_PATH，跳过生成"
else
    mkdir -p "$HOME/.ssh" && chmod 700 "$HOME/.ssh"
    ssh-keygen -t ed25519 -C "github-actions-build" -f "$SSH_KEY_PATH" -N "" -q
    cat "$SSH_KEY_PATH.pub" >> "$HOME/.ssh/authorized_keys"
    chmod 600 "$SSH_KEY_PATH"
    chmod 600 "$HOME/.ssh/authorized_keys"
    info "SSH 密钥已生成: $SSH_KEY_PATH"
fi

echo ""
echo -e "${YELLOW}============= SERVER_SSH_KEY (复制下面整块到 GitHub Secret) =============${NC}"
cat "$SSH_KEY_PATH"
echo -e "${YELLOW}=========================================================================${NC}"

# ---------------- 3. 追加 .env ----------------
step "3/4 追加 .env 的 GITHUB 配置"

if [ ! -f "$ENV_FILE" ]; then
    error "未找到 $ENV_FILE"
    exit 1
fi

echo -e "${YELLOW}请输入你的 GitHub Personal Access Token (repo + workflow 权限):${NC}"
echo -e "  创建地址: https://github.com/settings/tokens"
echo -e "  权限: repo (全部) + workflow"
read -rp "GITHUB_TOKEN=" GITHUB_TOKEN

if [ -z "$GITHUB_TOKEN" ]; then
    warn "Token 为空，跳过 .env 追加（你可以手动追加）"
else
    {
        echo ""
        echo "# === GitHub Actions APK 构建 (setup_build_apk.sh 自动追加) ==="
        echo "GITHUB_TOKEN=$GITHUB_TOKEN"
        echo "GITHUB_OWNER=jiujiu123520"
        echo "GITHUB_REPO=im-push-system"
        echo "GITHUB_WORKFLOW_FILE=build-apk.yml"
        echo "GITHUB_API_PROXY=https://gh.jasonzeng.dev/"
        echo "GITHUB_API_TIMEOUT=30"
    } >> "$ENV_FILE"

    chown www-data:www-data "$ENV_FILE" 2>/dev/null || true
    chmod 600 "$ENV_FILE"
    info ".env 已追加 GITHUB_* 配置"
fi

# ---------------- 4. 输出总结 ----------------
step "4/4 完成清单"

echo ""
echo -e "${GREEN}✅ 服务器端配置完成！${NC}"
echo ""
echo -e "${BLUE}━━━ 需要手动在 GitHub 仓库配置 Secrets ━━━${NC}"
echo -e "  地址: https://github.com/jiujiu123520/im-push-system/settings/secrets/actions"
echo ""

if [ -f "$KEYSTORE_DIR/release.keystore" ]; then
    echo -e "${YELLOW}【可选但推荐】添加 Release Keystore Secrets（不添加也能用自动临时签名）${NC}"
    echo ""
    echo -e "  ${BLUE}APK_KEYSTORE_BASE64${NC} ="
    base64 -w 0 "$KEYSTORE_DIR/release.keystore"
    echo ""
    echo -e "  ${BLUE}APK_KEYSTORE_PASSWORD${NC} = (keystore 密码，请看上方第 1 步输出)"
    echo -e "  ${BLUE}APK_KEY_ALIAS${NC}         = release"
    echo -e "  ${BLUE}APK_KEY_PASSWORD${NC}     = (key 密码，一般与上面相同)"
    echo ""
fi

echo -e "${YELLOW}【必做】添加 SERVER_SSH_KEY Secret${NC}"
echo -e "  ${BLUE}SERVER_SSH_KEY${NC} = (上方第 2 步输出的完整私钥内容，含 BEGIN/END 行)"
echo ""

# 获取 SSH 主机信息
if command -v curl >/dev/null 2>&1; then
    PUBLIC_IP=$(curl -s ifconfig.me 2>/dev/null || curl -s icanhazip.com 2>/dev/null || echo "")
    if [ -n "$PUBLIC_IP" ]; then
        echo -e "${BLUE}SERVER_SSH_HOST${NC} = $PUBLIC_IP"
    fi
fi
if [ -f "$PROJECT_DIR/.env" ]; then
    EXISTING_HOST=$(grep -oP 'SERVER_SSH_HOST=\K.*' "$PROJECT_DIR/.env" 2>/dev/null || echo "")
    if [ -n "$EXISTING_HOST" ]; then
        echo "  (deploy.yml 已用 SERVER_SSH_HOST=$EXISTING_HOST，应该已经配好了)"
    fi
fi

echo ""
echo -e "${GREEN}━━━ 验证方式 ━━━${NC}"
echo "  1. 在后台 APP 构建页面提交一次构建任务"
echo "  2. 或手动: https://github.com/jiujiu123520/im-push-system/actions/workflows/build-apk.yml → Run workflow"
echo ""
echo -e "${GREEN}━━━ 服务器更新命令（配置完成后需要重启 PHP 加载新 .env）━━━${NC}"
echo "  sudo systemctl restart push-http push-websocket"

echo ""
exit 0
