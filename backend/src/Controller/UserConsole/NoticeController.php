<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Service\UserNoticeService;

/**
 * 用户端公告
 *
 * 路由前缀：/user-api/notices
 */
class NoticeController extends BaseUserController
{
    public function index(array $context, array $params)
    {
        // 登录用户和匿名都能看（首页展示需要匿名可访问）
        [$page, $perPage, $offset, $keyword] = $this->parsePage($context, 20);
        $type = (int)($context['get']['type'] ?? 0);
        $onlyShowHome = (int)($context['get']['show_home'] ?? 0);
        $srv = new UserNoticeService();

        $userId = null;
        $payload = $this->auth($context);
        if ($payload !== null) {
            $userId = (int)($context['user_id'] ?? 0);
        }

        return $srv->listPublished($page, $perPage, [
            'keyword'   => $keyword,
            'type'      => $type,
            'show_home' => $onlyShowHome,
        ], $userId);
    }

    public function dialogs(array $context, array $params)
    {
        $userId = null;
        $payload = $this->auth($context);
        if ($payload !== null) {
            $userId = (int)($context['user_id'] ?? 0);
        }
        $srv = new UserNoticeService();
        return $srv->getDialogNotices($userId);
    }

    public function show(array $context, array $params)
    {
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return $this->fail($context, '无效的公告ID');
        $srv = new UserNoticeService();

        $userId = null;
        $payload = $this->auth($context);
        if ($payload !== null) {
            $userId = (int)($context['user_id'] ?? 0);
        }

        $row = $srv->getPublished($id, $userId);
        if ($row === null) {
            return $this->fail($context, '公告不存在或未发布', 404, 404);
        }
        return $row;
    }

    public function markRead(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $body = $this->parseBody($context);
        $ids = $body['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            $id = (int)($params['id'] ?? 0);
            if ($id > 0) $ids = [$id];
        }
        if (empty($ids)) return $this->fail($context, 'ids 不能为空');
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, fn($v) => (int)$v > 0))));

        $srv = new UserNoticeService();
        $srv->markRead($userId, $ids);
        return ['marked' => true, 'count' => count($ids)];
    }

    public function markAllRead(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $srv = new UserNoticeService();
        $srv->markAllRead($userId);
        return ['marked' => true];
    }
}
