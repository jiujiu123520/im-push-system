<?php
declare(strict_types=1);

namespace App\Service;

use Swoole\WebSocket\Server;

/**
 * 推送分发器
 *
 * 负责将消息推送到目标设备：
 *   - pushToDevice($deviceId, $message)     单设备推送
 *   - pushToDevices(array $deviceIds, ...)  多设备推送
 *   - pushByKey($keyValue, $message)        Key 维度推送
 *   - storeOfflineMessage($deviceId, ...)   离线消息存 Redis
 *   - getOfflineMessages($deviceId)         获取并清除离线消息
 *
 * 支持两种运行上下文：
 *   1. WebSocket 上下文：持有 $server 引用，直接通过 $server->push() 投递
 *   2. HTTP 上下文：$server 为 null，将推送指令写入 Redis 队列，
 *      由 WebSocket 进程的定时器消费并实际投递
 *
 * 返回值统一为数组结构：
 *   [ 'success_count' => int, 'fail_count' => int, 'detail' => [...] ]
 */
class PushDispatcher
{
    /**
     * Redis 推送队列 Key（HTTP -> WS 跨进程投递）
     */
    private const PUSH_QUEUE_KEY = 'push:queue';

    /**
     * Redis 断开连接命令队列 Key（黑名单 -> WS）
     */
    private const DISCONNECT_QUEUE_KEY = 'ws:command:disconnect';

    /**
     * @var Server|null Swoole WebSocket Server 实例（HTTP 上下文中为 null）
     */
    private ?Server $server = null;

    /**
     * @var ConnectionManager 连接管理器
     */
    private ConnectionManager $connectionManager;

    /**
     * 构造方法
     *
     * @param Server|null           $server
     * @param ConnectionManager|null $connectionManager
     */
    public function __construct(?Server $server = null, ?ConnectionManager $connectionManager = null)
    {
        $this->server = $server;
        // 无 ConnectionManager 时创建一个不使用 Swoole Table 的实例
        $this->connectionManager = $connectionManager ?? new ConnectionManager(false);
    }

    /**
     * 设置 WebSocket Server 实例
     *
     * @param Server $server
     * @return void
     */
    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    /**
     * 设置连接管理器
     *
     * @param ConnectionManager $cm
     * @return void
     */
    public function setConnectionManager(ConnectionManager $cm): void
    {
        $this->connectionManager = $cm;
    }

    /**
     * 单设备推送
     *
     * 链路：HTTP 入口 -> storeMessage -> getFdsByDevice -> pushToFds(入队/直推)
     *
     * @param string $deviceId 设备唯一标识
     * @param array  $message  消息体
     * @return array
     */
    public function pushToDevice(string $deviceId, array $message): array
    {
        // 补充消息 ID（用于串联整条推送链路日志）
        $message['message_id'] = $message['message_id'] ?? uniqid('msg_', true);
        $msgId = $message['message_id'];
        $ctx = $this->server !== null ? 'WS' : 'HTTP';

        $this->logPush("[pushToDevice·{$ctx}] 入口 device_id={$deviceId} msg_id={$msgId} title=" . ($message['title'] ?? ''));

        // 持久化消息到 messages 表（便于 ACK 与历史查询）
        $this->storeMessage($deviceId, $message);

        $fds = $this->connectionManager->getFdsByDevice($deviceId);
        $this->logPush("[pushToDevice·{$ctx}] 查询在线 fd device_id={$deviceId} msg_id={$msgId} fds=" . json_encode($fds));

        if (empty($fds)) {
            // 设备离线，存离线消息
            $this->storeOfflineMessage($deviceId, $message);
            $this->logPush("[pushToDevice·{$ctx}] 设备离线，已存离线 device_id={$deviceId} msg_id={$msgId}");
            return [
                'success_count' => 0,
                'fail_count'    => 1,
                'detail'        => [
                    [
                        'device_id' => $deviceId,
                        'status'    => 'offline',
                        'message'   => '设备离线，消息已存为离线',
                    ],
                ],
            ];
        }

        return $this->pushToFds($fds, $message, $deviceId);
    }

    /**
     * 多设备推送
     *
     * @param array  $deviceIds 设备 ID 数组
     * @param array  $message   消息体
     * @return array
     */
    public function pushToDevices(array $deviceIds, array $message): array
    {
        $message['message_id'] = $message['message_id'] ?? uniqid('msg_', true);

        $result = [
            'success_count' => 0,
            'fail_count'    => 0,
            'detail'        => [],
        ];

        foreach ($deviceIds as $deviceId) {
            $deviceId = trim((string)$deviceId);
            if ($deviceId === '') {
                continue;
            }
            $r = $this->pushToDevice($deviceId, $message);
            $result['success_count'] += $r['success_count'];
            $result['fail_count']    += $r['fail_count'];
            $result['detail']         = array_merge($result['detail'], $r['detail']);
        }

        return $result;
    }

    /**
     * Key 维度推送（查询所有订阅该 Key 的在线设备）
     *
     * 链路：HTTP 入口 -> getDevicesByKey(在线 fd) -> storeMessage -> pushToFds(入队/直推)
     *       无在线设备时 -> sMembers(订阅设备) -> storeMessage + storeOfflineMessage
     *
     * @param string $keyValue 推送 Key 值
     * @param array  $message  消息体
     * @return array
     */
    public function pushByKey(string $keyValue, array $message): array
    {
        $message['message_id'] = $message['message_id'] ?? uniqid('msg_', true);
        $msgId = $message['message_id'];
        $ctx = $this->server !== null ? 'WS' : 'HTTP';

        $this->logPush("[pushByKey·{$ctx}] 入口 key={$keyValue} msg_id={$msgId} title=" . ($message['title'] ?? ''));

        $fds = $this->connectionManager->getDevicesByKey($keyValue);
        $this->logPush("[pushByKey·{$ctx}] 查询在线设备 key={$keyValue} msg_id={$msgId} fds=" . json_encode($fds));

        if (empty($fds)) {
            // 无在线设备，查询所有订阅该 Key 的设备 ID 并存离线
            $deviceIds = Redis::getInstance()->sMembers("key:subscribe:{$keyValue}");
            if (empty($deviceIds)) {
                $this->logPush("[pushByKey] 无订阅设备 key={$keyValue} msg_id={$message['message_id']}");
                return [
                    'success_count' => 0,
                    'fail_count'    => 0,
                    'detail'        => [
                        [
                            'key'     => $keyValue,
                            'status'  => 'no_subscribers',
                            'message' => '无订阅设备',
                        ],
                    ],
                ];
            }
            foreach ($deviceIds as $deviceId) {
                $this->storeMessage($deviceId, $message);
                $this->storeOfflineMessage($deviceId, $message);
            }
            $this->logPush("[pushByKey] 所有设备离线，已存离线 key={$keyValue} devices=" . count($deviceIds) . " msg_id={$message['message_id']}");
            return [
                'success_count' => 0,
                'fail_count'    => count($deviceIds),
                'detail'        => [
                    [
                        'key'     => $keyValue,
                        'status'  => 'all_offline',
                        'message' => '所有设备离线，已存离线消息',
                        'count'   => count($deviceIds),
                    ],
                ],
            ];
        }

        // 修复：收集所有 deviceId 用于 push 失败时存离线消息，并持久化消息
        $deviceIds = Redis::getInstance()->sMembers("key:subscribe:{$keyValue}");
        $deviceIdStr = empty($deviceIds) ? null : implode(',', $deviceIds);

        // 持久化消息到 messages 表（按 key 推送时也需要记录）
        foreach ($deviceIds as $did) {
            $this->storeMessage($did, $message);
        }

        $this->logPush("[pushByKey] 在线设备 fds=" . json_encode($fds) . " key={$keyValue} msg_id={$message['message_id']}");

        return $this->pushToFds($fds, $message, $deviceIdStr, $keyValue);
    }

    /**
     * 存储离线消息到 Redis
     *
     * Key: offline:{deviceId}，TTL 从环境变量 OFFLINE_MESSAGE_TTL 读取
     *
     * @param string $deviceId
     * @param array  $message
     * @return void
     */
    public function storeOfflineMessage(string $deviceId, array $message): void
    {
        $ttl = (int)Config::env('OFFLINE_MESSAGE_TTL', 86400);
        $key = "offline:{$deviceId}";

        $redis = Redis::getInstance();
        $payload = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $redis->lPush($key, $payload);
        $redis->expire($key, $ttl);
    }

    /**
     * 持久化消息到 messages 表
     *
     * 用于后续 ACK 确认与历史查询。
     *
     * @param string $deviceId 目标设备ID
     * @param array  $message  消息体
     * @return void
     */
    private function storeMessage(string $deviceId, array $message): void
    {
        try {
            $messageId = (string)($message['message_id'] ?? '');
            $pushKeyId = (int)($message['push_key_id'] ?? 0);
            $title     = (string)($message['title'] ?? '');
            $content   = (string)($message['content'] ?? '');
            $payload   = isset($message['payload']) && is_array($message['payload'])
                ? json_encode($message['payload'], JSON_UNESCAPED_UNICODE)
                : (string)($message['payload'] ?? '');

            Database::insert(
                'INSERT INTO messages (message_id, push_key_id, device_id, title, content, payload, is_read)
                 VALUES (?, ?, ?, ?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)',
                [$messageId, $pushKeyId, $deviceId, $title, $content, $payload]
            );
        } catch (\Throwable $e) {
            // 消息持久化失败不影响推送流程，但记录日志便于排查
            // 常见原因：数据库连接断开、Packets out of order、表不存在、字段超长
            $this->logPush("[storeMessage] 持久化失败 device_id={$deviceId} msg_id=" . ($message['message_id'] ?? '') . " err=" . $e->getMessage());
        }
    }

    /**
     * 获取并清除离线消息
     *
     * 并发安全:
     *   - 旧实现先 lLen 再循环 rPop,在并发场景下会有竞态(两请求都拿到 count=N,
     *     一个先消费完,另一个循环 N 次都 rPop 到 null)
     *   - 新实现直接循环 rPop 到 null 为止,无论并发多少个请求,消息不会丢失也不会重复
     *   - 设置最大循环次数 1000 防止异常情况下死循环
     *
     * @param string $deviceId
     * @return array
     */
    public function getOfflineMessages(string $deviceId): array
    {
        $redis = Redis::getInstance();
        $key = "offline:{$deviceId}";

        $messages = [];
        $maxIterations = 1000;  // 防止异常死循环
        for ($i = 0; $i < $maxIterations; $i++) {
            $raw = $redis->rPop($key);
            if ($raw === null) {
                break;
            }
            $msg = json_decode($raw, true);
            $messages[] = is_array($msg) ? $msg : ['raw' => $raw];
        }

        return $messages;
    }

    /**
     * 向一组 fd 推送消息
     *
     * 失败原因诊断（写入 ws_debug.log）：
     *   1. push 返回 false 的常见原因：
     *      - 发送缓冲区已满（send_buffer_size 配置过小，或客户端接收过慢）
     *      - 连接已被对端关闭但 isEstablished 仍为 true（时序竞态）
     *      - fd 不属于当前 worker 进程（多 worker 模型下，fd 由固定 worker 处理）
     *      - Swoole 底层 send 系统调用失败（EMSGSIZE 包过大、EPIPE 连接断开、EAGAIN 非阻塞）
     *   2. isEstablished 返回 false 的原因：
     *      - 客户端已主动断开但 ConnectionManager 尚未清理
     *      - 心跳超时被 Swoole 内置机制关闭
     *      - 黑名单触发 disconnect
     *   3. swoole_last_error 错误码含义：
     *      - 1001: 连接不存在 / 已关闭
     *      - 1002: 发送数据超过 max_packet_size（默认 2MB）
     *      - 1003: 发送缓冲区已满且 send_yield=false
     *      - 1202: 连接不存在
     *   可通过 swoole_strerror($code) 获取错误描述
     *
     * @param array       $fds
     * @param array       $message
     * @param string|null $deviceId 用于离线兜底
     * @param string|null $keyValue 用于日志
     * @return array
     */
    private function pushToFds(array $fds, array $message, ?string $deviceId = null, ?string $keyValue = null): array
    {
        $success = 0;
        $fail    = 0;
        $detail  = [];

        $payload = $this->packMessage($message);
        $msgId   = $message['message_id'] ?? '';
        $payloadSize = strlen($payload);

        if ($this->server !== null) {
            // WebSocket 上下文：直接推送
            foreach ($fds as $fd) {
                $fd = (int)$fd;

                // 重置 swoole 错误码，便于准确归因本次 push 的失败原因
                if (function_exists('swoole_last_error')) {
                    // swoole_last_error 是读取操作，无法直接重置；但记录 push 前后的值可帮助诊断
                    $errBefore = swoole_last_error();
                } else {
                    $errBefore = 0;
                }

                $established = $this->server->isEstablished($fd);
                if (!$established) {
                    // 原因 1：fd 未建立 WebSocket 连接
                    // 常见诱因：客户端已断开但 ConnectionManager 的 fd↔device 映射未及时清理；
                    //          或 Swoole 内置心跳已关闭该连接但 onClose 还没触发
                    $fail++;
                    $detail[] = ['fd' => $fd, 'status' => 'failed', 'message' => 'fd 未建立 WebSocket 连接'];
                    $this->logPush("[pushToFds·WS] fd 未建立连接 fd={$fd} msg_id={$msgId} 原因=isEstablished=false（连接已关闭或 onClose 未触发）");
                    continue;
                }

                // 采集 fd 详细状态（用于失败时定位）
                $clientInfo = $this->server->getClientInfo($fd);
                $connInfo = [
                    'established'     => $clientInfo['websocket_status'] ?? '?',
                    'sending'         => $clientInfo['sending'] ?? '?',      // 1=正在发送，缓冲区可能堆积
                    'connect_time'    => $clientInfo['connect_time'] ?? '?',
                    'last_time'       => $clientInfo['last_time'] ?? '?',
                    'remote_ip'       => $clientInfo['remote_ip'] ?? '?',
                    'remote_port'     => $clientInfo['remote_port'] ?? '?',
                ];

                $pushResult = $this->server->push($fd, $payload);

                $errAfter = function_exists('swoole_last_error') ? swoole_last_error() : 0;
                $errStr   = function_exists('swoole_strerror') && $errAfter > 0 ? swoole_strerror($errAfter) : '';

                if ($pushResult) {
                    $success++;
                    $detail[] = ['fd' => $fd, 'status' => 'success'];
                    $this->logPush("[pushToFds·WS] push 成功 fd={$fd} msg_id={$msgId} size={$payloadSize}");
                } else {
                    // 原因 2：push 返回 false，表示 Swoole 底层投递失败
                    // 详细诊断：错误码 + fd 状态 + 消息大小，便于判断是缓冲区满、连接断开还是包过大
                    $fail++;
                    $detail[] = [
                        'fd'        => $fd,
                        'status'    => 'failed',
                        'message'   => 'push 返回 false',
                        'err_code'  => $errAfter,
                        'err_str'   => $errStr,
                        'size'      => $payloadSize,
                        'sending'   => $connInfo['sending'],
                    ];
                    $this->logPush(
                        "[pushToFds·WS] push 返回 false fd={$fd} msg_id={$msgId}" .
                        " err_code={$errAfter} err_str={$errStr}" .
                        " size={$payloadSize} sending={$connInfo['sending']}" .
                        " remote={$connInfo['remote_ip']}:{$connInfo['remote_port']}" .
                        " 原因=" . $this->explainPushFailure($errAfter, $connInfo['sending'], $payloadSize)
                    );
                }
            }

            // 全部失败且有 device_id，存离线
            if ($success === 0 && $deviceId !== null) {
                // deviceId 可能是逗号分隔的多个 ID（pushByKey 场景）
                foreach (explode(',', $deviceId) as $did) {
                    $did = trim($did);
                    if ($did !== '') {
                        $this->storeOfflineMessage($did, $message);
                    }
                }
                $this->logPush("[pushToFds·WS] 全部失败，已存离线 device_id={$deviceId} msg_id={$msgId}");
            }
        } else {
            // HTTP 上下文：写入 Redis 队列，由 WS 进程消费
            // 注意：这里返回的 success_count 表示"已成功入队"，并非"已成功推送到 APP"
            // 真正的推送结果由 WS 进程的 processQueue 处理后写入 ws_debug.log
            $enqueued = $this->enqueuePush($fds, $message, $deviceId, $keyValue);
            if ($enqueued) {
                $success = count($fds);
                $detail[] = [
                    'status'  => 'queued',
                    'count'   => count($fds),
                    'message' => '已加入推送队列，等待 WS 进程投递',
                ];
                $this->logPush("[pushToFds·HTTP] 已入队 fds=" . json_encode($fds) . " msg_id={$msgId} key=" . ($keyValue ?? 'null') . " size={$payloadSize}");
            } else {
                $fail = count($fds);
                $detail[] = [
                    'status'  => 'enqueue_failed',
                    'count'   => count($fds),
                    'message' => '入队失败：Redis 写入异常，推送指令丢失',
                ];
                $this->logPush("[pushToFds·HTTP] 入队失败 msg_id={$msgId} fds=" . json_encode($fds) . " 原因=Redis lPush 返回 false");
            }
        }

        return [
            'success_count' => $success,
            'fail_count'    => $fail,
            'detail'        => $detail,
        ];
    }

    /**
     * 解释 push 返回 false 的原因（基于错误码与状态）
     *
     * @param int        $errCode     swoole_last_error 错误码
     * @param int|string $sending     fd 是否正在发送
     * @param int        $payloadSize 消息大小
     * @return string 人类可读的失败原因
     */
    private function explainPushFailure(int $errCode, $sending, int $payloadSize): string
    {
        // Swoole 错误码定义见 swoole_strerror，常见值：
        // 1001=连接不存在/已关闭，1002=数据包超过 max_packet_size，1003=发送缓冲区满
        switch ($errCode) {
            case 0:
                // 无错误码但仍失败，通常是 fd 已在 push 时被关闭（时序竞态）
                return '无错误码，疑似 push 时连接刚被关闭（时序竞态）';
            case 1001:
            case 1202:
                return '连接不存在或已关闭';
            case 1002:
                return "数据包过大 size={$payloadSize}，超过 max_packet_size（默认 2MB）";
            case 1003:
                return '发送缓冲区已满，send_yield 未生效或客户端接收过慢';
            default:
                if ((int)$sending === 1) {
                    return 'fd 正在发送（缓冲区可能堆积），客户端接收速度过慢';
                }
                return "未知错误 err_code={$errCode}";
        }
    }

    /**
     * 将推送指令写入 Redis 队列（供 WS 进程消费）
     *
     * 失败场景：
     *   - Redis 连接断开（网络抖动、Redis 重启）
     *   - Redis 内存满（OOM，需检查 maxmemory 配置）
     *   - lPush 返回 false（队列操作失败）
     * 失败后果：推送指令丢失，APP 无法收到推送，调用方需感知失败
     *
     * @param array       $fds
     * @param array       $message
     * @param string|null $deviceId
     * @param string|null $keyValue
     * @return bool true=入队成功，false=入队失败
     */
    private function enqueuePush(array $fds, array $message, ?string $deviceId, ?string $keyValue): bool
    {
        $command = [
            'fds'       => array_map('intval', $fds),
            'message'   => $message,
            'device_id' => $deviceId,
            'key_value' => $keyValue,
            'created_at'=> time(),
        ];
        $payload = json_encode($command, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $this->logPush("[enqueuePush] JSON 编码失败 msg_id=" . ($message['message_id'] ?? '') . " json_err=" . json_last_error_msg());
            return false;
        }

        try {
            $redis = Redis::getInstance();
            $result = $redis->lPush(self::PUSH_QUEUE_KEY, $payload);
            // lPush 返回队列长度（>=1 表示成功），false 表示失败
            if ($result === false) {
                $this->logPush("[enqueuePush] lPush 返回 false msg_id=" . ($message['message_id'] ?? '') . " 原因=Redis 操作失败（连接异常或权限问题）");
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logPush("[enqueuePush] 异常 msg_id=" . ($message['message_id'] ?? '') . " err=" . $e->getMessage());
            return false;
        }
    }

    /**
     * 处理 Redis 推送队列（仅 WS 上下文调用）
     *
     * 由 WebSocket 进程的定时器周期性调用，消费队列中的推送指令。
     *
     * 失败场景：
     *   - rPop 返回 null：队列为空（正常）
     *   - json_decode 失败：入队时数据已损坏（罕见）
     *   - fds 为空：入队时设备已离线，但指令未清理（应被 pushToFds 存离线兜底）
     *   - pushToFds 全部失败：fd 失效（已断开但未清理），已存离线
     *   - Redis 异常：连接断开，本轮处理中止（下轮定时器会重连重试）
     *
     * @param int $limit 单次处理上限
     * @return int 实际处理数量
     */
    public function processQueue(int $limit = 100): int
    {
        if ($this->server === null) {
            return 0;
        }

        try {
            $redis = Redis::getInstance();
        } catch (\Throwable $e) {
            $this->logPush("[processQueue] Redis 连接失败，本轮中止 err=" . $e->getMessage());
            return 0;
        }

        $processed = 0;

        for ($i = 0; $i < $limit; $i++) {
            try {
                $raw = $redis->rPop(self::PUSH_QUEUE_KEY);
            } catch (\Throwable $e) {
                $this->logPush("[processQueue] rPop 异常，本轮中止 err=" . $e->getMessage());
                break;
            }

            if ($raw === null) {
                break;
            }

            $command = json_decode($raw, true);
            if (!is_array($command)) {
                $this->logPush("[processQueue] 队列消息解析失败 raw=" . substr($raw, 0, 200) . " 原因=JSON 格式错误，消息丢弃");
                continue;
            }

            $fds      = $command['fds'] ?? [];
            $message  = $command['message'] ?? [];
            $deviceId = $command['device_id'] ?? null;
            $keyValue = $command['key_value'] ?? null;
            $msgId    = $message['message_id'] ?? '';
            $queuedAt = $command['created_at'] ?? 0;
            // 计算入队到出队的延迟，便于排查 WS 进程消费过慢的问题
            $waitSec = $queuedAt > 0 ? (time() - $queuedAt) : -1;

            $this->logPush("[processQueue] 出队 msg_id={$msgId} fds=" . json_encode($fds) . " key=" . ($keyValue ?? 'null') . " wait={$waitSec}s");

            if (empty($fds)) {
                $this->logPush("[processQueue] fds 为空，跳过 msg_id={$msgId} 原因=入队时设备已离线");
                $processed++;
                continue;
            }

            $result = $this->pushToFds($fds, $message, $deviceId, $keyValue);

            // 汇总本轮投递结果，便于追踪链路
            $sc = $result['success_count'] ?? 0;
            $fc = $result['fail_count'] ?? 0;
            if ($sc === 0 && $fc > 0) {
                // 全部失败：pushToFds 内部已存离线兜底，这里只记录汇总
                $this->logPush("[processQueue] 投递全部失败 msg_id={$msgId} fail={$fc} 已存离线");
            } elseif ($fc > 0) {
                // 部分失败：部分 fd 投递成功，部分失败，失败的 fd 已在 pushToFds 记录详细原因
                $this->logPush("[processQueue] 投递部分失败 msg_id={$msgId} success={$sc} fail={$fc}");
            }

            $processed++;
        }

        if ($processed > 0) {
            $this->logPush("[processQueue] 本轮处理 {$processed} 条");
        }

        return $processed;
    }

    /**
     * 将断开连接命令写入 Redis 队列（HTTP 上下文使用）
     *
     * @param string $field 匹配字段：device_id / ip / fingerprint
     * @param string $value 匹配值
     * @return void
     */
    public function enqueueDisconnect(string $field, string $value): void
    {
        $command = [
            'type'  => 'disconnect',
            'field' => $field,
            'value' => $value,
        ];
        Redis::getInstance()->lPush(self::DISCONNECT_QUEUE_KEY, json_encode($command, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 处理断开连接队列（仅 WS 上下文调用）
     *
     * @param int $limit
     * @return int
     */
    public function processDisconnectQueue(int $limit = 100): int
    {
        if ($this->server === null) {
            return 0;
        }

        $redis = Redis::getInstance();
        $processed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $raw = $redis->rPop(self::DISCONNECT_QUEUE_KEY);
            if ($raw === null) {
                break;
            }

            $command = json_decode($raw, true);
            if (!is_array($command)) {
                continue;
            }

            $field = $command['field'] ?? '';
            $value = $command['value'] ?? '';

            $fds = $this->connectionManager->findFdsByField($field, $value);
            foreach ($fds as $fd) {
                if ($this->server->isEstablished($fd)) {
                    $this->server->disconnect($fd, 4003, 'blacklisted');
                }
            }
            $processed++;
        }

        return $processed;
    }

    /**
     * 打包统一的消息协议
     *
     * 输出格式与 APP 端 ServerEnvelope 协议一致：
     * 顶层包含 type 字段用于消息分发，推送字段平铺在顶层。
     *
     * @param array $message
     * @return string
     */
    private function packMessage(array $message): string
    {
        // 兼容两种格式：
        // 1. 新格式：type=push + 平铺字段（APP 端 ServerEnvelope 可直接解析）
        // 2. 旧格式：code/message/data/time（管理后台等旧客户端兼容）
        return json_encode([
            'type'      => 'push',
            'id'        => $message['message_id'] ?? '',
            'title'     => $message['title'] ?? '',
            'content'   => $message['content'] ?? '',
            'priority'  => $message['priority'] ?? 'default',
            'timestamp' => $message['timestamp'] ?? time(),
            // 兼容旧格式
            'code'      => 0,
            'message'   => 'message',
            'data'      => $message,
            'time'      => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 推送链路日志（写入 ws_debug.log 便于排查）
     *
     * @param string $message
     * @return void
     */
    private function logPush(string $message): void
    {
        $logFile = BASE_PATH . '/runtime/logs/ws_debug.log';
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $time = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[{$time}] {$message}\n", FILE_APPEND);
    }
}
