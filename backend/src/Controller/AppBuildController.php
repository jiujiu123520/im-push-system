<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Jwt;
use App\Service\Response;
use BuildServer\BuildQueue;

// 引入打包队列服务（位于 build/queue 目录）
require_once dirname(__DIR__, 3) . '/build/queue/BuildQueue.php';

/**
 * APP 打包控制器
 *
 * 提供管理后台触发 APK 打包的 HTTP 接口，需要管理员鉴权。
 *
 * 路由：
 *   POST /admin/app-build                      提交打包任务
 *   GET  /admin/app-build/list                 构建历史列表（分页10条）
 *   GET  /admin/app-build/status/{build_id}    查询构建状态
 *   GET  /admin/app-build/log/{build_id}       获取构建日志
 *   GET  /admin/app-build/download/{build_id} 下载 APK
 */
class AppBuildController
{
    /**
     * 管理员鉴权
     *
     * 从请求头/查询参数提取 JWT 并校验，返回管理员载荷。
     * 失败时直接输出错误响应并返回 null。
     *
     * 注意：当前仅要求 token 合法且包含 admin_id / uid 标识，
     * 待管理员角色体系完善后，应补充 role === 'admin' 的严格校验。
     *
     * @param array $context
     * @return array|null 管理员载荷；鉴权失败返回 null（已自行输出响应）
     */
    private function authenticate(array $context): ?array
    {
        $response = $context['response'];

        // 提取 token：优先 Authorization 头，其次查询参数
        $authHeader = $context['header']['authorization']
            ?? $context['server']['HTTP_AUTHORIZATION']
            ?? $context['server']['http_authorization']
            ?? '';
        $token = null;
        if (preg_match('/Bearer\s+(.+)/i', (string)$authHeader, $matches)) {
            $token = trim($matches[1]);
        }
        if (!$token) {
            $token = $context['get']['token'] ?? null;
        }

        if (!$token) {
            Response::fail($response, '未提供授权令牌', Response::CODE_UNAUTHORIZED, 401);
            return null;
        }

        try {
            $payload = Jwt::verify($token);
        } catch (\Throwable $e) {
            Response::fail($response, '授权令牌无效或已过期：' . $e->getMessage(), Response::CODE_UNAUTHORIZED, 401);
            return null;
        }

        // 校验令牌类型必须为管理员令牌
        $type = $payload['type'] ?? '';
        if ($type !== 'admin') {
            Response::fail($response, '无权限访问该资源：需要管理员令牌', Response::CODE_FORBIDDEN, 403);
            return null;
        }

        // 校验是否为管理员标识
        $adminId = $payload['admin_id'] ?? null;
        if (!$adminId) {
            Response::fail($response, '无权限访问该资源', Response::CODE_FORBIDDEN, 403);
            return null;
        }

        return $payload;
    }

    /**
     * 解析请求体（支持 JSON 与表单）
     *
     * @param array $context
     * @return array
     */
    private function parseBody(array $context): array
    {
        $data = $context['post'] ?? [];
        if (!empty($data)) {
            return $data;
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
     * POST /admin/app-build
     * 提交打包任务
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function submit(array $context, array $params)
    {
        if (!$this->authenticate($context)) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        // 参数校验
        $appName = trim((string)($data['app_name'] ?? ''));
        if ($appName === '') {
            Response::fail($response, '应用名称（app_name）不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        $config = [
            'app_name'    => $appName,
            'default_key' => (string)($data['default_key'] ?? 'default_key'),
            'server_url'  => (string)($data['server_url'] ?? ''),
            'ws_url'      => (string)($data['ws_url'] ?? ''),
            'icon_path'   => (string)($data['icon_path'] ?? ''),
            'admin_id'    => (int)($data['admin_id'] ?? 0),
        ];

        try {
            $buildId = BuildQueue::submitBuild($config);
        } catch (\Throwable $e) {
            Response::fail($response, '提交打包任务失败：' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }

        return [
            'build_id'   => $buildId,
            'status'     => 'pending',
            'message'    => '打包任务已提交，请稍后查询构建状态',
            'query_url'  => '/admin/app-build/status/' . $buildId,
        ];
    }

    /**
     * GET /admin/app-build/list
     * 构建历史列表（分页10条）
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function list(array $context, array $params)
    {
        if (!$this->authenticate($context)) {
            return false;
        }

        $response = $context['response'];
        $page = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        try {
            $result = BuildQueue::getBuildList($page, $keyword);
        } catch (\Throwable $e) {
            Response::fail($response, '获取构建列表失败：' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }

        return $result;
    }

    /**
     * GET /admin/app-build/status/{build_id}
     * 查询构建状态
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function status(array $context, array $params)
    {
        if (!$this->authenticate($context)) {
            return false;
        }

        $response = $context['response'];
        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        $task = BuildQueue::getBuildStatus($buildId);
        if (!$task) {
            Response::fail($response, '构建任务不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return $task;
    }

    /**
     * GET /admin/app-build/log/{build_id}
     * 获取构建日志
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function log(array $context, array $params)
    {
        if (!$this->authenticate($context)) {
            return false;
        }

        $response = $context['response'];
        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 校验任务是否存在
        $task = BuildQueue::getBuildStatus($buildId);
        if (!$task) {
            Response::fail($response, '构建任务不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $log = BuildQueue::getBuildLog($buildId);

        return [
            'build_id' => $buildId,
            'log'      => $log,
        ];
    }

    /**
     * GET /admin/app-build/download/{build_id}
     * 下载 APK
     *
     * @param array $context
     * @param array $params
     * @return false
     */
    public function download(array $context, array $params)
    {
        if (!$this->authenticate($context)) {
            return false;
        }

        $response = $context['response'];
        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        $task = BuildQueue::getBuildStatus($buildId);
        if (!$task) {
            Response::fail($response, '构建任务不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        if (($task['status'] ?? '') !== 'success') {
            Response::fail($response, '构建未成功，无法下载', Response::CODE_BAD_REQUEST);
            return false;
        }

        $apkPath = (string)($task['apk_path'] ?? '');
        if ($apkPath === '' || !is_file($apkPath)) {
            Response::fail($response, 'APK 文件不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        // 设置下载响应头并通过 Swoole sendfile 流式输出
        $filename = basename($apkPath);
        $response->status(200);
        $response->header('Content-Type', 'application/vnd.android.package-archive');
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->header('Content-Length', (string)filesize($apkPath));
        $response->header('Access-Control-Allow-Origin', '*');
        $response->sendfile($apkPath);

        return false;
    }
}
