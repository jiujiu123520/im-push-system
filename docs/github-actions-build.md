# GitHub Actions 构建 APP 配置指南

通过 GitHub Actions 云端构建 Android APK，无需服务器安装 JDK/Android SDK。

## 前置条件

1. GitHub 账号（仓库管理权限）
2. 服务器 SSH 访问权限
3. keystore 文件（如无，执行 `bash build/generate_keystore.sh` 生成）
4. 已部署最新代码（包含 GitHub Actions workflow 和后端改造）

## 配置步骤

### 1. 创建 GitHub Personal Access Token

1. 访问 https://github.com/settings/tokens → **Generate new token (classic)**
2. 勾选权限：`repo` + `workflow`
3. 生成后立即复制保存（只显示一次）

### 2. 配置服务器 .env

```ini
# GitHub Actions 构建
GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
GITHUB_OWNER=jiujiu123520
GITHUB_REPO=im-push-system
GITHUB_WORKFLOW_FILE=build-apk.yml
GITHUB_API_PROXY=https://gh.jasonzeng.dev/
GITHUB_API_TIMEOUT=30
```

```bash
sudo chown www-data:www-data /www/push-system/backend/.env
sudo chmod 600 /www/push-system/backend/.env
sudo systemctl restart push-http
```

### 3. 配置 GitHub 仓库 Secrets

**Settings → Secrets and variables → Actions → New repository secret**，添加 8 个：

| Secret 名 | 值 | 说明 |
|-----------|-----|------|
| `APK_KEYSTORE_BASE64` | keystore 的 base64 编码 | 见下一步 |
| `APK_KEYSTORE_PASSWORD` | keystore 密码 | 生成时设置 |
| `APK_KEY_ALIAS` | 密钥别名 | 通常为 `release` |
| `APK_KEY_PASSWORD` | 密钥密码 | 生成时设置 |
| `SERVER_SSH_HOST` | 服务器 IP | |
| `SERVER_SSH_PORT` | `22` | SSH 端口 |
| `SERVER_SSH_USER` | `ubuntu` | SSH 用户 |
| `SERVER_SSH_KEY` | SSH 私钥完整内容 | 见 SSH 配置 |

### 4. 获取 Keystore base64

```bash
base64 -w 0 /www/push-system/build/keystore/release.keystore
```

输出复制到 GitHub Secret `APK_KEYSTORE_BASE64`。

### 5. 配置 SSH 密钥

```bash
# 服务器上生成专用密钥对（如已有可跳过）
ssh-keygen -t ed25519 -C "github-actions-build" -f ~/.ssh/github_actions_key -N ""

# 公钥加入授权
cat ~/.ssh/github_actions_key.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys

# 查看私钥，完整复制（含 BEGIN/END 行）到 Secret SERVER_SSH_KEY
cat ~/.ssh/github_actions_key
```

## 工作流程

```
管理后台提交构建 → 后端调用 GitHub API (workflow_dispatch)
→ Runner 构建 APK → SCP 上传到服务器 → SSH 回调更新 Redis 状态
→ 前端每 3 秒轮询状态 → 成功后下载 APK
```

状态流转：`pending → processing → success / failed`

## 验证测试

1. 登录管理后台 → **APP 生成** 页面
2. 确认顶部「GitHub Actions 构建配置说明」面板显示 ✅ 已就绪
3. 填写应用参数 → 点击「生成安装包」
4. 构建历史中观察状态变化，成功后下载

```bash
# 查看服务器收到的 APK
ls -la /www/push-system/build/output/

# 查看 Redis 中的构建状态
redis-cli hgetall im_push:build:task:<build_id>
```

## 常见问题

### 触发构建失败（Token 问题）

```bash
# 测试 Token 有效性（应返回 200）
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer <TOKEN>" \
  https://gh.jasonzeng.dev/https://api.github.com/user
```

确认 `.env` 的 `GITHUB_TOKEN` 有效且有 `repo`+`workflow` 权限。

### SCP 上传失败

1. Secret `SERVER_SSH_KEY` 须为完整私钥（含 BEGIN/END 行）
2. 核对 `SERVER_SSH_HOST/PORT/USER`
3. 服务器检查 `sudo systemctl status sshd`

### 构建成功但状态一直 processing

Runner SSH 回调失败。检查并测试回调脚本：

```bash
sudo chmod +x /www/push-system/backend/bin/update_build_status.php
sudo -u www-data php /www/push-system/backend/bin/update_build_status.php \
  --build-id "test-001" --status "success" --message "测试" --apk-path "/tmp/test.apk"
```

### 国内服务器无法访问 GitHub API

确认 `.env` 中 `GITHUB_API_PROXY=https://gh.jasonzeng.dev/`：

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://gh.jasonzeng.dev/https://api.github.com/zen
# 应返回 200
```

## 相关文件

| 文件 | 用途 |
|------|------|
| `.github/workflows/build-apk.yml` | workflow 定义 |
| `backend/src/Service/GitHubActionsService.php` | GitHub API 客户端 |
| `backend/src/Controller/AppBuildController.php` | 构建接口控制器 |
| `backend/bin/update_build_status.php` | SSH 回调脚本 |
| `build/build_apk.sh` | 主构建脚本 |

## 费用说明

- 公开仓库：免费
- 私有仓库：每月 2000 分钟免费额度（每次构建约 5-10 分钟）
