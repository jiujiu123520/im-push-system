#!/usr/bin/env bash
# ============================================================
# 上传 APK 到蓝奏云（up.woozooo.com）
#
# 本脚本基于 真实免费号( up.woozooo.com ) 实测流程：
#   Step 1: GET  https://up.woozooo.com/mydisk.php?item=files&action=index
#           -> 拿 反CSRF vei + 用户 uid (doupload.php?uid=xxx)
#   Step 2: POST https://up.woozooo.com/html5up.php
#           -> multipart 上传 apk, 字段名 upload_file
#   Step 3: POST https://up.woozooo.com/doupload.php?uid=<uid>  task=22
#           -> 创建分享链接, 返回 密码 pwd + 短链 f_id + 分享子域名 is_newd
#   最终下载链接: {is_newd}/{f_id}  , 提取码: pwd
#
# 用法: upload-to-lanzou.sh <apk_path> <app_name> <cookie>
#
# 输出: JSON 格式
#   成功: {"success":true,"url":"分享链接","password":"提取码","message":"上传成功"}
#   失败: {"success":false,"message":"错误原因"}
#
# 注意:
#   1. 蓝奏云免费版单文件大小限制 100MB（超过必须升级会员），本脚本硬校验 100MB
#   2. Cookie 在浏览器开发者工具抓取，抓 up.woozooo.com 请求下的整条 Cookie 字符串
#      典型至少要包含: ylogin=xxx; ylogins=xxx; PHPSESSID=xxx; phpdisk_info=xxx; uag=xxx
#   3. APK 扩展直接可传，不需伪装 zip
#   4. 蓝奏云免费号 taoc 提示: 非会员不支持手机端分享 apk 文件(电脑端支持)
#      若用户在手机端打开分享页被拦截, 建议切阿里云 OSS/腾讯云 COS 托管
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

# 蓝奏云免费版 单文件 100MB 上限 (html5uploader3.js: fileSingleSizeLimit=upsizeb=104857600)
FREE_LIMIT=$(( 100 * 1024 * 1024 ))
if [[ "$FILE_SIZE" -gt "$FREE_LIMIT" ]]; then
    fail "文件超过蓝奏云免费版 100MB 上限（当前: $(( FILE_SIZE / 1024 / 1024 ))MB）；升级会员或改用阿里云 OSS"
fi

command -v curl >/dev/null 2>&1 || fail "未安装 curl，请先 apt-get install curl"
command -v sed  >/dev/null 2>&1 || fail "未安装 sed"

# 安全文件名：APP 名只保留字母数字和常用安全字符
SAFE_APP_NAME=$(printf '%s' "$APP_NAME" | sed 's/[^A-Za-z0-9._-]//g; s/^[.-]*//; s/[.-]*$//')
[[ -z "$SAFE_APP_NAME" ]] && SAFE_APP_NAME="app"
VER=$(date +%Y%m%d%H%M%S)
UPLOAD_FILENAME="${SAFE_APP_NAME}-${VER}.apk"

# ============================================================
# Step 1: GET mydisk.php 取 vei (反CSRF) + uid
# ============================================================
DISK_HTML=$(curl -sS -k --max-time 20 \
    -H "User-Agent: $UA" \
    -H "Referer: $BASE_URL/mydisk.php" \
    -b "$COOKIE" \
    "$BASE_URL/mydisk.php?item=files&action=index" 2>/dev/null || echo "")

[[ -z "$DISK_HTML" ]] && fail "无法访问蓝奏云控制台 (mydisk.php 请求为空，检查网络/域名/Cookie)"

# ---------- 登录态 / 账号问题 明确检测 ----------
# 蓝奏云在各种异常时会先返回 <script>alert("提示");location.href="..."</script>
# 这里把常见异常提前识别出来，给出中文明确提示，而不是让用户猜。
if grep -qE '<script[^>]*>.*alert\(' <<<"$DISK_HTML"; then
    ALERT_MSG=$(grep -oE 'alert\(["\x27][^"\x27]*["\x27]\)' <<<"$DISK_HTML" \
        | head -1 | sed -E "s/alert\([\"']([^\"']*)[\"']\)/\1/")
    [[ -n "$ALERT_MSG" ]] && fail "蓝奏云返回提示：${ALERT_MSG}。请先登录 up.woozooo.com 网页端处理后再重新获取 Cookie"
fi

# 未登录特征：出现 登录/请登录/password/立即登录
if grep -qiE '请登录|立即登录|login.*password|password.*action.*login' <<<"$DISK_HTML"; then
    fail "蓝奏云 Cookie 已失效（返回登录页），请在浏览器重新登录 up.woozooo.com 后抓取完整 Cookie 填入"
fi

# ---------- 取 vei: 反 CSRF 令牌（AJAX 里写 'vei':'WV0EVABSBgoEBgJSCFI='）----------
VEI=$(grep -oE "'vei'\s*:\s*['\"][A-Za-z0-9_\-+=/]{10,}['\"]" <<<"$DISK_HTML" \
    | head -1 | sed -E "s/.*vei[\"']?\s*:\s*[\"']([A-Za-z0-9_\-+=/]{10,})[\"'].*/\1/")
if [[ -z "$VEI" ]]; then
    # 兜底：<input type="hidden" name="vei" value="xxx"> （某些版本）
    VEI=$(grep -oE 'name=["\x27]?vei["\x27]?[^>]*value=["\x27][A-Za-z0-9_\-+=/]{10,}["\x27]' <<<"$DISK_HTML" \
        | head -1 | sed -E 's/.*value=["\x27]([A-Za-z0-9_\-+=/]{10,})["\x27].*/\1/')
fi
[[ -z "$VEI" ]] && fail "无法解析蓝奏云反 CSRF 参数 (vei)，请检查 Cookie 是否完整且账号已登录"

# ---------- 取 uid: 用户 ID（AJAX url 里写 '/doupload.php?uid=1132484'）----------
UID=$(grep -oE '/doupload\.php\?uid=[0-9]+' <<<"$DISK_HTML" \
    | head -1 | sed -E 's/.*uid=([0-9]+).*/\1/')
[[ -z "$UID" ]] && fail "无法解析蓝奏云用户 uid (doupload.php?uid=xxx)，请检查 Cookie 是否有效"

# ============================================================
# Step 2: POST html5up.php 上传 APK (字段名 upload_file, 不是 file!)
# ============================================================
UPLOAD_BODY=$(curl -sS -k --max-time 600 \
    -H "User-Agent: $UA" \
    -H "Referer: $BASE_URL/mydisk.php" \
    -H "X-Requested-With: XMLHttpRequest" \
    -H "Accept: application/json, text/javascript, */*; q=0.01" \
    -b "$COOKIE" \
    -X POST \
    --form-string "task=1" \
    --form-string "vie=2" \
    --form-string "ve=2" \
    --form-string "folder_id=-1" \
    --form-string "id=-1" \
    --form-string "name=${UPLOAD_FILENAME}" \
    -F "upload_file=@${APK_PATH};filename=${UPLOAD_FILENAME};type=application/octet-stream" \
    "$BASE_URL/html5up.php" 2>/dev/null || echo "")

[[ -z "$UPLOAD_BODY" ]] && fail "上传请求失败 (html5up.php 无响应)，请检查网络/文件大小（100MB限制）"

# ---------- 上传返回解析 ----------
# 成功示例:
# {
#   "zt":1,
#   "info":"上传成功",
#   "text":[{
#     "icon":"apk",
#     "id":"304172609",
#     "f_id":"iyDbH402g24j",
#     "name_all":"lanzou_test-xxx.apk",
#     "size":"5.0 M",
#     "is_newd":"https://xiaogangpao.lanzout.com"
#   }]
# }
# 失败示例:
#   {"zt":0,"info":"不能上传.bin格式的文件","text":null}  <- 扩展名不在白名单
#   {"zt":9,...}  <- 未登录, 需要刷新登录
Z_STATUS=$(grep -oE '"zt"\s*:\s*[0-9]+' <<<"$UPLOAD_BODY" | head -1 | sed -E 's/.*:\s*//')
Z_INFO=$(  grep -oE '"info"\s*:\s*"[^"]*"'   <<<"$UPLOAD_BODY" | head -1 | sed -E 's/.*:"([^"]*)".*/\1/')

if [[ -z "$Z_STATUS" ]]; then
    # 非 JSON，典型是要求登录/被风控
    if grep -qiE 'login|登录|password|请登录|立即登录' <<<"$UPLOAD_BODY"; then
        fail "蓝奏云 Cookie 已失效（上传接口要求登录），请在浏览器重新登录并复制新 Cookie"
    fi
    PREVIEW=$(printf '%.200s' "$UPLOAD_BODY" | sed 's/\\/\\\\/g; s/"/\\"/g')
    fail "上传返回异常，不是 JSON（可能接口变更/风控）：$PREVIEW"
fi

if [[ "$Z_STATUS" == "9" ]]; then
    fail "蓝奏云 Cookie 已失效（上传返回 zt=9 no login），请重新抓取 Cookie"
fi

if [[ "$Z_STATUS" != "1" ]]; then
    [[ -z "$Z_INFO" ]] && Z_INFO="上传失败(zt=$Z_STATUS)"
    fail "$Z_INFO"
fi

# 取文件 ID（创建分享时用的 file_id=xxx, 是长整型 id 304172609，不是短链 f_id）
Z_ID=$(grep -oE '"id"\s*:\s*"[0-9]+"' <<<"$UPLOAD_BODY" | head -1 | sed -E 's/.*:"([0-9]+)".*/\1/')
[[ -z "$Z_ID" ]] && Z_ID=$(grep -oE '"id"\s*:\s*[0-9]+' <<<"$UPLOAD_BODY" | head -1 | sed -E 's/.*:\s*([0-9]+).*/\1/')
[[ -z "$Z_ID" ]] && fail "上传成功但未解析到文件 id，请检查蓝奏云接口返回格式"

# 兜底: 上传成功直接拿到的短链/域名（如果 step3 创建分享失败时直接用）
UPLOAD_F_ID=$(grep -oE '"f_id"\s*:\s*"[A-Za-z0-9]+"' <<<"$UPLOAD_BODY" | head -1 | sed -E 's/.*:"([A-Za-z0-9]+)".*/\1/')
UPLOAD_DOMAIN=$(grep -oE '"is_newd"\s*:\s*"https?://[^"]+"' <<<"$UPLOAD_BODY" | head -1 | sed -E 's/.*:"(https?:\/\/[^"]+)".*/\1/')

# ============================================================
# Step 3: POST doupload.php?uid=<uid>  task=22  创建分享
# ============================================================
# 免费号随机 4 位提取码（蓝奏云返回 info.pwd= 下一次它实际用的码，不一定等于传进去的）
PASS=$(head -c 4 /dev/urandom 2>/dev/null | od -An -tx1 | tr -d ' \n' | head -c 4)
[[ -z "$PASS" ]] && PASS=$(tr -dc 'a-zA-Z0-9' </dev/urandom 2>/dev/null | head -c 4 || echo "qwer")

SHARE_RESP=$(curl -sS -k --max-time 20 \
    -H "User-Agent: $UA" \
    -H "Referer: $BASE_URL/mydisk.php" \
    -H "X-Requested-With: XMLHttpRequest" \
    -H "Accept: application/json, text/javascript, */*; q=0.01" \
    -b "$COOKIE" \
    -X POST \
    --data-urlencode "task=22" \
    --data-urlencode "file_id=$Z_ID" \
    --data-urlencode "pwd=$PASS" \
    --data-urlencode "name_desc=" \
    --data-urlencode "des=" \
    --data-urlencode "vei=$VEI" \
    "$BASE_URL/doupload.php?uid=$UID" 2>/dev/null || echo "")

# ---------- 解析分享返回 ----------
# 成功示例:
# {
#   "zt":1,
#   "info":{
#     "pwd":"8jcb",
#     "onof":"0",
#     "f_id":"iyDbH402g24j",
#     "taoc":"非会员不支持手机分享apk文件（电脑支持）...",
#     "is_newd":"https://xiaogangpao.lanzout.com"
#   }
# }
# 失败示例 (仅会员):
#   {"zt":null,"info":"此功能仅会员使用（个人中心-会员个性化）","text":null,"dat":null}
SHARE_ZT=$(grep -oE '"zt"\s*:\s*[0-9]+' <<<"$SHARE_RESP" | head -1 | sed -E 's/.*:\s*//')
SHARE_PWD=$( grep -oE '"pwd"\s*:\s*"[A-Za-z0-9]+"' <<<"$SHARE_RESP" | head -1 | sed -E 's/.*:"([A-Za-z0-9]+)".*/\1/')
SHARE_FID=$( grep -oE '"f_id"\s*:\s*"[A-Za-z0-9]+"' <<<"$SHARE_RESP" | head -1 | sed -E 's/.*:"([A-Za-z0-9]+)".*/\1/')
SHARE_DOM=$( grep -oE '"is_newd"\s*:\s*"https?://[^"]+"' <<<"$SHARE_RESP" | head -1 | sed -E 's/.*:"(https?:\/\/[^"]+)".*/\1/')
SHARE_INFO_STR=$(grep -oE '"info"\s*:\s*"[^"]*"' <<<"$SHARE_RESP" | head -1 | sed -E 's/.*:"([^"]*)".*/\1/')
# taoc 字段只在输出日志里保留，不用解析给前端

if [[ "$SHARE_ZT" == "1" && -n "$SHARE_FID" && -n "$SHARE_DOM" ]]; then
    SHARE_URL="${SHARE_DOM%/}/${SHARE_FID}"
    FINAL_PWD="${SHARE_PWD:-$PASS}"
    output_json "true" "上传蓝奏云成功" "$SHARE_URL" "$FINAL_PWD"
fi

# ---------- Step3 失败时的兜底：用 Step2 直接拿到的 f_id + is_newd ----------
# 典型 case: task=22 返回非会员/其他错误，但文件本身已经上传成功并分配了短链
if [[ -n "$UPLOAD_F_ID" && -n "$UPLOAD_DOMAIN" ]]; then
    FALLBACK_URL="${UPLOAD_DOMAIN%/}/${UPLOAD_F_ID}"
    output_json "true" \
        "文件已上传并生成链接（自动创建带密码分享失败$([ -n "$SHARE_INFO_STR" ] && echo ": ${SHARE_INFO_STR}")；已退回无密码公开链接）" \
        "$FALLBACK_URL" ""
fi

# ---------- 终极兜底：告诉用户手动去后台分享 ----------
WARN_MSG="文件已上传成功（蓝奏云文件ID=$Z_ID"
[ -n "$UPLOAD_F_ID" ] && WARN_MSG="$WARN_MSG，短链f_id=$UPLOAD_F_ID"
[ -n "$UPLOAD_DOMAIN" ] && WARN_MSG="$WARN_MSG，分享域名=$UPLOAD_DOMAIN"
WARN_MSG="$WARN_MSG），但自动创建分享链接失败"
[ -n "$SHARE_INFO_STR" ] && WARN_MSG="$WARN_MSG：$SHARE_INFO_STR"
WARN_MSG="$WARN_MSG。请登录 up.woozooo.com 后台 → 找到该文件 → 点右侧三个点「分享」手动获取链接和密码"
output_json "true" "$WARN_MSG" "" ""
