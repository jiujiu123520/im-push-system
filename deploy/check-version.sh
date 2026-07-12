#!/bin/bash
# ============================================================
# 即时消息推送系统 - 版本检查脚本
#
# 功能：
#   1. 检查本地版本与云端版本是否一致
#   2. 显示本地和云端的 commit hash
#   3. 计算差异提交数，列出新增的提交
#   4. 支持代理加速检查
#
# 用法:
#   bash deploy/check-version.sh                    # 正常检查
#   bash deploy/check-version.sh --proxy=http://127.0.0.1:7890   # 使用 HTTP 代理
#   bash deploy/check-version.sh --gh-proxy        # 使用 gh.jasonzeng.dev GitHub 代理
#   bash deploy/check-version.sh --full            # 显示完整提交列表
#   bash deploy/check-version.sh --json            # JSON 格式输出（便于脚本调用）
#
# 环境变量:
#   PROJECT_DIR    - 项目目录（默认 /www/push-system）
#   GIT_PROXY      - Git 代理地址
#   GH_PROXY       - 是否使用 GitHub 代理（1=启用）
#
# 返回值:
#   0 - 版本一致
#   1 - 检查失败
#   2 - 本地落后于云端（有新版本）
#   3 - 本地领先于云端（有未推送的提交）
# ============================================================

PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"

# ------------------------------------------------------------
# 解析命令行参数
# ------------------------------------------------------------
GIT_PROXY="${GIT_PROXY:-}"
GH_PROXY="${GH_PROXY:-}"
OUTPUT_JSON=""
FULL_LOG=""

for arg in "$@"; do
    case $arg in
        --proxy=*)              GIT_PROXY="${arg#*=}" ;;
        --gh-proxy)             GH_PROXY="1" ;;
        --project-dir=*)        PROJECT_DIR="${arg#*=}" ;;
        --json)                 OUTPUT_JSON="1" ;;
        --full)                 FULL_LOG="1" ;;
        -h|--help)
            head -n 50 "$0"
            exit 0
            ;;
    esac
done

cd "$PROJECT_DIR" || exit 1

# Git 安全目录配置
git config --global --add safe.directory "$PROJECT_DIR"

# ------------------------------------------------------------
# 颜色输出
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
# 代理配置函数
# ------------------------------------------------------------
setup_git_proxy() {
    local proxy_url="$1"
    if [[ -n "${proxy_url}" ]]; then
        info "配置 Git 代理: ${proxy_url}"
        git config --global http.proxy "${proxy_url}"
        git config --global https.proxy "${proxy_url}"
        export HTTP_PROXY="${proxy_url}"
        export HTTPS_PROXY="${proxy_url}"
    elif [[ "${GH_PROXY}" == "1" ]]; then
        info "使用 GitHub 代理加速..."
        local remote_url
        remote_url="$(git remote get-url origin 2>/dev/null || echo '')"
        if [[ "${remote_url}" =~ github\.com ]]; then
            local new_url="${remote_url/github.com/gh.jasonzeng.dev\/https:\/\/github.com}"
            git remote set-url origin "${new_url}"
            echo "${remote_url}" > "${PROJECT_DIR}/.git-original-origin"
        fi
    fi
}

restore_git_proxy() {
    if [[ -n "${GIT_PROXY}" ]]; then
        git config --global --unset http.proxy 2>/dev/null || true
        git config --global --unset https.proxy 2>/dev/null || true
        unset HTTP_PROXY
        unset HTTPS_PROXY
    fi
    if [[ "${GH_PROXY}" == "1" && -f "${PROJECT_DIR}/.git-original-origin" ]]; then
        local original_url
        original_url="$(cat "${PROJECT_DIR}/.git-original-origin")"
        if [[ -n "${original_url}" ]]; then
            git remote set-url origin "${original_url}"
            rm -f "${PROJECT_DIR}/.git-original-origin"
        fi
    fi
}

# ------------------------------------------------------------
# 版本检查
# ------------------------------------------------------------
# 配置代理
setup_git_proxy "${GIT_PROXY}"

# 获取本地版本
LOCAL_COMMIT="$(git rev-parse HEAD 2>/dev/null || echo '')"
if [[ -z "${LOCAL_COMMIT}" ]]; then
    error "无法获取本地版本信息，不是 Git 仓库？"
    restore_git_proxy
    exit 1
fi
LOCAL_SHORT="${LOCAL_COMMIT:0:8}"

# 获取远程版本
if [[ -z "${OUTPUT_JSON}" ]]; then
    info "获取远程版本信息..."
fi

# 先尝试浅 fetch，失败则完整 fetch
git fetch origin --depth=50 2>/dev/null || git fetch origin 2>/dev/null || {
    error "无法连接到远程仓库，请检查网络或代理设置"
    restore_git_proxy
    exit 1
}

# 获取远程最新 commit
REMOTE_COMMIT="$(git rev-parse origin/main 2>/dev/null || git rev-parse origin/master 2>/dev/null || echo '')"
if [[ -z "${REMOTE_COMMIT}" ]]; then
    error "无法获取远程版本信息（未找到 origin/main 或 origin/master 分支）"
    restore_git_proxy
    exit 1
fi
REMOTE_SHORT="${REMOTE_COMMIT:0:8}"

# 获取远程分支名
REMOTE_BRANCH=""
if git rev-parse --verify origin/main >/dev/null 2>&1; then
    REMOTE_BRANCH="main"
elif git rev-parse --verify origin/master >/dev/null 2>&1; then
    REMOTE_BRANCH="master"
fi

# 恢复代理
restore_git_proxy

# 计算差异
AHEAD_COUNT=0
BEHIND_COUNT=0
STATUS="unknown"

if [[ "${LOCAL_COMMIT}" == "${REMOTE_COMMIT}" ]]; then
    STATUS="up-to-date"
elif git merge-base --is-ancestor HEAD origin/main 2>/dev/null || git merge-base --is-ancestor HEAD origin/master 2>/dev/null; then
    # 本地是远程的祖先，说明本地落后
    STATUS="behind"
    AHEAD_COUNT="$(git rev-list --count HEAD..origin/main 2>/dev/null || git rev-list --count HEAD..origin/master 2>/dev/null || echo 0)"
elif git merge-base --is-ancestor origin/main HEAD 2>/dev/null || git merge-base --is-ancestor origin/master HEAD 2>/dev/null; then
    # 远程是本地的祖先，说明本地领先
    STATUS="ahead"
    BEHIND_COUNT="$(git rev-list --count origin/main..HEAD 2>/dev/null || git rev-list --count origin/master..HEAD 2>/dev/null || echo 0)"
else
    # 分叉了
    STATUS="diverged"
    AHEAD_COUNT="$(git rev-list --count HEAD..origin/main 2>/dev/null || echo 0)"
    BEHIND_COUNT="$(git rev-list --count origin/main..HEAD 2>/dev/null || echo 0)"
fi

# 本地提交时间
LOCAL_DATE="$(git log -1 --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' 2>/dev/null || echo '未知')"
REMOTE_DATE="$(git log -1 origin/main --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' 2>/dev/null || git log -1 origin/master --format=%cd --date=format:'%Y-%m-%d %H:%M:%S' 2>/dev/null || echo '未知')"

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
    "date": "${LOCAL_DATE}"
  },
  "remote": {
    "commit": "${REMOTE_COMMIT}",
    "short": "${REMOTE_SHORT}",
    "branch": "${REMOTE_BRANCH}",
    "date": "${REMOTE_DATE}"
  },
  "status": "${STATUS}",
  "ahead_count": ${AHEAD_COUNT},
  "behind_count": ${BEHIND_COUNT},
  "project_dir": "${PROJECT_DIR}"
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
            if [[ -n "${FULL_LOG}" ]]; then
                git log --oneline "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null | while read -r line; do
                    echo -e "    ${COLOR_YELLOW}↑${COLOR_RESET} ${line}"
                done
            else
                git log --oneline "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null | head -n 10 | while read -r line; do
                    echo -e "    ${COLOR_YELLOW}↑${COLOR_RESET} ${line}"
                done
                LOCAL_REMOTE_COUNT=$(git rev-list --count "HEAD..origin/${REMOTE_BRANCH}" 2>/dev/null || echo 0)
                if [[ "${LOCAL_REMOTE_COUNT}" -gt 10 ]]; then
                    echo -e "    ${COLOR_YELLOW}... 还有 $((LOCAL_REMOTE_COUNT - 10)) 条提交，使用 --full 查看全部${COLOR_RESET}"
                fi
            fi
            echo ""
            info "如需更新，请执行: bash deploy/update.sh"
            EXIT_CODE=2
            ;;
        ahead)
            echo -e "  版本状态:    ${COLOR_RED}↓ 本地领先 ${BEHIND_COUNT} 个提交${COLOR_RESET}"
            echo ""
            error "本地有未推送的提交，更新将覆盖本地修改！"
            warn "本地领先的提交："
            if [[ -n "${FULL_LOG}" ]]; then
                git log --oneline "origin/${REMOTE_BRANCH}..HEAD" 2>/dev/null | while read -r line; do
                    echo -e "    ${COLOR_RED}↓${COLOR_RESET} ${line}"
                done
            else
                git log --oneline "origin/${REMOTE_BRANCH}..HEAD" 2>/dev/null | head -n 10 | while read -r line; do
                    echo -e "    ${COLOR_RED}↓${COLOR_RESET} ${line}"
                done
                if [[ "${BEHIND_COUNT}" -gt 10 ]]; then
                    echo -e "    ${COLOR_RED}... 还有 $((BEHIND_COUNT - 10)) 条提交，使用 --full 查看全部${COLOR_RESET}"
                fi
            fi
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
