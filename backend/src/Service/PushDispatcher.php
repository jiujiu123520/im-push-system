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
            // 设备 WebSocket 离线，尝试 APNS 通道（iOS 设备后台/被杀时）
            $apnsResult = $this->tryApnsPush($deviceId, $message);
            if ($apnsResult !== null) {
                // APNS 已处理（无论成功失败），不再存离线
                return $apnsResult;
            }

            // 非 iOS 设备或 APNS 未配置，存离线消息
            $this->storeOfflineMessage($deviceId, $message);
            $this->logPush("[pushToDevice·{$ctx}] 设备离线，已存离线 device_id={$deviceId} msg_id={$msgId}");
            $reason = '设备离线，APP未连接或已断开（消息已存为离线，设备重连后可拉取）';
            return [
                'success_count'  => 0,
                'fail_count'     => 1,
                'stored_offline' => true,  // 标记：消息已存离线，非真正失败
                'detail'        => [
                    [
                        'device_id' => $deviceId,
                        'status'    => 'offline',
                        'message'   => $reason,
                    ],
                ],
                'fail_detail'   => [['target' => $deviceId, 'reason' => $reason]],
                'fail_reason'   => $reason,
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
            'success_count'  => 0,
            'fail_count'     => 0,
            'stored_offline' => false,
            'detail'        => [],
            'fail_detail'   => [],
            'fail_reason'   => '',
        ];

        $failReasons = [];
        $anyStoredOffline = false;
        foreach ($deviceIds as $deviceId) {
            $deviceId = trim((string)$deviceId);
            if ($deviceId === '') {
                continue;
            }
            $r = $this->pushToDevice($deviceId, $message);
            $result['success_count'] += $r['success_count'];
            $result['fail_count']    += $r['fail_count'];
            $result['detail']         = array_merge($result['detail'], $r['detail']);
            if (!empty($r['stored_offline'])) {
                $anyStoredOffline = true;
            }
            if (!empty($r['fail_detail'])) {
                $result['fail_detail'] = array_merge($result['fail_detail'], $r['fail_detail']);
            }
            if (!empty($r['fail_reason'])) {
                $failReasons[$r['fail_reason']] = ($failReasons[$r['fail_reason']] ?? 0) + 1;
            }
        }
        $result['stored_offline'] = $anyStoredOffline;
        $result['fail_reason'] = $this->buildFailReasonSummary($failReasons);

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
                $reason = '无订阅设备：该 Key 未绑定任何设备，APP端未注册或已解绑';
                return [
                    'success_count' => 0,
                    'fail_count'    => 0,
                    'stored_offline' => false,
                    'detail'        => [
                        [
                            'key'     => $keyValue,
                            'status'  => 'no_subscribers',
                            'message' => $reason,
                        ],
                    ],
                    'fail_detail'   => [['target' => 'key:' . $keyValue, 'reason' => $reason]],
                    'fail_reason'   => $reason,
                ];
            }
            foreach ($deviceIds as $deviceId) {
                $this->storeMessage($deviceId, $message);
                // 尝试 APNS 通道（iOS 设备），失败则存离线
                $apnsResult = $this->tryApnsPush($deviceId, $message);
                if ($apnsResult === null) {
                    // 非 iOS 设备或 APNS 未配置，存离线
                    $this->storeOfflineMessage($deviceId, $message);
                }
            }
            $this->logPush("[pushByKey] 所有设备离线，已处理 key={$keyValue} devices=" . count($deviceIds) . " msg_id={$message['message_id']}");
            $reason = '所有设备离线（共' . count($deviceIds) . '台），iOS设备已走APNS，其他设备已存离线';
            return [
                'success_count'  => 0,
                'fail_count'     => count($deviceIds),
                'stored_offline' => true,  // 标记：消息已存离线，非真正失败
                'detail'        => [
                    [
                        'key'     => $keyValue,
                        'status'  => 'all_offline',
                        'message' => $reason,
                        'count'   => count($deviceIds),
                    ],
                ],
                'fail_detail'   => [['target' => 'key:' . $keyValue, 'reason' => $reason]],
                'fail_reason'   => $reason,
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
     * 尝试通过 APNS 推送给 iOS 设备（WebSocket 离线时的兜底通道）
     *
     * 逻辑：
     *   1. 查询设备是否有有效的 apns_token（apns_active=1）
     *   2. 有则调用 ApnsService::send 发送 APNS 推送
     *   3. 返回推送结果数组（成功或失败）
     *   4. 如果设备不是 iOS 或没有 apns_token，返回 null（调用方应存离线）
     *
     * @param string $deviceId
     * @param array  $message
     * @return array|null 推送结果数组，null 表示不适用 APNS（应走离线）
     */
    private function tryApnsPush(string $deviceId, array $message): ?array
    {
        try {
            $row = Database::fetch(
                'SELECT platform, apns_token, apns_active, apns_bundle_id
                 FROM devices
                 WHERE device_id = ?
                 ORDER BY id DESC
                 LIMIT 1',
                [$deviceId]
            );
        } catch (\Throwable $e) {
            $this->logPush("[tryApnsPush] 查询设备信息失败 device_id={$deviceId} error=" . $e->getMessage());
            return null;
        }

        // 设备不存在或非 iOS 或 APNS 未激活
        if ($row === false) return null;
        $platform = (string)($row['platform'] ?? '');
        $apnsToken = (string)($row['apns_token'] ?? '');
        $apnsActive = (int)($row['apns_active'] ?? 0);

        if ($platform !== 'ios') return null;
        if ($apnsActive !== 1 || $apnsToken === '') return null;

        // 调用 APNS 发送
        $title   = (string)($message['title'] ?? '');
        $content = (string)($message['content'] ?? '');
        $msgId   = $message['message_id'] ?? '';
        $payload = [];
        // 将消息 ID 放入 payload，客户端可据此去重
        if ($msgId !== '') {
            $payload['message_id'] = $msgId;
        }
        // 透传自定义 payload
        if (isset($message['payload']) && is_array($message['payload'])) {
            $payload['data'] = $message['payload'];
        }

        $this->logPush("[tryApnsPush] 通过 APNS 推送 device_id={$deviceId} msg_id={$msgId}");

        // 告警合并：将告警加入聚合窗口，由 AlertAggregator 决定是否立即发送
        // 30 秒窗口内的多条告警合并为 1 条汇总推送，使用 collapse-id 防刷屏
        // 这是防 APNS DoS 风控的最有效措施
        $aggResult = \App\Service\AlertAggregator::add($deviceId, $apnsToken, $title, $content, $payload);

        if ($aggResult['aggregated'] && !$aggResult['flushed']) {
            // 告警已暂存在聚合窗口，等待窗口结束后合并发送
            $this->logPush("[tryApnsPush] 告警已聚合（窗口内第 {$aggResult['count']} 条），等待合并发送 device_id={$deviceId} msg_id={$msgId}");
            return [
                'success_count'  => 0,
                'fail_count'     => 0,
                'stored_offline' => true,  // 标记为已处理（离线消息已存，聚合窗口也暂存了）
                'detail'         => [
                    [
                        'device_id' => $deviceId,
                        'status'    => 'aggregated',
                        'message'   => '告警已聚合（窗口内第 ' . $aggResult['count'] . ' 条），将合并发送',
                        'count'     => $aggResult['count'],
                    ],
                ],
                'fail_detail' => [],
                'fail_reason' => '',
            ];
        }

        // 聚合器已触发 flush（窗口到期或达到阈值），汇总推送已发送
        // 或者聚合失败降级为直接发送
        if ($aggResult['flushed']) {
            // 聚合器内部已调用 ApnsService::send 发送汇总推送
            // 这里返回成功结果（实际推送结果由聚合器内部处理）
            $this->logPush("[tryApnsPush] 告警合并发送完成（合并 {$aggResult['count']} 条）device_id={$deviceId} msg_id={$msgId}");
            return [
                'success_count'  => 1,
                'fail_count'     => 0,
                'stored_offline' => false,
                'detail'         => [
                    [
                        'device_id' => $deviceId,
                        'status'    => 'apns_aggregated',
                        'message'   => 'APNS 汇总推送已发送（合并 ' . $aggResult['count'] . ' 条告警）',
                    ],
                ],
                'fail_detail' => [],
                'fail_reason' => '',
            ];
        }

        // 聚合失败，降级为直接发送单条推送
        $result = \App\Service\ApnsService::send($apnsToken, $title, $content, $payload);

        if ($result['success']) {
            $this->logPush("[tryApnsPush] APNS 推送成功 device_id={$deviceId} msg_id={$msgId} apns_id=" . ($result['apns_id'] ?? ''));
            return [
                'success_count'  => 1,
                'fail_count'     => 0,
                'stored_offline' => false,
                'detail'         => [
                    [
                        'device_id' => $deviceId,
                        'status'    => 'apns_success',
                        'message'   => 'APNS 推送成功',
                        'apns_id'   => $result['apns_id'] ?? '',
                    ],
                ],
                'fail_detail' => [],
                'fail_reason' => '',
            ];
        }

        // APNS 失败：token 失效/限流/熔断等由 ApnsService 内部统一处理
        // 这里只负责存离线消息作为兜底（设备重新打开 APP 时可拉取）
        $this->logPush("[tryApnsPush] APNS 推送失败 device_id={$deviceId} msg_id={$msgId} reason=" . $result['message']);
        $this->storeOfflineMessage($deviceId, $message);

        return [
            'success_count'  => 0,
            'fail_count'     => 1,
            'stored_offline' => true,
            'detail'         => [
                [
                    'device_id' => $deviceId,
                    'status'    => 'apns_failed',
                    'message'   => 'APNS 推送失败：' . $result['message'] . '（已存离线兜底）',
                ],
            ],
            'fail_detail' => [['target' => $deviceId, 'reason' => $result['message']]],
            'fail_reason' => 'APNS 推送失败：' . $result['message'],
        ];
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
        $failDetail = [];  // 结构化失败明细：[{target, reason}]
        $failReasons = []; // 失败原因摘要集合（用于生成 fail_reason）
        $httpStoredOffline = false;  // HTTP 上下文下从 WS 进程回传的 stored_offline 标记

        $payload = $this->packMessage($message);
        $msgId   = $message['message_id'] ?? '';
        $payloadSize = strlen($payload);

        if ($this->server !== null) {
            // WebSocket 上下文：直接推送
            // ⚠️ 注意：Swoole 多 worker 模型下，每个 fd 由 hash 分配到固定 worker。
            //   isEstablished($fd) 只能正确判断"当前 worker 所属"的 fd，跨 worker
            //   调用时永远返回 false（即使目标 fd 在其所属 worker 中完全活跃）。
            //   processQueue() 只在 Worker 0 执行，若直接用 isEstablished 会误判
            //   Worker 1/N 的所有 fd 为"未建立"，导致推送 100% 失败 + 错误清理在线连接。
            //   修复策略：移除 isEstablished 前置拦截；先通过 ConnectionManager 的
            //   Swoole Table（跨 Worker 共享）读取 last_active 过滤长时间死连接，
            //   再直接调用 server->push()，由 Swoole 内核做真实投递并返回结果。
            $deadThreshold = 600;  // 与 heartbeat_idle_time 一致，Android Doze 模式下需容忍 9-15 分钟维护窗口
            $now = time();
            $cm = $this->connectionManager;

            foreach ($fds as $fd) {
                $fd = (int)$fd;

                // 重置 swoole 错误码，便于准确归因本次 push 的失败原因
                if (function_exists('swoole_last_error')) {
                    // swoole_last_error 是读取操作，无法直接重置；但记录 push 前后的值可帮助诊断
                    $errBefore = swoole_last_error();
                } else {
                    $errBefore = 0;
                }

                // [步骤1] 使用 Swoole Table 的 last_active 做"软过滤"
                //   - 有信息且在有效期内：认为活跃，直接 push（避免 isEstablished 跨 worker 误判）
                //   - 有信息但超阈值：标记疑似死连接，走 push 验证
                //   - 无信息：新连接或已清理过，走 push 验证
                $skipEstablishedCheck = false;
                $info = $cm ? $cm->getDeviceInfo($fd) : null;
                if ($info !== null && !empty($info['last_active'])) {
                    $lastActive = (int)$info['last_active'];
                    if (($now - $lastActive) <= $deadThreshold) {
                        $skipEstablishedCheck = true;
                    }
                }

                // [步骤2] 仅当 last_active 超时且 fd 有可能属于"当前 worker"时才调 isEstablished
                //   Swoole 中 fd 分配：worker_id = (fd - 1) % worker_num（启动后 worker_num 固定）
                //   若计算出不属于当前 worker，则永远跳过 isEstablished，直接 push
                $established = true;
                if (!$skipEstablishedCheck) {
                    $workerNum = $this->server->setting['worker_num'] ?? 1;
                    $expectedWorker = ($fd - 1) % max(1, $workerNum);
                    $currentWorker = $this->server->worker_id ?? 0;
                    if ($expectedWorker == $currentWorker) {
                        // fd 理论上属于当前 worker，isEstablished 才有意义
                        $established = $this->server->isEstablished($fd);
                    }
                }

                if (!$established) {
                    // 只有在"当前 worker 的 fd"才会走到这里
                    $fail++;
                    $reason = 'fd 未建立 WebSocket 连接（设备已离线或连接已断开）';
                    $detail[] = ['fd' => $fd, 'status' => 'failed', 'message' => $reason];
                    $failDetail[] = ['target' => 'fd:' . $fd, 'reason' => $reason];
                    $failReasons[$reason] = ($failReasons[$reason] ?? 0) + 1;
                    $this->logPush("[pushToFds·WS] fd 未建立连接（本 worker） fd={$fd} msg_id={$msgId} 原因=isEstablished=false，触发清理");
                    $this->cleanupDeadConnection($fd);
                    continue;
                }

                // 采集 fd 详细状态（用于失败时定位）—— getClientInfo 同样有 worker 归属限制，尽量容错
                $clientInfo = $this->server->getClientInfo($fd);
                $connInfo = [
                    'established'     => is_array($clientInfo) ? ($clientInfo['websocket_status'] ?? '?') : '?',
                    'sending'         => is_array($clientInfo) ? ($clientInfo['sending'] ?? '?') : '?',
                    'connect_time'    => is_array($clientInfo) ? ($clientInfo['connect_time'] ?? '?') : '?',
                    'last_time'       => is_array($clientInfo) ? ($clientInfo['last_time'] ?? '?') : '?',
                    'remote_ip'       => is_array($clientInfo) ? ($clientInfo['remote_ip'] ?? '?') : '?',
                    'remote_port'     => is_array($clientInfo) ? ($clientInfo['remote_port'] ?? '?') : '?',
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
                    $reason = $this->explainPushFailure($errAfter, $connInfo['sending'], $payloadSize);
                    $detail[] = [
                        'fd'        => $fd,
                        'status'    => 'failed',
                        'message'   => 'push 返回 false：' . $reason,
                        'err_code'  => $errAfter,
                        'err_str'   => $errStr,
                        'size'      => $payloadSize,
                        'sending'   => $connInfo['sending'],
                    ];
                    $failDetail[] = ['target' => 'fd:' . $fd, 'reason' => $reason];
                    $failReasons[$reason] = ($failReasons[$reason] ?? 0) + 1;
                    $this->logPush(
                        "[pushToFds·WS] push 返回 false fd={$fd} msg_id={$msgId}" .
                        " err_code={$errAfter} err_str={$errStr}" .
                        " size={$payloadSize} sending={$connInfo['sending']}" .
                        " remote={$connInfo['remote_ip']}:{$connInfo['remote_port']}" .
                        " 原因=" . $reason
                    );
                    // 只有明确是"连接不存在/已关闭/状态无效"时才清理连接映射，避免临时错误误杀
                    //   1001 / 1202 = Swoole 连接不存在或已关闭
                    //   503 = WebSocket 状态无效（未完成握手或正在关闭），连接无法接收推送
                    //   其他错误（1002包过大 / 1003缓冲区满）不应该清理在线映射
                    if (in_array($errAfter, [1001, 1202, 503], true)) {
                        $this->logPush("[pushToFds·WS] push失败确认为连接已失效，清理 fd={$fd} err_code={$errAfter}");
                        $this->cleanupDeadConnection($fd);
                    }
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
            // 入队后短暂等待 WS 进程的真实投递结果（最多 800ms），避免状态显示为"queued"
            $resultKey = $this->enqueuePush($fds, $message, $deviceId, $keyValue);
            $fdCount = count($fds);
            if ($resultKey !== '') {
                $queuedDetail = [
                    'status'  => 'queued',
                    'count'   => $fdCount,
                    'message' => '已加入推送队列，等待 WS 进程投递',
                ];
                $this->logPush("[pushToFds·HTTP] 已入队 fds=" . json_encode($fds) . " msg_id={$msgId} key=" . ($keyValue ?? 'null') . " size={$payloadSize} 等待 WS 投递结果...");

                // 等待 WS 进程消费队列并写入真实投递结果
                $realResult = $this->waitForPushResult($resultKey, [$queuedDetail], $fdCount);

                $success   = (int)$realResult['success_count'];
                $fail      = (int)$realResult['fail_count'];
                $detail    = $realResult['detail'];
                $failDetail= $realResult['fail_detail'] ?? [];
                $failReasons = [];
                if (!empty($realResult['fail_reason'])) {
                    $failReasons[$realResult['fail_reason']] = $fail;
                }
                // 继承 WS 进程的 stored_offline 标记
                $httpStoredOffline = !empty($realResult['stored_offline']);
            } else {
                $fail = $fdCount;
                $reason = '入队失败：Redis 写入异常，推送指令丢失';
                $detail[] = [
                    'status'  => 'enqueue_failed',
                    'count'   => $fdCount,
                    'message' => $reason,
                ];
                $failDetail[] = ['target' => 'fds:' . $fdCount, 'reason' => $reason];
                $failReasons[$reason] = ($failReasons[$reason] ?? 0) + 1;
                $this->logPush("[pushToFds·HTTP] 入队失败 msg_id={$msgId} fds=" . json_encode($fds) . " 原因=Redis lPush 返回 false");
                $httpStoredOffline = false;
            }
        }

        // stored_offline 判断：WS 上下文直接判断，HTTP 上下文从 waitForPushResult 继承
        $storedOffline = ($this->server !== null)
            ? ($success === 0 && $fail > 0 && $deviceId !== null)
            : (bool)($httpStoredOffline ?? false);

        return [
            'success_count' => $success,
            'fail_count'    => $fail,
            'detail'        => $detail,
            'fail_detail'   => $failDetail,
            'fail_reason'   => $this->buildFailReasonSummary($failReasons),
            'stored_offline'=> $storedOffline,
        ];
    }

    /**
     * 构建失败原因摘要（人类可读）
     *
     * 将多个失败原因合并为简洁的摘要字符串，限制长度避免数据库字段溢出
     *
     * @param array $failReasons 失败原因统计 [reason => count]
     * @return string 摘要文本，无失败时返回空字符串
     */
    private function buildFailReasonSummary(array $failReasons): string
    {
        if (empty($failReasons)) {
            return '';
        }
        $parts = [];
        foreach ($failReasons as $reason => $count) {
            if ($count > 1) {
                $parts[] = $reason . '（' . $count . '次）';
            } else {
                $parts[] = $reason;
            }
        }
        $summary = implode('；', $parts);
        // 限制 480 字符，预留数据库 VARCHAR(500) 的安全余量
        if (strlen($summary) > 480) {
            $summary = mb_substr($summary, 0, 160, 'UTF-8') . '...';
        }
        return $summary;
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
        // 503=WebSocket连接状态无效（未完成握手或正在关闭），1202=连接不存在
        switch ($errCode) {
            case 0:
                // 无错误码但仍失败，通常是 fd 已在 push 时被关闭（时序竞态）
                return '无错误码，疑似 push 时连接刚被关闭（时序竞态）';
            case 1001:
            case 1202:
                return '连接不存在或已关闭';
            case 503:
                // SW_ERROR_WEBSOCKET_BAD_REQUEST：fd 存在但 websocket_status 不是 ACTIVE
                // 原因：连接未完成 WebSocket 握手（还在 pending auth 阶段），
                //       或连接正在关闭过程中（状态已从 ACTIVE 变为 CLOSING/CLOSED）
                return 'WebSocket 连接状态无效（未完成握手或正在关闭）';
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
     * @return string 非空=入队成功（返回唯一 resultKey），空字符串=入队失败
     */
    private function enqueuePush(array $fds, array $message, ?string $deviceId, ?string $keyValue): string
    {
        $msgId = $message['message_id'] ?? '';
        // 生成唯一的结果通信 key，避免多 key/多设备推送时 msgId 冲突
        $resultKey = uniqid('r_', true);
        $command = [
            'fds'        => array_map('intval', $fds),
            'message'    => $message,
            'device_id'  => $deviceId,
            'key_value'  => $keyValue,
            'created_at' => time(),
            'msg_id'     => $msgId,
            'result_key' => $resultKey,
        ];
        $payload = json_encode($command, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $this->logPush("[enqueuePush] JSON 编码失败 msg_id={$msgId} json_err=" . json_last_error_msg());
            return '';
        }

        try {
            $redis = Redis::getInstance();
            $result = $redis->lPush(self::PUSH_QUEUE_KEY, $payload);
            // lPush 返回队列长度（>=1 表示成功），false 表示失败
            if ($result === false) {
                $this->logPush("[enqueuePush] lPush 返回 false msg_id={$msgId} 原因=Redis 操作失败（连接异常或权限问题）");
                return '';
            }
            // 预置结果等待 key（TTL 10秒），WS processQueue 消费后会写入真实投递结果
            // 使用唯一 resultKey 避免多 key/多设备推送时冲突
            $redis->setex('push:result:wait:' . $resultKey, 10, '1');
            return $resultKey;
        } catch (\Throwable $e) {
            $this->logPush("[enqueuePush] 异常 msg_id={$msgId} err=" . $e->getMessage());
            return '';
        }
    }

    /**
     * HTTP 端入队后等待 WS 进程的真实投递结果（最多等待 800ms）
     *
     * 机制：
     *   1. enqueuePush 时生成唯一 resultKey，预置 push:result:wait:{resultKey}（TTL 10s）
     *   2. WS processQueue 消费后写入 push:result:{resultKey}（含真实 success/fail/detail）
     *   3. HTTP 端轮询读取 push:result:{resultKey}，拿到后覆盖 queued 状态
     *   4. 超时未拿到仍返回 queued（WS 进程消费慢或未启动）
     *
     * @param string $resultKey 唯一结果通信 key（由 enqueuePush 返回）
     * @param array  $queuedDetail 入队时的初始 detail（queued 状态）
     * @param int    $fdCount 入队时的 fd 数量
     * @return array [success_count, fail_count, detail, fail_detail, fail_reason, stored_offline]
     */
    private function waitForPushResult(string $resultKey, array $queuedDetail, int $fdCount): array
    {
        $defaultResult = [
            'success_count' => $fdCount,
            'fail_count'    => 0,
            'detail'        => $queuedDetail,
            'fail_detail'   => [],
            'fail_reason'   => '',
            'stored_offline'=> false,
        ];

        if ($resultKey === '') {
            return $defaultResult;
        }

        try {
            $redis = Redis::getInstance();
        } catch (\Throwable $e) {
            return $defaultResult;
        }

        // 轮询 800ms（每 50ms 检查一次，共 16 次）
        $maxAttempts = 16;
        $intervalMs  = 50;
        for ($i = 0; $i < $maxAttempts; $i++) {
            usleep($intervalMs * 1000);
            try {
                $raw = $redis->get('push:result:' . $resultKey);
                if ($raw !== null && $raw !== false) {
                    $result = json_decode($raw, true);
                    if (is_array($result) && isset($result['success_count'])) {
                        // 清理结果 key
                        $redis->del('push:result:' . $resultKey);
                        $redis->del('push:result:wait:' . $resultKey);
                        $this->logPush("[waitForPushResult] 获取真实结果成功 result_key={$resultKey} attempt=" . ($i + 1) . " success={$result['success_count']} fail={$result['fail_count']}");
                        return $result;
                    }
                }
            } catch (\Throwable $e) {
                $this->logPush("[waitForPushResult] 读取异常 result_key={$resultKey} err=" . $e->getMessage());
                break;
            }
        }

        // 超时未拿到结果，清理等待 key，返回 queued 状态
        try {
            $redis->del('push:result:wait:' . $resultKey);
        } catch (\Throwable $e) {}
        $this->logPush("[waitForPushResult] 等待超时（800ms）result_key={$resultKey} 仍为 queued 状态");
        return $defaultResult;
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

            // 将真实投递结果写入 Redis，供 HTTP 端 waitForPushResult 读取
            // 使用入队时生成的唯一 result_key，避免多 key/多设备推送时冲突
            $resultKey = $command['result_key'] ?? '';
            if ($resultKey !== '') {
                try {
                    $waitKey = 'push:result:wait:' . $resultKey;
                    if ($redis->exists($waitKey)) {
                        $realResult = [
                            'success_count' => $sc,
                            'fail_count'    => $fc,
                            'detail'        => $result['detail'] ?? [],
                            'fail_detail'   => $result['fail_detail'] ?? [],
                            'fail_reason'   => $result['fail_reason'] ?? '',
                            'stored_offline'=> ($sc === 0 && $fc > 0 && $deviceId !== null),
                        ];
                        $redis->setex('push:result:' . $resultKey, 10, json_encode($realResult, JSON_UNESCAPED_UNICODE));
                        $this->logPush("[processQueue] 已写入真实结果 result_key={$resultKey} msg_id={$msgId} success={$sc} fail={$fc}");
                    }
                } catch (\Throwable $e) {
                    $this->logPush("[processQueue] 写入真实结果失败 result_key={$resultKey} msg_id={$msgId} err=" . $e->getMessage());
                }
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
     * 支持两种命令格式：
     *   1. {field: 'device_id', value: 'xxx'} — 按 field 查找 fd 并断开（黑名单用）
     *   2. {action: 'disconnect', device_id: 'xxx', fds: [1,2,3]} — 直接断开指定 fd（踢出/禁用用）
     *
     * 同时消费两个队列：
     *   - ws:command:disconnect（enqueueDisconnect 写入）
     *   - ws:queue:cmd（DeviceService::notifyDisconnectDevice / kickDevice 写入）
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
            // 优先消费标准队列，再消费 ws:queue:cmd
            $raw = $redis->rPop(self::DISCONNECT_QUEUE_KEY);
            if ($raw === null) {
                $raw = $redis->rPop('ws:queue:cmd');
            }
            if ($raw === null) {
                break;
            }

            $command = json_decode($raw, true);
            if (!is_array($command)) {
                continue;
            }

            // 格式2：直接指定 fds（踢出/禁用设备时使用）
            $action = $command['action'] ?? '';
            if ($action === 'disconnect' && isset($command['fds']) && is_array($command['fds'])) {
                $reason = $command['reason'] ?? 'kicked';
                foreach ($command['fds'] as $fd) {
                    $fd = (int)$fd;
                    if ($this->server->isEstablished($fd)) {
                        $this->server->disconnect($fd, 4003, $reason);
                    }
                }
                $processed++;
                continue;
            }

            // 格式1：按 field/value 查找 fd（黑名单用）
            $field = $command['field'] ?? '';
            $value = $command['value'] ?? '';

            if ($field !== '' && $value !== '') {
                $fds = $this->connectionManager->findFdsByField($field, $value);
                foreach ($fds as $fd) {
                    if ($this->server->isEstablished($fd)) {
                        $this->server->disconnect($fd, 4003, 'blacklisted');
                    }
                }
                $processed++;
            }
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
            'timestamp' => $message['timestamp'] ?? (time() * 1000),
            // 兼容旧格式
            'code'      => 0,
            'message'   => 'message',
            'data'      => $message,
            'time'      => time() * 1000,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 清理僵尸连接（Redis 层面）
     *
     * 当发现 isEstablished=false 但 Redis 中仍有该 fd 的在线记录时，
     * 从 Redis 中移除该 fd，避免后续推送继续命中死连接。
     *
     * @param int $fd
     * @return void
     */
    private function cleanupDeadConnection(int $fd): void
    {
        try {
            $redis = Redis::getInstance();
            $redis->sRem('ws:online', (string)$fd);

            $deviceId = $redis->hGet('ws:fd:device', (string)$fd);
            if ($deviceId) {
                $redis->sRem("ws:device:{$deviceId}", (string)$fd);
                $redis->hDel('ws:fd:device', (string)$fd);
                $remaining = $redis->sCard("ws:device:{$deviceId}");
                if ($remaining == 0) {
                    $keyValue = $redis->hGet('device:key', $deviceId);
                    if ($keyValue) {
                        $redis->sRem("key:subscribe:{$keyValue}", $deviceId);
                    }
                    $redis->hDel('device:key', $deviceId);
                }
            }

            $this->logPush("[cleanupDeadConnection] 已清理僵尸连接 fd={$fd} device_id=" . ($deviceId ?: 'unknown'));
        } catch (\Throwable $e) {
            $this->logPush("[cleanupDeadConnection] 清理失败 fd={$fd} err=" . $e->getMessage());
        }
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
