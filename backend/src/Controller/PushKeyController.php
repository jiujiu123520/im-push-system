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
            "SELECT id, key_value, name, max_devices, status,
                    notify_email, notify_enabled, notify_interval,
                    created_at, updated_at
             FROM push_keys{$where} ORDER BY id DESC LIMIT " . self::PER_PAGE . " OFFSET " . $offset,
            $sqlParams
        );

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

        $key = Database::fetch(
            'SELECT id, key_value, name, max_devices, status,
                    notify_email, notify_enabled, notify_interval,
                    created_at, updated_at
             FROM push_keys WHERE id = ? LIMIT 1',
            [$id]
        );

        if ($key === false) {
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

        // 兼容 camelCase 和 snake_case 字段名
        $maxDevices = (int)($body['max_devices'] ?? $body['daily_limit'] ?? $body['dailyLimit'] ?? $body['maxDevices'] ?? 0);
        $notifyEmail = (string)($body['notify_email'] ?? $body['notifyEmail'] ?? '');
        $notifyEnabled = (int)($body['notify_enabled'] ?? $body['notifyEnabled'] ?? 0);
        $notifyInterval = (int)($body['notify_interval'] ?? $body['notifyInterval'] ?? 300);

        $id = Database::insert(
            'INSERT INTO push_keys (key_value, name, max_devices, user_id, status,
                                    notify_email, notify_enabled, notify_interval)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
            [$keyValue, $name, $maxDevices, (int)($admin['admin_id'] ?? 0), $notifyEmail, $notifyEnabled, $notifyInterval]
        );

        // 直接返回新创建的记录，避免重复鉴权
        return Database::fetch(
            'SELECT id, key_value, name, max_devices, status,
                    notify_email, notify_enabled, notify_interval,
                    created_at, updated_at
             FROM push_keys WHERE id = ? LIMIT 1',
            [$id]
        ) ?: ['id' => $id, 'key_value' => $keyValue, 'name' => $name];
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

        // 兼容 name 和 title 两种字段名
        if (isset($body['name'])) {
            $data['name'] = (string)$body['name'];
        } elseif (isset($body['title'])) {
            $data['name'] = (string)$body['title'];
        }
        if (isset($body['max_devices'])) {
            $data['max_devices'] = (int)$body['max_devices'];
        } elseif (isset($body['daily_limit'])) {
            $data['max_devices'] = (int)$body['daily_limit'];
        } elseif (isset($body['dailyLimit'])) {
            $data['max_devices'] = (int)$body['dailyLimit'];
        } elseif (isset($body['maxDevices'])) {
            $data['max_devices'] = (int)$body['maxDevices'];
        }
        if (isset($body['status'])) {
            $data['status'] = (int)$body['status'];
        }
        if (isset($body['notify_email'])) {
            $data['notify_email'] = (string)$body['notify_email'];
        } elseif (isset($body['notifyEmail'])) {
            $data['notify_email'] = (string)$body['notifyEmail'];
        }
        if (isset($body['notify_enabled'])) {
            $data['notify_enabled'] = (int)$body['notify_enabled'];
        } elseif (isset($body['notifyEnabled'])) {
            $data['notify_enabled'] = (int)$body['notifyEnabled'];
        }
        if (isset($body['notify_interval'])) {
            $data['notify_interval'] = (int)$body['notify_interval'];
        } elseif (isset($body['notifyInterval'])) {
            $data['notify_interval'] = (int)$body['notifyInterval'];
        }

        if (!empty($data)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
            $columns = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
            $values = array_values($data);
            $values[] = $id;
            Database::execute("UPDATE push_keys SET {$columns} WHERE id = ?", $values);
        }

        // 直接返回更新后的记录，避免重复鉴权
        return Database::fetch(
            'SELECT id, key_value, name, max_devices, status,
                    notify_email, notify_enabled, notify_interval,
                    created_at, updated_at
             FROM push_keys WHERE id = ? LIMIT 1',
            [$id]
        ) ?: ['id' => $id];
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
}