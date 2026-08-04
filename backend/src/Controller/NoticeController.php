<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Database;
use App\Service\Response;
use App\Service\UserNoticeService;

/**
 * 管理端用户公告控制器（管理员鉴权）
 *
 * 路由：
 *   GET    /admin/user-notices                 列表（含草稿）
 *   POST   /admin/user-notices                 创建公告
 *   GET    /admin/user-notices/{id}            详情
 *   PUT    /admin/user-notices/{id}            更新
 *   DELETE /admin/user-notices/{id}            删除
 *   POST   /admin/user-notices/{id}/publish    发布
 *   POST   /admin/user-notices/{id}/withdraw   撤回
 *   PUT    /admin/user-notices/{id}/sticky     置顶/取消置顶（{is_sticky:0|1}）
 */
class NoticeController
{
    private const PER_PAGE = 20;

    /**
     * 列表（GET /admin/user-notices）
     */
    public function index(array $context, array $params): array
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return [];
        }

        $request  = $context['request'];
        $query    = $request->get ?? [];
        $page     = max(1, (int)($query['page'] ?? 1));
        $perPage  = max(1, min(200, (int)($query['pageSize'] ?? $query['per_page'] ?? self::PER_PAGE)));

        $filters = [];
        if (!empty($query['keyword'])) $filters['keyword'] = $query['keyword'];
        if (isset($query['status']) && $query['status'] !== '' && $query['status'] !== null) {
            $filters['status'] = (int)$query['status'];
        }
        if (!empty($query['type'])) $filters['type'] = (int)$query['type'];

        $svc = new UserNoticeService();
        return $svc->adminList($page, $perPage, $filters);
    }

    /**
     * 详情（GET /admin/user-notices/{id}）
     */
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
        $row = Database::fetch('SELECT * FROM user_notices WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($context['response'], '公告不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        return $row;
    }

    /**
     * 创建公告（POST /admin/user-notices）
     */
    public function store(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }
        $body = $context['parsed_body'] ?? [];
        if (empty($body)) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }

        $adminId = (int)($admin['admin_id'] ?? 0);
        try {
            $svc = new UserNoticeService();
            $id = $svc->adminCreate($body, $adminId);
            return ['id' => (int)$id, 'message' => '创建成功'];
        } catch (\InvalidArgumentException $e) {
            Response::fail($context['response'], $e->getMessage(), Response::CODE_BAD_REQUEST, 400);
            return false;
        }
    }

    /**
     * 更新公告（PUT /admin/user-notices/{id}）
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
        $body = $context['parsed_body'] ?? [];
        if (empty($body)) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }

        $adminId = (int)($admin['admin_id'] ?? 0);
        $svc = new UserNoticeService();
        $ok = $svc->adminUpdate($id, $body, $adminId);
        if (!$ok) {
            Response::fail($context['response'], '公告不存在或更新失败', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        return ['message' => '更新成功'];
    }

    /**
     * 删除公告（DELETE /admin/user-notices/{id}）
     */
    public function destroy(array $context, array $params)
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
        $svc = new UserNoticeService();
        $svc->adminDelete($id);
        return ['message' => '删除成功'];
    }

    /**
     * 发布公告（POST /admin/user-notices/{id}/publish）
     */
    public function publish(array $context, array $params)
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
        $row = Database::fetch('SELECT id, status FROM user_notices WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($context['response'], '公告不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        $now = date('Y-m-d H:i:s');
        Database::execute(
            'UPDATE user_notices SET status = 1, publish_at = COALESCE(publish_at, ?), updated_at = ? WHERE id = ?',
            [$now, $now, $id]
        );
        return ['message' => '已发布'];
    }

    /**
     * 撤回公告（POST /admin/user-notices/{id}/withdraw）
     */
    public function withdraw(array $context, array $params)
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
        $row = Database::fetch('SELECT id FROM user_notices WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($context['response'], '公告不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        Database::execute(
            'UPDATE user_notices SET status = 0, updated_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $id]
        );
        return ['message' => '已撤回'];
    }

    /**
     * 置顶/取消置顶（PUT /admin/user-notices/{id}/sticky）
     * Body: {is_sticky: 0|1}
     */
    public function toggleSticky(array $context, array $params)
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
        $body = $context['parsed_body'] ?? [];
        if (empty($body)) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $body = $decoded;
        }
        $isSticky = (int)($body['is_sticky'] ?? 0);
        if (!in_array($isSticky, [0, 1], true)) $isSticky = 0;

        $row = Database::fetch('SELECT id FROM user_notices WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($context['response'], '公告不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        Database::execute(
            'UPDATE user_notices SET is_sticky = ?, updated_at = ? WHERE id = ?',
            [$isSticky, date('Y-m-d H:i:s'), $id]
        );
        return ['message' => $isSticky === 1 ? '已置顶' : '已取消置顶'];
    }
}
