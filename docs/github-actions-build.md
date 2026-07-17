# GitHub Actions 构建 APP 配置指南

本文档介绍如何配置 GitHub Actions 构建 Android APK,替代服务器本地构建。

## 目录

- [架构概览](#架构概览)
- [前置条件](#前置条件)
- [配置步骤](#配置步骤)
  - [1. 创建 GitHub Personal Access Token](#1-创建-github-personal-access-token)
  - [2. 配置服务器 .env](#2-配置服务器-env)
  - [3. 配置 GitHub 仓库 Secrets](#3-配置-github-仓库-secrets)
  - [4. 获取 Keystore base64](#4-获取-keystore-base64)
  - [5. 配置 SSH 密钥](#5-配置-ssh-密钥)
  - [6. 停止旧的本地构建服务](#6-停止旧的本地构建服务)
- [工作流程](#工作流程)
- [验证测试](#验证测试)
- [常见问题](#常见问题)
- [回滚方案](#回滚方案)

---

## 架构概览

```
┌─────────────┐     ┌──────────────┐     ┌──────────────────────┐
│  管理后台    │ ──> │  后端 API    │ ──> │  GitHub API          │
│  (前端)     │     │  (PHP/Swoole)│     │  (workflow_dispatch) │
└─────────────┘     └──────┬───────┘     └──────────┬───────────┘
                           │                          │
                           │                          v
                    ┌──────┴───────┐     ┌──────────────────────┐
                    │  Redis       │ <── │  GitHub Actions      │
                    │  (状态存储)  │     │  Runner              │
                    └──────┬───────┘     │  (checkout + gradle) │
                           │             └──────────┬───────────┘
                           │                        │
                           v                        v
                    ┌──────┴───────┐     ┌──────────────────────┐
                    │  服务器      │ <── │  SCP 上传 APK         │
                    │  (下载 APK)  │     │  SSH 更新 Redis 状态  │
                    └──────────────┘     └──────────────────────┘
```

### 核心组件

| 组件 | 职责 | 位置 |
|------|------|------|
| 后端 API | 接收前端请求,调用 GitHub API 触发 workflow | 服务器 124.220.64.209 |
| GitHub Actions Runner | 执行 APK 构建 | GitHub 云端 |
| Redis | 存储构建状态和任务列表 | 服务器 |
| SCP/SSH | Runner → 服务器的文件传输和状态回调 | GitHub Actions → 服务器 |
| 代理 | 国内服务器访问 GitHub API | gh.jasonzeng.dev |

---

## 前置条件

1. **GitHub 账号** - 拥有仓库管理权限
2. **服务器 SSH 访问权限** - 用于配置 SSH 密钥
3. **现有 keystore 文件** - 位于服务器 `/www/push-system/build/keystore/release.keystore`(如无,执行 `bash build/generate_keystore.sh` 生成)
4. **已部署最新代码** - 包含 GitHub Actions workflow 和后端改造

---

## 配置步骤

### 1. 创建 GitHub Personal Access Token

1. 访问 https://github.com/settings/tokens
2. 点击 **Generate new token (classic)**
3. 填写 Note: `im-push-system-build`
4. 勾选权限:
   - ✅ `repo` (完整仓库访问)
   - ✅ `workflow` (修改 workflow)
5. 点击 **Generate token**
6. **立即复制 token**(形如 `ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`),后续配置需要

> ⚠️ Token 只显示一次,务必保存好。

### 2. 配置服务器 .env

在服务器执行:

```bash
sudo nano /www/push-system/backend/.env
```

在文件末尾追加(替换为实际值):

```ini
# GitHub Actions 构建
GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
GITHUB_OWNER=jiujiu123520
GITHUB_REPO=im-push-system
GITHUB_WORKFLOW_FILE=build-apk.yml
GITHUB_API_PROXY=https://gh.jasonzeng.dev/
GITHUB_API_TIMEOUT=30
```

设置权限:

```bash
sudo chown www-data:www-data /www/push-system/backend/.env
sudo chmod 600 /www/push-system/backend/.env
```

重启 HTTP 服务使配置生效:

```bash
sudo systemctl restart push-http
```

### 3. 配置 GitHub 仓库 Secrets

访问仓库页面:**Settings → Secrets and variables → Actions → New repository secret**

需要添加以下 8 个 Secrets:

| Secret 名 | 值 | 说明 |
|-----------|-----|------|
| `APK_KEYSTORE_BASE64` | keystore 文件的 base64 编码 | 见[下一步](#4-获取-keystore-base64) |
| `APK_KEYSTORE_PASSWORD` | keystore 密码 | 生成 keystore 时设置的密码 |
| `APK_KEY_ALIAS` | 密钥别名 | 通常为 `release` |
| `APK_KEY_PASSWORD` | 密钥密码 | 生成 keystore 时设置的密码 |
| `SERVER_SSH_HOST` | `124.220.64.209` | 服务器 IP |
| `SERVER_SSH_PORT` | `22` | SSH 端口 |
| `SERVER_SSH_USER` | `ubuntu` | SSH 登录用户 |
| `SERVER_SSH_KEY` | SSH 私钥完整内容 | 见 [SSH 配置](#5-配置-ssh-密钥) |

### 4. 获取 Keystore base64

在服务器执行:

```bash
base64 -w 0 /www/push-system/build/keystore/release.keystore
```

将输出的长字符串复制到 GitHub Secret `APK_KEYSTORE_BASE64`。

> 💡 如未生成 keystore,先执行 `bash /www/push-system/build/generate_keystore.sh` 生成。

### 5. 配置 SSH 密钥

在服务器生成专用密钥对(如已有可跳过):

```bash
# 生成密钥对(无密码)
ssh-keygen -t ed25519 -C "github-actions-build" -f ~/.ssh/github_actions_key -N ""

# 将公钥添加到 authorized_keys
cat ~/.ssh/github_actions_key.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys

# 查看私钥(完整复制到 GitHub Secret SERVER_SSH_KEY)
cat ~/.ssh/github_actions_key
```

将 `cat ~/.ssh/github_actions_key` 的完整输出(含 `-----BEGIN OPENSSH PRIVATE KEY-----` 和 `-----END OPENSSH PRIVATE KEY-----`)复制到 GitHub Secret `SERVER_SSH_KEY`。

### 6. 停止旧的本地构建服务

迁移完成后,旧的本地构建服务不再需要:

```bash
# 停止服务
sudo systemctl stop push-build-worker

# 禁用开机自启
sudo systemctl disable push-build-worker

# 删除 systemd 服务文件(代码仓库中已删除)
sudo rm /etc/systemd/system/push-build-worker.service
sudo systemctl daemon-reload
sudo systemctl reset-failed push-build-worker 2>/dev/null || true
```

> 💡 一键迁移脚本: `bash migrate_to_github_actions.sh`(交互式配置 .env 并完成上述所有步骤)

---

## 工作流程

### 构建流程(7 个步骤)

1. **前端提交** - 用户在管理后台填写应用参数,点击「生成安装包」
2. **API 触发** - 后端 `/admin/app-build` 接收请求,调用 GitHub API `workflow_dispatch`(通过 gh.jasonzeng.dev 代理)
3. **Runner 启动** - GitHub Actions Runner checkout 代码,setup JDK 17 + Android SDK + Gradle 8.7
4. **构建 APK** - 解码 keystore Secret → 执行 `build_apk.sh` 构建 APK
5. **SCP 上传** - 将 APK 通过 SCP 上传到服务器 `/www/push-system/build/output/{build_id}/`
6. **状态回调** - SSH 调用 `update_build_status.php` 更新 Redis 中的构建状态
7. **前端轮询** - 前端每 3 秒轮询 list 接口获取最新状态,成功后可下载 APK

### 状态流转

```
pending → processing → success
                     → failed
```

- `pending`: 后端已创建 Redis 任务记录,正在调用 GitHub API
- `processing`: GitHub workflow 已触发,Runner 正在构建
- `success`: APK 已上传到服务器,可下载
- `failed`: 构建失败,可查看日志或重试

---

## 验证测试

### 手动触发测试

1. 访问 GitHub 仓库 **Actions** 页面
2. 选择 **Build Android APK** workflow
3. 点击 **Run workflow**
4. 填写测试参数:
   - `build_id`: `test-manual-001`
   - `app_name`: `TestApp`
   - `package_name`: `com.test.app`
   - `default_key`: `testkey`
   - `server_url`: `http://124.220.64.209:7070`
   - `ws_url`: `ws://124.220.64.209:9393`
5. 点击 **Run workflow** 开始构建

### 通过管理后台测试

1. 登录管理后台 `http://124.220.64.209/`
2. 进入 **APP 生成** 页面
3. 展开顶部的 **GitHub Actions 构建配置说明** 面板,确认显示 ✅ 已就绪
4. 填写应用参数,点击 **生成安装包**
5. 在构建历史中观察状态变化
6. 构建成功后点击 **下载** 获取 APK

### 检查构建状态

```bash
# 查看 GitHub Actions 运行状态
# 访问 https://github.com/jiujiu123520/im-push-system/actions

# 查看服务器收到的 APK
ls -la /www/push-system/build/output/

# 查看构建日志
ls -la /www/push-system/build/logs/

# 查看 Redis 中的构建状态
redis-cli -n 0 hgetall im_push:build:task:<build_id>
```

---

## 常见问题

### Q1: 提交构建后,后端返回「触发 GitHub Actions 失败」

**原因**: GitHub Token 无效或权限不足。

**解决**:
1. 检查 `.env` 中的 `GITHUB_TOKEN` 是否正确
2. 确认 Token 未过期(GitHub → Settings → Developer settings → Personal access tokens)
3. 确认 Token 有 `repo` 和 `workflow` 权限
4. 测试 Token 有效性:
   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" \
     -H "Authorization: Bearer <TOKEN>" \
     https://gh.jasonzeng.dev/https://api.github.com/user
   # 应返回 200
   ```

### Q2: GitHub Actions 构建失败,提示「SCP 上传失败」

**原因**: SSH 密钥配置错误或服务器防火墙拦截。

**解决**:
1. 检查 GitHub Secret `SERVER_SSH_KEY` 是否为完整私钥(含 BEGIN/END 行)
2. 检查 `SERVER_SSH_HOST`、`SERVER_SSH_PORT`、`SERVER_SSH_USER` 是否正确
3. 在服务器检查 SSH 服务是否运行:`sudo systemctl status sshd`
4. 手动测试 SSH 登录(从其他机器):
   ```bash
   ssh -i ~/.ssh/github_actions_key ubuntu@124.220.64.209
   ```

### Q3: 构建成功但前端状态一直是 processing

**原因**: Runner SSH 调用 `update_build_status.php` 失败。

**解决**:
1. 检查 `update_build_status.php` 是否存在且有执行权限:
   ```bash
   ls -la /www/push-system/backend/bin/update_build_status.php
   sudo chmod +x /www/push-system/backend/bin/update_build_status.php
   sudo chown www-data:www-data /www/push-system/backend/bin/update_build_status.php
   ```
2. 手动测试脚本:
   ```bash
   sudo -u www-data php /www/push-system/backend/bin/update_build_status.php \
     --build-id "test-001" --status "success" --message "测试" --apk-path "/tmp/test.apk"
   ```
3. 查看 GitHub Actions 日志中的 SSH 步骤输出

### Q4: GitHub Actions 中 jlink 失败

**原因**: JDK 版本不正确或环境变量未设置。

**解决**:
1. 确认 workflow 中使用 `actions/setup-java@v4` with `java-version: '17'`
2. 检查 `build_apk.sh` 中的 JAVA_HOME 自动检测逻辑
3. 在 workflow 日志中查找 `JAVA_HOME=` 输出

### Q5: 构建很慢(超过 10 分钟)

**原因**: 首次构建需要下载 Gradle 依赖,或网络问题。

**解决**:
1. 首次构建慢是正常的(下载依赖约 5-10 分钟)
2. 后续构建会利用 Gradle 缓存(但 GitHub Actions 每次是新环境)
3. 可启用 `actions/cache` 缓存 Gradle 依赖(如已配置 `gradle/actions/setup-gradle@v3` 会自动缓存)

### Q6: 国内服务器无法访问 GitHub API

**原因**: GitHub API 在国内访问不稳定。

**解决**:
1. 确认 `.env` 中 `GITHUB_API_PROXY=https://gh.jasonzeng.dev/`
2. 测试代理连通性:
   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" https://gh.jasonzeng.dev/https://api.github.com/zen
   # 应返回 200
   ```

---

## 回滚方案

如 GitHub Actions 构建失败,可临时回滚到本地构建:

### 1. 恢复本地构建代码

```bash
cd /www/push-system
# 回退到迁移前的 commit
git log --oneline | grep "迁移"  # 找到迁移 commit
git revert <commit-hash> --no-edit
```

### 2. 重启本地构建服务

```bash
# 恢复 systemd 服务文件
sudo cp deploy/systemd/push-build-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl reset-failed push-build-worker 2>/dev/null || true
sudo systemctl enable push-build-worker
sudo systemctl restart push-build-worker
```

### 3. 验证本地构建

```bash
sudo systemctl status push-build-worker
# 提交测试构建任务
```

---

## 相关文件

| 文件 | 用途 |
|------|------|
| [.github/workflows/build-apk.yml](../../.github/workflows/build-apk.yml) | GitHub Actions workflow 定义 |
| [backend/src/Service/GitHubActionsService.php](../../backend/src/Service/GitHubActionsService.php) | GitHub API 客户端 |
| [backend/src/Controller/AppBuildController.php](../../backend/src/Controller/AppBuildController.php) | 构建接口控制器 |
| [backend/bin/update_build_status.php](../../backend/bin/update_build_status.php) | SSH 回调脚本 |
| [backend/config/github.php](../../backend/config/github.php) | GitHub 配置 |
| [build/build_apk.sh](../../build/build_apk.sh) | 主构建脚本 |
| [build/inject_config.sh](../../build/inject_config.sh) | 配置注入脚本 |
| [migrate_to_github_actions.sh](../../migrate_to_github_actions.sh) | 一键迁移脚本 |

---

## 费用说明

- **公开仓库**: GitHub Actions 无限免费
- **私有仓库**: 每月 2000 分钟免费额度(每次构建约 5-10 分钟,可构建 200-400 次)
- **超出额度**: $0.008/分钟

如构建频繁,建议将仓库设为公开(注意 keystore 等敏感信息已通过 Secret 保护,不会泄露)。
