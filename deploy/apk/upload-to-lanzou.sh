#!/usr/bin/env bash
# ============================================================
# 上传 APK 到蓝奏云（up.woozooo.com）
#
# 蓝奏云( up.woozooo.com / lanzou.com ) 没有官方开放 API，本脚本
# 模拟浏览器完整上传 + 建分享链接流程。
#
# 完整流程：
#   Step 1: GET  https://up.woozooo.com/mydisk.php     -> 拿 ve(CSRF) + folder_id_f(上传文件夹)
#   Step 2: POST https://up.woozooo.com/html5up.php   -> multipart 上传 apk 文件
#   Step 3: POST https://up.woozooo.com/share.php      -> 创建分享链接(随机4位密码)
#
# 用法: upload-to-lanzou.sh <apk_path> <app_name> <cookie>
#
# 输出: JSON 格式
#   成功: {"success":true,"url":"分享链接","password":"提取码","message":"上传成功"}
#   失败: {"success":false,"message":"错误原因"}
#
# 注意:
#   1. 蓝奏云免费版单文件大小限制 100MB（超过必须升级会员）
#   2. Cookie 在浏览器开发者工具抓取，抓 up.woozooo.com 请求下的整条 Cookie 字符串
#      典型至少要包含: ylogin=xxx; phpdisk_info=xxx;
#   3. APK 扩展直接可传，不需伪装 zip
#   4. PHP 的 shell_exec 会丢弃 stderr，这里把 curl 的错误写到 stderr 会丢，
#      所以 curl -f 失败时用 HTTP 码 + body 文本检测，不用 2>&1 混写
# ============================================================

set -u
set -o pipefail

APK_PATH="${1:-}"
APP_NAME="${2:-app}"
COOKIE="${3:-}"

UA="Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
BASE_URL="https://up.woozooo.com"

# ---------- 辅助函数 ----------
output_json() {
    local success="$1"
    local message="$2"
    local url="${3:-}"
    local password="${4:-}"
    # JSON 转义简单版：把 " 和 \ 转掉
    message=$(printf '%s' "$message" | sed 's/\\/\\\\/g; s/"/\\"/g')
    url=$(printf '%s' "$url" | sed 's/\\/\\\\/g; s/"/\\"/g')
    password=$(printf '%s' "$password" | sed 's/\\/\\\\/g; s/"/\\"/g')
    printf '{"success":%s,"message":"%s","url":"%s","password":"%s"}\n' \
        "$success" "$message" "$url" "$password"
    exit 0
}

fail() { output_json "false" "$1"; }

# ---------- 基本校验 ----------
[[ -z "$APK_PATH" || -z "$COOKIE" ]] && fail "参数不完整：需要 apk_path 和 cookie"
[[ -f "$APK_PATH" ]] || fail "APK 文件不存在: ${APK_PATH}"

FILE_SIZE=$(stat -c%s "$APK_PATH" 2>/dev/null || stat -f%z "$APK_PATH" 2>/dev/null || echo 0)
[[ "$FILE_SIZE" -eq 0 ]] && fail "APK 文件为空"
# 蓝奏云免费版 100MB；即使是会员也先按 2GB 上限保护
MAX_BYTES=$(( 2 * 1024 * 1024 * 1024 ))
if [[ "$FILE_SIZE" -gt "$MAX_BYTES" ]]; then
    fail "文件超过 2GB 上限（当前: $(( FILE_SIZE / 1024 / 1024 ))MB），请压缩或改用自托管"
fi
if [[ "$FILE_SIZE" -gt 104857600 ]]; then
    fail "文件超过 100MB（蓝奏云免费版限制，当前: $(( FILE_SIZE / 1024 / 1024 ))MB）；升级会员后可传更大"
fi

command -v curl >/dev/null 2>&1 || fail "未安装 curl，请先 apt-get install curl"
command -v sed >/dev/null 2>&1  || fail "未安装 sed"

# 安全文件名：APP 名只保留字母数字和常用安全字符
SAFE_APP_NAME=$(printf '%s' "$APP_NAME" | sed 's/[^A-Za-z0-9._-]//g; s/^[.-]*//; s/[.-]*$//')
[[ -z "$SAFE_APP_NAME" ]] && SAFE_APP_NAME="app"
VER=$(date +%Y%m%d%H%M%S)
UPLOAD_FILENAME="${SAFE_APP_NAME}-${VER}.apk"

# ---------- Step 1: 取 CSRF (ve) + folder_id ----------
DISK_HTML=$(curl -sS -k --max-time 15 \
    -H "User-Agent: $UA" \
    -H "Referer: $BASE_URL/mydisk.php" \
    -b "$COOKIE" \
    "$BASE_URL/mydisk.php?item=files&action=index" 2>/dev/null || echo "")

[[ -z "$DISK_HTML" ]] && fail "无法访问蓝奏云控制台 (mydisk.php 请求为空，检查网络/域名)"

# ---------- 登录态 / 账号问题 明确检测 ----------
# 蓝奏云在各种异常时会先返回 <script>alert("提示");location.href="..."</script>
# 这里把常见异常提前识别出来，给出中文明确提示，而不是让用户猜。
if grep -qE '<script[^>]*>.*alert\(' <<<"$DISK_HTML"; then
    # 提取 alert(...) 里的中文提示
    ALERT_MSG=$(grep -oE 'alert\(["\x27][^"\x27]*["\x27]\)' <<<"$DISK_HTML" \
        | head -1 | sed -E "s/alert\([\"']([^\"']*)[\"']\)/\1/")
    [[ -n "$ALERT_MSG" ]] && fail "蓝奏云返回提示：${ALERT_MSG}。请先登录 up.woozooo.com 网页端处理后再重新获取 Cookie"
fi

# 验证登录态：未登录时页面一般出现 "登录"/"password" 表单，没 ve 也没 folder_id_f
if ! grep -qE '(name=["'"'"']?ve["'"'"']?|ve\s*[:=]\s*["'"'"'])' <<<"$DISK_HTML" \
    && ! grep -q 'folder_id_f' <<<"$DISK_HTML"; then
    if grep -qiE 'login|登录|password|请登录|立即登录' <<<"$DISK_HTML"; then
        fail "蓝奏云 Cookie 已失效（跳转到登录页），请在浏览器重新登录并复制新 Cookie"
    fi
fi

# 取 CSRF: name="ve" value="xxxxx"
VE=$(grep -oE 'name=["'"'"']?ve["'"'"']?[^>]*value=["'"'"'][^"'"'"']*["'"'"']' <<<"$DISK_HTML" \
    | head -1 | sed -E 's/.*value=["'"'"']([^"'"'"']*)["'"'"'].*/\1/')

# folder_id_f：通常是 <input type="hidden" name="folder_id_f" value="数字">
FOLDER_ID=$(grep -oE 'name=["'"'"']?folder_id_f["'"'"']?[^>]*value=["'"'"'][0-9]*["'"'"']' <<<"$DISK_HTML" \
    | head -1 | sed -E 's/.*value=["'"'"']([0-9]*)["'"'"'].*/\1/')
[[ -z "$FOLDER_ID" ]] && FOLDER="0" || FOLDER="$FOLDER_ID"

if [[ -z "$VE" ]]; then
    # 兜底：有的版本在 JS 里写 ve: "xx"
    VE=$(grep -oE 've\s*[:=]\s*["'"'"'][A-Za-z0-9_-]{10,}["'"'"']' <<<"$DISK_HTML" \
        | head -1 | sed -E "s/.*[\"']([A-Za-z0-9_-]{10,})[\"'].*/\1/")
fi
[[ -z "$VE" ]] && fail "无法解析蓝奏云反 CSRF 参数 (ve)，请检查 Cookie 是否为 up.woozooo.com 域名下完整 Cookie"

# ---------- Step 2: 上传 ----------
# 蓝奏云 html5up.php 接受字段：
#   task         固定 1
#   ve           反 CSRF
#   id           folder_id_f
#   name         文件名
#   chunk        分片索引 0
#   chunks       总分片数 1
#   uploadedSize 已上传大小（和 size 一样，不分片）
#   size         总大小（字节）
#   file         multipart 文件
UPLOAD_BODY=$(curl -sS -k --max-time 600 \
    -H "User-Agent: $UA" \
    -H "Referer: $BASE_URL/mydisk.php" \
    -H "X-Requested-With: XMLHttpRequest" \
    -H "Accept: application/json, text/javascript, */*; q=0.01" \
    -b "$COOKIE" \
    -X POST \
    -F "task=1" \
    -F "ve=$VE" \
    -F "id=$FOLDER" \
    -F "name=$UPLOAD_FILENAME" \
    -F "chunk=0" \
    -F "chunks=1" \
    -F "uploadedSize=$FILE_SIZE" \
    -F "size=$FILE_SIZE" \
    -F "folder_id=$FOLDER" \
    -F "folder_id_f=$FOLDER" \
    -F "file=@${APK_PATH};filename=${UPLOAD_FILENAME};type=application/octet-stream" \
    "$BASE_URL/html5up.php" 2>/dev/null || echo "")

[[ -z "$UPLOAD_BODY" ]] && fail "上传请求失败 (html5up.php 无响应)，请检查网络和文件大小"

# 上传返回典型 JSON：
#   成功: {"zt":1,"info":"上传成功","text":"","id":1234567,"name":"xxx.apk"}
#   失败: {"zt":0,"info":"错误描述"} 或 错误 HTML
Z_STATUS=$(grep -oE '"zt"\s*:\s*[0-9]+' <<<"$UPLOAD_BODY" | head -1 | sed -E 's/.*:\s*//')
Z_INFO=$(  grep -oE '"info"\s*:\s*"[^"]*"'   <<<"$UPLOAD_BODY" | head -1 | sed -E 's/.*:"([^"]*)".*/\1/')
Z_ID=$(    grep -oE '"id"\s*:\s*[0-9]+'      <<<"$UPLOAD_BODY" | head -1 | sed -E 's/.*:\s*//')

if [[ -z "$Z_STATUS" ]]; then
    # 非 JSON 返回，很可能是登录失效或被拦截
    if grep -qiE 'login|登录|password' <<<"$UPLOAD_BODY"; then
        fail "蓝奏云 Cookie 已失效（上传接口要求登录），请在浏览器重新登录并复制新 Cookie"
    fi
    # 打印前 120 字符便于排查
    PREVIEW=$(printf '%.120s' "$UPLOAD_BODY" | sed 's/\\/\\\\/g; s/"/\\"/g')
    fail "上传返回格式异常，不是 JSON（可能接口变更）：$PREVIEW"
fi

if [[ "$Z_STATUS" != "1" ]]; then
    [[ -z "$Z_INFO" ]] && Z_INFO="上传失败"
    fail "$Z_INFO"
fi

[[ -z "$Z_ID" ]] && fail "上传成功但未获取到文件 id (蓝奏云接口返回缺失)"

# ---------- Step 3: 创建分享链接 & 随机 4 位密码 ----------
PASS=$(head -c 4 /dev/urandom 2>/dev/null | od -An -tx1 | tr -d ' \n' | head -c 4)
[[ -z "$PASS" ]] && PASS=$(tr -dc 'a-zA-Z0-9' </dev/urandom 2>/dev/null | head -c 4 || echo "qwer")

# share.php 创建分享：POST action=share&file_id=XX&pwd=1（=带密码）&name_desc=&des=
SHARE_RESP=$(curl -sS -k --max-time 15 \
    -H "User-Agent: $UA" \
    -H "Referer: $BASE_URL/mydisk.php" \
    -H "X-Requested-With: XMLHttpRequest" \
    -H "Accept: application/json, text/javascript, */*; q=0.01" \
    -b "$COOKIE" \
    -X POST \
    --data-urlencode "action=share" \
    --data-urlencode "file_id=$Z_ID" \
    --data-urlencode "pwd=$PASS" \
    --data-urlencode "name_desc=" \
    --data-urlencode "des=" \
    --data-urlencode "ve=$VE" \
    "$BASE_URL/share.php" 2>/dev/null || echo "")

# 返回样例：{"zt":1,"info":"分享地址：https:\/\/wwi.lanzoup.com\/ixxxx","pwd":"qwer","url":"https:\/\/..."}
SHARE_ZT=$(  grep -oE '"zt"\s*:\s*[0-9]+' <<<"$SHARE_RESP" | head -1 | sed -E 's/.*:\s*//')
SHARE_URL=$( grep -oE '"url"\s*:\s*"[^"]*"'  <<<"$SHARE_RESP" | head -1 | sed -E 's/.*:"([^"]*)".*/\1/')
SHARE_INFO=$(grep -oE '"info"\s*:\s*"[^"]*"' <<<"$SHARE_RESP" | head -1 | sed -E 's/.*:"([^"]*)".*/\1/')
SHARE_PWD=$( grep -oE '"pwd"\s*:\s*"[^"]*"'  <<<"$SHARE_RESP" | head -1 | sed -E 's/.*:"([^"]*)".*/\1/')

if [[ -n "$SHARE_URL" ]]; then
    output_json "true" "上传蓝奏云成功" "$SHARE_URL" "${SHARE_PWD:-$PASS}"
fi

# 兜底：info 字段里可能写了 分享地址：https://xxx
FALLBACK_URL=$(grep -oE 'https?://[A-Za-z0-9./_-]+' <<<"$SHARE_INFO" | head -1 || echo "")
if [[ -n "$FALLBACK_URL" ]]; then
    output_json "true" "上传蓝奏云成功（分享地址从 info 中提取）" "$FALLBACK_URL" "$PASS"
fi

# 分享接口失败也不影响文件本身——告诉用户手动去后台设置分享
MSG="文件已上传成功(蓝奏云文件ID=$Z_ID)，但自动创建分享链接失败。请登录 up.woozooo.com 后台 -> 找到该文件 -> 右键「分享」手动获取链接和密码"
output_json "true" "$MSG" "" ""
