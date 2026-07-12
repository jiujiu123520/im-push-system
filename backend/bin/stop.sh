#!/bin/bash
# ============================================================
# 停止脚本：停止 HTTP API 服务 + WebSocket 推送服务
# 使用方式：./bin/stop.sh
# ============================================================

# 切换到项目根目录
cd "$(dirname "$0")/.."

PROJECT_DIR="$(pwd)"

echo "=============================="
echo " IM Push 后端服务停止"
echo " 项目目录：${PROJECT_DIR}"
echo "=============================="

# 通过 PID 文件优雅停止
STOPPED_ANY=0

stop_by_pid_file() {
    local pid_file="$1"
    local name="$2"
    if [ -f "${pid_file}" ]; then
        local pid
        pid=$(cat "${pid_file}")
        if [ -n "${pid}" ] && kill -0 "${pid}" 2>/dev/null; then
            echo "[停止] ${name} 服务，PID=${pid}"
            kill "${pid}"
            # 等待最多 10 秒
            for i in $(seq 1 10); do
                if kill -0 "${pid}" 2>/dev/null; then
                    sleep 1
                else
                    break
                fi
            done
            # 仍未退出则强杀
            if kill -0 "${pid}" 2>/dev/null; then
                echo "[警告] ${name} 未在 10 秒内退出，强制终止"
                kill -9 "${pid}"
            fi
            STOPPED_ANY=1
        else
            echo "[跳过] ${name} 进程不存在（PID=${pid}）"
        fi
        rm -f "${pid_file}"
    else
        echo "[跳过] 未找到 ${name} PID 文件"
    fi
}

# 停止 HTTP 服务
stop_by_pid_file "runtime/http_server.pid" "HTTP API"

# 停止 WebSocket 服务
stop_by_pid_file "runtime/websocket_server.pid" "WebSocket"

# 兜底：按进程名查找并停止
if pgrep -f "im-push-http-master" >/dev/null 2>&1; then
    echo "[兜底] 终止残留 HTTP 进程"
    pkill -f "im-push-http-master"
fi
if pgrep -f "im-push-ws-master" >/dev/null 2>&1; then
    echo "[兜底] 终止残留 WebSocket 进程"
    pkill -f "im-push-ws-master"
fi

if [ "${STOPPED_ANY}" = "1" ]; then
    echo "[完成] 服务已停止"
else
    echo "[提示] 没有需要停止的服务"
fi
