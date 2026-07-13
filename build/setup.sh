#!/bin/bash
# ============================================================
# Android 构建环境一键安装脚本（国内服务器专用）
#
# 用法:
#   sudo bash build/setup.sh
#
# 功能:
#   1. 安装 JDK 17（Android Gradle Plugin 要求）
#   2. 下载并安装 Android cmdline-tools
#   3. 接受许可协议并安装 SDK 组件（platform-tools, platforms, build-tools）
#   4. 安装 Gradle 8.7
#   5. 创建 Gradle Wrapper（如果项目没有）
#   6. 创建 local.properties 指向 Android SDK
#   7. 安装并启动 BuildWorker systemd 服务
#   8. 设置 build/app 目录权限给 www-data
#
# 注意:
#   - 脚本需要 root 或 sudo 权限
#   - 默认 Android SDK 安装到 /opt/android-sdk
#   - 默认 Gradle 安装到 /opt/gradle-8.7
#   - 国内服务器自动使用镜像加速
# ============================================================

set -e

# ------------------------------------------------------------
# 配置项
# ------------------------------------------------------------
PROJECT_DIR="${PROJECT_DIR:-/www/push-system}"
ANDROID_SDK_ROOT="${ANDROID_SDK_ROOT:-/opt/android-sdk}"
GRADLE_VERSION="${GRADLE_VERSION:-8.7}"
ANDROID_API_LEVEL="${ANDROID_API_LEVEL:-34}"
BUILD_TOOLS_VERSION="${BUILD_TOOLS_VERSION:-34.0.0}"

# 颜色输出
COLOR_GREEN='\033[0;32m'
COLOR_YELLOW='\033[1;33m'
COLOR_RED='\033[0;31m'
COLOR_BLUE='\033[0;34m'
COLOR_RESET='\033[0m'

info()  { echo -e "${COLOR_GREEN}[INFO]${COLOR_RESET} $*"; }
warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*" >&2; }
step()  { echo -e "\n${COLOR_BLUE}===== $1 =====${COLOR_RESET}"; }

echo "========================================"
echo "  Android 构建环境一键安装"
echo "========================================"
echo "项目目录: $PROJECT_DIR"
echo "Android SDK: $ANDROID_SDK_ROOT"
echo "Gradle 版本: $GRADLE_VERSION"
echo "Android API: $ANDROID_API_LEVEL"
echo ""

# ------------------------------------------------------------
# [1/8] 安装 JDK 17
# ------------------------------------------------------------
step "[1/8] 检查并安装 JDK 17"
if command -v java >/dev/null 2>&1 && java -version 2>&1 | grep -q "version \"17"; then
    info "JDK 17 已安装"
    java -version 2>&1 | head -n 1
else
    info "安装 JDK 17..."
    apt-get update -qq
    apt-get install -y openjdk-17-jdk-headless
    info "JDK 17 安装完成"
    java -version 2>&1 | head -n 1
fi

# 设置 JAVA_HOME 环境变量
JAVA_HOME_PATH=$(dirname $(dirname $(readlink -f $(which java))))
if ! grep -q "JAVA_HOME" /etc/environment; then
    echo "JAVA_HOME=$JAVA_HOME_PATH" >> /etc/environment
    info "已设置 JAVA_HOME=$JAVA_HOME_PATH 到 /etc/environment"
fi
export JAVA_HOME="$JAVA_HOME_PATH"

# ------------------------------------------------------------
# [2/8] 下载并安装 Android cmdline-tools
# ------------------------------------------------------------
step "[2/8] 安装 Android cmdline-tools"
SDKMANAGER="$ANDROID_SDK_ROOT/cmdline-tools/latest/bin/sdkmanager"

if [ -x "$SDKMANAGER" ]; then
    info "Android cmdline-tools 已安装"
    "$SDKMANAGER" --version
else
    info "下载 Android cmdline-tools..."
    mkdir -p "$ANDROID_SDK_ROOT/cmdline-tools"
    cd /tmp
    CMDLINE_ZIP="commandlinetools-linux-11076708_latest.zip"
    if [ ! -f "$CMDLINE_ZIP" ]; then
        # 官方源 + 国内镜像备用
        wget -q --tries=3 --timeout=60 \
            "https://dl.google.com/android/repository/$CMDLINE_ZIP" -O "$CMDLINE_ZIP" || \
        wget -q --tries=3 --timeout=60 \
            "https://mirrors.tuna.tsinghua.edu.cn/android/repository/$CMDLINE_ZIP" -O "$CMDLINE_ZIP"
    fi
    # 清空旧目录并重新解压
    rm -rf "$ANDROID_SDK_ROOT/cmdline-tools"
    mkdir -p "$ANDROID_SDK_ROOT/cmdline-tools"
    unzip -q "$CMDLINE_ZIP" -d "$ANDROID_SDK_ROOT/cmdline-tools"
    # Google 压缩包结构是 cmdline-tools/bin/，需要改名为 latest/
    mv "$ANDROID_SDK_ROOT/cmdline-tools/cmdline-tools" "$ANDROID_SDK_ROOT/cmdline-tools/latest"
    info "Android cmdline-tools 安装完成"
    "$SDKMANAGER" --version
fi

# ------------------------------------------------------------
# [3/8] 接受许可协议并安装 SDK 组件
# ------------------------------------------------------------
step "[3/8] 安装 Android SDK 组件"
info "接受许可协议..."
yes | "$SDKMANAGER" --licenses > /dev/null 2>&1 || true

info "安装 platform-tools, platforms;android-$ANDROID_API_LEVEL, build-tools;$BUILD_TOOLS_VERSION..."
"$SDKMANAGER" \
    "platform-tools" \
    "platforms;android-$ANDROID_API_LEVEL" \
    "build-tools;$BUILD_TOOLS_VERSION"

info "Android SDK 组件已安装"
ls -la "$ANDROID_SDK_ROOT"

# ------------------------------------------------------------
# [4/8] 安装 Gradle
# ------------------------------------------------------------
step "[4/8] 安装 Gradle $GRADLE_VERSION"
GRADLE_DIR="/opt/gradle-$GRADLE_VERSION"

if [ -x "$GRADLE_DIR/bin/gradle" ]; then
    info "Gradle $GRADLE_VERSION 已安装"
    "$GRADLE_DIR/bin/gradle" --version | head -n 5
else
    info "下载 Gradle $GRADLE_VERSION..."
    cd /tmp
    GRADLE_ZIP="gradle-$GRADLE_VERSION-bin.zip"
    if [ ! -f "$GRADLE_ZIP" ]; then
        # 腾讯云镜像 + 官方源备用
        wget -q --tries=3 --timeout=60 \
            "https://mirrors.cloud.tencent.com/gradle/$GRADLE_ZIP" -O "$GRADLE_ZIP" || \
        wget -q --tries=3 --timeout=60 \
            "https://services.gradle.org/distributions/$GRADLE_ZIP" -O "$GRADLE_ZIP"
    fi
    unzip -q "$GRADLE_ZIP" -d /opt/
    info "Gradle 安装完成"
    "$GRADLE_DIR/bin/gradle" --version | head -n 5
fi

# ------------------------------------------------------------
# [5/8] 创建 local.properties
# ------------------------------------------------------------
step "[5/8] 创建 local.properties"
cat > "$PROJECT_DIR/local.properties" <<EOF
# 自动生成：Android SDK 路径
sdk.dir=$ANDROID_SDK_ROOT
EOF
info "local.properties 已创建"
cat "$PROJECT_DIR/local.properties"

# ------------------------------------------------------------
# [6/8] 删除 gradlew（强制使用全局 gradle）
# ------------------------------------------------------------
step "[6/8] 清理 gradlew"
if [ -f "$PROJECT_DIR/gradlew" ]; then
    info "删除 gradlew（强制使用全局 gradle，避免 wrapper 下载 distribution 超时）..."
    rm -f "$PROJECT_DIR/gradlew"
    rm -rf "$PROJECT_DIR/gradle"
else
    info "gradlew 不存在，跳过"
fi

# ------------------------------------------------------------
# [7/8] 设置目录权限
# ------------------------------------------------------------
step "[7/8] 设置目录权限"
info "设置 build/app/.gradle 目录权限给 www-data..."
mkdir -p "$PROJECT_DIR/build/logs" "$PROJECT_DIR/.gradle"
chown -R www-data:www-data "$PROJECT_DIR/build" "$PROJECT_DIR/app" "$PROJECT_DIR/.gradle"
chmod -R u+rw "$PROJECT_DIR/build" "$PROJECT_DIR/app"
info "目录权限设置完成"

# ------------------------------------------------------------
# [8/8] 安装并启动 BuildWorker 服务
# ------------------------------------------------------------
step "[8/8] 安装并启动 BuildWorker 服务"
BUILD_WORKER_SERVICE="$PROJECT_DIR/deploy/systemd/push-build-worker.service"

if [ -f "$BUILD_WORKER_SERVICE" ]; then
    info "安装 push-build-worker systemd 服务..."
    cp "$BUILD_WORKER_SERVICE" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable push-build-worker 2>/dev/null || true
    systemctl restart push-build-worker
    sleep 2
    info "push-build-worker 服务状态："
    systemctl status push-build-worker --no-pager -l | head -n 12
else
    warn "未找到 push-build-worker.service，跳过 BuildWorker 安装"
fi

# ------------------------------------------------------------
# 完成
# ------------------------------------------------------------
echo ""
echo "========================================"
echo -e "  ${COLOR_GREEN}✓ Android 构建环境安装完成${COLOR_RESET}"
echo "========================================"
echo ""
echo "环境概览："
echo "  - JDK: $(java -version 2>&1 | head -n 1)"
echo "  - Android SDK: $ANDROID_SDK_ROOT"
echo "  - Gradle: $GRADLE_DIR/bin/gradle"
echo "  - BuildWorker: systemctl status push-build-worker"
echo ""
echo "现在可以在管理后台提交 APP 构建任务。"
echo "查看构建日志: sudo journalctl -u push-build-worker -f"
