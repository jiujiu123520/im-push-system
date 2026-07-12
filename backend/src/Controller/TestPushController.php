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
        ];

        // 调用 PushDispatcher 执行推送
        $dispatcher = new PushDispatcher();

        if ($targetType === 'device') {
            $result = $dispatcher->pushToDevices([$targetValue], $message);
        } else {
            $result = $dispatcher->pushByKey($targetValue, $message);
        }

        $elapsedMs = (int)((microtime(true) - $startTime) * 1000);

        // 记录测试推送日志
        try {
            Database::insert(
                'INSERT INTO push_logs (api_key_id, target_type, target_value, title, content, success_count, fail_count, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    0, // api_key_id=0 表示管理员测试推送
                    $targetType,
                    $targetValue,
                    $testTitle,
                    $testContent,
                    $result['success_count'],
                    $result['fail_count'],
                    json_encode($result['detail'], JSON_UNESCAPED_UNICODE),
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
     *     "online_count": int,
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

            return [
                'online'       => $fdCount > 0,
                'online_count' => $fdCount,
                'detail'       => [
                    'device_id'   => $value,
                    'key_value'   => $keyValue ?: null,
                    'fd_count'    => $fdCount,
                    'checked_at'  => date('Y-m-d H:i:s'),
                ],
            ];
        }

        // Key 维度
        $deviceIds = $redis->sMembers("key:subscribe:{$value}");
        $onlineDevices = [];
        foreach ($deviceIds as $deviceId) {
            $fdCount = (int)$redis->sCard("ws:device:{$deviceId}");
            if ($fdCount > 0) {
                $onlineDevices[] = $deviceId;
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
            'online'       => count($onlineDevices) > 0,
            'online_count' => count($onlineDevices),
            'detail'       => [
                'key_value'        => $value,
                'subscribed_total' => count($deviceIds),
                'online_devices'   => $onlineDevices,
                'key_info'         => $keyInfo,
                'checked_at'       => date('Y-m-d H:i:s'),
            ],
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
            Response::fail($response, '服务器错误：' . $e->getMessage(), Response::CODE_INTERNAL, 500);
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
        ];

        // 调用 PushDispatcher 推送到当前设备
        $dispatcher = new PushDispatcher();
        $result = $dispatcher->pushToDevice($deviceId, $message);

        $elapsedMs = (int)((microtime(true) - $startTime) * 1000);

        $success = $result['success_count'] > 0;

        return [
            'online'     => $online,
            'success'    => $success,
            'message'    => $success
                ? '测试消息已发送，请查看通知栏'
                : ($online ? '推送失败，请检查连接' : '设备离线，消息已存为离线消息'),
            'elapsed_ms' => $elapsedMs,
        ];
    }
}
