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