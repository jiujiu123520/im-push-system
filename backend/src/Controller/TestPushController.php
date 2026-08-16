<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\ConnectionManager;
use App\Service\Database;
use App\Service\PushDispatcher;
use App\Service\Redis;
use App\Service\Response;

/**
 * 测试调试推送控制器（需管理员鉴权）
 *
 * 用于调试推送通道是否正常工作，支持：
 *   - 按设备推送测试消息
 *   - 按 Key 推送测试消息
 *   - 返回详细调试信息（在线状态、推送结果、耗时）
 *
 * 路由：
 *   POST /admin/test-push           发送测试推送
 *   GET  /admin/test-push/check     检查设备/Key 在线状态
 */
class TestPushController
{
    /**
     * 发送测试推送
     * 路由：POST /admin/test-push
     *
     * 请求体：
     *   {
     *     "target_type":  "device" | "key",
     *     "target_value": "设备ID或Key值",
     *     "title":        "可选自定义标题",
     *     "content":      "可选自定义内容",
     *     "priority":     "high" | "normal" | "low"
     *   }
     *
     * 返回：
     *   {
     *     "online_count": int,
     *     "success_count": int,
     *     "fail_count": int,
     *     "detail": [...],
     *     "elapsed_ms": int,
     *     "debug": { ... }
     *   }
     */
    public function send(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $body = $this->parseBody($context);

        $targetType  = (string)($body['target_type'] ?? '');
        $targetValue = (string)($body['target_value'] ?? '');
        $title       = (string)($body['title'] ?? '');
        $content     = (string)($body['content'] ?? '');
        $priority    = (string)($body['priority'] ?? 'high');

        // 参数校验
        if (!in_array($targetType, ['device', 'key'], true)) {
            Response::fail($response, 'target_type 必须为 device 或 key', Response::CODE_BAD_REQUEST, 400);
            return false;
        }
        if ($targetValue === '') {
            Response::fail($response, 'target_value 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $startTime = microtime(true);

        // 默认测试消息内容
        $testTitle = $title !== '' ? $title : '【测试推送】';
        $testContent = $content !== '' ? $content : sprintf(
            '这是一条测试消息，用于调试推送通道。发送时间：%s，操作管理员：%s',
            date('Y-m-d H:i:s'),
            $admin['username'] ?? 'unknown'
        );

        // 检查在线状态
        $redis = Redis::getInstance();
        $onlineDevices = [];
        $debugInfo = [
            'target_type'  => $targetType,
            'target_value' => $targetValue,
            'server_time'  => date('Y-m-d H:i:s'),
        ];

        if ($targetType === 'device') {
            // 检查单个设备在线状态
            $onlineCount = (int)$redis->sCard("ws:device:{$targetValue}");
            $debugInfo['device_online'] = $onlineCount > 0;
            $debugInfo['online_fd_count'] = $onlineCount;
            $onlineDevices[] = $targetValue;
        } else {
            // Key 维度：查询该 Key 下所有订阅设备
            $deviceIds = $redis->sMembers("key:subscribe:{$targetValue}");
            $debugInfo['subscribed_devices'] = count($deviceIds);
            foreach ($deviceIds as $deviceId) {
                $fdCount = (int)$redis->sCard("ws:device:{$deviceId}");
                if ($fdCount > 0) {
                    $onlineDevices[] = $deviceId;
                }
            }
            $debugInfo['online_devices'] = count($onlineDevices);
        }

        // 构建测试消息体
        $message = [
            'message_id' => uniqid('test_', true),
            'title'      => $testTitle,
            'content'    => $testContent,
            'payload'    => [
                'is_test'    => true,
                'admin_id'   => $admin['admin_id'] ?? 0,
                'admin_name' => $admin['username'] ?? '',
                'sent_at'    => date('Y-m-d H:i:s'),
            ],
            'priority'   => $priority,
            'timestamp'  => time(),
        ];

        // 查询 push_key_id
        if ($targetType === 'key') {
            $pushKeyRow = Database::fetch(
                'SELECT id FROM push_keys WHERE key_value = ? LIMIT 1',
                [$targetValue]
            );
            if ($pushKeyRow) {
                $message['push_key_id'] = (int)$pushKeyRow['id'];
            }
        }

        // 调用 PushDispatcher 执行推送
        $dispatcher = new PushDispatcher();

        if ($targetType === 'device') {
            $result = $dispatcher->pushToDevices([$targetValue], $message);
        } else {
            $result = $dispatcher->pushByKey($targetValue, $message);
        }

        $elapsedMs = (int)((microtime(true) - $startTime) * 1000);

        // 派生推送状态：0=失败 1=成功 2=部分成功 3=进行中 4=已存离线
        $successCount = (int)$result['success_count'];
        $failCount    = (int)$result['fail_count'];
        $storedOffline = !empty($result['stored_offline']);
        if ($successCount > 0 && $failCount === 0) {
            $status = 1;
        } elseif ($successCount > 0 && $failCount > 0) {
            $status = 2;
        } elseif ($storedOffline) {
            $status = 4;
        } else {
            $status = 0;
        }

        // 记录测试推送日志（含失败原因、状态、耗时）
        try {
            Database::insert(
                'INSERT INTO push_logs (api_key_id, target_type, target_value, title, content, success_count, fail_count, fail_reason, status, elapsed_ms, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    0, // api_key_id=0 表示管理员测试推送
                    $targetType,
                    $targetValue,
                    $testTitle,
                    $testContent,
                    $successCount,
                    $failCount,
                    $result['fail_reason'] ?? '',
                    $status,
                    $elapsedMs,
                    json_encode([
                        'push_detail'  => $result['detail'],
                        'fail_detail'  => $result['fail_detail'] ?? [],
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
            // 日志写入失败不影响结果
        }

        return [
            'online_count'  => count($onlineDevices),
            'success_count' => $result['success_count'],
            'fail_count'    => $result['fail_count'],
            'detail'        => $result['detail'],
            'elapsed_ms'    => $elapsedMs,
            'message'       => $testTitle . ' - ' . $testContent,
            'debug'         => $debugInfo,
        ];
    }

    /**
     * 检查设备/Key 在线状态
     * 路由：GET /admin/test-push/check?type=device&value=xxx
     *
     * 返回：
     *   {
     *     "online": bool,
     *     "device_count": int,       // 在线设备数
     *     "connection_count": int,   // 在线连接数（fd 总数）
     *     "online_count": int,       // 兼容旧前端：等于 device_count
     *     "detail": { ... }
     *   }
     */
    public function check(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $get = $context['get'] ?? [];

        $type  = (string)($get['type'] ?? '');
        $value = (string)($get['value'] ?? '');

        if (!in_array($type, ['device', 'key'], true)) {
            Response::fail($response, 'type 必须为 device 或 key', Response::CODE_BAD_REQUEST, 400);
            return false;
        }
        if ($value === '') {
            Response::fail($response, 'value 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $redis = Redis::getInstance();

        if ($type === 'device') {
            $fdCount = (int)$redis->sCard("ws:device:{$value}");
            $keyValue = $redis->hGet('device:key', $value);

            // 查询设备数据库信息
            $deviceInfo = null;
            try {
                $deviceInfo = Database::fetch(
                    'SELECT id, device_id, device_name, device_model, platform, app_version, status, last_active_at FROM devices WHERE device_id = ? LIMIT 1',
                    [$value]
                );
                if ($deviceInfo !== false) {
                    $deviceInfo['online_fd_count'] = $fdCount;
                } else {
                    $deviceInfo = null;
                }
            } catch (\Throwable $e) {
            }

            return [
                'online'           => $fdCount > 0,
                'device_count'     => $fdCount > 0 ? 1 : 0,
                'connection_count' => $fdCount,
                'online_count'     => $fdCount > 0 ? 1 : 0, // 兼容旧前端
                'detail'           => [
                    'device_id'   => $value,
                    'key_value'   => $keyValue ?: null,
                    'fd_count'    => $fdCount,
                    'device_info' => $deviceInfo,
                    'checked_at'  => date('Y-m-d H:i:s'),
                ],
            ];
        }

        // Key 维度：查询该 Key 下所有订阅设备
        $deviceIds = $redis->sMembers("key:subscribe:{$value}");
        $onlineDevices = [];
        $totalFdCount = 0;
        foreach ($deviceIds as $deviceId) {
            $fdCount = (int)$redis->sCard("ws:device:{$deviceId}");
            if ($fdCount > 0) {
                $onlineDevices[] = [
                    'device_id' => $deviceId,
                    'fd_count'  => $fdCount,
                ];
                $totalFdCount += $fdCount;
            }
        }

        // 查询在线设备的数据库详情（型号、平台、最后活跃时间等）
        $onlineDeviceDetails = [];
        if (!empty($onlineDevices)) {
            $onlineIds = array_column($onlineDevices, 'device_id');
            $placeholders = implode(',', array_fill(0, count($onlineIds), '?'));
            try {
                $stmt = Database::pdo()->prepare(
                    "SELECT id, device_id, device_name, device_model, platform, app_version, status, last_active_at FROM devices WHERE device_id IN ({$placeholders})"
                );
                $stmt->execute($onlineIds);
                $dbDevices = $stmt->fetchAll(\PDO::FETCH_ASSOC);
                $dbMap = [];
                foreach ($dbDevices as $d) {
                    $dbMap[$d['device_id']] = $d;
                }
                // 合并 Redis fd_count 和数据库信息
                foreach ($onlineDevices as $od) {
                    $detail = $dbMap[$od['device_id']] ?? null;
                    $onlineDeviceDetails[] = [
                        'device_id'      => $od['device_id'],
                        'fd_count'       => $od['fd_count'],
                        'device_name'    => $detail['device_name'] ?? '',
                        'device_model'   => $detail['device_model'] ?? '',
                        'platform'       => $detail['platform'] ?? '',
                        'app_version'    => $detail['app_version'] ?? '',
                        'db_id'          => $detail['id'] ?? 0,
                        'status'         => $detail['status'] ?? 0,
                        'last_active_at' => $detail['last_active_at'] ?? '',
                    ];
                }
            } catch (\Throwable $e) {
                // 数据库查询失败则仅返回 Redis 数据
                $onlineDeviceDetails = $onlineDevices;
            }
        }

        // 查询 Key 信息
        $keyInfo = null;
        try {
            $stmt = Database::pdo()->prepare('SELECT id, name, status, max_devices FROM push_keys WHERE key_value = ?');
            $stmt->execute([$value]);
            $keyInfo = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
        }

        return [
            'online'           => count($onlineDevices) > 0,
            'device_count'     => count($onlineDevices),
            'connection_count' => $totalFdCount,
            'online_count'     => count($onlineDevices), // 兼容旧前端
            'detail'           => [
                'key_value'        => $value,
                'subscribed_total' => count($deviceIds),
                'online_devices'   => array_column($onlineDevices, 'device_id'),
                'online_device_details' => $onlineDeviceDetails,
                'key_info'         => $keyInfo,
                'checked_at'       => date('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * 并发压测推送
     * 路由：POST /admin/test-push/concurrent
     *
     * 请求体：
     *   {
     *     "target_type":  "device" | "key",
     *     "target_value": "设备ID或Key值",
     *     "title":        "可选自定义标题",
     *     "content":      "可选自定义内容",
     *     "priority":     "high" | "normal" | "low",
     *     "concurrency":  int, 并发数（1-1000，默认 10）
     *     "total":        int, 总推送次数（1-10000，默认 100，0=只按并发数发一批）
     *     "interval_ms":  int, 每批之间的间隔毫秒数（默认 0，不间隔）
     *   }
     *
     * 返回：
     *   {
     *     "concurrency": int,  实际并发数
     *     "total_sent":  int,  实际发送总数
     *     "success_count": int,
     *     "fail_count":    int,
     *     "elapsed_ms":    int,  总耗时
     *     "avg_ms":        float, 平均单条耗时
     *     "qps":           float, 每秒推送次数
     *     "batches":       int,   批次数
     *     "detail":        [...]
     *   }
     */
    public function concurrentTest(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $body = $this->parseBody($context);

        $targetType  = (string)($body['target_type'] ?? '');
        $targetValue = (string)($body['target_value'] ?? '');
        $title       = (string)($body['title'] ?? '');
        $content     = (string)($body['content'] ?? '');
        $priority    = (string)($body['priority'] ?? 'high');
        $concurrency = max(1, min(1000, (int)($body['concurrency'] ?? 10)));
        $total       = (int)($body['total'] ?? 100);
        $intervalMs  = max(0, min(60000, (int)($body['interval_ms'] ?? 0)));

        // 参数校验
        if (!in_array($targetType, ['device', 'key'], true)) {
            Response::fail($response, 'target_type 必须为 device 或 key', Response::CODE_BAD_REQUEST, 400);
            return false;
        }
        if ($targetValue === '') {
            Response::fail($response, 'target_value 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }
        if ($total < 0 || $total > 10000) {
            Response::fail($response, 'total 取值范围 0-10000', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 默认 0 表示只按并发数发一批
        $actualTotal = $total > 0 ? $total : $concurrency;
        $batches = (int)ceil($actualTotal / $concurrency);

        $testTitle = $title !== '' ? $title : '【并发压测】';
        $baseContent = $content !== '' ? $content : '并发压测消息';

        $startTime = microtime(true);

        $totalSuccess = 0;
        $totalFail = 0;
        $totalSent = 0;
        $details = [];

        // 使用 PushDispatcher 进行推送
        $dispatcher = new PushDispatcher();

        // 查询 push_key_id（按 Key 推送时使用）
        $pushKeyId = 0;
        if ($targetType === 'key') {
            try {
                $pushKeyRow = Database::fetch(
                    'SELECT id FROM push_keys WHERE key_value = ? LIMIT 1',
                    [$targetValue]
                );
                if ($pushKeyRow) {
                    $pushKeyId = (int)$pushKeyRow['id'];
                }
            } catch (\Throwable $e) {}
        }

        for ($batch = 0; $batch < $batches; $batch++) {
            // 当前批次应推送的数量
            $remaining = $actualTotal - $totalSent;
            $currentBatchSize = min($concurrency, $remaining);
            if ($currentBatchSize <= 0) {
                break;
            }

            // 使用 swoole 协程并发推送（如果在 Swoole HTTP 上下文中）
            // 这里简化为同步循环推送，因为 PushDispatcher 内部已通过 Redis 队列异步处理
            for ($i = 0; $i < $currentBatchSize; $i++) {
                $seq = $totalSent + 1;
                $message = [
                    'message_id' => uniqid('conc_test_', true),
                    'title'      => $testTitle . ' #' . $seq,
                    'content'    => $baseContent . '（第 ' . $seq . ' 条，批次 ' . ($batch + 1) . '）',
                    'payload'    => [
                        'is_test'      => true,
                        'concurrent'   => true,
                        'seq'          => $seq,
                        'batch'        => $batch + 1,
                        'concurrency'  => $concurrency,
                        'admin_id'     => $admin['admin_id'] ?? 0,
                        'admin_name'   => $admin['username'] ?? '',
                        'sent_at'      => date('Y-m-d H:i:s'),
                    ],
                    'priority'   => $priority,
                    'timestamp'  => time(),
                ];
                if ($pushKeyId > 0) {
                    $message['push_key_id'] = $pushKeyId;
                }

                try {
                    if ($targetType === 'device') {
                        $r = $dispatcher->pushToDevices([$targetValue], $message);
                    } else {
                        $r = $dispatcher->pushByKey($targetValue, $message);
                    }
                    $totalSuccess += $r['success_count'];
                    $totalFail    += $r['fail_count'];
                } catch (\Throwable $e) {
                    $totalFail++;
                    $details[] = [
                        'seq'   => $seq,
                        'batch' => $batch + 1,
                        'status' => 'failed',
                        'reason' => $e->getMessage(),
                    ];
                }
                $totalSent++;
            }

            // 批次间隔（除最后一批外）
            if ($intervalMs > 0 && $batch < $batches - 1) {
                usleep($intervalMs * 1000);
            }
        }

        $elapsedMs = (int)((microtime(true) - $startTime) * 1000);
        $avgMs = $totalSent > 0 ? round($elapsedMs / $totalSent, 2) : 0;
        $qps = $elapsedMs > 0 ? round($totalSent / ($elapsedMs / 1000), 2) : 0;

        // 派生推送状态
        if ($totalSuccess > 0 && $totalFail === 0) {
            $stressStatus = 1;
        } elseif ($totalSuccess > 0 && $totalFail > 0) {
            $stressStatus = 2;
        } else {
            $stressStatus = 0;
        }
        $stressFailReason = $totalFail > 0
            ? '并发压测失败 ' . $totalFail . '/' . $totalSent . '（详见 detail）'
            : '';

        // 记录压测日志（含失败原因、状态、耗时）
        try {
            Database::insert(
                'INSERT INTO push_logs (api_key_id, target_type, target_value, title, content, success_count, fail_count, fail_reason, status, elapsed_ms, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    0,
                    $targetType,
                    $targetValue,
                    $testTitle,
                    '并发压测：concurrency=' . $concurrency . ', total=' . $actualTotal . ', interval=' . $intervalMs . 'ms',
                    $totalSuccess,
                    $totalFail,
                    $stressFailReason,
                    $stressStatus,
                    $elapsedMs,
                    json_encode([
                        'concurrency' => $concurrency,
                        'total_sent'  => $totalSent,
                        'batches'     => $batches,
                        'elapsed_ms'  => $elapsedMs,
                        'avg_ms'      => $avgMs,
                        'qps'         => $qps,
                        'fail_detail' => $details,
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {}

        return [
            'concurrency'   => $concurrency,
            'total_sent'    => $totalSent,
            'success_count' => $totalSuccess,
            'fail_count'    => $totalFail,
            'elapsed_ms'    => $elapsedMs,
            'avg_ms'        => $avgMs,
            'qps'           => $qps,
            'batches'       => $batches,
            'interval_ms'   => $intervalMs,
            'detail'        => $details,
        ];
    }

    /**
     * 解析请求体
     */
    private function parseBody(array $context): array
    {
        $body = $context['post'] ?? [];
        if (!empty($body)) {
            return $body;
        }
        $raw = $context['raw'] ?? '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * 构建失败原因摘要（与 PushDispatcher::buildFailReasonSummary 逻辑一致）
     *
     * @param array $failReasons 失败原因统计 [reason => count]
     * @return string
     */
    private function buildFailReasonSummary(array $failReasons): string
    {
        if (empty($failReasons)) {
            return '';
        }
        $parts = [];
        foreach ($failReasons as $reason => $count) {
            $parts[] = $count > 1 ? $reason . '（' . $count . '次）' : $reason;
        }
        $summary = implode('；', $parts);
        if (strlen($summary) > 480) {
            $summary = mb_substr($summary, 0, 160, 'UTF-8') . '...';
        }
        return $summary;
    }

    /**
     * APP 自测推送（无需管理员鉴权，通过 Key + device_id 鉴权）
     * 路由：POST /api/test-push-self
     *
     * 请求体：
     *   { "key": "推送Key", "device_id": "设备ID" }
     *
     * 用于 APP 端自测推送通道是否正常，发送一条测试消息到当前设备。
     *
     * 返回：
     *   {
     *     "online": bool,
     *     "success": bool,
     *     "message": string,
     *     "elapsed_ms": int
     *   }
     */
    public function selfTest(array $context, array $params)
    {
        $response = $context['response'];
        $body = $this->parseBody($context);

        $key      = (string)($body['key'] ?? '');
        $deviceId = (string)($body['device_id'] ?? '');

        if ($key === '' || $deviceId === '') {
            Response::fail($response, 'key 和 device_id 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $startTime = microtime(true);

        // 校验 Key 有效性
        try {
            $stmt = Database::pdo()->prepare('SELECT id, status FROM push_keys WHERE key_value = ?');
            $stmt->execute([$key]);
            $keyRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[TestPush] selfTest Key查询失败: ' . $e->getMessage());
            Response::fail($response, '服务器错误，请稍后再试', Response::CODE_INTERNAL, 500);
            return false;
        }

        if (!$keyRow) {
            Response::fail($response, 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        if ((int)$keyRow['status'] !== 1) {
            Response::fail($response, 'Key 已被禁用', Response::CODE_FORBIDDEN, 403);
            return false;
        }

        // 检查设备在线状态
        $redis = Redis::getInstance();
        $fdCount = (int)$redis->sCard("ws:device:{$deviceId}");
        $online = $fdCount > 0;

        // 构建测试消息
        $message = [
            'message_id' => uniqid('test_self_', true),
            'title'      => '【通道测试】',
            'content'    => sprintf(
                '测试推送成功！时间：%s，设备：%s',
                date('Y-m-d H:i:s'),
                substr($deviceId, 0, 8) . '...'
            ),
            'payload'    => [
                'is_test' => true,
                'self_test' => true,
                'sent_at' => date('Y-m-d H:i:s'),
            ],
            'priority'   => 'high',
            'timestamp'  => time(),
        ];

        // 调用 PushDispatcher 推送到当前设备
        $dispatcher = new PushDispatcher();
        $result = $dispatcher->pushToDevice($deviceId, $message);

        $elapsedMs = (int)((microtime(true) - $startTime) * 1000);

        $success = $result['success_count'] > 0;

        // 派生推送状态
        $sfSuccess = (int)$result['success_count'];
        $sfFail    = (int)$result['fail_count'];
        $sfStoredOffline = !empty($result['stored_offline']);
        if ($sfSuccess > 0 && $sfFail === 0) {
            $sfStatus = 1;
        } elseif ($sfSuccess > 0 && $sfFail > 0) {
            $sfStatus = 2;
        } elseif ($sfStoredOffline) {
            $sfStatus = 4;
        } else {
            $sfStatus = 0;
        }

        // 记录 APP 自测推送日志
        try {
            Database::insert(
                'INSERT INTO push_logs (api_key_id, target_type, target_value, title, content, success_count, fail_count, fail_reason, status, elapsed_ms, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int)$keyRow['id'],
                    'device',
                    $deviceId,
                    '【通道测试】',
                    'APP 端自测推送',
                    $sfSuccess,
                    $sfFail,
                    $result['fail_reason'] ?? '',
                    $sfStatus,
                    $elapsedMs,
                    json_encode([
                        'is_self_test' => true,
                        'device_id'    => $deviceId,
                        'fail_detail'  => $result['fail_detail'] ?? [],
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
        }

        return [
            'online'     => $online,
            'success'    => $success,
            'message'    => $success
                ? '测试消息已发送，请查看通知栏'
                : ($online ? '推送失败，请检查连接' : '设备离线，消息已存为离线消息'),
            'elapsed_ms' => $elapsedMs,
        ];
    }

    /**
     * 管理后台发送推送
     * 路由：POST /admin/push/send
     *
     * 请求体（PushParams）：
     *   {
     *     "title":       "标题",
     *     "content":     "内容",
     *     "platform":    "android|ios|all",
     *     "targetType":  "all|tag|alias|deviceId",
     *     "target":      "目标值（targetType=all 时为空）",
     *     "pushType":    "notification|message|silent",
     *     "extras":      {}
     *   }
     *
     * 返回：
     *   { "messageId": "xxx", "success": true, "message": "推送成功" }
     */
    public function sendPush(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $body = $this->parseBody($context);

        $title      = (string)($body['title'] ?? '');
        $content    = (string)($body['content'] ?? '');
        // 兼容前端字段名 target_type/target_value 与 PushParams.targetType/target
        $targetType = (string)($body['targetType'] ?? $body['target_type'] ?? 'all');
        $target     = (string)($body['target'] ?? $body['target_value'] ?? '');
        $pushType   = (string)($body['pushType'] ?? $body['push_type'] ?? 'notification');

        if ($title === '') {
            Response::fail($context['response'], '标题不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $startTime = microtime(true);

        // 构建消息体
        $message = [
            'message_id' => uniqid('push_', true),
            'title'      => $title,
            'content'    => $content,
            'payload'    => [
                'push_type' => $pushType,
                'admin_id'  => $admin['admin_id'] ?? 0,
                'sent_at'   => date('Y-m-d H:i:s'),
            ],
            'priority'   => $pushType === 'silent' ? 'low' : 'high',
            'timestamp'  => time(),
        ];

        $dispatcher = new PushDispatcher();

        // 归一化 targetType：device/deviceId 视为按设备推送；key 视为按 Key 推送；其余视为全量
        if (in_array($targetType, ['device', 'deviceId'], true) && $target !== '') {
            // 支持多个设备 ID（英文逗号分隔）
            $deviceIds = array_values(array_filter(array_map('trim', explode(',', $target))));
            $result = $dispatcher->pushToDevices($deviceIds, $message);
        } elseif ($targetType === 'key' && $target !== '') {
            // 支持多个 Key（英文逗号分隔）
            $keyValues = array_values(array_filter(array_map('trim', explode(',', $target))));
            $totalSuccess = 0;
            $totalFail = 0;
            $details = [];
            $failDetail = [];
            $failReasons = [];
            $anyStoredOffline = false;
            foreach ($keyValues as $kv) {
                $r = $dispatcher->pushByKey($kv, $message);
                $totalSuccess += $r['success_count'];
                $totalFail += $r['fail_count'];
                $details[] = [
                    'key' => $kv,
                    'success' => $r['success_count'],
                    'fail' => $r['fail_count'],
                ];
                if (!empty($r['stored_offline'])) {
                    $anyStoredOffline = true;
                }
                if (!empty($r['fail_detail'])) {
                    $failDetail = array_merge($failDetail, $r['fail_detail']);
                }
                if (!empty($r['fail_reason'])) {
                    $failReasons[$r['fail_reason']] = ($failReasons[$r['fail_reason']] ?? 0) + 1;
                }
            }
            $result = [
                'success_count'  => $totalSuccess,
                'fail_count'     => $totalFail,
                'stored_offline' => $anyStoredOffline,
                'detail'        => $details,
                'fail_detail'   => $failDetail,
                'fail_reason'   => $this->buildFailReasonSummary($failReasons),
            ];
        } else {
            // 全量推送：遍历所有启用状态的 push_keys
            $keys = Database::fetchAll(
                'SELECT key_value FROM push_keys WHERE status = 1',
                []
            );

            $totalSuccess = 0;
            $totalFail = 0;
            $details = [];
            $failDetail = [];
            $failReasons = [];
            $anyStoredOffline = false;

            foreach ($keys as $keyRow) {
                $keyValue = (string)$keyRow['key_value'];
                $r = $dispatcher->pushByKey($keyValue, $message);
                $totalSuccess += $r['success_count'];
                $totalFail += $r['fail_count'];
                $details[] = [
                    'key' => $keyValue,
                    'success' => $r['success_count'],
                    'fail' => $r['fail_count'],
                ];
                if (!empty($r['stored_offline'])) {
                    $anyStoredOffline = true;
                }
                if (!empty($r['fail_detail'])) {
                    $failDetail = array_merge($failDetail, $r['fail_detail']);
                }
                if (!empty($r['fail_reason'])) {
                    $failReasons[$r['fail_reason']] = ($failReasons[$r['fail_reason']] ?? 0) + 1;
                }
            }

            $result = [
                'success_count'  => $totalSuccess,
                'fail_count'     => $totalFail,
                'stored_offline' => $anyStoredOffline,
                'detail'        => $details,
                'fail_detail'   => $failDetail,
                'fail_reason'   => $this->buildFailReasonSummary($failReasons),
            ];
        }

        $elapsedMs = (int)((microtime(true) - $startTime) * 1000);

        // 派生推送状态：0=失败 1=成功 2=部分成功 3=进行中 4=已存离线
        $spSuccess = (int)$result['success_count'];
        $spFail    = (int)$result['fail_count'];
        $spStoredOffline = !empty($result['stored_offline']);
        if ($spSuccess > 0 && $spFail === 0) {
            $spStatus = 1;
        } elseif ($spSuccess > 0 && $spFail > 0) {
            $spStatus = 2;
        } elseif ($spStoredOffline) {
            $spStatus = 4;
        } else {
            $spStatus = 0;
        }

        // 记录推送日志（含失败原因、状态、耗时）
        try {
            Database::insert(
                'INSERT INTO push_logs (api_key_id, target_type, target_value, title, content, success_count, fail_count, fail_reason, status, elapsed_ms, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    0,
                    $targetType,
                    $target,
                    $title,
                    $content,
                    $spSuccess,
                    $spFail,
                    $result['fail_reason'] ?? '',
                    $spStatus,
                    $elapsedMs,
                    json_encode([
                        'push_detail'  => $result['detail'],
                        'fail_detail'  => $result['fail_detail'] ?? [],
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
            // 日志写入失败不影响结果
        }

        return [
            'messageId'     => $message['message_id'],
            'success'        => $result['success_count'] > 0,
            'message'        => $result['success_count'] > 0
                ? "推送成功（成功 {$result['success_count']}，失败 {$result['fail_count']}）"
                : '推送失败，可能没有在线设备',
            'success_count' => $result['success_count'],
            'fail_count'    => $result['fail_count'],
            'elapsed_ms'    => $elapsedMs,
        ];
    }
}
