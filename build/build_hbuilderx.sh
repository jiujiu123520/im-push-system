#!/bin/bash
# ============================================
# HBuilderX 云打包脚本
# ============================================
# 功能：使用 HBuilderX 模板生成云打包项目
# 说明：本脚本生成打包后可直接导入 HBuilderX 进行云打包
# ============================================

set -e

# ---------------- 颜色定义 ----------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# ---------------- 工具函数 ----------------
info() { echo -e "${GREEN}[INFO]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1" >&2; }

# ---------------- 默认参数 ----------------
BUILD_ID="local-$(date +%s)"
APP_NAME="PushApp"
DEFAULT_KEY="default_key"
SERVER_URL="http://127.0.0.1:9501"
SERVER_WS_URL="ws://127.0.0.1:9502"
PACKAGE_NAME="com.example.pushapp"
VERSION_NAME="1.0.0"
VERSION_CODE="100"
OUTPUT_DIR="./output"
ICON_PATH=""

# 获取脚本所在目录
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
HBUILDERX_TEMPLATE="$SCRIPT_DIR/hbuilderx"

# ---------------- 解析参数 ----------------
while [ $# -gt 0 ]; do
    case "$1" in
        --build-id)      BUILD_ID="$2"; shift 2 ;;
        --app-name)      APP_NAME="$2"; shift 2 ;;
        --default-key)   DEFAULT_KEY="$2"; shift 2 ;;
        --server-url)    SERVER_URL="$2"; shift 2 ;;
        --ws-url)        SERVER_WS_URL="$2"; shift 2 ;;
        --package-name)  PACKAGE_NAME="$2"; shift 2 ;;
        --version-name)  VERSION_NAME="$2"; shift 2 ;;
        --version-code)  VERSION_CODE="$2"; shift 2 ;;
        --output-dir)    OUTPUT_DIR="$2"; shift 2 ;;
        --icon-path)     ICON_PATH="$2"; shift 2 ;;
        *) error "未知参数：$1"; exit 1 ;;
    esac
done

# ---------------- 开始构建 ----------------
info "============================================"
info "  HBuilderX 项目打包"
info "============================================"
info "构建ID:       $BUILD_ID"
info "应用名称:     $APP_NAME"
info "包名:         $PACKAGE_NAME"
info "版本:         $VERSION_NAME ($VERSION_CODE)"
info "服务器地址:   $SERVER_URL"
info "WebSocket:    $SERVER_WS_URL"
info "============================================"

# ---------------- 检查模板 ----------------
if [ ! -d "$HBUILDERX_TEMPLATE" ]; then
    error "HBuilderX 模板目录不存在: $HBUILDERX_TEMPLATE"
    exit 1
fi

# ---------------- 准备输出目录 ----------------
BUILD_OUTPUT="$OUTPUT_DIR/hbuilderx-$BUILD_ID"
rm -rf "$BUILD_OUTPUT"
mkdir -p "$BUILD_OUTPUT"

# ---------------- 复制模板 ----------------
info "复制模板文件..."
cp -r "$HBUILDERX_TEMPLATE/"* "$BUILD_OUTPUT/"

# ---------------- 注入配置 ----------------
info "注入应用配置..."

# 转义特殊字符用于 JSON
ESC_APP_NAME=$(printf '%s' "$APP_NAME" | sed 's/\\/\\\\/g; s/"/\\"/g')
ESC_PACKAGE_NAME=$(printf '%s' "$PACKAGE_NAME" | sed 's/\\/\\\\/g; s/"/\\"/g')
ESC_SERVER_URL=$(printf '%s' "$SERVER_URL" | sed 's/\\/\\\\/g; s/"/\\"/g')
ESC_WS_URL=$(printf '%s' "$SERVER_WS_URL" | sed 's/\\/\\\\/g; s/"/\\"/g')
ESC_DEFAULT_KEY=$(printf '%s' "$DEFAULT_KEY" | sed 's/\\/\\\\/g; s/"/\\"/g')

# 更新 manifest.json
MANIFEST_FILE="$BUILD_OUTPUT/manifest.json"
if [ -f "$MANIFEST_FILE" ]; then
    sed -i.bak "s/\"name\" : \"[^\"]*\"/\"name\" : \"$ESC_APP_NAME\"/" "$MANIFEST_FILE"
    sed -i.bak "s/\"versionName\" : \"[^\"]*\"/\"versionName\" : \"$VERSION_NAME\"/" "$MANIFEST_FILE"
    sed -i.bak "s/\"versionCode\" : \"[^\"]*\"/\"versionCode\" : \"$VERSION_CODE\"/" "$MANIFEST_FILE"
    rm -f "$MANIFEST_FILE.bak"
    info "已更新 manifest.json"
fi

# 更新 config.js
CONFIG_FILE="$BUILD_OUTPUT/js/config.js"
if [ -f "$CONFIG_FILE" ]; then
    cat > "$CONFIG_FILE" <<EOF
// 应用配置（由构建脚本动态注入）
window.APP_CONFIG = {
    default_key: "$ESC_DEFAULT_KEY",
    server_url: "$ESC_SERVER_URL",
    ws_url: "$ESC_WS_URL",
    version_name: "$VERSION_NAME"
};
EOF
    info "已更新 config.js"
fi

# ---------------- 处理图标 ----------------
if [ -n "$ICON_PATH" ] && [ -f "$ICON_PATH" ]; then
    info "处理应用图标..."
    ICON_DIR="$BUILD_OUTPUT/img"
    mkdir -p "$ICON_DIR"
    cp "$ICON_PATH" "$ICON_DIR/logo.png"
    info "已复制应用图标"
fi

# ---------------- 创建打包说明 ----------------
README_FILE="$BUILD_OUTPUT/README.txt"
cat > "$README_FILE" <<EOF
============================================
HBuilderX 云打包说明
============================================

项目名称: $APP_NAME
包名: $PACKAGE_NAME
版本: $VERSION_NAME ($VERSION_CODE)
构建ID: $BUILD_ID

打包步骤:
1. 打开 HBuilderX
2. 文件 -> 导入 -> 从本地目录导入
3. 选择本目录
4. 点击菜单: 发行 -> 原生App-云打包
5. 在弹出的对话框中:
   - Android: 勾选
   - iOS: 按需勾选
   - 包名: $PACKAGE_NAME
   - 证书: 选择自有证书或使用DCloud公用证书
   - 点击"打包"
6. 等待打包完成，下载 APK/IPA 文件

注意事项:
- 首次云打包需要在 DCloud 开发者中心实名认证
- 使用自有证书请确保证书文件和密码正确
- 包名一旦确定，后续更新请保持一致
- 服务器地址已内置到应用中，无需额外配置

============================================
EOF

info "已生成打包说明"

# ---------------- 完成 ----------------
info "============================================"
info "  打包完成!"
info "============================================"
info "输出目录: $BUILD_OUTPUT"
info ""
info "下一步操作:"
info "1. 打开 HBuilderX"
info "2. 导入目录: $BUILD_OUTPUT"
info "3. 菜单: 发行 -> 原生App-云打包"
info "============================================"

echo "$BUILD_OUTPUT" > "$OUTPUT_DIR/.last_hbuilderx_output"
