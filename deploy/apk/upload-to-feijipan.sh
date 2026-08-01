#!/usr/bin/env bash
# ============================================================
# 上传 APK 到小飞机网盘 (feejii.com)
#
# 小飞机网盘 API 流程 (基于 Web/App 真实抓包):
#   Step 1: GET  /app/vod/getUpToken?appToken=xxx&uuid=xxx&devCode=xxx&fileName=xxx&fileSize=xxx
#           -> 返回 S3 上传凭证 (uploadUrl/credential/key/bucket 等)
#   Step 2: PUT  {uploadUrl} (S3 兼容接口) 上传文件体
#   Step 3: GET  /app/share/url?appToken=xxx&uuid=xxx&devCode=xxx&fileId=xxx
#           -> 返回分享短链和密码 (如有)
#
# 用法: upload-to-feijipan.sh <apk_path> <app_name> <app_token> <uuid> <dev_code>
#
# 环境变量 (可选覆盖参数):
#   FEEJII_APP_TOKEN  / FEEJII_UUID / FEEJII_DEV_CODE / FEEJII_USER_ID
#
# 输出: JSON 格式
#   成功: {"success":true,"url":"分享链接","share_id":"文件ID","message":"上传成功"}
#   失败: {"success":false,"message":"错误原因"}
# ============================================================

set -u
set -o pipefail

APK_PATH="${1:-}"
APP_NAME="${2:-app}"
APP_TOKEN="${3:-${FEEJII_APP_TOKEN:-}}"
UUID="${4:-${FEEJII_UUID:-}}"
DEV_CODE="${5:-${FEEJII_DEV_CODE:-}}"

API_BASE="https://api.feejii.com"
UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"

# ---------- 辅助函数 ----------
output_json() {
    local success="$1"
    local message="$2"
    local url="${3:-}"
    local share_id="${4:-}"
    message=$(printf '%s' "$message" | sed 's/\\/\\\\/g; s/"/\\"/g')
    url=$(printf '%s' "$url" | sed 's/\\/\\\\/g; s/"/\\"/g')
    share_id=$(printf '%s' "$share_id" | sed 's/\\/\\\\/g; s/"/\\"/g')
    printf '{"success":%s,"message":"%s","url":"%s","share_id":"%s"}\n' \
        "$success" "$message" "$url" "$share_id"
    exit 0
}

fail() { output_json "false" "$1"; }

# ---------- 基本校验 ----------
[[ -z "$APK_PATH" ]] && fail "参数不完整：缺少 apk_path"
[[ -f "$APK_PATH" ]] || fail "APK 文件不存在: ${APK_PATH}"
[[ -z "$APP_TOKEN" ]] && fail "缺少 appToken，请在参数或环境变量 FEEJII_APP_TOKEN 中提供"
[[ -z "$UUID" ]]      && fail "缺少 uuid，请在参数或环境变量 FEEJII_UUID 中提供"
[[ -z "$DEV_CODE" ]]  && fail "缺少 devCode，请在参数或环境变量 FEEJII_DEV_CODE 中提供"

FILE_SIZE=$(stat -c%s "$APK_PATH" 2>/dev/null || stat -f%z "$APK_PATH" 2>/dev/null || echo 0)
[[ "$FILE_SIZE" -eq 0 ]] && fail "APK 文件为空"

command -v curl >/dev/null 2>&1 || fail "未安装 curl，请先 apt-get install curl"

# 安全文件名
SAFE_APP_NAME=$(printf '%s' "$APP_NAME" | sed 's/[^A-Za-z0-9._-]//g; s/^[.-]*//; s/[.-]*$//')
[[ -z "$SAFE_APP_NAME" ]] && SAFE_APP_NAME="app"
VER=$(date +%Y%m%d%H%M%S)
UPLOAD_FILENAME="${SAFE_APP_NAME}-${VER}.apk"

# URL 编码函数（用 python3 兜底，其次 printf + sed）
urlencode() {
    local s="$1"
    if command -v python3 >/dev/null 2>&1; then
        python3 -c "import sys,urllib.parse;sys.stdout.write(urllib.parse.quote(sys.argv[1]))" "$s"
    else
        local out="" i c
        for (( i=0; i<${#s}; i++ )); do
            c="${s:$i:1}"
            case "$c" in
                [a-zA-Z0-9.~_-]) out+="$c" ;;
                *) printf -v h '%02X' "'$c"; out+="%${h: -2}" ;;
            esac
        done
        printf '%s' "$out"
    fi
}

ENC_FILENAME=$(urlencode "$UPLOAD_FILENAME")

# ============================================================
# Step 1: 获取 S3 上传凭证
# ============================================================
UPTOKEN_URL="${API_BASE}/app/vod/getUpToken?appToken=${APP_TOKEN}&uuid=${UUID}&devCode=${DEV_CODE}&devType=1&userId=&categoryId=-1&fileName=${ENC_FILENAME}&fileSize=${FILE_SIZE}&etag=&duration=0&width=0&height=0"

TOKEN_RESP=$(curl -sS --max-time 30 \
    -H "User-Agent: $UA" \
    -H "Accept: application/json, text/plain, */*" \
    -H "Origin: https://www.feejii.com" \
    -H "Referer: https://www.feejii.com/" \
    "$UPTOKEN_URL" 2>/dev/null || echo "")

[[ -z "$TOKEN_RESP" ]] && fail "获取上传凭证失败：请求无响应（检查网络/凭证/域名）"

# 解析返回 JSON
# 成功示例：{"code":0,"msg":"success","data":{"uploadUrl":"https://xxx","key":"xxx","credential":"xxx","fileId":12345,...}}
# 失败示例：{"code":401,"msg":"appToken 无效"}
RESP_CODE=$(echo "$TOKEN_RESP" | grep -oE '"code"\s*:\s*-?[0-9]+' | head -1 | sed -E 's/.*:\s*(-?[0-9]+)/\1/')
RESP_MSG=$( echo "$TOKEN_RESP" | grep -oE '"msg"\s*:\s*"[^"]*"'  | head -1 | sed -E 's/.*:"([^"]*)".*/\1/')

if [[ -z "$RESP_CODE" ]]; then
    PREVIEW=$(printf '%.200s' "$TOKEN_RESP" | sed 's/\\/\\\\/g; s/"/\\"/g')
    fail "获取上传凭证返回异常（非 JSON）：$PREVIEW"
fi

if [[ "$RESP_CODE" != "0" ]]; then
    fail "获取上传凭证失败（code=$RESP_CODE）：${RESP_MSG:-未知错误}"
fi

# 提取 data 里的字段（支持多种写法，兼容返回）
extract_json_field() {
    local field="$1"
    local json="$2"
    # 优先 "field":"value" 字符串
    local v
    v=$(echo "$json" | grep -oE "\"$field\"\s*:\s*\"[^\"]*\"" | head -1 | sed -E "s/.*:\"([^\"]*)\".*/\1/")
    if [[ -n "$v" ]]; then echo "$v"; return; fi
    # 其次 "field":number
    v=$(echo "$json" | grep -oE "\"$field\"\s*:\s*-?[0-9]+" | head -1 | sed -E 's/.*:\s*(-?[0-9]+).*/\1/')
    echo "$v"
}

# 小飞机 getUpToken 通常把 data 作为一个子 JSON 块，这里尝试剥一层
DATA_BLOB=$(echo "$TOKEN_RESP" | sed -E 's/.*"data"\s*:\s*(\{.*\})[[:space:]]*$/\1/')
if [[ -z "$DATA_BLOB" || "$DATA_BLOB" == "$TOKEN_RESP" ]]; then
    DATA_BLOB="$TOKEN_RESP"
fi

UPLOAD_URL=$(extract_json_field "uploadUrl" "$DATA_BLOB")
S3_KEY=$(extract_json_field "key" "$DATA_BLOB")
FILE_ID=$(extract_json_field "fileId" "$DATA_BLOB")

[[ -z "$UPLOAD_URL" ]] && fail "上传凭证缺少 uploadUrl（返回：${RESP_MSG:-结构未识别}）"
[[ -z "$FILE_ID" ]]    && fail "上传凭证缺少 fileId"

# ============================================================
# Step 2: PUT 上传文件到 S3 兼容接口
# ============================================================
HTTP_CODE=$(curl -sS -o /tmp/feijii_s3_resp.$$ -w "%{http_code}" --max-time 600 \
    -H "User-Agent: $UA" \
    -X PUT \
    -T "$APK_PATH" \
    "$UPLOAD_URL" 2>/dev/null || echo "000")

S3_RESP=""
[[ -f /tmp/feijii_s3_resp.$$ ]] && S3_RESP=$(cat /tmp/feijii_s3_resp.$$ 2>/dev/null) && rm -f /tmp/feijii_s3_resp.$$

if [[ "$HTTP_CODE" != "200" && "$HTTP_CODE" != "201" && "$HTTP_CODE" != "204" ]]; then
    PREVIEW=$(printf '%.200s' "$S3_RESP" | sed 's/\\/\\\\/g; s/"/\\"/g')
    fail "S3 上传失败（HTTP $HTTP_CODE）：${PREVIEW:-网络超时或拒绝连接}"
fi

# ============================================================
# Step 3: 创建分享链接
# ============================================================
SHARE_URL="${API_BASE}/app/share/url?appToken=${APP_TOKEN}&uuid=${UUID}&devCode=${DEV_CODE}&devType=1&userId=&fileId=${FILE_ID}&pwd=&effect=0&effectCount=0"

SHARE_RESP=$(curl -sS --max-time 30 \
    -H "User-Agent: $UA" \
    -H "Accept: application/json, text/plain, */*" \
    -H "Origin: https://www.feejii.com" \
    -H "Referer: https://www.feejii.com/" \
    "$SHARE_URL" 2>/dev/null || echo "")

SHARE_CODE=$(echo "$SHARE_RESP" | grep -oE '"code"\s*:\s*-?[0-9]+' | head -1 | sed -E 's/.*:\s*(-?[0-9]+)/\1/')
SHARE_MSG=$( echo "$SHARE_RESP" | grep -oE '"msg"\s*:\s*"[^"]*"'  | head -1 | sed -E 's/.*:"([^"]*)".*/\1/')

# 分享返回 data 块
SHARE_DATA=$(echo "$SHARE_RESP" | sed -E 's/.*"data"\s*:\s*(\{.*\})[[:space:]]*$/\1/')
[[ -z "$SHARE_DATA" || "$SHARE_DATA" == "$SHARE_RESP" ]] && SHARE_DATA="$SHARE_RESP"

FINAL_URL=$(extract_json_field "url" "$SHARE_DATA")
[[ -z "$FINAL_URL" ]] && FINAL_URL=$(extract_json_field "shortUrl" "$SHARE_DATA")
[[ -z "$FINAL_URL" ]] && FINAL_URL=$(extract_json_field "shareUrl" "$SHARE_DATA")

if [[ "$SHARE_CODE" == "0" && -n "$FINAL_URL" ]]; then
    output_json "true" "上传小飞机网盘成功" "$FINAL_URL" "$FILE_ID"
fi

# 分享创建失败时兜底：提示用户手动去后台分享
WARN_MSG="文件已上传成功（小飞机文件ID=$FILE_ID）"
[[ -n "$S3_KEY" ]] && WARN_MSG="$WARN_MSG，S3 Key=$S3_KEY"
WARN_MSG="$WARN_MSG，但创建分享链接失败"
if [[ -n "$SHARE_MSG" ]]; then
    WARN_MSG="$WARN_MSG：${SHARE_MSG}"
fi
WARN_MSG="$WARN_MSG。请登录 www.feejii.com 后台 → 文件列表 → 找到该文件 → 「分享」手动获取下载链接"

# 如果分享没拿到 URL 但上传成功，仍然认为成功（让用户手动去拿）
output_json "true" "$WARN_MSG" "" "$FILE_ID"
