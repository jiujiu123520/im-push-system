<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\BlacklistService;
use App\Service\PushDispatcher;
use App\Service\Response;

/**
 * 黑名单控制器（需管理员鉴权）
 *
 * 路由：
 *   GET    /admin/blacklist          列表（分页10条）
 *   POST   /admin/blacklist          添加拉黑（加入后立即断开在线连接）
 *   DELETE /admin/blacklist/{id}     解黑
 */
class BlacklistController
{
    /**
     * 黑名单列表
     * 路由：GET /admin/blacklist
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function index(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $page    = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        $service = new BlacklistService();
        return $service->list($page, $keyword);
    }

    /**
     * 添加拉黑
     * 路由：POST /admin/blacklist
     *
     * 加入黑名单后立即通过 Redis 队列通知 WebSocket 进程断开对应在线连接。
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function create(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $body   = $this->parseBody($context);
        $type   = (string)($body['type'] ?? '');
        $value  = (string)($body['value'] ?? '');
        $reason = (string)($body['reason'] ?? '');

        // 参数校验
        if (!in_array($type, ['device_id', 'ip', 'fingerprint'], true)) {
            Response::fail($context['response'], 'type 必须为 device_id / ip / fingerprint', Response::CODE_BAD_REQUEST, 400);
            return false;
        }
        if ($value === '') {
            Response::fail($context['response'], 'value 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $adminId = (int)($admin['admin_id'] ?? 0);

        $service = new BlacklistService();
        $id = $service->add($type, $value, $reason, $adminId);

        // 通过 Redis 队列通知 WebSocket 进程断开对应连接
        $dispatcher = new PushDispatcher();
        $dispatcher->enqueueDisconnect($type, $value);

        return [
            'id'      => $id,
            'type'    => $type,
            'value'   => $value,
            'reason'  => $reason,
            'message' => '已加入黑名单，在线连接将被断开',
        ];
    }

    /**
     * 解黑
     * 路由：DELETE /admin/blacklist/{id}
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
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

        $service = new BlacklistService();
        $existing = $service->getById($id);
        if ($existing === null) {
            Response::fail($context['response'], '黑名单记录不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $service->remove($id);

        return ['deleted' => true];
    }

    /**
     * 解析请求体
     *
     * @param array $context
     * @return array
     */
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
