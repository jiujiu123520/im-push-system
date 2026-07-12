#!/bin/bash
# ============================================================
# 生成 release 签名密钥脚本
# 使用 keytool 生成 release.keystore，并将密码等信息保存到
# keystore.properties 供 build_apk.sh 读取。
# 使用方式：
#   bash build/generate_keystore.sh \
#     --alias release \
#     --store-password "your_store_pwd" \
#     --key-password "your_key_pwd" \
#     --validity 36500 \
#     --name "PushApp" \
#     --org "PushApp Inc."
# 如未传密码，则交互式输入；如未传 alias 默认 release
# ============================================================
set -e

# ---------------- 路径定位 ----------------
BUILD_DIR="$(cd "$(dirname "$0")" && pwd)"
KEYSTORE_DIR="$BUILD_DIR/keystore"
KEYSTORE_FILE="$KEYSTORE_DIR/release.keystore"
PROPS_FILE="$KEYSTORE_DIR/keystore.properties"

mkdir -p "$KEYSTORE_DIR"

# ---------------- 颜色输出 ----------------
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN}[KEYSTORE]${NC} $1"; }
warn()  { echo -e "${YELLOW}[KEYSTORE]${NC} $1"; }
error() { echo -e "${RED}[KEYSTORE]${NC} $1" >&2; }

# ---------------- 默认参数 ----------------
ALIAS="release"
STORE_PASSWORD=""
KEY_PASSWORD=""
VALIDITY=36500
CN_NAME="PushApp"
ORG_UNIT="PushApp"
ORG_NAME="PushApp Inc."
LOCALITY="Beijing"
STATE="Beijing"
COUNTRY="CN"

# ---------------- 解析参数 ----------------
while [ $# -gt 0 ]; do
    case "$1" in
        --alias)          ALIAS="$2"; shift 2 ;;
        --store-password) STORE_PASSWORD="$2"; shift 2 ;;
        --key-password)   KEY_PASSWORD="$2"; shift 2 ;;
        --validity)       VALIDITY="$2"; shift 2 ;;
        --name)           CN_NAME="$2"; shift 2 ;;
        --org)            ORG_NAME="$2"; shift 2 ;;
        --help|-h)
            sed -n '2,20p' "$0"
            exit 0
            ;;
        *) error "未知参数：$1"; exit 1 ;;
    esac
done

# ---------------- 检查 keytool ----------------
if ! command -v keytool >/dev/null 2>&1; then
    error "未找到 keytool，请先安装 JDK 17（运行 build/setup.sh）"
    exit 1
fi

# ---------------- 已存在则确认覆盖 ----------------
if [ -f "$KEYSTORE_FILE" ]; then
    warn "已存在 $KEYSTORE_FILE"
    read -r -p "是否覆盖？(y/N) " ans
    case "$ans" in
        y|Y|yes|YES) rm -f "$KEYSTORE_FILE" "$PROPS_FILE"; info "已删除旧文件" ;;
        *) info "已取消，退出"; exit 0 ;;
    esac
fi

# ---------------- 密码处理 ----------------
if [ -z "$STORE_PASSWORD" ]; then
    read -r -s -p "请输入 keystore 密码 (store_password): " STORE_PASSWORD
    echo ""
fi
if [ -z "$KEY_PASSWORD" ]; then
    read -r -s -p "请输入 key 密码 (key_password，回车则与 store 密码一致): " KEY_PASSWORD
    echo ""
    [ -z "$KEY_PASSWORD" ] && KEY_PASSWORD="$STORE_PASSWORD"
fi

if [ ${#STORE_PASSWORD} -lt 6 ]; then
    error "密码长度至少 6 位"
    exit 1
fi

# ---------------- 生成 keystore ----------------
DNAME="CN=$CN_NAME, OU=$ORG_UNIT, O=$ORG_NAME, L=$LOCALITY, ST=$STATE, C=$COUNTRY"
info "生成 release.keystore ..."
info "alias=$ALIAS validity=$VALIDITY"
info "dname=$DNAME"

keytool -genkeypair \
    -alias "$ALIAS" \
    -keyalg RSA \
    -keysize 2048 \
    -validity "$VALIDITY" \
    -keystore "$KEYSTORE_FILE" \
    -storepass "$STORE_PASSWORD" \
    -keypass "$KEY_PASSWORD" \
    -dname "$DNAME"

if [ ! -f "$KEYSTORE_FILE" ]; then
    error "keystore 生成失败"
    exit 1
fi
info "keystore 生成成功：$KEYSTORE_FILE"

# ---------------- 保存 properties ----------------
cat > "$PROPS_FILE" <<EOF
# release 签名密钥配置（由 build/generate_keystore.sh 生成）
# 请妥善保管，不要提交到版本库
STORE_FILE=$KEYSTORE_FILE
KEY_ALIAS=$ALIAS
STORE_PASSWORD=$STORE_PASSWORD
KEY_PASSWORD=$KEY_PASSWORD
EOF
chmod 600 "$PROPS_FILE"
info "密码配置已保存：$PROPS_FILE （权限 600）"

# ---------------- 校验 ----------------
info "校验 keystore ..."
keytool -list -keystore "$KEYSTORE_FILE" -storepass "$STORE_PASSWORD" -alias "$ALIAS" | tee /dev/null && info "校验通过"

echo ""
info "============ 密钥生成完成 ============"
echo " keystore: $KEYSTORE_FILE"
echo " 配置文件: $PROPS_FILE"
echo " alias:   $ALIAS"
echo " 有效期:  $VALIDITY 天"
echo ""
echo " 后续打包时 build_apk.sh 会自动读取 keystore.properties 进行签名"

exit 0
