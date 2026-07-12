#!/bin/bash
# ============================================================
# 启动脚本：启动 HTTP API 服务 + WebSocket 推送服务
# 使用方式：./bin/start.sh
# ============================================================
set -e

# 切换到项目根目录
cd "$(dirname "$0")/.."

PROJECT_DIR="$(pwd)"
PHP_BIN="${PHP_BIN:-php}"

echo "=============================="
echo " IM Push 后端服务启动"
echo " 项目目录：${PROJECT_DIR}"
echo " PHP 解释器：${PHP_BIN}"
echo "=============================="

# 1. 检查 vendor 目录是否存在
if [ ! -d "vendor" ]; then
    echo "[错误] 未找到 vendor 目录，请先执行 composer install"
    exit 1
fi

# 2. 检查 .env 是否存在，不存在则从 .env.example 复制
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        cp .env.example .env
        echo "[提示] 已从 .env.example 复制生成 .env，请按需修改配置"
    else
        echo "[错误] 未找到 .env 文件，且没有 .env.example 模板"
        exit 1
    fi
fi

# 3. 创建运行时目录
mkdir -p runtime/logs

# 4. 启动 HTTP API 服务（后台守护进程）
echo "[启动] HTTP API 服务..."
${PHP_BIN} public/index.php --daemon
sleep 1

# 5. 启动 WebSocket 推送服务（后台守护进程）
echo "[启动] WebSocket 推送服务..."
${PHP_BIN} public/index.php --ws --daemon
sleep 1

# 6. 输出 PID 信息
if [ -f "runtime/http_server.pid" ]; then
    HTTP_PID=$(cat runtime/http_server.pid)
    echo "[完成] HTTP 服务 PID: ${HTTP_PID}"
fi
if [ -f "runtime/websocket_server.pid" ]; then
    WS_PID=$(cat runtime/websocket_server.pid)
    echo "[完成] WebSocket 服务 PID: ${WS_PID}"
fi

echo ""
echo "服务已启动。可通过以下方式查看进程："
echo "  ps -ef | grep im-push"
echo "停止服务请执行：./bin/stop.sh"
