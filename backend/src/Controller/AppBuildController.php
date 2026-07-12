<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Response;

/**
 * APP 打包控制器
 *
 * 提供管理后台触发 APK 打包的 HTTP 接口，需要管理员鉴权。
 *
 * 注意：打包服务（BuildQueue）依赖独立的构建环境（Android SDK + Gradle），
 * 当 build/queue/BuildQueue.php 不存在时，所有接口返回“打包服务未配置”。
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
    /** @var bool|null BuildQueue 是否可用（懒加载） */
    private static $available = null;

    /**
     * 检查打包服务是否可用
     *
     * @return bool
     */
    private static function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        $path = dirname(__DIR__, 3) . '/build/queue/BuildQueue.php';
        if (!is_file($path)) {
            self::$available = false;
            return false;
        }
        try {
            require_once $path;
            self::$available = true;
        } catch (\Throwable $e) {
            self::$available = false;
        }
        return self::$available;
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
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        if (!self::isAvailable()) {
            Response::fail($response, '打包服务未配置：缺少 build/queue/BuildQueue.php', Response::CODE_ERROR, 503);
            return false;
        }

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
            'admin_id'    => (int)($payload['admin_id'] ?? 0),
        ];

        try {
            $buildId = \BuildServer\BuildQueue::submitBuild($config);
        } catch (\Throwable $e) {
            Response::fail($response, '提交打包任务失败：' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }

        return [
            'build_id'  => $buildId,
            'status'    => 'pending',
            'message'   => '打包任务已提交，请稍后查询构建状态',
            'query_url' => '/admin/app-build/status/' . $buildId,
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
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        if (!self::isAvailable()) {
            Response::fail($response, '打包服务未配置', Response::CODE_ERROR, 503);
            return false;
        }

        $page = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        try {
            return \BuildServer\BuildQueue::getBuildList($page, $keyword);
        } catch (\Throwable $e) {
            Response::fail($response, '获取构建列表失败：' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }
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
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        if (!self::isAvailable()) {
            Response::fail($response, '打包服务未配置', Response::CODE_ERROR, 503);
            return false;
        }

        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        $task = \BuildServer\BuildQueue::getBuildStatus($buildId);
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
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        if (!self::isAvailable()) {
            Response::fail($response, '打包服务未配置', Response::CODE_ERROR, 503);
            return false;
        }

        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        $task = \BuildServer\BuildQueue::getBuildStatus($buildId);
        if (!$task) {
            Response::fail($response, '构建任务不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return [
            'build_id' => $buildId,
            'log'      => \BuildServer\BuildQueue::getBuildLog($buildId),
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
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        if (!self::isAvailable()) {
            Response::fail($response, '打包服务未配置', Response::CODE_ERROR, 503);
            return false;
        }

        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        $task = \BuildServer\BuildQueue::getBuildStatus($buildId);
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
