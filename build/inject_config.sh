#!/bin/bash
# ============================================================
# 配置注入脚本
# 在打包前将应用配置注入到 Android 工程中：
#   1. 生成 app/src/main/assets/build_config.json（运行时读取）
#   2. 通过 app/inject.gradle 以 resValue 注入应用名
#   3. 如有自定义图标，复制到 mipmap-xxxhdpi 覆盖 ic_launcher
# 使用方式：
#   bash build/inject_config.sh \
#     --app-name "MyApp" \
#     --default-key "abc123" \
#     --server-url "http://1.2.3.4:9501" \
#     --ws-url "ws://1.2.3.4:9502" \
#     --icon-path "/tmp/icon.png" \
#     --output-apk-name "app-release.apk" \
#     --build-id "b610..."
# ============================================================
set -e

# ---------------- 路径定位 ----------------
BUILD_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$BUILD_DIR")"
APP_DIR="$PROJECT_DIR/app"
ASSETS_DIR="$APP_DIR/src/main/assets"
RES_DIR="$APP_DIR/src/main/res"
TEMPLATE_FILE="$BUILD_DIR/templates/build_config.json.template"

# ---------------- 默认参数 ----------------
APP_NAME="PushApp"
DEFAULT_KEY="default_key"
SERVER_URL="http://127.0.0.1:9501"
SERVER_WS_URL="ws://127.0.0.1:9502"
ICON_PATH=""
PACKAGE_NAME=""
OUTPUT_APK_NAME="app-release.apk"
BUILD_ID="local-$(date +%s)"

# ---------------- 颜色输出 ----------------
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}[INJECT]${NC} $1"; }
warn()  { echo -e "${YELLOW}[INJECT]${NC} $1"; }
error() { echo -e "${RED}[INJECT]${NC} $1" >&2; }

# ---------------- 解析参数 ----------------
while [ $# -gt 0 ]; do
    case "$1" in
        --app-name)        APP_NAME="$2"; shift 2 ;;
        --default-key)    DEFAULT_KEY="$2"; shift 2 ;;
        --server-url)     SERVER_URL="$2"; shift 2 ;;
        --ws-url)         SERVER_WS_URL="$2"; shift 2 ;;
        --icon-path)      ICON_PATH="$2"; shift 2 ;;
        --package-name)   PACKAGE_NAME="$2"; shift 2 ;;
        --output-apk-name) OUTPUT_APK_NAME="$2"; shift 2 ;;
        --build-id)       BUILD_ID="$2"; shift 2 ;;
        *) error "未知参数：$1"; exit 1 ;;
    esac
done

info "应用名称：$APP_NAME"
[ -n "$PACKAGE_NAME" ] && info "包名：$PACKAGE_NAME"
info "服务器地址：$SERVER_URL"
info "WebSocket地址：$SERVER_WS_URL"
info "构建ID：$BUILD_ID"

# ---------------- 1. 生成 build_config.json ----------------
mkdir -p "$ASSETS_DIR"

BUILD_TIME=$(date '+%Y-%m-%d %H:%M:%S')
info "生成 $ASSETS_DIR/build_config.json ..."

if [ -f "$TEMPLATE_FILE" ]; then
    # 基于模板渲染
    CONTENT=$(cat "$TEMPLATE_FILE")
    CONTENT="${CONTENT//\{\{APP_NAME\}\}/$APP_NAME}"
    CONTENT="${CONTENT//\{\{DEFAULT_KEY\}\}/$DEFAULT_KEY}"
    CONTENT="${CONTENT//\{\{SERVER_URL\}\}/$SERVER_URL}"
    CONTENT="${CONTENT//\{\{SERVER_WS_URL\}\}/$SERVER_WS_URL}"
    CONTENT="${CONTENT//\{\{BUILD_TIME\}\}/$BUILD_TIME}"
    CONTENT="${CONTENT//\{\{BUILD_ID\}\}/$BUILD_ID}"
    printf '%s\n' "$CONTENT" > "$ASSETS_DIR/build_config.json"
else
    # 模板缺失时直接构造
    cat > "$ASSETS_DIR/build_config.json" <<EOF
{
  "app_name": "$APP_NAME",
  "default_key": "$DEFAULT_KEY",
  "server_url": "$SERVER_URL",
  "server_ws_url": "$SERVER_WS_URL",
  "build_time": "$BUILD_TIME",
  "build_id": "$BUILD_ID"
}
EOF
fi
info "build_config.json 生成完成"

# ---------------- 2. 通过 resValue 注入应用名 ----------------
# 生成 app/inject.gradle（Groovy 脚本），并在 build.gradle.kts 末尾追加 apply。
# resValue 会生成 <string name="app_name">，需确保 strings.xml 中不再同名声明。
INJECT_GRADLE="$APP_DIR/inject.gradle"
info "生成 $INJECT_GRADLE（resValue 注入） ..."

# 转义应用名中的特殊字符用于 Groovy 字符串
ESC_APP_NAME=$(printf '%s' "$APP_NAME" | sed "s/\\\\/\\\\\\\\/g; s/\"/\\\\\"/g")

cat > "$INJECT_GRADLE" <<EOF
// ===== 自动生成：打包配置注入（由 build/inject_config.sh 维护，勿手动编辑）=====
android {
    defaultConfig {
        resValue "string", "app_name", "$ESC_APP_NAME"
        resValue "string", "build_id", "$BUILD_ID"
    }
}
EOF

# 在 build.gradle.kts 末尾追加 apply from（Kotlin DSL 语法）
APPLY_LINE='apply(from = "inject.gradle")'
BUILD_GRADLE="$APP_DIR/build.gradle.kts"
if [ -f "$BUILD_GRADLE" ]; then
    # 先彻底清理所有旧的注入行（两种语法、注释行都清理，不用 ^ 锚定）
    sed -i '/apply from: "inject.gradle"/d' "$BUILD_GRADLE" 2>/dev/null || true
    sed -i '/apply(from = "inject.gradle")/d' "$BUILD_GRADLE" 2>/dev/null || true
    sed -i '/apply from: "signing.gradle"/d' "$BUILD_GRADLE" 2>/dev/null || true
    sed -i '/apply(from = "signing.gradle")/d' "$BUILD_GRADLE" 2>/dev/null || true
    sed -i '/自动注入打包配置/d' "$BUILD_GRADLE" 2>/dev/null || true
    sed -i '/自动注入签名配置/d' "$BUILD_GRADLE" 2>/dev/null || true
    sed -i '/自动生成.*debug 签名兜底/d' "$BUILD_GRADLE" 2>/dev/null || true
    # 清理尾部空行
    sed -i -e :a -e '/^\n*$/{$d;N;};/\n$/ba' "$BUILD_GRADLE" 2>/dev/null || true
    # 追加 Kotlin DSL 语法的 apply
    info "在 build.gradle.kts 追加 apply inject.gradle ..."
    printf '\n// 自动注入打包配置（由 build/inject_config.sh 维护）\n%s\n' "$APPLY_LINE" >> "$BUILD_GRADLE"
else
    warn "未找到 $BUILD_GRADLE，跳过 apply 注入"
fi

# 若 strings.xml 中存在 app_name 节点，注释避免与 resValue 冲突
STRINGS_XML="$RES_DIR/values/strings.xml"
if [ -f "$STRINGS_XML" ]; then
    if grep -qE '<string name="app_name"' "$STRINGS_XML"; then
        info "移除 strings.xml 中的 app_name 声明（改由 resValue 提供）..."
        sed -i -E '/<string name="app_name"/d' "$STRINGS_XML"
    fi
fi

# ---------------- 3. 处理自定义图标 ----------------
if [ -n "$ICON_PATH" ] && [ -f "$ICON_PATH" ]; then
    info "复制自定义图标：$ICON_PATH"
    XXXHDPI_DIR="$RES_DIR/mipmap-xxxhdpi"
    mkdir -p "$XXXHDPI_DIR"
    cp -f "$ICON_PATH" "$XXXHDPI_DIR/ic_launcher.png"
    cp -f "$ICON_PATH" "$XXXHDPI_DIR/ic_launcher_round.png"
    info "图标已覆盖到 mipmap-xxxhdpi/ic_launcher.png"
else
    info "未提供自定义图标，使用默认图标"
fi

# ---------------- 4. 修改包名（package_name） ----------------
if [ -n "$PACKAGE_NAME" ]; then
    BUILD_GRADLE="$APP_DIR/build.gradle.kts"
    if [ -f "$BUILD_GRADLE" ]; then
        info "修改包名为：$PACKAGE_NAME"
        sed -i "s/namespace = \".*\"/namespace = \"$PACKAGE_NAME\"/" "$BUILD_GRADLE"
        sed -i "s/applicationId = \".*\"/applicationId = \"$PACKAGE_NAME\"/" "$BUILD_GRADLE"
        info "build.gradle.kts 包名已更新"

        # 修改 Kotlin 源码目录结构
        OLD_NAMESPACE="com.push.app"
        OLD_PATH="$APP_DIR/src/main/java/$(echo "$OLD_NAMESPACE" | tr '.' '/')"
        NEW_PATH="$APP_DIR/src/main/java/$(echo "$PACKAGE_NAME" | tr '.' '/')"

        if [ -d "$OLD_PATH" ] && [ "$OLD_PATH" != "$NEW_PATH" ]; then
            info "移动源码目录：$OLD_PATH -> $NEW_PATH"
            # 如果目标目录已存在（上次构建残留），先删除避免 mv 将源码移入子目录
            if [ -d "$NEW_PATH" ]; then
                warn "目标目录 $NEW_PATH 已存在（上次构建残留），先删除..."
                rm -rf "$NEW_PATH"
            fi
            mkdir -p "$(dirname "$NEW_PATH")"
            mv "$OLD_PATH" "$NEW_PATH"

            # 清理空目录
            CURRENT_DIR="$(dirname "$OLD_PATH")"
            while [ "$CURRENT_DIR" != "$APP_DIR/src/main/java" ] && [ -d "$CURRENT_DIR" ]; do
                if [ -z "$(ls -A "$CURRENT_DIR" 2>/dev/null)" ]; then
                    rmdir "$CURRENT_DIR"
                    CURRENT_DIR="$(dirname "$CURRENT_DIR")"
                else
                    break
                fi
            done

            # 更新源码中所有包名引用（package 声明、import、代码体内的全限定名）
            info "更新源码中所有包名引用：$OLD_NAMESPACE -> $PACKAGE_NAME"
            find "$NEW_PATH" -type f \( -name "*.kt" -o -name "*.java" \) -print0 | while IFS= read -r -d '' file; do
                # 全局替换所有 com.push.app -> 新包名（覆盖 package 声明、import、全限定名引用）
                sed -i "s|$OLD_NAMESPACE|$PACKAGE_NAME|g" "$file"
            done
            # 同时更新 AndroidManifest.xml 中的包名引用
            MANIFEST="$APP_DIR/src/main/AndroidManifest.xml"
            if [ -f "$MANIFEST" ]; then
                sed -i "s|$OLD_NAMESPACE|$PACKAGE_NAME|g" "$MANIFEST"
                info "AndroidManifest.xml 包名已更新"
            fi
            info "源码包名引用已全部更新"
        fi
    else
        warn "未找到 $BUILD_GRADLE，跳过包名修改"
    fi
fi

info "配置注入完成"
exit 0
