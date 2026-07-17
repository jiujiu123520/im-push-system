#!/bin/bash
# ============================================================
# 服务器迁移脚本 - 从本地构建迁移到 GitHub Actions 构建
# 功能:
#   1. 拉取最新代码(包含 GitHub Actions workflow 和相关改动)
#   2. 停止并禁用旧的 push-build-worker 服务
#   3. 配置 .env 中的 GitHub 相关变量(交互式)
#   4. 设置 update_build_status.php 权限
#   5. 重启 push-http 服务
#   6. 验证 GitHub API 连通性
# 前置条件:
#   - 已在 GitHub 仓库配置好 Secrets(APK_KEYSTORE_BASE64 等)
#   - 已创建 GitHub Personal Access Token(repo + workflow 权限)
# 使用方式:
#   bash migrate_to_github_actions.sh
# ============================================================
set -e

# ---------------- 1. 进入项目根目录 ----------------
cd /www/push-system

# ---------------- 2. 修复权限并拉取最新代码 ----------------
echo "========== 1. 拉取最新代码 =========="
sudo chown -R ubuntu:ubuntu /www/push-system
git checkout -- build/ app/ 2>/dev/null || true
git clean -fd build/ app/ 2>/dev/null || true
git fetch https://gh.jasonzeng.dev/https://github.com/jiujiu123520/im-push-system.git main
git merge --no-edit FETCH_HEAD
git log --oneline -3
echo ""

# ---------------- 3. 停止并禁用旧的构建服务 ----------------
echo "========== 2. 停止旧的 push-build-worker 服务 =========="
if systemctl is-active --quiet push-build-worker 2>/dev/null; then
    sudo systemctl stop push-build-worker
    echo "已停止 push-build-worker"
else
    echo "push-build-worker 未运行,跳过"
fi
if systemctl is-enabled --quiet push-build-worker 2>/dev/null; then
    sudo systemctl disable push-build-worker
    echo "已禁用 push-build-worker 开机自启"
fi
# 删除 systemd 服务文件(代码仓库中已删除)
if [ -f /etc/systemd/system/push-build-worker.service ]; then
    sudo rm /etc/systemd/system/push-build-worker.service
    sudo systemctl daemon-reload
    sudo systemctl reset-failed push-build-worker 2>/dev/null || true
    echo "已删除 push-build-worker.service 文件"
fi
echo ""

# ---------------- 4. 配置 .env(交互式) ----------------
echo "========== 3. 配置 GitHub Actions 环境变量 =========="
ENV_FILE="/www/push-system/backend/.env"

# 检查是否已配置 GitHub 变量
if grep -q "^GITHUB_TOKEN=" "$ENV_FILE" 2>/dev/null; then
    echo "检测到 .env 已包含 GitHub 配置,跳过交互式配置"
    echo "如需修改,请手动编辑: sudo nano $ENV_FILE"
else
    echo "请准备以下信息:"
    echo "  1. GitHub Personal Access Token(需要 repo 和 workflow 权限)"
    echo "  2. GitHub 仓库所有者(用户名或组织名)"
    echo "  3. GitHub 仓库名"
    echo ""
    read -p "GitHub Token (ghp_xxx): " GITHUB_TOKEN_INPUT
    read -p "仓库所有者 [jiujiu123520]: " GITHUB_OWNER_INPUT
    GITHUB_OWNER_INPUT=${GITHUB_OWNER_INPUT:-jiujiu123520}
    read -p "仓库名 [im-push-system]: " GITHUB_REPO_INPUT
    GITHUB_REPO_INPUT=${GITHUB_REPO_INPUT:-im-push-system}
    read -p "API 代理 [https://gh.jasonzeng.dev/]: " GITHUB_API_PROXY_INPUT
    GITHUB_API_PROXY_INPUT=${GITHUB_API_PROXY_INPUT:-https://gh.jasonzeng.dev/}

    sudo tee -a "$ENV_FILE" > /dev/null <<EOF

# ============================
# GitHub Actions 构建
# ============================
GITHUB_TOKEN=$GITHUB_TOKEN_INPUT
GITHUB_OWNER=$GITHUB_OWNER_INPUT
GITHUB_REPO=$GITHUB_REPO_INPUT
GITHUB_WORKFLOW_FILE=build-apk.yml
GITHUB_API_PROXY=$GITHUB_API_PROXY_INPUT
GITHUB_API_TIMEOUT=30
EOF
    echo "GitHub 配置已写入 $ENV_FILE"
fi

# 设置 .env 权限
sudo chown www-data:www-data "$ENV_FILE"
sudo chmod 600 "$ENV_FILE"
echo ""

# ---------------- 5. 设置 update_build_status.php 权限 ----------------
echo "========== 4. 设置 update_build_status.php 权限 =========="
UPDATE_STATUS_SCRIPT="/www/push-system/backend/bin/update_build_status.php"
if [ -f "$UPDATE_STATUS_SCRIPT" ]; then
    sudo chmod +x "$UPDATE_STATUS_SCRIPT"
    sudo chown www-data:www-data "$UPDATE_STATUS_SCRIPT"
    echo "已设置权限: $UPDATE_STATUS_SCRIPT"
else
    echo "[WARN] 未找到 $UPDATE_STATUS_SCRIPT,代码可能未更新成功"
fi
echo ""

# ---------------- 6. 确保输出和日志目录存在 ----------------
echo "========== 5. 确保构建目录存在 =========="
sudo mkdir -p /www/push-system/build/output
sudo mkdir -p /www/push-system/build/logs
sudo chown -R www-data:www-data /www/push-system/build/output /www/push-system/build/logs
echo "目录已就绪: build/output/ build/logs/"
echo ""

# ---------------- 7. 重启 HTTP 服务加载新配置 ----------------
echo "========== 6. 重启 push-http 服务 =========="
sudo systemctl restart push-http
sleep 2
sudo systemctl status push-http --no-pager -l | head -10
echo ""

# ---------------- 8. 验证 GitHub API 连通性 ----------------
echo "========== 7. 验证 GitHub API 连通性 =========="
echo "测试通过代理访问 GitHub API..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" https://gh.jasonzeng.dev/https://api.github.com/zen 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
    echo "[OK] GitHub API 代理连通正常(HTTP 200)"
else
    echo "[FAIL] GitHub API 代理访问失败(HTTP $HTTP_CODE)"
    echo "请检查网络或更换代理"
fi
echo ""

# ---------------- 9. 验证 GitHub Token(可选) ----------------
GITHUB_TOKEN=$(grep "^GITHUB_TOKEN=" "$ENV_FILE" 2>/dev/null | cut -d= -f2)
if [ -n "$GITHUB_TOKEN" ] && [ "$GITHUB_TOKEN" != "your_github_token" ]; then
    echo "========== 8. 验证 GitHub Token =========="
    TOKEN_CHECK=$(curl -s -o /dev/null -w "%{http_code}" \
        -H "Authorization: Bearer $GITHUB_TOKEN" \
        -H "Accept: application/vnd.github+json" \
        https://gh.jasonzeng.dev/https://api.github.com/user 2>/dev/null || echo "000")
    if [ "$TOKEN_CHECK" = "200" ]; then
        echo "[OK] GitHub Token 验证通过"
    else
        echo "[FAIL] GitHub Token 验证失败(HTTP $TOKEN_CHECK)"
        echo "请检查 Token 是否有效,以及是否有 repo 和 workflow 权限"
    fi
    echo ""
fi

# ---------------- 10. 提示后续步骤 ----------------
echo "============================================================"
echo "服务器迁移完成!"
echo ""
echo "后续步骤(在 GitHub 网页完成):"
echo "  1. 在仓库 Settings → Secrets and variables → Actions 添加 Secrets:"
echo "     - APK_KEYSTORE_BASE64: keystore 文件 base64"
echo "     - APK_KEYSTORE_PASSWORD: keystore 密码"
echo "     - APK_KEY_ALIAS: 密钥别名(通常 release)"
echo "     - APK_KEY_PASSWORD: 密钥密码"
echo "     - SERVER_SSH_HOST: 124.220.64.209"
echo "     - SERVER_SSH_PORT: 22"
echo "     - SERVER_SSH_USER: ubuntu"
echo "     - SERVER_SSH_KEY: SSH 私钥(完整内容)"
echo ""
echo "  2. 获取 keystore base64(在服务器执行):"
echo "     base64 -w 0 /www/push-system/build/keystore/release.keystore"
echo ""
echo "  3. 在 GitHub Actions 页面手动触发一次测试构建"
echo ""
echo "  4. 在管理后台提交构建任务测试"
echo "============================================================"
