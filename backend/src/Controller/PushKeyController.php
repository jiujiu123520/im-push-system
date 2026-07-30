<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Database;
use App\Service\Response;

/**
 * Push Key 管理控制器（需管理员鉴权）
 *
 * 路由：
 *   GET    /admin/keys          列表（分页10条，支持搜索）
 *   POST   /admin/keys          创建
 *   PUT    /admin/keys/{id}     更新（含通知邮箱配置）
 *   DELETE /admin/keys/{id}     删除
 *   PUT    /admin/keys/{id}/status  切换状态
 */
class PushKeyController
{
    private const PER_PAGE = 10;

    public function index(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $page    = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        $page    = max(1, $page);
        $offset  = ($page - 1) * self::PER_PAGE;

        $where  = '';
        $sqlParams = [];
        if ($keyword !== '') {
            $where  = ' WHERE name LIKE ? OR key_value LIKE ?';
            $sqlParams = ["%{$keyword}%", "%{$keyword}%"];
        }

        $list = Database::fetchAll(
            "SELECT id, key_value, name, max_devices, status, created_at, updated_at
             FROM push_keys{$where} ORDER BY id DESC LIMIT " . self::PER_PAGE . " OFFSET " . $offset,
            $sqlParams
        );

        // 尝试追加通知字段（如果表中存在）
        $list = $this->appendNotifyFields($list);

        // 追加订阅统计：总订阅数 + 当前在线订阅数（从 Redis 读取）
        try {
            $redis = \App\Service\Redis::getInstance();
            foreach ($list as &$row) {
                $keyValue = (string)($row['key_value'] ?? '');
                if ($keyValue !== '') {
                    // 订阅总数（key:subscribe:{} 集合的元素数，持久订阅关系）
                    $subscribedTotal = (int)$redis->sCard("key:subscribe:{$keyValue}");
                    $row['subscribed_total'] = $subscribedTotal;

                    // 在线订阅数：遍历订阅设备，检查每个 device_id 是否有在线 fd（ws:device:{} 集合）
                    $onlineCount = 0;
                    if ($subscribedTotal > 0) {
                        $deviceIds = $redis->sMembers("key:subscribe:{$keyValue}");
                        if (is_array($deviceIds)) {
                            foreach ($deviceIds as $deviceId) {
                                if ((int)$redis->sCard('ws:device:' . $deviceId) > 0) {
                                    $onlineCount++;
                                }
                            }
                        }
                    }
                    $row['online_count'] = $onlineCount;
                } else {
                    $row['subscribed_total'] = 0;
                    $row['online_count'] = 0;
                }
            }
            unset($row);
        } catch (\Throwable $e) {
            // Redis 异常时兜底给 0
            foreach ($list as &$row) {
                $row['subscribed_total'] = 0;
                $row['online_count'] = 0;
            }
            unset($row);
        }

        $total = (int)(Database::fetch(
            "SELECT COUNT(*) AS total FROM push_keys{$where}",
            $sqlParams
        )['total'] ?? 0);

        return [
            'list'        => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => self::PER_PAGE,
            'total_pages' => $total > 0 ? (int)ceil($total / self::PER_PAGE) : 0,
        ];
    }

    public function show(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = $this->fetchKeyById($id);

        if ($key === null) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return $key;
    }

    /**
     * 获取某个 Key 的订阅设备列表（含在线状态和设备详情）
     * 路由：GET /admin/keys/{id}/subscribers
     */
    public function subscribers(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = Database::fetch(
            'SELECT id, key_value, name FROM push_keys WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($key === false) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $keyValue = (string)($key['key_value'] ?? '');
        $redis = \App\Service\Redis::getInstance();

        // 从 key:subscribe 获取订阅的 device_id 集合
        $deviceIds = $redis->sMembers("key:subscribe:{$keyValue}");
        if (!is_array($deviceIds) || empty($deviceIds)) {
            return Response::success([
                'key_info' => $key,
                'list' => [],
                'total' => 0,
                'online_count' => 0,
            ]);
        }

        // 从 devices 表批量查询设备详情
        $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
        $dbDeviceRows = Database::fetchAll(
            "SELECT id, device_id, device_name, device_model, os_version, platform, app_version,
                    ip, status, last_connect_at, last_active_at, user_id, push_key_id
             FROM devices WHERE push_key_id = ? AND device_id IN ({$placeholders})",
            array_merge([$id], $deviceIds)
        );

        // 建立 device_id -> 行信息 的索引
        $deviceMap = [];
        foreach ($dbDeviceRows as $row) {
            $deviceMap[(string)$row['device_id']] = $row;
        }

        // 逐个组装返回
        $list = [];
        $onlineCount = 0;
        foreach ($deviceIds as $deviceId) {
            $deviceId = (string)$deviceId;
            $fdCount = (int)$redis->sCard('ws:device:' . $deviceId);
            $isOnline = $fdCount > 0;
            if ($isOnline) {
                $onlineCount++;
            }

            $dbRow = $deviceMap[$deviceId] ?? null;
            $list[] = [
                'device_id'     => $deviceId,
                'fd_count'      => $fdCount,
                'online'        => $isOnline ? 1 : 0,
                'exists_in_db'  => $dbRow !== null ? 1 : 0,  // 是否还存在于 devices 表（1=存在,0=僵尸订阅）
                'device_name'   => (string)($dbRow['device_name'] ?? ''),
                'device_model'  => (string)($dbRow['device_model'] ?? ''),
                'platform'      => (string)($dbRow['platform'] ?? ''),
                'os_version'    => (string)($dbRow['os_version'] ?? ''),
                'app_version'   => (string)($dbRow['app_version'] ?? ''),
                'ip'            => (string)($dbRow['ip'] ?? ''),
                'status'        => (int)($dbRow['status'] ?? 0),
                'last_connect_at'  => $dbRow['last_connect_at'] ?? null,
                'last_active_at'   => $dbRow['last_active_at'] ?? null,
                'user_id'       => (int)($dbRow['user_id'] ?? 0),
                'db_device_id'  => (int)($dbRow['id'] ?? 0),
            ];
        }

        // 在线的排前面，然后是僵尸订阅，最后是离线的
        usort($list, function ($a, $b) {
            if ($a['online'] !== $b['online']) return $b['online'] <=> $a['online'];
            if ($a['exists_in_db'] !== $b['exists_in_db']) return $b['exists_in_db'] <=> $a['exists_in_db'];
            return 0;
        });

        return Response::success([
            'key_info'     => $key,
            'list'         => $list,
            'total'        => count($list),
            'online_count' => $onlineCount,
        ]);
    }

    /**
     * 从某个 Key 中移除一个订阅设备（同时断开在线连接 + 清理订阅关系）
     * 路由：DELETE /admin/keys/{id}/subscribers/{device_id}
     */
    public function removeSubscriber(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        $deviceId = (string)($params['device_id'] ?? '');
        if ($id <= 0 || $deviceId === '') {
            Response::fail($context['response'], '参数错误', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = Database::fetch(
            'SELECT id, key_value, name FROM push_keys WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($key === false) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        $keyValue = (string)($key['key_value'] ?? '');

        $redis = \App\Service\Redis::getInstance();

        // 检查订阅关系
        if ((int)$redis->sIsMember("key:subscribe:{$keyValue}", $deviceId) === 0) {
            Response::fail($context['response'], '此设备未订阅该 Key', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        // 1. 清理订阅关系（双向）
        $redis->sRem("key:subscribe:{$keyValue}", $deviceId);
        $redis->hDel('device:key', $deviceId);

        // 2. 如果设备在线，踢下线（发送 disconnect 命令到 WebSocket 命令队列）
        $fds = $redis->sMembers('ws:device:' . $deviceId);
        $fdCount = is_array($fds) ? count($fds) : 0;
        if ($fdCount > 0) {
            $cmd = [
                'action'     => 'disconnect',
                'device_id'  => $deviceId,
                'fds'        => array_map('intval', $fds),
                'reason'     => 'removed from key subscribers',
                'created_at' => time(),
            ];
            $redis->lPush('ws:queue:cmd', json_encode($cmd, JSON_UNESCAPED_UNICODE));

            // 清理在线映射
            foreach ($fds as $fd) {
                $redis->sRem("ws:device:{$deviceId}", (string)$fd);
                $redis->hDel('ws:fd:device', (string)$fd);
                $redis->sRem('ws:online', (string)$fd);
                $redis->del("ws:conn:{$fd}");
            }
        }

        // 3. 将 devices 表中该设备标记为离线（避免下次启动还显示在线）
        try {
            Database::execute(
                'UPDATE devices SET last_active_at = NOW() WHERE push_key_id = ? AND device_id = ? LIMIT 1',
                [$id, $deviceId]
            );
        } catch (\Throwable $e) {
            // 忽略 devices 表异常（订阅关系已清理即可）
        }

        return Response::success([
            'removed'        => true,
            'disconnected'   => $fdCount > 0 ? $fdCount : 0,
        ], "已移除设备 {$deviceId}（断开连接 {$fdCount} 个）");
    }

    public function create(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $body = $this->parseBody($context);
        $name = (string)($body['name'] ?? $body['title'] ?? '');

        if ($name === '') {
            Response::fail($context['response'], '名称不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $keyValue = $this->generateKeyValue();

        // 兼容 camelCase 和 snake_case 字段名；未提供时默认 10（与表 DEFAULT 一致）
        $maxDevices = (int)($body['max_devices'] ?? $body['daily_limit'] ?? $body['dailyLimit'] ?? $body['maxDevices'] ?? 10);
        $notifyEmail = (string)($body['notify_email'] ?? $body['notifyEmail'] ?? '');
        $notifyEnabled = (int)($body['notify_enabled'] ?? $body['notifyEnabled'] ?? 0);
        $notifyInterval = (int)($body['notify_interval'] ?? $body['notifyInterval'] ?? 300);

        // 先插入基本字段（确保兼容旧表结构）
        $id = Database::insert(
            'INSERT INTO push_keys (key_value, name, max_devices, user_id, status)
             VALUES (?, ?, ?, ?, 1)',
            [$keyValue, $name, $maxDevices, (int)($admin['admin_id'] ?? 0)]
        );

        // 尝试更新通知字段（如果表中有这些列）
        try {
            Database::execute(
                'UPDATE push_keys SET notify_email = ?, notify_enabled = ?, notify_interval = ? WHERE id = ?',
                [$notifyEmail, $notifyEnabled, $notifyInterval, $id]
            );
        } catch (\Throwable $e) {
            // 表中可能没有通知字段，忽略错误
        }

        // 直接返回新创建的记录
        return $this->fetchKeyById((int)$id) ?: ['id' => $id, 'key_value' => $keyValue, 'name' => $name];
    }

    public function update(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = Database::fetch('SELECT id FROM push_keys WHERE id = ? LIMIT 1', [$id]);
        if ($key === false) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $body = $this->parseBody($context);
        $data = [];

        // 基本字段更新
        $basicData = [];
        if (isset($body['name'])) {
            $basicData['name'] = (string)$body['name'];
        } elseif (isset($body['title'])) {
            $basicData['name'] = (string)$body['title'];
        }
        if (isset($body['max_devices'])) {
            $basicData['max_devices'] = (int)$body['max_devices'];
        } elseif (isset($body['daily_limit'])) {
            $basicData['max_devices'] = (int)$body['daily_limit'];
        } elseif (isset($body['dailyLimit'])) {
            $basicData['max_devices'] = (int)$body['dailyLimit'];
        } elseif (isset($body['maxDevices'])) {
            $basicData['max_devices'] = (int)$body['maxDevices'];
        }
        if (isset($body['status'])) {
            $basicData['status'] = (int)$body['status'];
        }

        if (!empty($basicData)) {
            $basicData['updated_at'] = date('Y-m-d H:i:s');
            $columns = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($basicData)));
            $values = array_values($basicData);
            $values[] = $id;
            Database::execute("UPDATE push_keys SET {$columns} WHERE id = ?", $values);
        }

        // 通知字段更新（单独处理，兼容表中无这些列的情况）
        $notifyData = [];
        if (isset($body['notify_email'])) {
            $notifyData['notify_email'] = (string)$body['notify_email'];
        } elseif (isset($body['notifyEmail'])) {
            $notifyData['notify_email'] = (string)$body['notifyEmail'];
        }
        if (isset($body['notify_enabled'])) {
            $notifyData['notify_enabled'] = (int)$body['notify_enabled'];
        } elseif (isset($body['notifyEnabled'])) {
            $notifyData['notify_enabled'] = (int)$body['notifyEnabled'];
        }
        if (isset($body['notify_interval'])) {
            $notifyData['notify_interval'] = (int)$body['notify_interval'];
        } elseif (isset($body['notifyInterval'])) {
            $notifyData['notify_interval'] = (int)$body['notifyInterval'];
        }

        if (!empty($notifyData)) {
            $notifyData['updated_at'] = date('Y-m-d H:i:s');
            $columns = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($notifyData)));
            $values = array_values($notifyData);
            $values[] = $id;
            try {
                Database::execute("UPDATE push_keys SET {$columns} WHERE id = ?", $values);
            } catch (\Throwable $e) {
                // 表中可能没有通知字段，忽略
            }
        }

        // 直接返回更新后的记录
        return $this->fetchKeyById($id) ?: ['id' => $id];
    }

    public function delete(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = Database::fetch('SELECT id FROM push_keys WHERE id = ? LIMIT 1', [$id]);
        if ($key === false) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        Database::execute('DELETE FROM push_keys WHERE id = ?', [$id]);

        return ['deleted' => true];
    }

    public function updateStatus(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $body = $this->parseBody($context);
        $status = (int)($body['status'] ?? 0);

        Database::execute('UPDATE push_keys SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);

        return ['id' => $id, 'status' => $status];
    }

    private function generateKeyValue(): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $key = '';
        for ($i = 0; $i < 32; $i++) {
            $key .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $key;
    }

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
     * 查询单条 Key 记录（兼容表中无通知字段的情况）
     */
    private function fetchKeyById(int $id): ?array
    {
        $row = Database::fetch(
            'SELECT id, key_value, name, max_devices, status, created_at, updated_at
             FROM push_keys WHERE id = ? LIMIT 1',
            [$id]
        );

        if ($row === false) {
            return null;
        }

        // 尝试追加通知字段
        $rows = $this->appendNotifyFields([$row]);
        return $rows[0] ?? $row;
    }

    /**
     * 为列表追加通知字段（notify_email, notify_enabled, notify_interval）
     * 如果表中不存在这些列，则填充默认值
     */
    private function appendNotifyFields(array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $ids = array_column($rows, 'id');
        if (empty($ids)) {
            return $rows;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            $notifyRows = Database::fetchAll(
                "SELECT id, notify_email, notify_enabled, notify_interval
                 FROM push_keys WHERE id IN ({$placeholders})",
                $ids
            );

            if (!empty($notifyRows)) {
                $notifyMap = [];
                foreach ($notifyRows as $nr) {
                    $notifyMap[$nr['id']] = $nr;
                }
                foreach ($rows as &$row) {
                    $nr = $notifyMap[$row['id']] ?? null;
                    $row['notify_email']     = $nr['notify_email'] ?? '';
                    $row['notify_enabled']   = $nr['notify_enabled'] ?? 0;
                    $row['notify_interval']  = $nr['notify_interval'] ?? 300;
                }
                unset($row);
            } else {
                foreach ($rows as &$row) {
                    $row['notify_email']     = '';
                    $row['notify_enabled']   = 0;
                    $row['notify_interval']  = 300;
                }
                unset($row);
            }
        } catch (\Throwable $e) {
            // 表中可能没有通知字段，填充默认值
            foreach ($rows as &$row) {
                $row['notify_email']     = '';
                $row['notify_enabled']   = 0;
                $row['notify_interval']  = 300;
            }
            unset($row);
        }

        return $rows;
    }
}