#!/bin/bash
# ============================================================
# 即时消息推送系统 - 服务器更新脚本（转发到 backend/deploy/update.sh）
#
# 本脚本为兼容入口，实际逻辑在 backend/deploy/update.sh（5 步制）
#
# 用法:
#   bash deploy/update.sh                    # 正常更新（含确认）
#   bash deploy/update.sh --yes             # 跳过确认
#   bash deploy/update.sh --gh-proxy        # 使用 gh.jasonzeng.dev 代理
#   bash deploy/update.sh --skip-build     # 跳过前端构建
#   bash deploy/update.sh --skip-migration # 跳过数据库迁移
#   bash deploy/update.sh --resume          # 断点续装
#   bash deploy/update.sh --restart         # 重新开始
# ============================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ACTUAL_SCRIPT="${SCRIPT_DIR}/../backend/deploy/update.sh"

if [ ! -f "${ACTUAL_SCRIPT}" ]; then
    echo "[ERROR] 更新脚本不存在: ${ACTUAL_SCRIPT}"
    exit 1
fi

exec bash "${ACTUAL_SCRIPT}" "$@"
