<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Service\Database;

/**
 * 用户端 Key 管理（push_keys）
 *
 * 路由前缀：/user-api/keys
 */
class KeyController extends BaseUserController
{
    public function index(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        [$page, $perPage, $offset, $keyword] = $this->parsePage($context, 20);

        $where = ' WHERE user_id = ?';
        $bind = [$userId];
        if ($keyword !== '') {
            $where .= ' AND (name LIKE ? OR key_value LIKE ?)';
            $kw = "%{$keyword}%";
            array_push($bind, $kw, $kw);
        }
        $total = (int)(Database::fetch("SELECT COUNT(*) cnt FROM push_keys {$where}", $bind)['cnt'] ?? 0);
        $list = Database::fetchAll(
            "SELECT id, key_value, name, status, max_devices, created_at, updated_at
             FROM push_keys {$where}
             ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $bind
        );
        // 追加订阅统计
        try {
            $redis = \App\Service\Redis::getInstance();
            foreach ($list as &$row) {
                $totalSub = (int)$redis->sCard('key:subscribe:' . $row['key_value']);
                $row['subscribed_total'] = $totalSub;
                $row['online_count'] = 0;
                if ($totalSub > 0) {
                    $ids = $redis->sMembers('key:subscribe:' . $row['key_value']);
                    if (is_array($ids)) {
                        foreach ($ids as $did) {
                            if ((int)$redis->sCard('ws:device:' . $did) > 0) {
                                $row['online_count']++;
                            }
                        }
                    }
                }
            }
            unset($row);
        } catch (\Throwable $e) {
        }

        return $this->pageResult($list, $total, $page, $perPage);
    }

    public function store(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $body = $this->parseBody($context);
        $name = trim((string)($body['name'] ?? ''));
        $maxDevices = (int)($body['max_devices'] ?? 10);
        $maxDevices = max(1, min(10000, $maxDevices));
        if ($name === '') return $this->fail($context, 'Key 名称不能为空');

        // 生成 key_value：确保唯一
        for ($i = 0; $i < 5; $i++) {
            $keyValue = 'pk_' . bin2hex(random_bytes(12));
            $exists = Database::fetch('SELECT 1 FROM push_keys WHERE key_value = ? LIMIT 1', [$keyValue]);
            if ($exists === false) break;
        }

        $id = Database::insert(
            'INSERT INTO push_keys (key_value, name, user_id, status, max_devices, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, NOW(), NOW())',
            [$keyValue, $name, $userId, $maxDevices]
        );
        return [
            'id'         => (int)$id,
            'key_value'  => $keyValue,
            'name'       => $name,
            'max_devices'=> $maxDevices,
            'status'     => 1,
        ];
    }

    public function update(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return $this->fail($context, '无效的 Key ID');

        $row = Database::fetch(
            'SELECT id, key_value, name, status, max_devices FROM push_keys WHERE id = ? AND user_id = ? LIMIT 1',
            [$id, $userId]
        );
        if ($row === false) return $this->fail($context, 'Key 不存在或不属于你', 404, 404);

        $body = $this->parseBody($context);
        $name = trim((string)($body['name'] ?? (string)$row['name']));
        $maxDevices = isset($body['max_devices']) ? (int)$body['max_devices'] : (int)$row['max_devices'];
        $maxDevices = max(1, min(10000, $maxDevices));
        if ($name === '') return $this->fail($context, 'Key 名称不能为空');

        Database::execute(
            'UPDATE push_keys SET name = ?, max_devices = ?, updated_at = NOW() WHERE id = ?',
            [$name, $maxDevices, $id]
        );
        return [
            'id'          => $id,
            'key_value'   => (string)$row['key_value'],
            'name'        => $name,
            'max_devices' => $maxDevices,
            'status'      => (int)$row['status'],
        ];
    }

    public function updateStatus(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return $this->fail($context, '无效的 Key ID');
        $body = $this->parseBody($context);
        $status = (int)($body['status'] ?? 0);
        if (!in_array($status, [0, 1], true)) return $this->fail($context, 'status 必须为 0 或 1');

        $exists = Database::fetch('SELECT id FROM push_keys WHERE id = ? AND user_id = ? LIMIT 1', [$id, $userId]);
        if ($exists === false) return $this->fail($context, 'Key 不存在或不属于你', 404, 404);

        Database::execute('UPDATE push_keys SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);
        return ['id' => $id, 'status' => $status];
    }

    public function destroy(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return $this->fail($context, '无效的 Key ID');

        $row = Database::fetch(
            'SELECT id, key_value FROM push_keys WHERE id = ? AND user_id = ? LIMIT 1',
            [$id, $userId]
        );
        if ($row === false) return $this->fail($context, 'Key 不存在或不属于你', 404, 404);

        // 清理 Redis 订阅
        try {
            $redis = \App\Service\Redis::getInstance();
            $members = $redis->sMembers('key:subscribe:' . $row['key_value']);
            if (is_array($members)) {
                foreach ($members as $did) {
                    $redis->sRem('key:subscribe:' . $row['key_value'], $did);
                }
            }
            $redis->del('key:subscribe:' . $row['key_value']);
        } catch (\Throwable $e) {
        }

        Database::execute('DELETE FROM push_keys WHERE id = ?', [$id]);
        return ['deleted' => true];
    }
}
