#!/usr/bin/env bash
# ============================================================
# [已弃用] 上传 APK 到小飞机网盘 (feijipan.com)
#
# ⚠️ 此脚本已弃用，保留仅供历史参考。
# 新流程：用户先在小飞机网盘上传文件 → 后台「上传小飞机」按钮
#         → 选择网盘文件 → 后端调用 createFeijiiShare(fileId) 创建分享链接。
# 不再需要通过服务器中转上传文件。
#
# 小飞机网盘 API 流程 (基于 Web/App 真实抓包):
#   Step 1: POST /app/vod/getUpToken?appToken=xxx&uuid=xxx&devCode=xxx&fileName=xxx&fileSize=xxx
#           -> 返回 S3 上传凭证 (uploadUrl/credential/key/bucket 等)
#   Step 2: PUT  {uploadUrl} (S3 兼容接口) 上传文件体
#   Step 3: POST /app/share/url  创建分享链接
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

# ============================================================
# 跨系统：依赖检查 & 发行版识别（apt/apk/yum/brew/pacman）
# ============================================================
_need_cmd() { command -v "$1" >/dev/null 2>&1; }

_distro_install_hint() {
  local pkg="$1"
  if _need_cmd apt-get; then
    echo "  Debian/Ubuntu:  sudo apt-get update && sudo apt-get install -y $pkg"
  elif _need_cmd apk; then
    echo "  Alpine:         apk add --no-cache $pkg"
  elif _need_cmd yum; then
    echo "  CentOS/RHEL:     sudo yum install -y $pkg"
  elif _need_cmd dnf; then
    echo "  Rocky/Fedora:    sudo dnf install -y $pkg"
  elif _need_cmd brew; then
    echo "  macOS:           brew install $pkg"
  elif _need_cmd pacman; then
    echo "  Arch:            sudo pacman -S --noconfirm $pkg"
  else
    echo "  请使用系统包管理器安装: $pkg"
  fi
}

assert_dependencies() {
  local missing=()
  _need_cmd bash  || missing+=("bash (>=4.0，当前脚本依赖 bash 语法)")
  _need_cmd curl  || missing+=("curl")
  if ! _need_cmd python3; then
    echo "[WARN] upload-to-feijipan: 未检测到 python3，将使用 grep/sed 正则兜底解析 JSON（推荐安装 python3 以提高 Alpine/BSD 兼容性）" >&2
    _distro_install_hint "python3" >&2
  fi
  # BusyBox 工具链告警（Alpine 默认 BusyBox grep/sed 扩展正则与 GNU 行为不一致）
  if _need_cmd apk && ! _need_cmd python3; then
    echo "[WARN] Alpine 环境检测到 BusyBox grep/sed，扩展正则可能失效。请先执行：apk add --no-cache python3 curl bash" >&2
  fi
  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "[ERROR] upload-to-feijipan 缺失以下必需依赖，请先安装后重试：" >&2
    local m base_pkg
    for m in "${missing[@]}"; do
      echo "  - $m" >&2
      base_pkg="${m%% *}"
      _distro_install_hint "$base_pkg" >&2
    done
    exit 1
  fi
}

assert_dependencies

APK_PATH="${1:-}"
APP_NAME="${2:-app}"
APP_TOKEN="${3:-${FEEJII_APP_TOKEN:-}}"
UUID="${4:-${FEEJII_UUID:-}}"
DEV_CODE="${5:-${FEEJII_DEV_CODE:-}}"

API_BASE="https://api.feijipan.com"
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
    -H "Origin: https://www.feijipan.com" \
    -H "Referer: https://www.feijipan.com/" \
    "$UPTOKEN_URL" 2>/dev/null || echo "")

[[ -z "$TOKEN_RESP" ]] && fail "获取上传凭证失败：请求无响应（检查网络/凭证/域名）"

# 解析返回 JSON
# 成功示例：{"code":0,"msg":"success","data":{"uploadUrl":"https://xxx","key":"xxx","credential":"xxx","fileId":12345,...}}
# 失败示例：{"code":401,"msg":"appToken 无效"}
RESP_CODE=$(extract_json_field "code" "$TOKEN_RESP")
RESP_MSG=$(extract_json_field  "msg"  "$TOKEN_RESP")

if [[ -z "$RESP_CODE" ]]; then
    PREVIEW=$(printf '%.200s' "$TOKEN_RESP" | sed 's/\\/\\\\/g; s/"/\\"/g')
    fail "获取上传凭证返回异常（非 JSON）：$PREVIEW"
fi

if [[ "$RESP_CODE" != "0" ]]; then
    fail "获取上传凭证失败（code=$RESP_CODE）：${RESP_MSG:-未知错误}"
fi

# 通用 JSON 字段提取（顶层或 data 子对象都尝试）
#   主路径：python3 json.loads（BusyBox/BSD grep/sed 下不会抽风）
#   兜底：grep/sed 扩展正则（GNU 环境常用）
extract_json_field() {
    local field="$1"
    local json="$2"
    [[ -z "$json" ]] && { echo ""; return; }

    # ---- 主路径：python3（优先，跨系统行为一致）----
    if _need_cmd python3; then
        # 通过临时文件把 json 传给 python3（避免 heredoc + stdin + 命令行参数三者混用的坑）
        local _tmp="/tmp/feijii_json_$$"
        printf '%s' "$json" > "$_tmp" 2>/dev/null
        python3 - "$field" "$_tmp" <<'PYEOF' 2>/dev/null || true
import sys, json, os
f = sys.argv[1]
path = sys.argv[2] if len(sys.argv) > 2 else ''
raw = ''
if path and os.path.exists(path):
    try:
        with open(path, 'r', encoding='utf-8', errors='replace') as fh:
            raw = fh.read()
    except Exception:
        raw = ''
if not raw:
    try:
        raw = sys.stdin.read()
    except Exception:
        raw = ''
if not raw:
    print(''); sys.exit(0)

def lookup(d):
    if isinstance(d, dict):
        if f in d: return d[f]
        if 'data' in d and isinstance(d['data'], dict) and f in d['data']:
            return d['data'][f]
    return None
v = None
try:
    top = json.loads(raw)
    v = lookup(top)
except Exception:
    v = None
# 某些接口 data 是一个字符串化的 JSON，再试一次
if v is None:
    try:
        top = json.loads(raw)
        if isinstance(top, dict) and isinstance(top.get('data'), str):
            try:
                nested = json.loads(top['data'])
                if isinstance(nested, dict) and f in nested: v = nested[f]
            except Exception:
                pass
    except Exception:
        pass
if v is None:
    print('')
elif isinstance(v, bool):
    print('true' if v else 'false')
else:
    print(str(v))
PYEOF
        [[ -f "$_tmp" ]] && rm -f "$_tmp" 2>/dev/null
        return
    fi

    # ---- 兜底：老的 grep -oE 正则（GNU Linux 默认环境）----
    local v
    v=$(echo "$json" | grep -oE "\"$field\"\s*:\s*\"[^\"]*\"" | head -1 | sed -E "s/.*:\"([^\"]*)\".*/\1/")
    [[ -n "$v" ]] && { echo "$v"; return; }
    v=$(echo "$json" | grep -oE "\"$field\"\s*:\s*-?[0-9]+" | head -1 | sed -E 's/.*:\s*(-?[0-9]+).*/\1/')
    echo "$v"
}

# 提取 getUpToken 响应 data 字段（顶层 / data 子对象都兼容，extract_json_field 内部已处理）
UPLOAD_URL=$(extract_json_field "uploadUrl" "$TOKEN_RESP")
S3_KEY=$(extract_json_field "key" "$TOKEN_RESP")
FILE_ID=$(extract_json_field "fileId" "$TOKEN_RESP")

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
    -H "Origin: https://www.feijipan.com" \
    -H "Referer: https://www.feijipan.com/" \
    "$SHARE_URL" 2>/dev/null || echo "")

SHARE_CODE=$(extract_json_field "code" "$SHARE_RESP")
SHARE_MSG=$(extract_json_field  "msg"  "$SHARE_RESP")

# 提取分享 URL（extract_json_field 内部会去 data 子对象里找，多个字段名依次尝试）
FINAL_URL=""
for k in url shortUrl shareUrl downloadUrl; do
    v=$(extract_json_field "$k" "$SHARE_RESP")
    if [[ -n "$v" && "$v" == http* ]]; then FINAL_URL="$v"; break; fi
done

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
WARN_MSG="$WARN_MSG。请登录 www.feijipan.com 后台 → 文件列表 → 找到该文件 → 「分享」手动获取下载链接"

# 如果分享没拿到 URL 但上传成功，仍然认为成功（让用户手动去拿）
output_json "true" "$WARN_MSG" "" "$FILE_ID"
