#!/usr/bin/env bash
# ============================================================
# 自定义上传脚本示例
#
# 本脚本在 APK 构建成功后被自动调用，用于将 APK 上传到
# 你指定的网盘或存储服务（如阿里云盘、腾讯 COS、七牛云等）。
#
# 用法: custom-upload-example.sh <apk_path> <build_id> <app_name>
#
# 脚本需要：
#   1. 可执行权限（chmod +x）
#   2. 第一行输出上传后的 URL（http:// 或 https:// 开头）
#   3. 后续行可选输出额外信息
#
# 配置方法：
#   1. 复制本文件为 custom-upload.sh
#   2. 修改上传逻辑为你使用的网盘/存储服务
#   3. 在后台「APK分发 → 设置」中填写脚本路径
# ============================================================

APK_PATH="${1:-}"
BUILD_ID="${2:-}"
APP_NAME="${3:-app}"

# ===== 示例1：上传到七牛云 =====
# 需要先安装 qshell（七牛云命令行工具）
# QINIU_BUCKET="your-bucket"
# QINIU_DOMAIN="https://cdn.example.com"
# UPLOAD_KEY="apk/${APP_NAME}-${BUILD_ID}.apk"
# qshell rput "${QINIU_BUCKET}" "${UPLOAD_KEY}" "${APK_PATH}" 2>/dev/null
# echo "${QINIU_DOMAIN}/${UPLOAD_KEY}"

# ===== 示例2：上传到阿里云 OSS =====
# 需要先安装 ossutil
# OSS_BUCKET="your-bucket"
# OSS_ENDPOINT="oss-cn-hangzhou.aliyuncs.com"
# OSS_KEY="apk/${APP_NAME}-${BUILD_ID}.apk"
# ossutil cp "${APK_PATH}" "oss://${OSS_BUCKET}/${OSS_KEY}" -e "${OSS_ENDPOINT}" 2>/dev/null
# echo "https://${OSS_BUCKET}.${OSS_ENDPOINT}/${OSS_KEY}"

# ===== 示例3：上传到腾讯云 COS =====
# 需要先安装 coscli
# COS_BUCKET="your-bucket-1234567890"
# COS_REGION="ap-guangzhou"
# COS_KEY="apk/${APP_NAME}-${BUILD_ID}.apk"
# coscli cp "${APK_PATH}" "cos://${COS_BUCKET}/${COS_KEY}" 2>/dev/null
# echo "https://${COS_BUCKET}.cos.${COS_REGION}.myqcloud.com/${COS_KEY}"

# ===== 示例4：使用 curl 上传到自建 HTTP 接口 =====
# UPLOAD_URL="https://your-upload-server.com/api/upload"
# RESULT=$(curl -s -X POST "${UPLOAD_URL}" -F "file=@${APK_PATH}" -F "key=apk/${BUILD_ID}")
# URL=$(echo "$RESULT" | grep -o '"url":"[^"]*"' | cut -d'"' -f4)
# echo "$URL"

# ===== 示例5：仅输出服务器自身下载链接（与自托管下载相同） =====
# SERVER_DOMAIN="https://your-domain.com"
# echo "${SERVER_DOMAIN}/api/apk-distribution/download/PLACEHOLDER"
# 注意：此方式需手动替换 token，不推荐

echo ""
echo "请在 custom-upload.sh 中实现你的上传逻辑"
exit 1
