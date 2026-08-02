<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Service\Database;

/**
 * 用户端 API 文档（只读）
 *
 * 路由前缀：/user-api/docs
 */
class DocsController extends BaseUserController
{
    public function index(array $context, array $params)
    {
        // 无需鉴权，允许登录页展示
        $sections = [
            [
                'title' => '概述',
                'items' => [
                    '接口前缀默认：/api/（可在后台路径设置修改）',
                    '所有接口使用 HTTPS / HTTP POST，返回统一 JSON 结构',
                    '鉴权方式：请求头 X-Api-Key',
                    'Content-Type: application/json',
                ],
            ],
            [
                'title' => '返回结构',
                'items' => [
                    '{ "code": 0, "message": "ok", "data": { ... } }',
                    'code=0 表示成功；非 0 表示失败，message 中包含原因',
                ],
            ],
            [
                'title' => 'POST /api/push 推送消息',
                'items' => [
                    'target_type: "device" | "key" | "broadcast"',
                    'target_value: 目标 ID（多个逗号分隔；broadcast 时留空）',
                    'title: 标题（content 或 title 必填一个）',
                    'content: 内容',
                    'payload: 自定义数据对象（可选）',
                    'priority: "high" | "normal" | "low"（可选，默认 normal）',
                ],
                'example' => <<<'JSON'
curl -X POST https://your-domain/api/push \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: YOUR_API_KEY" \
  -d '{
    "target_type": "key",
    "target_value": "pk_xxxxxxxxxxxxxxxxxxxxxxxx",
    "title": "您有一条新消息",
    "content": "请登录系统查看",
    "payload": { "order_id": 1024, "type": "order" },
    "priority": "high"
  }'
JSON,
            ],
            [
                'title' => '错误码',
                'items' => [
                    '40001: 参数错误',
                    '40101: 缺少或无效的 API Key',
                    '40301: Key 已被禁用',
                    '42901: 频率超限',
                    '50001: 服务器内部错误',
                ],
            ],
        ];

        return [
            'sections' => $sections,
            'base_url_hint' => '把 https://your-domain 替换为你的实际域名；/api/ 前缀可在后台修改。',
        ];
    }

    public function userApiKeyList(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        [$page, $perPage, $offset, $keyword] = $this->parsePage($context, 20);

        $where = ' WHERE user_id = ?';
        $bind = [$userId];
        if ($keyword !== '') {
            $where .= ' AND (name LIKE ? OR key_value LIKE ? OR description LIKE ?)';
            $kw = "%{$keyword}%";
            array_push($bind, $kw, $kw, $kw);
        }
        $total = (int)(Database::fetch("SELECT COUNT(*) cnt FROM api_keys {$where}", $bind)['cnt'] ?? 0);
        $list = Database::fetchAll(
            "SELECT id, key_value, name, description, status, expire_at, created_at, updated_at
             FROM api_keys {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $bind
        );
        return $this->pageResult($list, $total, $page, $perPage);
    }

    public function createApiKey(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $body = $this->parseBody($context);
        $name = trim((string)($body['name'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $expireDays = (int)($body['expire_days'] ?? 0);
        if ($name === '') return $this->fail($context, 'API Key 名称不能为空');

        for ($i = 0; $i < 5; $i++) {
            $kv = 'ak_' . bin2hex(random_bytes(16));
            $exists = Database::fetch('SELECT 1 FROM api_keys WHERE key_value = ? LIMIT 1', [$kv]);
            if ($exists === false) break;
        }

        $expireAt = null;
        if ($expireDays > 0) {
            $expireAt = date('Y-m-d H:i:s', time() + $expireDays * 86400);
        }

        $id = Database::insert(
            'INSERT INTO api_keys (key_value, name, description, user_id, status, expire_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())',
            [$kv, $name, $description, $userId, $expireAt]
        );
        return [
            'id'          => (int)$id,
            'key_value'   => $kv,
            'name'        => $name,
            'description' => $description,
            'expire_at'   => $expireAt,
            'status'      => 1,
        ];
    }

    public function updateApiKeyStatus(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return $this->fail($context, '无效的 API Key ID');

        $body = $this->parseBody($context);
        $status = (int)($body['status'] ?? 0);
        if (!in_array($status, [0, 1], true)) return $this->fail($context, 'status 必须为 0 或 1');

        $exists = Database::fetch('SELECT id FROM api_keys WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
        if ($exists === false) return $this->fail($context, 'API Key 不存在或不属于你', 404, 404);

        Database::execute('UPDATE api_keys SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);
        return ['id' => $id, 'status' => $status];
    }

    public function deleteApiKey(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return $this->fail($context, '无效的 API Key ID');

        $exists = Database::fetch('SELECT id FROM api_keys WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
        if ($exists === false) return $this->fail($context, 'API Key 不存在或不属于你', 404, 404);

        Database::execute('DELETE FROM api_keys WHERE id = ?', [$id]);
        return ['deleted' => true];
    }
}
