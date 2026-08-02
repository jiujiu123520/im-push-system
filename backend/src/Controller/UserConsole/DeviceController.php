<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Redis;

/**
 * 用户端设备管理
 *
 * 路由前缀：/user-api/devices
 */
class DeviceController extends BaseUserController
{
    public function index(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        [$page, $perPage, $offset, $keyword] = $this->parsePage($context, 20);

        $platform = (string)($context['get']['platform'] ?? '');
        $online   = (int)($context['get']['online'] ?? 0);
        $status   = (int)($context['get']['status'] ?? 0);

        $where = " WHERE (d.user_id = ? OR pk.user_id = ?)";
        $bind = [$userId, $userId];
        if ($keyword !== '') {
            $where .= ' AND (d.device_id LIKE ? OR d.device_name LIKE ? OR d.device_model LIKE ? OR d.ip LIKE ?)';
            $kw = "%{$keyword}%";
            array_push($bind, $kw, $kw, $kw, $kw);
        }
        if ($platform !== '') {
            $where .= ' AND d.platform = ?';
            $bind[] = $platform;
        }
        if ($status > 0) {
            $where .= ' AND d.status = ?';
            $bind[] = $status;
        }

        $total = (int)(Database::fetch(
            "SELECT COUNT(DISTINCT d.id) cnt FROM devices d
             LEFT JOIN push_keys pk ON pk.id = d.push_key_id
             {$where}",
            $bind
        )['cnt'] ?? 0);

        $list = Database::fetchAll(
            "SELECT d.*, pk.key_value push_key_value, pk.name push_key_name
             FROM devices d
             LEFT JOIN push_keys pk ON pk.id = d.push_key_id
             {$where}
             ORDER BY d.last_connect_at DESC, d.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bind
        );

        try {
            $redis = Redis::getInstance();
            foreach ($list as &$row) {
                $fdCount = (int)$redis->sCard('ws:device:' . $row['device_id']);
                $row['online'] = $fdCount > 0 ? 1 : 0;
                $row['model']  = (string)$row['device_model'];
                if (!isset($row['platform']) || $row['platform'] === null || $row['platform'] === '') {
                    $ua = (string)$row['ua'];
                    if (stripos($ua, 'android') !== false) $row['platform'] = 'android';
                    elseif (stripos($ua, 'iphone') !== false || stripos($ua, 'ios') !== false) $row['platform'] = 'ios';
                    elseif (stripos($ua, 'harmony') !== false) $row['platform'] = 'harmony';
                    else $row['platform'] = 'web';
                }
            }
            unset($row);
        } catch (\Throwable $e) {
        }

        if ($online > 0) {
            $list = array_values(array_filter($list, fn($r) => (int)($r['online'] ?? 0) === $online));
            $total = count($list);
        }

        return $this->pageResult($list, $total, $page, $perPage);
    }

    public function show(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $id = (string)($params['id'] ?? '');
        if ($id === '') return $this->fail($context, '缺少设备ID');

        $sql = "SELECT d.*, pk.key_value push_key_value, pk.name push_key_name
                FROM devices d
                LEFT JOIN push_keys pk ON pk.id = d.push_key_id
                WHERE (d.user_id = ? OR pk.user_id = ?) AND ";
        $bind = [$userId, $userId];
        if (ctype_digit($id)) {
            $sql .= ' d.id = ? LIMIT 1';
            $bind[] = (int)$id;
        } else {
            $sql .= ' d.device_id = ? LIMIT 1';
            $bind[] = $id;
        }
        $row = Database::fetch($sql, $bind);
        if ($row === false) return $this->fail($context, '设备不存在', 404, 404);

        try {
            $redis = Redis::getInstance();
            $row['online'] = (int)$redis->sCard('ws:device:' . $row['device_id']) > 0 ? 1 : 0;
            $row['model']  = (string)$row['device_model'];
        } catch (\Throwable $e) {
            $row['online'] = 0;
            $row['model']  = (string)$row['device_model'];
        }
        return $row;
    }

    public function updateStatus(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return $this->fail($context, '无效的设备ID');

        $body = $this->parseBody($context);
        $status = (int)($body['status'] ?? 0);
        if (!in_array($status, [1, 2], true)) {
            return $this->fail($context, 'status 必须为 1（启用）或 2（禁用）');
        }

        $exists = Database::fetch(
            "SELECT d.id FROM devices d
             LEFT JOIN push_keys pk ON pk.id = d.push_key_id
             WHERE d.id = ? AND (d.user_id = ? OR pk.user_id = ?)
             LIMIT 1",
            [$id, $userId, $userId]
        );
        if ($exists === false) return $this->fail($context, '设备不存在或不属于你', 404, 404);

        Database::execute('UPDATE devices SET status = ? WHERE id = ?', [$status, $id]);
        return ['status' => $status];
    }

    public function destroy(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return $this->fail($context, '无效的设备ID');

        $exists = Database::fetch(
            "SELECT d.id, d.device_id FROM devices d
             LEFT JOIN push_keys pk ON pk.id = d.push_key_id
             WHERE d.id = ? AND (d.user_id = ? OR pk.user_id = ?)
             LIMIT 1",
            [$id, $userId, $userId]
        );
        if ($exists === false) return $this->fail($context, '设备不存在或不属于你', 404, 404);

        // 清理 Redis 订阅
        try {
            $redis = Redis::getInstance();
            $pk = Database::fetch('SELECT key_value FROM push_keys WHERE id = ?', [(int)($exists['push_key_id'] ?? 0)]);
            if ($pk) {
                $redis->sRem('key:subscribe:' . $pk['key_value'], $exists['device_id']);
            }
            $redis->del('ws:device:' . $exists['device_id']);
            $redis->hDel('device:key', $exists['device_id']);
            $fds = $redis->sMembers('ws:device:' . $exists['device_id']);
            if (is_array($fds)) {
                foreach ($fds as $fd) {
                    $redis->hDel('ws:fd:device', (string)$fd);
                }
            }
        } catch (\Throwable $e) {
        }

        Database::execute('DELETE FROM devices WHERE id = ?', [$id]);
        return ['deleted' => true];
    }
}
