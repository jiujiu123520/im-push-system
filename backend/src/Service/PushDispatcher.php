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
     * @param string $deviceId 设备唯一标识
     * @param array  $message  消息体
     * @return array
     */
    public function pushToDevice(string $deviceId, array $message): array
    {
        // 补充消息 ID
        $message['message_id'] = $message['message_id'] ?? uniqid('msg_', true);

        // 持久化消息到 messages 表（便于 ACK 与历史查询）
        $this->storeMessage($deviceId, $message);

        $fds = $this->connectionManager->getFdsByDevice($deviceId);

        if (empty($fds)) {
            // 设备离线，存离线消息
            $this->storeOfflineMessage($deviceId, $message);
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
     * @param string $keyValue 推送 Key 值
     * @param array  $message  消息体
     * @return array
     */
    public function pushByKey(string $keyValue, array $message): array
    {
        $message['message_id'] = $message['message_id'] ?? uniqid('msg_', true);

        $fds = $this->connectionManager->getDevicesByKey($keyValue);

        if (empty($fds)) {
            // 无在线设备，查询所有订阅该 Key 的设备 ID 并存离线
            $deviceIds = Redis::getInstance()->sMembers("key:subscribe:{$keyValue}");
            if (empty($deviceIds)) {
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
                $this->storeOfflineMessage($deviceId, $message);
            }
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

        return $this->pushToFds($fds, $message, null, $keyValue);
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
            // 消息持久化失败不影响推送流程
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

        if ($this->server !== null) {
            // WebSocket 上下文：直接推送
            foreach ($fds as $fd) {
                $fd = (int)$fd;
                if ($this->server->isEstablished($fd) && $this->server->push($fd, $payload)) {
                    $success++;
                    $detail[] = ['fd' => $fd, 'status' => 'success'];
                } else {
                    $fail++;
                    $detail[] = ['fd' => $fd, 'status' => 'failed', 'message' => '推送失败'];
                }
            }

            // 全部失败且有 device_id，存离线
            if ($success === 0 && $deviceId !== null) {
                $this->storeOfflineMessage($deviceId, $message);
            }
        } else {
            // HTTP 上下文：写入 Redis 队列，由 WS 进程消费
            $this->enqueuePush($fds, $message, $deviceId, $keyValue);
            $success = count($fds);
            $detail[] = [
                'status'  => 'queued',
                'count'   => count($fds),
                'message' => '已加入推送队列',
            ];
        }

        return [
            'success_count' => $success,
            'fail_count'    => $fail,
            'detail'        => $detail,
        ];
    }

    /**
     * 将推送指令写入 Redis 队列（供 WS 进程消费）
     *
     * @param array       $fds
     * @param array       $message
     * @param string|null $deviceId
     * @param string|null $keyValue
     * @return void
     */
    private function enqueuePush(array $fds, array $message, ?string $deviceId, ?string $keyValue): void
    {
        $command = [
            'fds'       => array_map('intval', $fds),
            'message'   => $message,
            'device_id' => $deviceId,
            'key_value' => $keyValue,
            'created_at'=> time(),
        ];
        Redis::getInstance()->lPush(self::PUSH_QUEUE_KEY, json_encode($command, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 处理 Redis 推送队列（仅 WS 上下文调用）
     *
     * 由 WebSocket 进程的定时器周期性调用，消费队列中的推送指令。
     *
     * @param int $limit 单次处理上限
     * @return int 实际处理数量
     */
    public function processQueue(int $limit = 100): int
    {
        if ($this->server === null) {
            return 0;
        }

        $redis = Redis::getInstance();
        $processed = 0;

        for ($i = 0; $i < $limit; $i++) {
            $raw = $redis->rPop(self::PUSH_QUEUE_KEY);
            if ($raw === null) {
                break;
            }

            $command = json_decode($raw, true);
            if (!is_array($command)) {
                continue;
            }

            $fds     = $command['fds'] ?? [];
            $message = $command['message'] ?? [];
            $deviceId = $command['device_id'] ?? null;
            $keyValue = $command['key_value'] ?? null;

            $this->pushToFds($fds, $message, $deviceId, $keyValue);
            $processed++;
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
}
