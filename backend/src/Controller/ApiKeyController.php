<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\ApiKeyService;
use App\Service\Response;

/**
 * API Key 管理控制器（需管理员鉴权）
 *
 * 路由：
 *   GET    /admin/api-keys          列表（分页10条，支持搜索）
 *   POST   /admin/api-keys          创建
 *   PUT    /admin/api-keys/{id}     更新
 *   DELETE /admin/api-keys/{id}     删除
 */
class ApiKeyController
{
    /**
     * 列表
     * 路由：GET /admin/api-keys
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

        $service = new ApiKeyService();
        return $service->list($page, $keyword);
    }

    /**
     * 创建
     * 路由：POST /admin/api-keys
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

        $body = $this->parseBody($context);
        $name = (string)($body['name'] ?? '');
        $expireAt = $body['expire_at'] ?? $body['expiresAt'] ?? null;

        if ($name === '') {
            Response::fail($context['response'], 'name 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $service = new ApiKeyService();
        $key = $service->generateKey($name, $expireAt);

        return $key;
    }

    /**
     * 更新
     * 路由：PUT /admin/api-keys/{id}
     *
     * @param array $context
     * @param array $params
     * @return array|false
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

        $service = new ApiKeyService();
        $existing = $service->getById($id);
        if ($existing === null) {
            Response::fail($context['response'], 'API Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $body = $this->parseBody($context);
        $data = [];

        if (isset($body['name'])) {
            $data['name'] = (string)$body['name'];
        }
        if (isset($body['status'])) {
            $data['status'] = (int)$body['status'];
        }
        if (array_key_exists('expire_at', $body)) {
            $data['expire_at'] = $body['expire_at'];
        }
        if (array_key_exists('expiresAt', $body) && !array_key_exists('expire_at', $body)) {
            $data['expire_at'] = $body['expiresAt'];
        }

        $service->update($id, $data);

        return $service->getById($id);
    }

    /**
     * 删除
     * 路由：DELETE /admin/api-keys/{id}
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

        $service = new ApiKeyService();
        $existing = $service->getById($id);
        if ($existing === null) {
            Response::fail($context['response'], 'API Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $service->delete($id);

        return ['deleted' => true];
    }

    /**
     * 切换状态
     * 路由：PUT /admin/api-keys/{id}/status
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
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

        $service = new ApiKeyService();
        $existing = $service->getById($id);
        if ($existing === null) {
            Response::fail($context['response'], 'API Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $body = $this->parseBody($context);
        $status = (int)($body['status'] ?? 0);

        $service->update($id, ['status' => $status]);

        return $service->getById($id);
    }

    /**
     * 详情
     * 路由：GET /admin/api-keys/{id}
     *
     * @param array $context
     * @param array $params
     * @return array|false
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

        $service = new ApiKeyService();
        $existing = $service->getById($id);
        if ($existing === null) {
            Response::fail($context['response'], 'API Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return $existing;
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
