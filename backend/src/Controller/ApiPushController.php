<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\ApiKeyService;
use App\Service\Database;
use App\Service\PushDispatcher;
use App\Service\Response;

/**
 * 开放 API 推送控制器
 *
 * 路由：POST /api/push
 *
 * 鉴权方式：请求头 X-Api-Key
 * 请求体：
 *   {
 *     "target_type":  "device" | "key",
 *     "target_value": "设备ID或Key值（多个用逗号分隔）",
 *     "title":        "消息标题",
 *     "content":      "消息内容",
 *     "payload":      {},
 *     "priority":     "high" | "normal" | "low"
 *   }
 */
class ApiPushController
{
    /**
     * 推送消息
     * 路由：POST /api/push
     *
     * @param array $context 请求上下文
     * @param array $params  路径参数
     * @return array|false 返回数据数组；返回 false 表示已自行输出响应
     */
    public function push(array $context, array $params)
    {
        $response = $context['response'];
        $headers  = $context['header'] ?? [];

        // 获取 X-Api-Key 请求头（Swoole 中 header 键为小写）
        $apiKey = $headers['x-api-key'] ?? '';
        if ($apiKey === '') {
            Response::fail($response, '缺少 X-Api-Key 请求头', Response::CODE_UNAUTHORIZED, 401);
            return false;
        }

        // 校验 API Key
        $apiKeyService = new ApiKeyService();
        $validation = $apiKeyService->validateKey($apiKey);
        if (!$validation['valid']) {
            Response::fail($response, 'API Key 无效：' . $validation['reason'], Response::CODE_UNAUTHORIZED, 401);
            return false;
        }

        $apiKeyId = (int)$validation['key']['id'];

        // 解析请求体
        $body = $this->parseBody($context);

        $targetType  = (string)($body['target_type'] ?? '');
        $targetValue = (string)($body['target_value'] ?? '');
        $title       = (string)($body['title'] ?? '');
        $content     = (string)($body['content'] ?? '');
        $payload     = $body['payload'] ?? [];
        $priority    = (string)($body['priority'] ?? 'normal');

        // 参数校验
        if (!in_array($targetType, ['device', 'key'], true)) {
            Response::fail($response, 'target_type 必须为 device 或 key', Response::CODE_BAD_REQUEST, 400);
            return false;
        }
        if ($targetValue === '') {
            Response::fail($response, 'target_value 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 解析目标列表（设备ID或Key值，支持多个用逗号分隔）
        $targets = array_filter(array_map('trim', explode(',', $targetValue)), fn($v) => $v !== '');

        // 构建消息体
        $message = [
            'message_id' => uniqid('msg_', true),
            'title'      => $title,
            'content'    => $content,
            'payload'    => $payload,
            'priority'   => $priority,
            'timestamp'  => time(),
        ];

        // key 推送时校验每个 key_value 是否真实存在且启用，并收集 push_key_id
        $validKeyIds = [];
        if ($targetType === 'key' && !empty($targets)) {
            foreach ($targets as $kv) {
                $pushKeyRow = Database::fetch(
                    'SELECT id, status FROM push_keys WHERE key_value = ? LIMIT 1',
                    [$kv]
                );
                if ($pushKeyRow === false) {
                    // key_value 不存在：明确告知用户，避免误以为"没设备在线"
                    Response::fail($response, "推送 Key [{$kv}] 不存在，请检查 target_value 是否填的是 push_key 的 key_value（而非 api_key）", Response::CODE_BAD_REQUEST, 400);
                    return false;
                }
                if ((int)$pushKeyRow['status'] !== 1) {
                    Response::fail($response, "推送 Key [{$kv}] 已被禁用", Response::CODE_FORBIDDEN, 403);
                    return false;
                }
                $validKeyIds[] = (int)$pushKeyRow['id'];
            }
            // 多 key 推送时，push_key_id 记录第一个（仅用于消息持久化关联）
            if (!empty($validKeyIds)) {
                $message['push_key_id'] = $validKeyIds[0];
            }
        }

        // 调用 PushDispatcher 执行推送（HTTP 上下文，无 server 引用）
        $dispatcher = new PushDispatcher();

        if ($targetType === 'device') {
            $result = $dispatcher->pushToDevices($targets, $message);
        } else {
            // key 维度推送
            $result = [
                'success_count' => 0,
                'fail_count'    => 0,
                'detail'        => [],
            ];
            foreach ($targets as $key) {
                $r = $dispatcher->pushByKey($key, $message);
                $result['success_count'] += $r['success_count'];
                $result['fail_count']    += $r['fail_count'];
                $result['detail']         = array_merge($result['detail'], $r['detail']);
            }
        }

        // 记录到 push_logs 表
        try {
            Database::insert(
                'INSERT INTO push_logs (api_key_id, target_type, target_value, title, content, success_count, fail_count, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $apiKeyId,
                    $targetType,
                    $targetValue,
                    $title,
                    $content,
                    $result['success_count'],
                    $result['fail_count'],
                    json_encode($result['detail'], JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
            // 日志写入失败不影响推送结果
        }

        return [
            'success_count' => $result['success_count'],
            'fail_count'    => $result['fail_count'],
            'detail'        => $result['detail'],
        ];
    }

    /**
     * 解析请求体（支持 JSON 和表单）
     *
     * @param array $context
     * @return array
     */
    private function parseBody(array $context): array
    {
        // 优先使用 POST 数据
        $body = $context['post'] ?? [];
        if (!empty($body)) {
            return $body;
        }

        // 尝试解析 raw JSON
        $raw = $context['raw'] ?? '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
