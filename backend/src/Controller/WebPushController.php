<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Database;
use App\Service\Response;
use App\Service\WebPushService;

/**
 * Web Push 控制器（PWA + iOS Safari 主屏幕）
 *
 * 路由：
 *   GET  /api/web-push/public-key   返回 VAPID 公钥（供 PushManager.subscribe 使用）
 *   POST /api/web-push/subscribe    保存订阅（push_key + device_id + subscription）
 *   POST /api/web-push/unsubscribe  删除订阅
 *   POST /api/web-push/send-test    测试推送
 *
 * 鉴权方式：请求参数 push_key + device_id，校验 push_key 存在且启用。
 */
class WebPushController
{
    /**
     * 获取 VAPID 公钥
     * 路由：GET /api/web-push/public-key
     */
    public function publicKey(array $context, array $params)
    {
        return ['public_key' => WebPushService::getPublicKey()];
    }

    /**
     * 保存订阅
     * 路由：POST /api/web-push/subscribe
     *
     * 参数（JSON body）：
     *   push_key     (string, 必填) 推送 Key 值
     *   device_id    (string, 必填) 稳定设备 ID（PWA localStorage 持久化）
     *   subscription (object, 必填) { endpoint, keys: { p256dh, auth } }
     *   platform     (string, 可选) ios/edge/chrome，默认 ios
     */
    public function subscribe(array $context, array $params)
    {
        $response = $context['response'];
        $body     = $this->parseBody($context);

        $pushKey      = (string)($body['push_key'] ?? '');
        $deviceId     = (string)($body['device_id'] ?? '');
        $subscription = $body['subscription'] ?? [];
        $platform     = (string)($body['platform'] ?? 'ios');

        if ($pushKey === '' || $deviceId === '') {
            Response::fail($response, 'push_key 和 device_id 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $p256dh   = trim((string)($subscription['keys']['p256dh'] ?? ''));
        $auth     = trim((string)($subscription['keys']['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            Response::fail($response, 'subscription 不完整（需 endpoint + keys.p256dh + keys.auth）', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 校验 push_key
        $keyRow = Database::fetch(
            'SELECT id, status FROM push_keys WHERE key_value = ? LIMIT 1',
            [$pushKey]
        );
        if ($keyRow === false) {
            Response::fail($response, 'push_key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        if ((int)$keyRow['status'] !== 1) {
            Response::fail($response, 'push_key 已被禁用', Response::CODE_FORBIDDEN, 403);
            return false;
        }

        $pushKeyId = (int)$keyRow['id'];
        $ua        = (string)($context['header']['user-agent'] ?? '');

        try {
            Database::execute(
                'INSERT INTO web_push_subscriptions (device_id, push_key_id, endpoint, p256dh, auth, platform, user_agent, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE endpoint = VALUES(endpoint), p256dh = VALUES(p256dh), auth = VALUES(auth),
                                         platform = VALUES(platform), user_agent = VALUES(user_agent),
                                         status = 1, updated_at = NOW()',
                [$deviceId, $pushKeyId, $endpoint, $p256dh, $auth, $platform, $ua]
            );
        } catch (\Throwable $e) {
            error_log('[WebPushController] subscribe 保存失败: ' . $e->getMessage());
            Response::fail($response, '保存订阅失败', Response::CODE_INTERNAL, 500);
            return false;
        }

        // 写入 Redis 订阅关系（让后台按 Key 推送能覆盖 PWA 设备，并正确写入消息）
        try {
            $redis = \App\Service\Redis::getInstance();
            $redis->sAdd("key:subscribe:{$pushKey}", $deviceId);
            $redis->hSet('device:key', $deviceId, $pushKey);
        } catch (\Throwable $e) {
            error_log('[WebPushController] subscribe 写订阅关系失败: ' . $e->getMessage());
        }

        return ['device_id' => $deviceId, 'subscribed' => true];
    }

    /**
     * 删除订阅
     * 路由：POST /api/web-push/unsubscribe
     */
    public function unsubscribe(array $context, array $params)
    {
        $response = $context['response'];
        $body     = $this->parseBody($context);

        $pushKey  = (string)($body['push_key'] ?? '');
        $deviceId = (string)($body['device_id'] ?? '');

        if ($pushKey === '' || $deviceId === '') {
            Response::fail($response, 'push_key 和 device_id 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $keyRow = Database::fetch('SELECT id FROM push_keys WHERE key_value = ? LIMIT 1', [$pushKey]);
        if ($keyRow === false) {
            Response::fail($response, 'push_key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        try {
            Database::execute(
                'DELETE FROM web_push_subscriptions WHERE device_id = ? AND push_key_id = ?',
                [$deviceId, (int)$keyRow['id']]
            );
        } catch (\Throwable $e) {
            error_log('[WebPushController] unsubscribe 失败: ' . $e->getMessage());
            Response::fail($response, '删除订阅失败', Response::CODE_INTERNAL, 500);
            return false;
        }

        return ['unsubscribed' => true];
    }

    /**
     * 测试推送（PWA 页"测试推送"按钮）
     * 路由：POST /api/web-push/send-test
     */
    public function sendTest(array $context, array $params)
    {
        $response = $context['response'];
        $body     = $this->parseBody($context);

        $pushKey  = (string)($body['push_key'] ?? '');
        $deviceId = (string)($body['device_id'] ?? '');

        if ($pushKey === '' || $deviceId === '') {
            Response::fail($response, 'push_key 和 device_id 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $keyRow = Database::fetch(
            'SELECT id, status FROM push_keys WHERE key_value = ? LIMIT 1',
            [$pushKey]
        );
        if ($keyRow === false) {
            Response::fail($response, 'push_key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        if ((int)$keyRow['status'] !== 1) {
            Response::fail($response, 'push_key 已被禁用', Response::CODE_FORBIDDEN, 403);
            return false;
        }

        $sub = Database::fetch(
            'SELECT endpoint, p256dh, auth FROM web_push_subscriptions
             WHERE device_id = ? AND push_key_id = ? AND status = 1 LIMIT 1',
            [$deviceId, (int)$keyRow['id']]
        );

        if ($sub === false) {
            Response::fail($response, '该设备尚未订阅 Web Push，请先启用推送', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $messageId = uniqid('test_', true);
        $title   = '测试推送';
        $content = '这是一条 Web Push 测试消息';

        // 写 messages 表（让消息列表能显示测试消息）
        try {
            Database::insert(
                'INSERT INTO messages (message_id, push_key_id, device_id, title, content, payload, is_read)
                 VALUES (?, ?, ?, ?, ?, ?, 0)',
                [$messageId, (int)$keyRow['id'], $deviceId, $title, $content, json_encode(['is_test' => true], JSON_UNESCAPED_UNICODE)]
            );
        } catch (\Throwable $e) {
            error_log('[WebPushController] sendTest 写消息失败: ' . $e->getMessage());
        }

        $result = WebPushService::send($sub, $title, $content, ['message_id' => $messageId]);

        if (!$result['success']) {
            if (!empty($result['expired'])) {
                WebPushService::markSubscriptionInvalid((string)$sub['endpoint']);
            }
            Response::fail($response, '测试推送失败：' . $result['message'], Response::CODE_ERROR, 200);
            return false;
        }

        return ['sent' => true, 'endpoint' => substr((string)$sub['endpoint'], 0, 60) . '...'];
    }

    /**
     * 解析请求体（支持 JSON 和表单）
     *
     * @param array $context
     * @return array
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
}
