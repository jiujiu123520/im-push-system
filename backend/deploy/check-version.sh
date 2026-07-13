#!/bin/bash
# ============================================================
# 即时消息推送系统 - 版本检查脚本
#
# 功能：
#   1. 检查本地版本与云端版本是否一致
#   2. 显示本地和云端的 commit hash、提交日期、分支名
#   3. 计算 ahead/behind 提交数
#   4. 确定版本状态：up-to-date / behind / ahead / diverged / unknown
#   5. 支持代理加速访问 GitHub
#
# 用法:
#   bash backend/deploy/check-version.sh                       # 正常检查
#   bash backend/deploy/check-version.sh --json                # JSON 格式输出（便于脚本调用）
#   bash backend/deploy/check-version.sh --gh-proxy             # 使用 gh.jasonzeng.dev GitHub 代理
#   bash backend/deploy/check-version.sh --proxy=http://127.0.0.1:7890  # 使用自定义 HTTP 代理
#
# 环境变量:
#   PROJECT_DIR    - 项目目录（默认 /www/push-system）
#
# 返回值:
#   0 - 版本一致（up-to-date）
#   1 - 检查失败
#   2 - 本地落后于云端（behind）
#   3 - 本地领先于云端（ahead）
#   4 - 版本分叉（diverged）
# ============================================================

set -e

PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"

# ------------------------------------------------------------
# 解析命令行参数
# ------------------------------------------------------------
OUTPUT_JSON=""
GH_PROXY=""
GIT_PROXY=""

for arg in "$@"; do
    case $arg in
        --json)                 OUTPUT_JSON="1" ;;
        --gh-proxy)             GH_PROXY="1" ;;
        --proxy=*)              GIT_PROXY="${arg#*=}" ;;
        --project-dir=*)        PROJECT_DIR="${arg#*=}" ;;
        -h|--help)
            head -n 30 "$0"
            exit 0
            ;;
        *)
            echo "未知参数: $arg" >&2
            exit 1
            ;;
    esac
done

cd "$PROJECT_DIR" || { echo "无法进入项目目录: $PROJECT_DIR" >&2; exit 1; }

# Git 安全目录配置，避免在 root 下操作时 git 报错
git config --global --add safe.directory "$PROJECT_DIR"

# ------------------------------------------------------------
# 颜色输出（JSON 模式下不使用颜色）
# ------------------------------------------------------------
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_CYAN='\033[0;36m'
COLOR_RESET='\033[0m'

info()  { [[ -z "${OUTPUT_JSON}" ]] && echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { [[ -z "${OUTPUT_JSON}" ]] && echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { [[ -z "${OUTPUT_JSON}" ]] && echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }

# ------------------------------------------------------------
# 代理配置：记录原始 remote URL，便于后续恢复
# ------------------------------------------------------------
ORIGINAL_ORIGIN_URL=""
PROXY_REPLACED=""

setup_git_proxy() {
    if [[ -n "${GIT_PROXY}" ]]; then
        # 使用自定义 HTTP 代理
        info "配置 Git 代理: ${GIT_PROXY}"
        git config --global http.proxy "${GIT_PROXY}"
        git config --global https.proxy "${GIT_PROXY}"
        export HTTP_PROXY="${GIT_PROXY}"
        export HTTPS_PROXY="${GIT_PROXY}"
    elif [[ "${GH_PROXY}" == "1" ]]; then
        # 使用 GitHub 代理 gh.jasonzeng.dev
        info "使用 GitHub 代理加速 (gh.jasonzeng.dev)..."
        local remote_url
        remote_url="$(git remote get-url origin 2>/dev/null || echo '')"
        # 检查是否已经包含代理前缀，避免重复添加
        if [[ -n "${remote_url}" && "${remote_url}" =~ github\.com ]] && [[ ! "${remote_url}" =~ gh\.jasonzeng\.dev ]]; then
            local new_url="${remote_url/github.com/gh.jasonzeng.dev\/https:\/\/github.com}"
            info "  替换远程地址: ${remote_url} -> ${new_url}"
            git remote set-url origin "${new_url}"
            ORIGINAL_ORIGIN_URL="${remote_url}"
            PROXY_REPLACED="1"
        elif [[ "${remote_url}" =~ gh\.jasonzeng\.dev ]]; then
            info "  远程地址已包含代理前缀，无需替换"
        fi
    fi
}

# 恢复代理设置，避免影响其他 git 操作
restore_git_proxy() {
    if [[ -n "${GIT_PROXY}" ]]; then
        git config --global --unset http.proxy 2>/dev/null || true
        git config --global --unset https.proxy 2>/dev/null || true
        unset HTTP_PROXY
        unset HTTPS_PROXY
    fi
    if [[ "${GH_PROXY}" == "1" && "${PROXY_REPLACED}" == "1" && -n "${ORIGINAL_ORIGIN_URL}" ]]; then
        info "恢复原始远程地址..."
        git remote set-url origin "${ORIGINAL_ORIGIN_URL}"
    fi
}

# ============================================================
# 开始版本检查
# ============================================================
# 配置代理
setup_git_proxy

# ------------------------------------------------------------
# 获取本地版本信息
# ------------------------------------------------------------
LOCAL_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
if [[ -z "${LOCAL_COMMIT}" ]]; then
    error "无法获取本地版本信息，当前目录不是 Git 仓库？"
    restore_git_proxy
    exit 1
fi
LOCAL_SHORT="${LOCAL_COMMIT:0:8}"
LOCAL_DATE="$(git log -1 --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' 2>/dev/null || echo '未知')"
# 当前分支名（ detached HEAD 时回退到 HEAD）
LOCAL_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo '')"
if [[ -z "${LOCAL_BRANCH}" || "${LOCAL_BRANCH}" == "HEAD" ]]; then
    LOCAL_BRANCH="main"
fi

# ------------------------------------------------------------
# 获取远端版本信息
# ------------------------------------------------------------
if [[ -z "${OUTPUT_JSON}" ]]; then
    info "获取远程版本信息..."
fi

# 先尝试浅 fetch，失败则完整 fetch
git fetch origin --depth=50 2>/dev/null || git fetch origin 2>/dev/null || {
    error "无法连接到远程仓库，请检查网络或代理设置"
    restore_git_proxy
    exit 1
}

# 优先使用 main 分支，回退到 master
REMOTE_BRANCH=""
if git rev-parse --verify origin/main >/dev/null 2>&1; then
    REMOTE_BRANCH="main"
elif git rev-parse --verify origin/master >/dev/null 2>&1; then
    REMOTE_BRANCH="master"
fi

if [[ -z "${REMOTE_BRANCH}" ]]; then
    error "无法获取远程版本信息（未找到 origin/main 或 origin/master 分支）"
    restore_git_proxy
    exit 1
fi

REMOTE_COMMIT="$(git rev-parse "origin/${REMOTE_BRANCH}" 2>/dev/null || echo '')"
if [[ -z "${REMOTE_COMMIT}" ]]; then
    error "无法获取远程 commit hash"
    restore_git_proxy
    exit 1
fi
REMOTE_SHORT="${REMOTE_COMMIT:0:8}"
REMOTE_DATE="$(git log -1 "origin/${REMOTE_BRANCH}" --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' 2>/dev/null || echo '未知')"

# 恢复代理
restore_git_proxy

# ------------------------------------------------------------
# 对比本地和远端的 ahead/behind 提交数，确定状态
# ------------------------------------------------------------
AHEAD_COUNT=0
BEHIND_COUNT=0
STATUS="unknown"

if [[ "${LOCAL_COMMIT}" == "${REMOTE_COMMIT}" ]]; then
    # 本地与远端 commit 完全一致
    STATUS="up-to-date"
elif git merge-base --is-ancestor HEAD "origin/${REMOTE_BRANCH}" 2>/dev/null; then
    # 本地是远端的祖先，说明本地落后
    STATUS="behind"
    AHEAD_COUNT="$(git rev-list --count "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null || echo 0)"
elif git merge-base --is-ancestor "origin/${REMOTE_BRANCH}" HEAD 2>/dev/null; then
    # 远端是本地的祖先，说明本地领先
    STATUS="ahead"
    BEHIND_COUNT="$(git rev-list --count "origin/${REMOTE_BRANCH}..HEAD" 2>/dev/null || echo 0)"
else
    # 本地与远端已分叉
    STATUS="diverged"
    AHEAD_COUNT="$(git rev-list --count "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null || echo 0)"
    BEHIND_COUNT="$(git rev-list --count "origin/${REMOTE_BRANCH}..HEAD" 2>/dev/null || echo 0)"
fi

# 保证计数值为整数（避免空值导致 JSON/算术错误）
AHEAD_COUNT="${AHEAD_COUNT:-0}"
BEHIND_COUNT="${BEHIND_COUNT:-0}"

# ------------------------------------------------------------
# 输出结果
# ------------------------------------------------------------
if [[ -n "${OUTPUT_JSON}" ]]; then
    # JSON 格式输出
    cat <<EOF
{
  "local": {
    "commit": "${LOCAL_COMMIT}",
    "short": "${LOCAL_SHORT}",
    "date": "${LOCAL_DATE}",
    "branch": "${LOCAL_BRANCH}"
  },
  "remote": {
    "commit": "${REMOTE_COMMIT}",
    "short": "${REMOTE_SHORT}",
    "branch": "${REMOTE_BRANCH}",
    "date": "${REMOTE_DATE}"
  },
  "status": "${STATUS}",
  "ahead_count": ${AHEAD_COUNT},
  "behind_count": ${BEHIND_COUNT}
}
EOF
else
    # 人类可读格式
    echo ""
    echo "========================================"
    echo "  版本一致性检查结果"
    echo "========================================"
    echo ""
    echo -e "  本地版本:    ${COLOR_CYAN}${LOCAL_SHORT}${COLOR_RESET}"
    echo -e "  本地分支:    ${LOCAL_BRANCH}"
    echo -e "  本地时间:    ${LOCAL_DATE}"
    echo ""
    echo -e "  云端版本:    ${COLOR_CYAN}${REMOTE_SHORT}${COLOR_RESET}"
    echo -e "  云端分支:    origin/${REMOTE_BRANCH}"
    echo -e "  云端时间:    ${REMOTE_DATE}"
    echo ""

    case "${STATUS}" in
        up-to-date)
            echo -e "  版本状态:    ${COLOR_GREEN}✓ 一致（已是最新版本）${COLOR_RESET}"
            echo ""
            info "当前版本与云端同步，无需更新。"
            EXIT_CODE=0
            ;;
        behind)
            echo -e "  版本状态:    ${COLOR_YELLOW}↑ 本地落后 ${AHEAD_COUNT} 个提交${COLOR_RESET}"
            echo ""
            warn "有新版本可用！云端新增的提交："
            git log --oneline "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null | head -n 10 | while read -r line; do
                echo -e "    ${COLOR_YELLOW}↑${COLOR_RESET} ${line}"
            done
            echo ""
            info "如需更新，请执行: bash backend/deploy/update.sh"
            EXIT_CODE=2
            ;;
        ahead)
            echo -e "  版本状态:    ${COLOR_RED}↓ 本地领先 ${BEHIND_COUNT} 个提交${COLOR_RESET}"
            echo ""
            warn "本地有未推送的提交，更新将覆盖本地修改！"
            warn "本地领先的提交（最近 10 条）："
            git log --oneline "origin/${REMOTE_BRANCH}..HEAD" 2>/dev/null | head -n 10 | while read -r line; do
                echo -e "    ${COLOR_RED}↓${COLOR_RESET} ${line}"
            done
            echo ""
            warn "强制更新将丢失本地修改，请先确认！"
            EXIT_CODE=3
            ;;
        diverged)
            echo -e "  版本状态:    ${COLOR_RED}✗ 版本分叉${COLOR_RESET}"
            echo ""
            warn "本地与云端版本已分叉，存在差异："
            echo -e "    云端新增: ${COLOR_YELLOW}${AHEAD_COUNT} 条${COLOR_RESET}"
            echo -e "    本地新增: ${COLOR_RED}${BEHIND_COUNT} 条${COLOR_RESET}"
            echo ""
            error "版本已分叉，建议先手动处理后再更新。"
            EXIT_CODE=4
            ;;
        *)
            echo -e "  版本状态:    ${COLOR_RED}未知${COLOR_RESET}"
            EXIT_CODE=1
            ;;
    esac

    echo ""
    echo "========================================"
    echo ""
fi

exit ${EXIT_CODE}
