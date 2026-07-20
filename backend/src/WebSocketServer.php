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
use Swoole\Table;
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
     * @var Table 待鉴权连接表（跨 worker 共享）
     *
     * 用于解决多 worker 进程下 $pendingAuth 数组不共享的问题。
     * 当客户端在新协议下连接后,需在 30 秒内发送 auth 消息,
     * 否则任意 worker 中的鉴权超时定时器都能从该表查询并清理。
     */
    private Table $pendingAuthTable;

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

        // 创建待鉴权连接表（跨 worker 共享,替代原 $pendingAuth 数组）
        $this->pendingAuthTable = new Table(65536);
        $this->pendingAuthTable->column('ip', Table::TYPE_STRING, 45);
        $this->pendingAuthTable->column('ua', Table::TYPE_STRING, 256);
        $this->pendingAuthTable->column('time', Table::TYPE_INT, 8);
        $this->pendingAuthTable->create();

        $this->configure();
        $this->bindEvents();
    }

    /**
     * 配置 Swoole WebSocket Server 运行参数
     *
     * 并发相关:
     *   - worker_num: 默认 CPU 核心数,WebSocket 连接按 fd hash 分配到固定 worker,保证同一连接事件串行
     *   - task_worker_num: 异步任务 worker,处理耗时推送/通知
     *   - max_conn: 单 worker 最大连接数,防止 fd 耗尽
     *   - max_request: 每个 worker 处理 N 次请求后重启(WebSocket 推荐设为 0 不重启,避免长连接断开)
     *   - max_wait_time: reload 时等待连接关闭的最大时间
     *   - send_yield: 当发送队列满时让出协程,避免阻塞 worker
     *   - send_buffer_size: 单连接发送缓冲区大小(字节)
     *
     * @return void
     */
    private function configure(): void
    {
        $this->server->set([
            'worker_num'             => swoole_cpu_num(),
            'task_worker_num'        => 4,
            'daemonize'              => false,
            'log_file'               => BASE_PATH . '/runtime/logs/websocket_server.log',
            'pid_file'               => BASE_PATH . '/runtime/websocket_server.pid',
            'open_websocket_close_frame' => true,
            // 并发与稳定性
            'max_conn'               => 10000,     // 单 worker 最大并发连接数
            'max_request'            => 0,         // WebSocket 长连接不重启 worker,避免连接断开
            'max_wait_time'          => 60,       // reload 时等待连接关闭的最大秒数
            'reloadable'             => true,      // worker 可被 reload 重启
            'send_yield'             => true,      // 发送队列满时让出协程,避免阻塞
            'send_buffer_size'       => 1048576,  // 1MB 单连接发送缓冲区
            // 鉴权超时定时器依赖,允许毫秒级 Timer
            'enable_coroutine'       => true,
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
     * 连接建立后不立即鉴权，等待客户端发送 auth 消息。
     * 同时支持旧的 query 参数鉴权方式（向后兼容）。
     *
     * @param Server $server
     * @param \Swoole\Http\Request $request
     * @return void
     */
    public function onOpen(Server $server, $request): void
    {
        $fd = $request->fd;

        // 获取客户端 IP
        $clientInfo = $server->getClientInfo($fd);
        $ip = is_array($clientInfo) ? (string)($clientInfo['remote_ip'] ?? '') : '';
        $ua = (string)($request->header['user-agent'] ?? '');

        // 支持 query 参数鉴权（向后兼容）
        $keyValue    = (string)($request->get['key'] ?? '');
        $deviceId    = (string)($request->get['device_id'] ?? '');

        // 调试日志：记录所有连接
        $logMsg = sprintf("[WS] onOpen fd=%d ip=%s key=%s device_id=%s uri=%s", $fd, $ip, $keyValue, $deviceId, $request->server['request_uri'] ?? '');
        echo $logMsg . "\n";
        error_log($logMsg);

        if ($keyValue !== '' && $deviceId !== '') {
            // query 参数鉴权方式
            $deviceName  = (string)($request->get['device_name'] ?? '');
            $model       = (string)($request->get['model'] ?? '');
            $osVersion   = (string)($request->get['os_version'] ?? '');
            $fingerprint = (string)($request->get['fingerprint'] ?? '');
            $heartbeatInterval = (int)($request->get['heartbeat_interval'] ?? $this->heartbeatInterval);

            $this->authenticateDevice($server, $fd, $keyValue, $deviceId, $deviceName, $model, $osVersion, $fingerprint, $heartbeatInterval, $ip, $ua);
        } else {
            // 新协议：等待客户端发送 auth 消息
            // 存储客户端 IP 和 UA 供后续鉴权使用(使用 Swoole Table 跨 worker 共享)
            $this->pendingAuthTable->set((string)$fd, [
                'ip'   => $ip,
                'ua'   => $ua,
                'time' => time(),
            ]);
            // 设置鉴权超时定时器（30秒内未鉴权则断开）
            // 注意: Swoole Timer 在哪个 worker 创建就在哪个 worker 触发
            // 由于 fd 的所有事件由同一 worker 处理,因此定时器与 onMessage/auth 在同 worker
            Timer::after(30000, function () use ($server, $fd) {
                if ($this->pendingAuthTable->exists((string)$fd)) {
                    $this->pendingAuthTable->del((string)$fd);
                    if ($server->isEstablished($fd)) {
                        $server->push($fd, $this->pack(-1, '鉴权超时', null, 'auth_result'));
                        $server->disconnect($fd, 4001, 'auth timeout');
                    }
                }
            });
        }
    }

    /**
     * 待鉴权连接列表(已弃用,改用 Swoole Table 跨 worker 共享)
     * @deprecated
     */
    private array $pendingAuth = [];

    /**
     * 执行设备鉴权
     *
     * @param Server $server
     * @param int $fd
     * @param string $keyValue
     * @param string $deviceId
     * @param string $deviceName
     * @param string $model
     * @param string $osVersion
     * @param string $fingerprint
     * @param int $heartbeatInterval
     * @param string $ip
     * @param string $ua
     * @return void
     */
    private function authenticateDevice(
        Server $server,
        int $fd,
        string $keyValue,
        string $deviceId,
        string $deviceName,
        string $model,
        string $osVersion,
        string $fingerprint,
        int $heartbeatInterval,
        string $ip,
        string $ua
    ): void {
        // 清除待鉴权标记(从 Swoole Table 删除)
        $this->pendingAuthTable->del((string)$fd);

        if ($keyValue === '' || $deviceId === '') {
            $this->sendAndClose($server, $fd, $this->pack(-1, '缺少 key 或 device_id 参数', null, 'auth_result'));
            return;
        }

        // 校验 Key 有效性（带异常保护，防止数据库连接问题导致静默断开）
        try {
            $pushKey = Database::fetch(
                'SELECT * FROM push_keys WHERE key_value = ? AND status = 1 LIMIT 1',
                [$keyValue]
            );
            $logMsg2 = sprintf("[WS] 鉴权查询完成 fd=%d key=%s result=%s", $fd, $keyValue, $pushKey === false ? 'NOT_FOUND' : 'FOUND');
            echo $logMsg2 . "\n";
            error_log($logMsg2);
        } catch (\Throwable $e) {
            echo "[WS] 鉴权查询异常 fd={$fd} key={$keyValue} error={$e->getMessage()}\n";
            error_log("[WS] 鉴权查询异常 fd={$fd} key={$keyValue} error={$e->getMessage()}");
            // 尝试重连后重试一次
            try {
                Database::reconnect();
                $pushKey = Database::fetch(
                    'SELECT * FROM push_keys WHERE key_value = ? AND status = 1 LIMIT 1',
                    [$keyValue]
                );
                echo "[WS] 鉴权重试成功 fd={$fd} result=" . ($pushKey === false ? 'NOT_FOUND' : 'FOUND') . "\n";
            } catch (\Throwable $e2) {
                echo "[WS] 鉴权重试仍失败 fd={$fd} error={$e2->getMessage()}\n";
                error_log("[WS] 鉴权重试仍失败 fd={$fd} error={$e2->getMessage()}");
                $this->sendAndClose($server, $fd, $this->pack(-1, '服务器内部错误，请稍后重试', null, 'auth_result'));
                return;
            }
        }
        if ($pushKey === false) {
            echo "[WS] Key 无效或已禁用 fd={$fd} key={$keyValue}\n";
            $this->sendAndClose($server, $fd, $this->pack(-1, '推送 Key 无效或已禁用', null, 'auth_result'));
            return;
        }

        echo "[WS] 鉴权成功 fd={$fd} key={$keyValue} push_key_id={$pushKey['id']}\n";

        $pushKeyId = (int)$pushKey['id'];
        $userId    = (int)$pushKey['user_id'];

        // 检查设备数量限制
        $deviceCount = $this->deviceService->countByPushKey($pushKeyId);
        $maxDevices  = (int)$pushKey['max_devices'];
        if ($maxDevices > 0 && $deviceCount >= $maxDevices) {
            $existing = $this->deviceService->getDeviceByKey($deviceId, $pushKeyId);
            if ($existing === null) {
                $this->sendAndClose($server, $fd, $this->pack(-1, "设备数量已达上限({$maxDevices})", null, 'auth_result'));
                return;
            }
        }

        // 检查黑名单
        if ($this->connectionManager->isBlacklisted('device_id', $deviceId)) {
            $this->sendAndClose($server, $fd, $this->pack(-1, '设备已被拉黑', null, 'auth_result'));
            return;
        }
        if ($ip !== '' && $this->connectionManager->isBlacklisted('ip', $ip)) {
            $this->sendAndClose($server, $fd, $this->pack(-1, 'IP 已被拉黑', null, 'auth_result'));
            return;
        }
        if ($fingerprint !== '' && $this->connectionManager->isBlacklisted('fingerprint', $fingerprint)) {
            $this->sendAndClose($server, $fd, $this->pack(-1, '设备指纹已被拉黑', null, 'auth_result'));
            return;
        }

        // 若未提供指纹则自动生成
        if ($fingerprint === '') {
            $fingerprint = $this->deviceService->generateFingerprint($deviceId, $model, $osVersion);
        }

        // 注册设备到 ConnectionManager
        $this->connectionManager->registerDevice($fd, $deviceId, $keyValue, $pushKeyId, [
            'ip'          => $ip,
            'fingerprint' => $fingerprint,
        ]);

        // 采集设备信息存 devices 表
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

        // 启动心跳
        $this->heartbeatManager->startHeartbeat($fd, $heartbeatInterval);
        $effectiveInterval = $this->heartbeatManager->getInterval($fd);

        // 回放离线消息
        $this->replayOfflineMessages($server, $fd, $deviceId);

        // 发送鉴权成功消息（带 type 字段，与 APP 协议一致）
        $server->push($fd, $this->pack(0, '连接成功', [
            'heartbeat_interval' => $effectiveInterval,
            'server_time'        => time(),
            'device_id'          => $deviceId,
            'push_key'           => $keyValue,
        ], 'auth_result'));
    }

    /**
     * 收到消息事件
     *
     * 处理客户端上报的 auth、pong、ack、subscribe 消息。
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
            $server->push($fd, $this->pack(0, 'pong', ['server_time' => time()], 'pong'));
            return;
        }

        $type = $data['type'] ?? '';
        switch ($type) {
            case 'auth':
                // 新协议：客户端发送 auth 消息进行鉴权
                // 从 Swoole Table 读取 pendingAuth 信息（跨 worker 共享）
                $row = $this->pendingAuthTable->get((string)$fd);
                $pendingIp = is_array($row) ? (string)($row['ip'] ?? '') : '';
                $pendingUa = is_array($row) ? (string)($row['ua'] ?? '') : '';
                $this->authenticateDevice(
                    $server,
                    $fd,
                    (string)($data['key'] ?? ''),
                    (string)($data['device_id'] ?? ''),
                    (string)($data['device_name'] ?? ''),
                    (string)($data['model'] ?? ''),
                    (string)($data['os_version'] ?? ''),
                    (string)($data['fingerprint'] ?? ''),
                    (int)($data['heartbeat_interval'] ?? $this->heartbeatInterval),
                    $pendingIp,
                    $pendingUa
                );
                break;

            case 'pong':
                // 客户端响应服务端心跳 ping，重置未响应计数
                $this->heartbeatManager->onPong($fd);
                break;

            case 'ping':
                // 客户端主动心跳，响应 pong（带 type 字段，与 APP 协议一致）
                $server->push($fd, $this->pack(0, 'pong', ['server_time' => time()], 'pong'));
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
            // 清除待鉴权标记（从 Swoole Table 删除）
            $this->pendingAuthTable->del((string)$fd);

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
     * @param string $type 消息类型（auth_result / pong / push / ping 等）
     * @return string
     */
    private function pack(int $code, string $message, $data = null, string $type = ''): string
    {
        $payload = [
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
            'time'    => time(),
        ];
        if ($type !== '') {
            $payload['type'] = $type;
            $payload['success'] = ($code === 0);
        }
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
