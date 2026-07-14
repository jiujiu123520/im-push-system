<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Database;
use App\Service\Response;

/**
 * 用户管理控制器（需管理员鉴权）
 *
 * 路由：
 *   GET    /admin/users              列表（分页10条，支持搜索）
 *   GET    /admin/users/{id}         用户详情
 *   PUT    /admin/users/{id}         更新用户信息（用户名/手机号/邮箱/状态）
 *   PUT    /admin/users/{id}/password  管理员重置用户密码
 *   DELETE /admin/users/{id}         删除单个用户
 *   DELETE /admin/users              清空所有用户（危险操作）
 *   PUT    /admin/users/{id}/status  切换用户状态
 */
class UserController
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
            $where  = ' WHERE username LIKE ? OR phone LIKE ? OR email LIKE ?';
            $sqlParams = ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"];
        }

        $list = Database::fetchAll(
            "SELECT id, username, phone, email, status, created_at, updated_at
             FROM users{$where} ORDER BY id DESC LIMIT " . self::PER_PAGE . " OFFSET " . $offset,
            $sqlParams
        );

        $total = (int)(Database::fetch(
            "SELECT COUNT(*) AS total FROM users{$where}",
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

        $user = Database::fetch(
            'SELECT id, username, phone, email, status, created_at, updated_at
             FROM users WHERE id = ? LIMIT 1',
            [$id]
        );

        if ($user === false) {
            Response::fail($context['response'], '用户不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return $user;
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

        $user = Database::fetch('SELECT id FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($user === false) {
            Response::fail($context['response'], '用户不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        Database::execute('DELETE FROM users WHERE id = ?', [$id]);

        return ['deleted' => true];
    }

    public function clearAll(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        // 先统计当前总数
        $countRow = Database::fetch('SELECT COUNT(*) AS total FROM users');
        $count = (int)($countRow['total'] ?? 0);

        Database::execute('DELETE FROM users WHERE id > 0');

        return ['cleared' => true, 'count' => $count];
    }

    public function toggleStatus(array $context, array $params)
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

        Database::execute('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);

        return ['id' => $id, 'status' => $status];
    }

    /**
     * 更新用户信息（用户名/手机号/邮箱/状态）
     * PUT /admin/users/{id}
     * Body: { username?, phone?, email?, status? }
     */
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

        $user = Database::fetch('SELECT id, username, phone, email, status FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($user === false) {
            Response::fail($context['response'], '用户不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $body = $this->parseBody($context);
        $fields = [];
        $sqlParams = [];

        // 用户名
        if (isset($body['username']) && $body['username'] !== '') {
            $newUsername = (string)$body['username'];
            if (strlen($newUsername) < 3 || strlen($newUsername) > 64) {
                Response::fail($context['response'], '用户名长度需在 3-64 之间', Response::CODE_BAD_REQUEST, 400);
                return false;
            }
            if ($newUsername !== $user['username']) {
                $exist = Database::fetch('SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1', [$newUsername, $id]);
                if ($exist !== false) {
                    Response::fail($context['response'], '用户名已被占用', Response::CODE_ERROR);
                    return false;
                }
            }
            $fields[] = 'username = ?';
            $sqlParams[] = $newUsername;
        }

        // 手机号
        if (isset($body['phone']) && $body['phone'] !== '') {
            $newPhone = (string)$body['phone'];
            if (!preg_match('/^1[3-9]\d{9}$/', $newPhone)) {
                Response::fail($context['response'], '手机号格式不正确', Response::CODE_BAD_REQUEST, 400);
                return false;
            }
            if ($newPhone !== $user['phone']) {
                $exist = Database::fetch('SELECT id FROM users WHERE phone = ? AND id != ? LIMIT 1', [$newPhone, $id]);
                if ($exist !== false) {
                    Response::fail($context['response'], '手机号已被占用', Response::CODE_ERROR);
                    return false;
                }
            }
            $fields[] = 'phone = ?';
            $sqlParams[] = $newPhone;
        }

        // 邮箱
        if (isset($body['email']) && $body['email'] !== '') {
            $newEmail = (string)$body['email'];
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                Response::fail($context['response'], '邮箱格式不正确', Response::CODE_BAD_REQUEST, 400);
                return false;
            }
            if ($newEmail !== $user['email']) {
                $exist = Database::fetch('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1', [$newEmail, $id]);
                if ($exist !== false) {
                    Response::fail($context['response'], '邮箱已被占用', Response::CODE_ERROR);
                    return false;
                }
            }
            $fields[] = 'email = ?';
            $sqlParams[] = $newEmail;
        }

        // 状态
        if (isset($body['status'])) {
            $status = (int)$body['status'];
            if (!in_array($status, [0, 1], true)) {
                Response::fail($context['response'], '状态值无效', Response::CODE_BAD_REQUEST, 400);
                return false;
            }
            $fields[] = 'status = ?';
            $sqlParams[] = $status;
        }

        if (empty($fields)) {
            Response::fail($context['response'], '没有需要更新的字段', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $fields[] = 'updated_at = NOW()';
        $sqlParams[] = $id;

        Database::execute('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $sqlParams);

        return ['id' => $id, 'message' => '更新成功'];
    }

    /**
     * 管理员重置用户密码
     * PUT /admin/users/{id}/password
     * Body: { password: "新密码" }
     */
    public function resetPassword(array $context, array $params)
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

        $user = Database::fetch('SELECT id FROM users WHERE id = ? LIMIT 1', [$id]);
        if ($user === false) {
            Response::fail($context['response'], '用户不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $body = $this->parseBody($context);
        $password = (string)($body['password'] ?? '');

        if (strlen($password) < 6 || strlen($password) > 64) {
            Response::fail($context['response'], '密码长度需在 6-64 之间', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($hash === false) {
            Response::fail($context['response'], '密码加密失败', Response::CODE_INTERNAL, 500);
            return false;
        }

        Database::execute(
            'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
            [$hash, $id]
        );

        return ['id' => $id, 'message' => '密码重置成功'];
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
