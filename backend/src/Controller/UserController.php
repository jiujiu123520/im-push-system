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
