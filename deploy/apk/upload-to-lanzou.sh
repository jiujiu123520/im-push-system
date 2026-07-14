#!/usr/bin/env bash
# ============================================================
# 上传 APK 到蓝奏云
#
# 蓝奏云没有官方开放 API，本脚本通过模拟浏览器请求实现上传。
# 需要提供登录后的 Cookie（从浏览器开发者工具获取）。
#
# 用法: upload-to-lanzou.sh <apk_path> <app_name> <cookie>
#
# 输出: JSON 格式
#   成功: {"success":true,"url":"分享链接","password":"提取码","message":"上传成功"}
#   失败: {"success":false,"message":"错误原因"}
#
# 注意:
#   1. 蓝奏云免费版文件大小限制 100MB
#   2. Cookie 有时效性，需定期更新
#   3. APK 文件类型可能被限制上传，需要先压缩为 zip
# ============================================================

set -euo pipefail

APK_PATH="${1:-}"
APP_NAME="${2:-app}"
COOKIE="${3:-}"

# 输出 JSON 结果
output_json() {
    local success="$1"
    local message="$2"
    local url="${3:-}"
    local password="${4:-}"
    echo "{\"success\":${success},\"message\":\"${message}\",\"url\":\"${url}\",\"password\":\"${password}\"}"
    exit 0
}

# 参数校验
if [[ -z "$APK_PATH" || -z "$COOKIE" ]]; then
    output_json "false" "参数不完整：需要 apk_path 和 cookie"
fi

if [[ ! -f "$APK_PATH" ]]; then
    output_json "false" "APK 文件不存在: ${APK_PATH}"
fi

# 文件大小检查（100MB = 104857600 字节）
FILE_SIZE=$(stat -c%s "$APK_PATH" 2>/dev/null || stat -f%z "$APK_PATH" 2>/dev/null || echo 0)
if [[ "$FILE_SIZE" -gt 104857600 ]]; then
    output_json "false" "文件超过 100MB 限制（当前: $(( FILE_SIZE / 1024 / 1024 ))MB）"
fi

# 蓝奏云上传需要将 APK 重命名为 zip（蓝奏云限制某些文件类型上传）
# 创建临时 zip 文件
TMP_DIR=$(mktemp -d)
ZIP_NAME="${APP_NAME}-$(date +%Y%m%d%H%M%S).zip"
ZIP_PATH="${TMP_DIR}/${ZIP_NAME}"
cp "$APK_PATH" "$ZIP_PATH"

# 清理临时文件
cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

# 获取上传信息（需要登录态）
UPLOAD_INFO=$(curl -s -b "$COOKIE" \
    "https://up.lanzou.com/fileup.php?action=info" 2>/dev/null || echo "")

if [[ -z "$UPLOAD_INFO" ]]; then
    output_json "false" "获取上传信息失败，Cookie 可能已过期"
fi

# 解析上传参数（蓝奏云返回 JSON）
# 字段: task_id, token, servers (上传服务器列表)
TASK_ID=$(echo "$UPLOAD_INFO" | grep -o '"task_id":"[^"]*"' | head -1 | cut -d'"' -f4 || echo "")
UPLOAD_TOKEN=$(echo "$UPLOAD_INFO" | grep -o '"token":"[^"]*"' | head -1 | cut -d'"' -f4 || echo "")

if [[ -z "$TASK_ID" || -z "$UPLOAD_TOKEN" ]]; then
    output_json "false" "解析上传参数失败，Cookie 可能已过期或蓝奏云接口变更"
fi

# 获取上传服务器
SERVER=$(echo "$UPLOAD_INFO" | grep -o '"server":"[^"]*"' | head -1 | cut -d'"' -f4 || echo "")
if [[ -z "$SERVER" ]]; then
    SERVER="https://up.lanzou.com"
fi

# 上传文件
UPLOAD_RESULT=$(curl -s \
    -b "$COOKIE" \
    -X POST \
    "${SERVER}/fileup.php" \
    -F "task=${TASK_ID}" \
    -F "token=${UPLOAD_TOKEN}" \
    -F "file=@${ZIP_PATH};filename=${ZIP_NAME}" \
    2>/dev/null || echo "")

if [[ -z "$UPLOAD_RESULT" ]]; then
    output_json "false" "上传请求失败，请检查网络连接"
fi

# 解析上传结果
FILE_ID=$(echo "$UPLOAD_RESULT" | grep -o '"f_id":"[^"]*"' | head -1 | cut -d'"' -f4 || echo "")

if [[ -z "$FILE_ID" ]]; then
    # 上传失败，尝试获取错误信息
    ERROR_MSG=$(echo "$UPLOAD_RESULT" | grep -o '"info":"[^"]*"' | head -1 | cut -d'"' -f4 || echo "上传失败")
    output_json "false" "${ERROR_MSG}"
fi

# 等待文件处理
sleep 2

# 获取文件分享链接
FILE_INFO=$(curl -s \
    -b "$COOKIE" \
    -X POST \
    "https://up.lanzou.com/fileinfo.php" \
    -d "fids[]=${FILE_ID}" \
    2>/dev/null || echo "")

# 提取分享链接
SHARE_URL=$(echo "$FILE_INFO" | grep -o '"share_url":"[^"]*"' | head -1 | cut -d'"' -f4 || echo "")

if [[ -n "$SHARE_URL" ]]; then
    output_json "true" "上传成功" "$SHARE_URL" ""
else
    # 分享链接获取失败，但文件已上传
    output_json "true" "上传成功，但分享链接获取失败，请到蓝奏云后台手动获取" "" ""
fi
