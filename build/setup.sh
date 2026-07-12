#!/bin/bash
# ============================================================
# 一键初始化 APK 打包环境（Linux）
# 安装组件：JDK 17 / Android SDK / Gradle 8.7 / Node.js 20 LTS
# 使用方式：sudo bash build/setup.sh
# ============================================================
set -e

# ---------------- 颜色输出 ----------------
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
error() { echo -e "${RED}[ERROR]${NC} $1" >&2; }

# ---------------- 前置检查 ----------------
# 必须以 root 执行
if [ "$(id -u)" -ne 0 ]; then
    error "请使用 root 或 sudo 执行此脚本：sudo bash build/setup.sh"
    exit 1
fi

# 项目根目录（build/ 的上一级）
BUILD_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$BUILD_DIR")"
cd "$PROJECT_DIR"

# 安装根目录（可通过环境变量覆盖）
INSTALL_ROOT="${ANDROID_INSTALL_ROOT:-/opt/android-build}"
SDK_ROOT="$INSTALL_ROOT/android-sdk"
GRADLE_ROOT="$INSTALL_ROOT/gradle-8.7"
NODE_ROOT="$INSTALL_ROOT/node"

info "项目目录：$PROJECT_DIR"
info "安装根目录：$INSTALL_ROOT"

mkdir -p "$INSTALL_ROOT" "$SDK_ROOT/cmdline-tools"
mkdir -p "$BUILD_DIR/output" "$BUILD_DIR/logs" "$BUILD_DIR/keystore"

# ---------------- 工具函数 ----------------
command_exists() { command -v "$1" >/dev/null 2>&1; }

# ============================================================
# 1. 检测并安装 JDK 17
# ============================================================
install_jdk() {
    info "检测 JDK 17 ..."
    # 先看系统中是否已存在 17 版本
    if command_exists java; then
        JAVA_VER=$(java -version 2>&1 | awk -F '"' '/version/ {print $2}' | awk -F. '{print $1}')
        if [ "$JAVA_VER" = "17" ]; then
            info "已检测到 JDK 17，跳过安装"
            if [ -z "${JAVA_HOME:-}" ]; then
                export JAVA_HOME=$(dirname "$(dirname "$(readlink -f "$(which java)")")")
            fi
            return 0
        fi
        warn "当前 JDK 版本为 $JAVA_VER，需要 17，开始安装"
    fi

    info "通过 apt 安装 openjdk-17-jdk ..."
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -y
    apt-get install -y openjdk-17-jdk

    # 定位 JAVA_HOME
    export JAVA_HOME="/usr/lib/jvm/java-17-openjdk-amd64"
    if [ ! -d "$JAVA_HOME" ]; then
        # 兼容 arm64
        export JAVA_HOME="/usr/lib/jvm/java-17-openjdk-arm64"
    fi
    info "JDK 17 安装完成，JAVA_HOME=$JAVA_HOME"
}

# ============================================================
# 2. 下载安装 Android SDK commandline-tools
# ============================================================
install_android_sdk() {
    info "检测 Android SDK commandline-tools ..."
    local CMDLINE_TOOLS_DIR="$SDK_ROOT/cmdline-tools/latest"
    if [ -f "$CMDLINE_TOOLS_DIR/bin/sdkmanager" ]; then
        info "commandline-tools 已存在，跳过下载"
        return 0
    fi

    local URL="https://dl.google.com/android/repository/commandlinetools-linux-11076708_latest.zip"
    local TMP_ZIP="/tmp/commandlinetools.zip"

    info "下载 commandline-tools：$URL"
    apt-get install -y unzip wget >/dev/null 2>&1 || true
    wget -q -O "$TMP_ZIP" "$URL"
    if [ ! -s "$TMP_ZIP" ]; then
        error "commandline-tools 下载失败"
        exit 1
    fi

    info "解压 commandline-tools ..."
    unzip -q -o "$TMP_ZIP" -d "$SDK_ROOT/cmdline-tools"
    # 官方包解压后顶层目录为 cmdline-tools，需重命名为 latest
    if [ -d "$SDK_ROOT/cmdline-tools/cmdline-tools" ] && [ ! -d "$CMDLINE_TOOLS_DIR" ]; then
        mv "$SDK_ROOT/cmdline-tools/cmdline-tools" "$CMDLINE_TOOLS_DIR"
    fi
    rm -f "$TMP_ZIP"

    if [ ! -f "$CMDLINE_TOOLS_DIR/bin/sdkmanager" ]; then
        error "sdkmanager 未找到，安装可能不完整"
        exit 1
    fi
    info "commandline-tools 安装完成"
}

# ============================================================
# 3. 安装 SDK 组件并接受许可协议
# ============================================================
install_sdk_components() {
    info "安装 SDK 组件 ..."
    export ANDROID_HOME="$SDK_ROOT"
    export ANDROID_SDK_ROOT="$SDK_ROOT"
    local SDKMANAGER="$SDK_ROOT/cmdline-tools/latest/bin/sdkmanager"

    # 接受所有许可协议（自动输入 y）
    info "接受 SDK 许可协议 ..."
    yes | "$SDKMANAGER" --licenses >/dev/null 2>&1 || true

    # 安装指定组件
    info "安装 platform-tools / platforms;android-34 / build-tools;34.0.0 ..."
    yes | "$SDKMANAGER" "platform-tools" "platforms;android-34" "build-tools;34.0.0" || {
        error "SDK 组件安装失败，请检查网络后重试"
        exit 1
    }
    info "SDK 组件安装完成"
}

# ============================================================
# 4. 下载安装 Gradle 8.7
# ============================================================
install_gradle() {
    info "检测 Gradle 8.7 ..."
    if [ -f "$GRADLE_ROOT/bin/gradle" ]; then
        info "Gradle 8.7 已存在，跳过下载"
        return 0
    fi

    local URL="https://services.gradle.org/distributions/gradle-8.7-bin.zip"
    local TMP_ZIP="/tmp/gradle-8.7-bin.zip"

    info "下载 Gradle 8.7：$URL"
    wget -q -O "$TMP_ZIP" "$URL"
    if [ ! -s "$TMP_ZIP" ]; then
        error "Gradle 下载失败"
        exit 1
    fi

    info "解压 Gradle 8.7 ..."
    unzip -q -o "$TMP_ZIP" -d "$INSTALL_ROOT"
    rm -f "$TMP_ZIP"

    export GRADLE_HOME="$GRADLE_ROOT"
    info "Gradle 8.7 安装完成，GRADLE_HOME=$GRADLE_HOME"
}

# ============================================================
# 5. 安装 Node.js 20 LTS（用于管理后台构建）
# ============================================================
install_nodejs() {
    info "检测 Node.js 20 ..."
    if command_exists node; then
        NODE_MAJOR=$(node -v 2>/dev/null | sed 's/v//' | awk -F. '{print $1}')
        if [ "$NODE_MAJOR" = "20" ]; then
            info "已检测到 Node.js 20，跳过安装"
            export NODE_HOME=""
            return 0
        fi
        warn "当前 Node 版本为 $NODE_MAJOR，需要 20，开始安装"
    fi

    info "通过 NodeSource 安装 Node.js 20 LTS ..."
    apt-get install -y ca-certificates curl gnupg >/dev/null 2>&1 || true
    # 导入 NodeSource GPG key 与仓库
    mkdir -p /etc/apt/keyrings
    curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key | gpg --dearmor -o /etc/apt/keyrings/nodesource.gpg 2>/dev/null || true
    echo "deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_20.x nodistro main" > /etc/apt/sources.list.d/nodesource.list
    apt-get update -y
    apt-get install -y nodejs
    info "Node.js 安装完成：$(node -v)"
}

# ============================================================
# 6. 配置环境变量到 /etc/profile.d/android-env.sh
# ============================================================
configure_env() {
    info "写入环境变量到 /etc/profile.d/android-env.sh ..."
    cat > /etc/profile.d/android-env.sh <<EOF
# Android 打包环境变量（由 build/setup.sh 生成）
export JAVA_HOME="$JAVA_HOME"
export ANDROID_HOME="$SDK_ROOT"
export ANDROID_SDK_ROOT="$SDK_ROOT"
export GRADLE_HOME="$GRADLE_ROOT"
export PATH="\$JAVA_HOME/bin:\$ANDROID_HOME/platform-tools:\$ANDROID_HOME/cmdline-tools/latest/bin:\$GRADLE_HOME/bin:\$PATH"
EOF
    chmod +x /etc/profile.d/android-env.sh
    info "环境变量配置完成"
}

# ============================================================
# 7. 验证安装结果
# ============================================================
verify() {
    info "================ 安装结果验证 ================"
    echo ""
    echo "JAVA_HOME      = $JAVA_HOME"
    java -version 2>&1 | head -n 1
    echo ""
    echo "ANDROID_HOME   = $SDK_ROOT"
    "$SDK_ROOT/cmdline-tools/latest/bin/sdkmanager" --version 2>/dev/null || echo "sdkmanager 版本未知"
    echo ""
    echo "GRADLE_HOME    = $GRADLE_ROOT"
    "$GRADLE_ROOT/bin/gradle" -v 2>/dev/null | grep -E "Gradle|Kotlin" | head -n 2 || echo "gradle 版本未知"
    echo ""
    if command_exists node; then
        echo "Node.js        = $(node -v)  npm = $(npm -v)"
    else
        warn "Node.js 未就绪"
    fi
    echo ""
    info "环境变量需重新加载后生效：source /etc/profile.d/android-env.sh"
    info "============ 安装完成 ============"
}

# ============================================================
# 主流程
# ============================================================
info "开始初始化 APK 打包环境 ..."

install_jdk
install_android_sdk
install_sdk_components
install_gradle
install_nodejs
configure_env
verify

exit 0
