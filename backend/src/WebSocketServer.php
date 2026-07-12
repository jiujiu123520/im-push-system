<?php
declare(strict_types=1);

namespace App;

use App\Service\Config;
use App\Service\ConnectionManager;
use App\Service\Database;
use App\Service\DeviceOfflineNotifier;
use App\Service\DeviceService;
use App\Service\HeartbeatManager;
use App\Service\PushDispatcher;
use App\Service\Redis;
use Swoole\Timer;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

/**
 * WebSocket 推送服务器
 *
 * 基于 Swoole WebSocket Server，负责维护客户端长连接、
 * 心跳保活、消息推送。
 *
 * 集成三个核心服务：
 *   - ConnectionManager：维护 fd↔device_id、key↔device_id 映射
 *   - HeartbeatManager：基于 Swoole Timer 的心跳保活
 *   - PushDispatcher：消息推送分发
 *
 * 连接鉴权流程（onOpen）：
 *   1. 解析 query 参数（key, device_id, device_name, model, os_version, fingerprint, heartbeat_interval）
 *   2. 校验 Key 有效性（查 push_keys 表）
 *   3. 检查黑名单（device_id / ip / fingerprint）
 *   4. 注册设备到 ConnectionManager
 *   5. 采集设备信息存 devices 表
 *   6. 启动心跳
 *   7. 回放离线消息
 */
class WebSocketServer
{
    /**
     * @var Server Swoole WebSocket Server 实例
     */
    private Server $server;

    /**
     * @var int 默认心跳间隔（秒）
     */
    private int $heartbeatInterval;

    /**
     * @var int 离线消息保留时长（秒）
     */
    private int $offlineMessageTtl;

    /**
     * @var ConnectionManager 连接管理器
     */
    private ConnectionManager $connectionManager;

    /**
     * @var HeartbeatManager 心跳管理器
     */
    private HeartbeatManager $heartbeatManager;

    /**
     * @var PushDispatcher 推送分发器
     */
    private PushDispatcher $pushDispatcher;

    /**
     * @var DeviceService 设备服务
     */
    private DeviceService $deviceService;

    /**
     * @var DeviceOfflineNotifier 设备掉线通知器
     */
    private DeviceOfflineNotifier $offlineNotifier;

    /**
     * 构造方法
     */
    public function __construct()
    {
        $host = (string)Config::env('WEBSOCKET_HOST', '0.0.0.0');
        $port = (int)Config::env('WEBSOCKET_PORT', 9502);

        $this->server = new Server($host, $port);
        $this->heartbeatInterval = (int)Config::env('DEFAULT_HEARTBEAT_INTERVAL', 30);
        $this->offlineMessageTtl = (int)Config::env('OFFLINE_MESSAGE_TTL', 86400);

        // 创建核心服务（在 server->start() 之前创建 Swoole Table）
        $this->connectionManager = new ConnectionManager(true);
        $this->heartbeatManager  = new HeartbeatManager();
        $this->pushDispatcher    = new PushDispatcher();
        $this->deviceService     = new DeviceService();
        $this->offlineNotifier   = new DeviceOfflineNotifier();

        $this->configure();
        $this->bindEvents();
    }

    /**
     * 配置 Swoole WebSocket Server 运行参数
     *
     * @return void
     */
    private function configure(): void
    {
        $this->server->set([
            'worker_num'             => swoole_cpu_num(),
            'task_worker_num'        => 4,
            'daemonize'              => false,
            'log_file'               => dirname(__DIR__, 2) . '/runtime/logs/websocket_server.log',
            'pid_file'               => dirname(__DIR__, 2) . '/runtime/websocket_server.pid',
            'open_websocket_close_frame' => true,
        ]);
    }

    /**
     * 绑定服务器事件
     *
     * @return void
     */
    private function bindEvents(): void
    {
        $this->server->on('Start', [$this, 'onStart']);
        $this->server->on('ManagerStart', [$this, 'onManagerStart']);
        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->server->on('Open', [$this, 'onOpen']);
        $this->server->on('Message', [$this, 'onMessage']);
        $this->server->on('Close', [$this, 'onClose']);
        $this->server->on('Task', [$this, 'onTask']);
        $this->server->on('Finish', [$this, 'onFinish']);
        $this->server->on('Shutdown', [$this, 'onShutdown']);
    }

    /**
     * 主进程启动事件
     *
     * @param Server $server
     * @return void
     */
    public function onStart(Server $server): void
    {
        echo sprintf(
            "[WS] WebSocket 服务已启动，监听 %s:%d，master pid=%d\n",
            $server->host,
            $server->port,
            $server->master_pid
        );
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title('im-push-ws-master');
        }
    }

    /**
     * manager 进程启动事件
     *
     * @param Server $server
     * @return void
     */
    public function onManagerStart(Server $server): void
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title('im-push-ws-manager');
        }
    }

    /**
     * Worker 启动事件
     *
     * 每个 Worker 启动时加载环境变量并重建数据库/Redis 连接，
     * 同时为服务注入 Server 实例并启动队列消费定时器。
     *
     * @param Server $server
     * @param int $workerId
     * @return void
     */
    public function onWorkerStart(Server $server, int $workerId): void
    {
        Config::loadEnv();
        Database::reconnect();
        Redis::reconnect();

        // 重建 ConnectionManager 的 Redis 连接
        $this->connectionManager->reconnect();

        // 为心跳管理器和推送分发器注入 Server 实例
        $this->heartbeatManager->setServer($server);
        $this->pushDispatcher->setServer($server);
        $this->pushDispatcher->setConnectionManager($this->connectionManager);

        // 在 Worker 0 中启动队列消费定时器（跨进程推送/断开连接）
        if ($workerId === 0) {
            Timer::tick(100, function () {
                try {
                    $this->pushDispatcher->processQueue(100);
                    $this->pushDispatcher->processDisconnectQueue(100);
                } catch (\Throwable $e) {
                    echo "[WS] 队列消费异常：{$e->getMessage()}\n";
                }
            });
        }
    }

    /**
     * 客户端连接建立事件
     *
     * 鉴权流程：解析 query 参数 -> 校验 Key -> 检查黑名单 -> 注册设备 -> 启动心跳 -> 回放离线消息
     *
     * @param Server $server
     * @param \Swoole\Http\Request $request
     * @return void
     */
    public function onOpen(Server $server, $request): void
    {
        $fd = $request->fd;

        // 1. 解析 query 参数
        $keyValue    = (string)($request->get['key'] ?? '');
        $deviceId    = (string)($request->get['device_id'] ?? '');
        $deviceName  = (string)($request->get['device_name'] ?? '');
        $model       = (string)($request->get['model'] ?? '');
        $osVersion   = (string)($request->get['os_version'] ?? '');
        $fingerprint = (string)($request->get['fingerprint'] ?? '');
        $heartbeatInterval = (int)($request->get['heartbeat_interval'] ?? $this->heartbeatInterval);

        // 获取客户端 IP
        $clientInfo = $server->getClientInfo($fd);
        $ip = is_array($clientInfo) ? (string)($clientInfo['remote_ip'] ?? '') : '';
        $ua = (string)($request->header['user-agent'] ?? '');

        if ($keyValue === '' || $deviceId === '') {
            $this->sendAndClose($server, $fd, $this->pack(-1, '缺少 key 或 device_id 参数'));
            return;
        }

        // 2. 校验 Key 有效性（查 push_keys 表）
        $pushKey = Database::fetch(
            'SELECT * FROM push_keys WHERE key_value = ? AND status = 1 LIMIT 1',
            [$keyValue]
        );
        if ($pushKey === false) {
            $this->sendAndClose($server, $fd, $this->pack(-1, '推送 Key 无效或已禁用'));
            return;
        }

        $pushKeyId = (int)$pushKey['id'];
        $userId    = (int)$pushKey['user_id'];

        // 检查设备数量限制
        $deviceCount = $this->deviceService->countByPushKey($pushKeyId);
        $maxDevices  = (int)$pushKey['max_devices'];
        if ($maxDevices > 0 && $deviceCount >= $maxDevices) {
            // 检查是否是已有设备（按 device_id + push_key_id 精确匹配）
            $existing = $this->deviceService->getDeviceByKey($deviceId, $pushKeyId);
            if ($existing === null) {
                $this->sendAndClose($server, $fd, $this->pack(-1, "设备数量已达上限({$maxDevices})"));
                return;
            }
        }

        // 3. 检查黑名单（device_id / ip / fingerprint）
        if ($this->connectionManager->isBlacklisted('device_id', $deviceId)) {
            $this->sendAndClose($server, $fd, $this->pack(-1, '设备已被拉黑'));
            return;
        }
        if ($ip !== '' && $this->connectionManager->isBlacklisted('ip', $ip)) {
            $this->sendAndClose($server, $fd, $this->pack(-1, 'IP 已被拉黑'));
            return;
        }
        if ($fingerprint !== '' && $this->connectionManager->isBlacklisted('fingerprint', $fingerprint)) {
            $this->sendAndClose($server, $fd, $this->pack(-1, '设备指纹已被拉黑'));
            return;
        }

        // 若未提供指纹则自动生成
        if ($fingerprint === '') {
            $fingerprint = $this->deviceService->generateFingerprint($deviceId, $model, $osVersion);
        }

        // 4. 注册设备到 ConnectionManager
        $this->connectionManager->registerDevice($fd, $deviceId, $keyValue, $pushKeyId, [
            'ip'          => $ip,
            'fingerprint' => $fingerprint,
        ]);

        // 5. 采集设备信息存 devices 表
        $this->deviceService->registerDevice([
            'device_id'    => $deviceId,
            'push_key_id'  => $pushKeyId,
            'user_id'      => $userId,
            'device_name'  => $deviceName,
            'device_model' => $model,
            'os_version'   => $osVersion,
            'ip'           => $ip,
            'ua'           => $ua,
            'fingerprint'  => $fingerprint,
        ]);

        // 6. 启动心跳（HeartbeatManager 会将 interval 校正到 10-300 范围内）
        $this->heartbeatManager->startHeartbeat($fd, $heartbeatInterval);
        // 获取实际生效的心跳间隔，反馈给客户端
        $effectiveInterval = $this->heartbeatManager->getInterval($fd);

        // 7. 回放离线消息
        $this->replayOfflineMessages($server, $fd, $deviceId);

        // 推送连接成功消息
        $server->push($fd, $this->pack(0, '连接成功', [
            'heartbeat_interval' => $effectiveInterval,
            'server_time'        => time(),
            'device_id'          => $deviceId,
            'push_key'            => $keyValue,
        ]));
    }

    /**
     * 收到消息事件
     *
     * 处理客户端上报的 pong、ack、subscribe 消息。
     *
     * @param Server $server
     * @param Frame $frame
     * @return void
     */
    public function onMessage(Server $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $data = json_decode($frame->data, true);

        // 非 JSON 文本：当作心跳 ping 处理（兼容客户端主动 ping）
        if (!is_array($data)) {
            $server->push($fd, $this->pack(0, 'pong', ['server_time' => time()]));
            return;
        }

        $type = $data['type'] ?? '';
        switch ($type) {
            case 'pong':
                // 客户端响应服务端心跳 ping，重置未响应计数
                $this->heartbeatManager->onPong($fd);
                break;

            case 'ping':
                // 客户端主动心跳，响应 pong
                $server->push($fd, $this->pack(0, 'pong', ['server_time' => time()]));
                break;

            case 'ack':
                // 消息确认送达
                $this->ackMessage($fd, (string)($data['message_id'] ?? ''));
                break;

            case 'subscribe':
                // 切换 Key 订阅
                $this->handleSubscribe($server, $fd, (string)($data['key'] ?? ''));
                break;

            default:
                $server->push($fd, $this->pack(-1, '未知的消息类型：' . $type));
        }
    }

    /**
     * 连接关闭事件
     *
     * 注销连接、停止心跳、更新设备状态为离线。
     *
     * @param Server $server
     * @param int $fd
     * @return void
     */
    public function onClose(Server $server, int $fd): void
    {
        try {
            // 停止心跳
            $this->heartbeatManager->stopHeartbeat($fd);

            // 注销连接并获取设备信息
            $info = $this->connectionManager->unregisterDevice($fd);

            if ($info !== null) {
                $deviceId   = $info['device_id'];
                $pushKeyId  = (int)$info['push_key_id'];

                // 检查该设备是否还有其他在线连接
                $remainingFds = $this->connectionManager->getFdsByDevice($deviceId);
                if (empty($remainingFds)) {
                    // 无其他连接，更新设备状态为离线
                    $this->deviceService->updateStatus($deviceId, $pushKeyId, 0);

                    // 获取设备详情用于通知
                    $deviceInfo = $this->deviceService->getDeviceByKey($deviceId, $pushKeyId);

                    // 触发设备掉线邮箱通知
                    $this->offlineNotifier->notify(
                        $deviceId,
                        $pushKeyId,
                        [
                            'device_id'     => $deviceId,
                            'device_name'   => $deviceInfo['device_name'] ?? '',
                            'ip'            => $deviceInfo['ip'] ?? '',
                            'device_model'  => $deviceInfo['device_model'] ?? '',
                            'os_version'    => $deviceInfo['os_version'] ?? '',
                        ]
                    );
                }
            }
        } catch (\Throwable $e) {
            echo "[WS] onClose 清理异常：{$e->getMessage()}\n";
        }
    }

    /**
     * Task 任务事件（异步推送等）
     *
     * @param Server $server
     * @param int $taskId
     * @param int $fromId
     * @param array $data
     * @return mixed
     */
    public function onTask(Server $server, int $taskId, int $fromId, $data)
    {
        $type = $data['type'] ?? '';
        switch ($type) {
            case 'push_device':
                $result = $this->pushDispatcher->pushToDevice(
                    (string)($data['device_id'] ?? ''),
                    $data['message'] ?? []
                );
                return $result;
            case 'push_devices':
                $result = $this->pushDispatcher->pushToDevices(
                    $data['device_ids'] ?? [],
                    $data['message'] ?? []
                );
                return $result;
            case 'push_key':
                $result = $this->pushDispatcher->pushByKey(
                    (string)($data['key_value'] ?? ''),
                    $data['message'] ?? []
                );
                return $result;
        }
        return true;
    }

    /**
     * Task 完成事件
     *
     * @param Server $server
     * @param int $taskId
     * @param mixed $data
     * @return void
     */
    public function onFinish(Server $server, int $taskId, $data): void
    {
        // 默认无操作
    }

    /**
     * 服务关闭事件
     *
     * @param Server $server
     * @return void
     */
    public function onShutdown(Server $server): void
    {
        $this->heartbeatManager->stopAll();
        echo "[WS] WebSocket 服务已关闭\n";
    }

    // ====================================================================
    // 内部方法
    // ====================================================================

    /**
     * 向指定客户端推送数据（内部方法）
     *
     * @param int    $fd
     * @param mixed  $data
     * @return bool 是否推送成功
     */
    public function pushToClient(int $fd, $data): bool
    {
        if (!$this->server->isEstablished($fd)) {
            return false;
        }
        $payload = is_string($data) ? $data : $this->pack(0, 'message', $data);
        return $this->server->push($fd, $payload);
    }

    /**
     * 回放离线消息
     *
     * @param Server $server
     * @param int    $fd
     * @param string $deviceId
     * @return void
     */
    private function replayOfflineMessages(Server $server, int $fd, string $deviceId): void
    {
        try {
            $messages = $this->pushDispatcher->getOfflineMessages($deviceId);
            foreach ($messages as $message) {
                $payload = $this->pack(0, 'offline_message', $message);
                $server->push($fd, $payload);
            }
        } catch (\Throwable $e) {
            echo "[WS] 离线消息回放异常：{$e->getMessage()}\n";
        }
    }

    /**
     * 处理客户端 ACK：确认消息送达
     *
     * @param int    $fd
     * @param string $messageId
     * @return void
     */
    private function ackMessage(int $fd, string $messageId): void
    {
        if ($messageId === '') {
            return;
        }
        // 获取设备信息
        $deviceInfo = $this->connectionManager->getDeviceInfo($fd);
        $deviceId = $deviceInfo['device_id'] ?? '';

        // 通过 message_id 更新 messages 表对应记录为已读
        if ($deviceId !== '') {
            try {
                Database::execute(
                    'UPDATE messages SET is_read = 1 WHERE device_id = ? AND message_id = ?',
                    [$deviceId, $messageId]
                );
            } catch (\Throwable $e) {
                // 忽略 ACK 写库异常
            }
        }
    }

    /**
     * 处理 subscribe 消息：切换 Key 订阅
     *
     * @param Server $server
     * @param int    $fd
     * @param string $newKeyValue 新的 Key 值
     * @return void
     */
    private function handleSubscribe(Server $server, int $fd, string $newKeyValue): void
    {
        if ($newKeyValue === '') {
            $server->push($fd, $this->pack(-1, 'subscribe 需要 key 参数'));
            return;
        }

        // 校验新 Key 有效性
        $pushKey = Database::fetch(
            'SELECT * FROM push_keys WHERE key_value = ? AND status = 1 LIMIT 1',
            [$newKeyValue]
        );
        if ($pushKey === false) {
            $server->push($fd, $this->pack(-1, 'Key 无效或已禁用', ['key' => $newKeyValue]));
            return;
        }

        // 切换 Key 订阅
        $this->connectionManager->switchKey($fd, $newKeyValue, (int)$pushKey['id']);

        $server->push($fd, $this->pack(0, '订阅切换成功', [
            'key'    => $newKeyValue,
            'key_id' => (int)$pushKey['id'],
        ]));
    }

    /**
     * 发送消息后主动关闭连接（用于鉴权失败场景）
     *
     * @param Server $server
     * @param int $fd
     * @param string $message
     * @return void
     */
    private function sendAndClose(Server $server, int $fd, string $message): void
    {
        $server->push($fd, $message);
        $server->disconnect($fd, 4001, 'auth failed');
    }

    /**
     * 打包统一的 JSON 协议
     *
     * @param int $code 业务码
     * @param string $message 消息类型/描述
     * @param mixed $data
     * @return string
     */
    private function pack(int $code, string $message, $data = null): string
    {
        return json_encode([
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
            'time'    => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 启动 WebSocket 服务器
     *
     * @return void
     */
    public function start(): void
    {
        $this->server->start();
    }

    /**
     * 获取 Swoole WebSocket Server 实例
     *
     * @return Server
     */
    public function getServer(): Server
    {
        return $this->server;
    }

    /**
     * 获取连接管理器
     *
     * @return ConnectionManager
     */
    public function getConnectionManager(): ConnectionManager
    {
        return $this->connectionManager;
    }

    /**
     * 获取推送分发器
     *
     * @return PushDispatcher
     */
    public function getPushDispatcher(): PushDispatcher
    {
        return $this->pushDispatcher;
    }

    /**
     * 获取心跳管理器
     *
     * @return HeartbeatManager
     */
    public function getHeartbeatManager(): HeartbeatManager
    {
        return $this->heartbeatManager;
    }
}
