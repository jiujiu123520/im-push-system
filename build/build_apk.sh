#!/bin/bash
# ============================================================
# APK 打包执行脚本
# 流程：
#   1. 接收打包参数（build_id, app_name, ...）
#   2. 调用 inject_config.sh 注入配置
#   3. 执行 gradlew assembleRelease（无签名时回退 assembleDebug）
#   4. 产出 APK 复制到 build/output/{build_id}/app-release.apk
#   5. 日志写入 build/logs/{build_id}.log
#   6. 输出构建结果 JSON（成功/失败 + APK路径）
# 使用方式：
#   bash build/build_apk.sh \
#     --build-id "b610xxx" \
#     --app-name "MyApp" \
#     --default-key "abc123" \
#     --server-url "http://1.2.3.4:9501" \
#     --ws-url "ws://1.2.3.4:9502" \
#     --icon-path "/tmp/icon.png" \
#     --build-type "release"
# 退出码：0 成功，非 0 失败
# ============================================================
set -e

# ---------------- 路径定位（必须在 check_resources 之前，磁盘检查需要 PROJECT_DIR）----------------
BUILD_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$BUILD_DIR")"
APP_DIR="$PROJECT_DIR/app"
OUTPUT_ROOT="$BUILD_DIR/output"
LOG_ROOT="$BUILD_DIR/logs"
KEYSTORE_DIR="$BUILD_DIR/keystore"
KEYSTORE_PROPS="$KEYSTORE_DIR/keystore.properties"
KEYSTORE_FILE="$KEYSTORE_DIR/release.keystore"

# ---------------- 资源预检查（防止 2H2G 服务器构建时 OOM，但不限制太死）----------------
check_resources() {
    # 检查可用内存（至少 80MB 即可启动，配合 swap 兜底）
    # 2G 服务器 MySQL+Redis+PHP 常驻后可用内存常低于 150MB，阈值过高会导致永远无法构建
    local available_mem
    available_mem=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
    if [[ "${available_mem}" -lt 80 ]]; then
        echo "[ERROR] 可用内存不足 80MB（当前 ${available_mem}MB），拒绝构建以防止服务器卡死"
        exit 1
    fi
    if [[ "${available_mem}" -lt 150 ]]; then
        echo "[WARN] 可用内存较低（${available_mem}MB < 150MB），将依赖 swap 进行构建，速度可能较慢"
    fi

    # 检查磁盘空间（至少 2GB）
    local available_disk
    available_disk=$(df -m "${PROJECT_DIR}" 2>/dev/null | awk 'NR==2{print $4}')
    if [[ -n "${available_disk}" ]] && [[ "${available_disk}" -lt 2048 ]]; then
        echo "[ERROR] 磁盘剩余空间不足 2GB（当前 ${available_disk}MB），拒绝构建"
        exit 1
    fi

    # 检查是否已有 Gradle 构建进程在运行（精确匹配 assemble/compile，避免残留进程导致永久拒绝）
    # 注意：pgrep 使用 POSIX ERE 正则，| 是交替运算符，不需要反斜杠转义
    if pgrep -f "gradle.*(assemble|compile|build)" >/dev/null 2>&1; then
        echo "[ERROR] 检测到已有 Gradle 构建进程运行，拒绝并发构建"
        exit 1
    fi
}
check_resources

# ---------------- 颜色输出 ----------------
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}[BUILD]${NC} $1"; }
warn()  { echo -e "${YELLOW}[BUILD]${NC} $1"; }
error() { echo -e "${RED}[BUILD]${NC} $1" >&2; }

# ---------------- 默认参数 ----------------
BUILD_ID="local-$(date +%s)"
APP_NAME="PushApp"
DEFAULT_KEY="default_key"
SERVER_URL="http://127.0.0.1:9501"
SERVER_WS_URL="ws://127.0.0.1:9502"
ICON_PATH=""
PACKAGE_NAME=""
BUILD_TYPE="release"

# ---------------- 解析参数 ----------------
while [ $# -gt 0 ]; do
    case "$1" in
        --build-id)     BUILD_ID="$2"; shift 2 ;;
        --app-name)     APP_NAME="$2"; shift 2 ;;
        --default-key)  DEFAULT_KEY="$2"; shift 2 ;;
        --server-url)   SERVER_URL="$2"; shift 2 ;;
        --ws-url)       SERVER_WS_URL="$2"; shift 2 ;;
        --icon-path)    ICON_PATH="$2"; shift 2 ;;
        --package-name) PACKAGE_NAME="$2"; shift 2 ;;
        --build-type)   BUILD_TYPE="$2"; shift 2 ;;
        *) error "未知参数：$1"; exit 1 ;;
    esac
done

# ---------------- 准备目录与日志 ----------------
mkdir -p "$OUTPUT_ROOT" "$LOG_ROOT"
OUTPUT_DIR="$OUTPUT_ROOT/$BUILD_ID"
mkdir -p "$OUTPUT_DIR"
LOG_FILE="$LOG_ROOT/$BUILD_ID.log"

# 结果状态（供调用方解析）
RESULT_FILE="$OUTPUT_DIR/result.json"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"; }

write_result() {
    # 写入结果 JSON：$1=success/failed, $2=apk_path, $3=message
    local status="$1" apk="$2" msg="$3"
    cat > "$RESULT_FILE" <<EOF
{
  "build_id": "$BUILD_ID",
  "status": "$status",
  "apk_path": "$apk",
  "message": "$msg",
  "build_time": "$(date '+%Y-%m-%d %H:%M:%S')"
}
EOF
}

# 记录开始
{
    echo "============================================================"
    echo "构建ID: $BUILD_ID"
    echo "应用名称: $APP_NAME"
    echo "服务器地址: $SERVER_URL"
    echo "WebSocket地址: $SERVER_WS_URL"
    echo "构建类型: $BUILD_TYPE"
    echo "开始时间: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "============================================================"
} | tee -a "$LOG_FILE"

# ---------------- 捕获失败 ----------------
on_fail() {
    local msg="$1"
    log "[失败] $msg"
    write_result "failed" "" "$msg"
    error "$msg"
}
trap 'on_fail "构建过程中断（退出码 $?）"' ERR

# ---------------- 1. 清理上次构建残留 + 注入配置 ----------------
# 恢复源码到原始状态（防止上次包名修改/配置注入残留导致编译失败）
info "清理上次构建残留..."
if [ -d "$PROJECT_DIR/.git" ]; then
    git -C "$PROJECT_DIR" checkout -- app/ 2>/dev/null || true
fi
# 清理 build.gradle.kts 中之前注入的 apply 行（防止 git checkout 无效时残留）
if [ -f "$APP_DIR/build.gradle.kts" ]; then
    sed -i '/^apply(from = "inject.gradle")/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
    sed -i '/^apply(from = "signing.gradle")/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
    sed -i '/^apply from: "inject.gradle"/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
    sed -i '/^apply from: "signing.gradle"/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
    sed -i '/自动注入打包配置/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
    sed -i '/自动注入签名配置/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
fi
# 删除注入产生的临时文件
rm -f "$APP_DIR/inject.gradle" "$APP_DIR/signing.gradle" 2>/dev/null || true
# 清理上次构建产物（避免 find 误判旧 APK）
rm -rf "$APP_DIR/build/outputs/apk" 2>/dev/null || true

info "调用 inject_config.sh 注入配置 ..."
# 使用数组安全存储参数（bash 版本支持）
inject_cmd=("bash" "$BUILD_DIR/inject_config.sh")
inject_cmd+=(--app-name "$APP_NAME")
inject_cmd+=(--default-key "$DEFAULT_KEY")
inject_cmd+=(--server-url "$SERVER_URL")
inject_cmd+=(--ws-url "$SERVER_WS_URL")
[ -n "$ICON_PATH" ] && inject_cmd+=(--icon-path "$ICON_PATH")
[ -n "$PACKAGE_NAME" ] && inject_cmd+=(--package-name "$PACKAGE_NAME")
inject_cmd+=(--build-id "$BUILD_ID")

"${inject_cmd[@]}" 2>&1 | tee -a "$LOG_FILE"

# ---------------- 2. 进入工程目录 ----------------
info "进入 Android 工程：$APP_DIR"
cd "$APP_DIR"

# 优先使用项目根目录的 gradlew（wrapper），不可用时回退到全局 gradle
GRADLEW=""
if [ -x "$PROJECT_DIR/gradlew" ]; then
    GRADLEW="$PROJECT_DIR/gradlew"
elif [ -x "/opt/gradle-8.7/bin/gradle" ]; then
    GRADLEW="/opt/gradle-8.7/bin/gradle"
    warn "gradlew 不可用，回退到全局 gradle: $GRADLEW"
else
    on_fail "未找到 gradlew 或全局 gradle，请安装 Gradle 或生成 wrapper"
    exit 1
fi

# 设置 Gradle 缓存目录到项目内（避免 www-data 用户无权写入 /var/www/.gradle）
export GRADLE_USER_HOME="${GRADLE_USER_HOME:-$PROJECT_DIR/.gradle}"
mkdir -p "$GRADLE_USER_HOME" 2>/dev/null || true

# ---------------- 3. 签名配置 ----------------
# 读取 keystore.properties（如有），生成 signing.gradle 并应用
signing_gradle="$APP_DIR/signing.gradle"
use_release_signing="false"

if [ -f "$KEYSTORE_PROPS" ] && [ -f "$KEYSTORE_FILE" ]; then
    info "发现 keystore 配置，启用 release 签名"
    # 解析 properties（KEY=VALUE 格式）
    # shellcheck disable=SC1090
    set -a; . "$KEYSTORE_PROPS"; set +a

    : "${STORE_FILE:=$KEYSTORE_FILE}"
    : "${KEY_ALIAS:=release}"
    : "${STORE_PASSWORD:=}"
    : "${KEY_PASSWORD:=}"

    if [ -z "$STORE_PASSWORD" ] || [ -z "$KEY_PASSWORD" ]; then
        warn "keystore.properties 缺少密码字段，回退 debug 签名"
    else
        cat > "$signing_gradle" <<EOF
// ===== 自动生成：签名配置（由 build_apk.sh 维护，勿手动编辑）=====
android {
    signingConfigs {
        release {
            storeFile file("$STORE_FILE")
            storePassword "$STORE_PASSWORD"
            keyAlias "$KEY_ALIAS"
            keyPassword "$KEY_PASSWORD"
        }
    }
    buildTypes {
        release {
            signingConfig signingConfigs.release
        }
    }
}
EOF
        # 在 build.gradle.kts 追加 apply（Kotlin DSL 语法，幂等）
        APPLY_LINE='apply(from = "signing.gradle")'
        # 先移除旧的 Groovy 语法行（兼容历史残留）
        sed -i '/^apply from: "signing.gradle"/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
        sed -i '/自动注入签名配置/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
        if ! grep -qF "$APPLY_LINE" "$APP_DIR/build.gradle.kts"; then
            printf '\n// 自动注入签名配置（由 build_apk.sh 维护）\n%s\n' "$APPLY_LINE" >> "$APP_DIR/build.gradle.kts"
        fi
        use_release_signing="true"
        info "release 签名已配置"
    fi
fi

# ---------------- 4. 执行 Gradle 构建 ----------------
if [ "$use_release_signing" = "true" ]; then
    GRADLE_TASK="assembleRelease"
elif [ "$BUILD_TYPE" = "debug" ]; then
    GRADLE_TASK="assembleDebug"
else
    # 无 release 签名时回退 debug 签名构建 release 变体
    warn "未配置 release 签名，使用 debug 签名构建"
    GRADLE_TASK="assembleRelease"
    # 用 debug 签名兜底
    if [ ! -f "$signing_gradle" ]; then
        cat > "$signing_gradle" <<EOF
// ===== 自动生成：debug 签名兜底（由 build_apk.sh 维护）=====
android {
    buildTypes {
        release {
            signingConfig signingConfigs.debug
        }
    }
}
EOF
        APPLY_LINE='apply(from = "signing.gradle")'
        # 先移除旧的 Groovy 语法行（兼容历史残留）
        sed -i '/^apply from: "signing.gradle"/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
        sed -i '/自动注入签名配置/d' "$APP_DIR/build.gradle.kts" 2>/dev/null || true
        if ! grep -qF "$APPLY_LINE" "$APP_DIR/build.gradle.kts"; then
            printf '\n// 自动注入签名配置（由 build_apk.sh 维护）\n%s\n' "$APPLY_LINE" >> "$APP_DIR/build.gradle.kts"
        fi
    fi
fi

info "执行 $GRADLEW $GRADLE_TASK ..."
log "执行命令：$GRADLEW $GRADLE_TASK --no-daemon --max-workers=1 -Dorg.gradle.parallel=false"
# 关闭 ERR trap 仅在 gradle 调用期间判断退出码
set +e
# --no-daemon: 禁用 daemon，构建后释放内存
# --max-workers=1: 限制单 worker，避免多 JVM 并发（2G 服务器优化）
# -Dorg.gradle.parallel=false: 禁用并行构建
# JVM 堆内存由 gradle.properties 中的 org.gradle.jvmargs 控制
# nice -n 10: 降低 CPU 优先级，让系统服务保持响应
# ionice -c 2 -n 7: 降低 IO 优先级，避免磁盘 IO 卡死其他服务
nice -n 10 ionice -c 2 -n 7 "$GRADLEW" "$GRADLE_TASK" --no-daemon --max-workers=1 -Dorg.gradle.parallel=false --stacktrace 2>&1 | tee -a "$LOG_FILE"
GRADLE_EXIT=${PIPESTATUS[0]}
set -e

if [ "$GRADLE_EXIT" -ne 0 ]; then
    on_fail "Gradle 构建失败（退出码 $GRADLE_EXIT）"
    exit 1
fi

# ---------------- 5. 定位并复制产出 APK ----------------
# 优先查找 release，其次 debug
APK_SRC=""
APK_SRC=$(find "$APP_DIR/build/outputs/apk" -name "*.apk" -path "*release*" -type f 2>/dev/null | head -n 1)
if [ -z "$APK_SRC" ]; then
    APK_SRC=$(find "$APP_DIR/build/outputs/apk" -name "*.apk" -type f 2>/dev/null | head -n 1)
fi

if [ -z "$APK_SRC" ] || [ ! -f "$APK_SRC" ]; then
    on_fail "未找到构建产出的 APK 文件"
    exit 1
fi

info "找到 APK：$APK_SRC"
APK_DEST="$OUTPUT_DIR/app-release.apk"
cp -f "$APK_SRC" "$APK_DEST"
info "已复制到：$APK_DEST"

# ---------------- 6. 写入结果 ----------------
log "[成功] 构建完成，APK 路径：$APK_DEST"
write_result "success" "$APK_DEST" "构建成功"

{
    echo "============================================================"
    echo "构建完成时间: $(date '+%Y-%m-%d %H:%M:%S')"
    echo "APK 路径: $APK_DEST"
    echo "============================================================"
} | tee -a "$LOG_FILE"

# 标准输出最终结果 JSON，供调用方解析
cat "$RESULT_FILE"

exit 0
