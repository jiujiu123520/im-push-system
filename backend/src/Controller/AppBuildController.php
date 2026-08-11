<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\ApkDistributionService;
use App\Service\Config;
use App\Service\GitHubActionsService;
use App\Service\Redis;
use App\Service\Response;

/**
 * APP 打包控制器
 *
 * 提供管理后台触发 APK 打包的 HTTP 接口，需要管理员鉴权。
 *
 * 注意：打包服务（BuildQueue）依赖独立的构建环境（Android SDK + Gradle），
 * 当 build/queue/BuildQueue.php 不存在时，所有接口返回"打包服务未配置"。
 *
 * 路由：
 *   POST /admin/app-build                      提交打包任务
 *   GET  /admin/app-build/list                 构建历史列表（分页10条）
 *   GET  /admin/app-build/status/{build_id}    查询构建状态
 *   GET  /admin/app-build/log/{build_id}       获取构建日志
 *   GET  /admin/app-build/download/{build_id}  下载 APK
 *   GET  /admin/app-build/random-config        生成随机配置（包名、APP名称）
 *   GET  /admin/app-build/generate-icon        生成图标（首字+渐变色）
 */
class AppBuildController
{
    /** @var bool|null BuildQueue 是否可用（懒加载） */
    private static $available = null;

    /**
     * 检查打包服务是否可用(GitHub Actions 配置是否完整)
     *
     * @return bool
     */
    private static function isAvailable(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }
        try {
            $config = Config::get('github') ?? [];
            $token = $config['token'] ?? '';
            $owner = $config['owner'] ?? '';
            $repo = $config['repo'] ?? '';
            self::$available = !empty($token) && !empty($owner) && !empty($repo);
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
     * 提交打包任务(通过 GitHub Actions 构建)
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
            Response::fail($response, '打包服务未配置：缺少 GitHub Actions 配置(GITHUB_TOKEN/GITHUB_OWNER/GITHUB_REPO)', Response::CODE_ERROR, 503);
            return false;
        }

        $data = $this->parseBody($context);

        // 参数校验
        $appName = trim((string)($data['app_name'] ?? ''));
        if ($appName === '') {
            Response::fail($response, '应用名称（app_name）不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        $packageName = trim((string)($data['package_name'] ?? ''));
        if ($packageName !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $packageName)) {
            Response::fail($response, '包名格式不正确，需符合 Java 包名规范（如 com.example.app）', Response::CODE_BAD_REQUEST);
            return false;
        }

        $defaultKey = (string)($data['default_key'] ?? 'default_key');
        $serverUrl = (string)($data['server_url'] ?? '');
        $wsUrl = (string)($data['ws_url'] ?? '');
        $iconBase64 = (string)($data['icon_path'] ?? '');  // 前端传的是 base64 字符串(可能含 data: 前缀)
        // 剥离 data URL 前缀(如 data:image/png;base64,),避免 base64 -d 解码失败
        $iconBase64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $iconBase64);
        $versionName = (string)($data['version'] ?? '1.0.0');

        // 生成 build_id
        $buildId = 'b' . uniqid() . sprintf('%03d', mt_rand(0, 999));
        $now = date('Y-m-d H:i:s');

        // 1. 在 Redis 中创建任务记录(用于状态查询)
        $task = [
            'build_id'       => $buildId,
            'app_name'       => $appName,
            'default_key'    => $defaultKey,
            'server_url'     => $serverUrl,
            'ws_url'         => $wsUrl,
            'icon_path'      => '',  // base64 不存 Redis,通过 inputs 传给 GitHub
            'package_name'   => $packageName,
            'version_name'   => $versionName,
            'admin_id'       => (string)($payload['admin_id'] ?? 0),
            'status'         => 'pending',
            'apk_path'       => '',
            'result_message' => '已提交到 GitHub Actions 队列',
            'created_at'     => $now,
            'updated_at'     => $now,
            'started_at'     => '',
            'finished_at'    => '',
        ];

        try {
            $redis = Redis::getInstance();
            foreach ($task as $field => $value) {
                $redis->hset('build:task:' . $buildId, (string)$field, (string)$value);
            }
            $redis->zadd('build:tasks', time(), $buildId);
        } catch (\Throwable $e) {
            Response::fail($response, '创建构建任务失败：' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }

        // 2. 调用 GitHub Actions API 触发 workflow
        try {
            $inputs = [
                'build_id'      => $buildId,
                'app_name'      => $appName,
                'package_name'  => $packageName,
                'default_key'   => $defaultKey,
                'server_url'    => $serverUrl,
                'ws_url'        => $wsUrl,
                'icon_base64'   => $iconBase64,
                'version_name'  => $versionName,
                'version_code'  => (string)crc32($buildId),
            ];
            $result = GitHubActionsService::triggerBuild($inputs);
            if (!$result['dispatched']) {
                // 触发失败,更新状态为 failed
                $redis->hset('build:task:' . $buildId, 'status', 'failed');
                $redis->hset('build:task:' . $buildId, 'result_message', $result['message']);
                $redis->hset('build:task:' . $buildId, 'updated_at', date('Y-m-d H:i:s'));
                $redis->hset('build:task:' . $buildId, 'finished_at', date('Y-m-d H:i:s'));
                Response::fail($response, $result['message'], Response::CODE_INTERNAL);
                return false;
            }
        } catch (\Throwable $e) {
            // API 调用异常,更新状态为 failed
            try {
                $redis = Redis::getInstance();
                $redis->hset('build:task:' . $buildId, 'status', 'failed');
                $redis->hset('build:task:' . $buildId, 'result_message', '触发 GitHub Actions 失败：' . $e->getMessage());
                $redis->hset('build:task:' . $buildId, 'updated_at', date('Y-m-d H:i:s'));
                $redis->hset('build:task:' . $buildId, 'finished_at', date('Y-m-d H:i:s'));
            } catch (\Throwable $ignore) {
            }
            Response::fail($response, '触发 GitHub Actions 失败：' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }

        // 3. 更新状态为 processing(workflow 已触发)
        try {
            $redis = Redis::getInstance();
            $redis->hset('build:task:' . $buildId, 'status', 'processing');
            $redis->hset('build:task:' . $buildId, 'started_at', date('Y-m-d H:i:s'));
            $redis->hset('build:task:' . $buildId, 'updated_at', date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            // 状态更新失败不影响返回
        }

        return [
            'build_id'  => $buildId,
            'status'    => 'processing',
            'message'   => '打包任务已提交到 GitHub Actions，请稍后查询构建状态',
            'query_url' => '/admin/app-build/status/' . $buildId,
        ];
    }

    /**
     * GET /admin/app-build/config-status
     * 获取 GitHub Actions 构建配置状态(供前端展示配置提示)
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function configStatus(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        // 读取 GitHub 配置(屏蔽敏感信息)
        $config = Config::get('github') ?? [];
        $token = $config['token'] ?? '';
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';
        $workflowFile = $config['workflow_file'] ?? 'build-apk.yml';
        $apiProxy = $config['api_proxy'] ?? '';

        // 构建配置状态(不返回 token 实际值,只返回是否已配置)
        return [
            'available'        => !empty($token) && !empty($owner) && !empty($repo),
            'token_configured' => !empty($token),
            'owner'            => $owner,
            'repo'             => $repo,
            'workflow_file'    => $workflowFile,
            'api_proxy'        => $apiProxy,
            'repo_url'         => !empty($owner) && !empty($repo) ? "https://github.com/{$owner}/{$repo}" : '',
            'actions_url'      => !empty($owner) && !empty($repo) ? "https://github.com/{$owner}/{$repo}/actions" : '',
            'secrets_url'      => !empty($owner) && !empty($repo) ? "https://github.com/{$owner}/{$repo}/settings/secrets/actions" : '',
            'token_create_url' => 'https://github.com/settings/tokens',
            // 需要在 GitHub 仓库配置的 Secrets 清单
            'required_secrets' => [
                ['name' => 'APK_KEYSTORE_BASE64', 'description' => 'keystore 文件 base64 编码', 'required' => true],
                ['name' => 'APK_KEYSTORE_PASSWORD', 'description' => 'keystore 密码', 'required' => true],
                ['name' => 'APK_KEY_ALIAS', 'description' => '密钥别名(通常 release)', 'required' => true],
                ['name' => 'APK_KEY_PASSWORD', 'description' => '密钥密码', 'required' => true],
                ['name' => 'SERVER_SSH_HOST', 'description' => '服务器 IP(如 124.220.64.209)', 'required' => true],
                ['name' => 'SERVER_SSH_PORT', 'description' => 'SSH 端口(通常 22)', 'required' => true],
                ['name' => 'SERVER_SSH_USER', 'description' => 'SSH 登录用户(如 ubuntu)', 'required' => true],
                ['name' => 'SERVER_SSH_KEY', 'description' => 'SSH 私钥(完整内容,含 BEGIN/END 行)', 'required' => true],
            ],
            // 服务器端 .env 需要配置的环境变量
            'required_env' => [
                ['name' => 'GITHUB_TOKEN', 'description' => 'GitHub Personal Access Token(repo + workflow 权限)'],
                ['name' => 'GITHUB_OWNER', 'description' => '仓库所有者(用户名或组织名)'],
                ['name' => 'GITHUB_REPO', 'description' => '仓库名'],
                ['name' => 'GITHUB_WORKFLOW_FILE', 'description' => 'Workflow 文件名(默认 build-apk.yml)'],
                ['name' => 'GITHUB_API_PROXY', 'description' => 'GitHub API 代理(国内服务器用 https://gh.jasonzeng.dev/)'],
            ],
        ];
    }

    /**
     * POST /admin/app-build/manual-trigger
     * 手动触发 GitHub Actions 构建(不经过 Redis 队列,直接调用 GitHub API)
     *
     * 与 submit() 的区别:
     *   - submit(): 创建 Redis 任务记录,前端通过 list 接口查询状态
     *   - manual-trigger(): 不创建 Redis 任务,直接返回 GitHub Actions 运行链接
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function manualTrigger(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        if (!self::isAvailable()) {
            Response::fail($response, '打包服务未配置：缺少 GitHub Actions 配置(GITHUB_TOKEN/GITHUB_OWNER/GITHUB_REPO)', Response::CODE_ERROR, 503);
            return false;
        }

        $data = $this->parseBody($context);

        // 参数校验
        $appName = trim((string)($data['app_name'] ?? ''));
        if ($appName === '') {
            Response::fail($response, '应用名称(app_name)不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        $packageName = trim((string)($data['package_name'] ?? ''));
        if ($packageName !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $packageName)) {
            Response::fail($response, '包名格式不正确,需符合 Java 包名规范(如 com.example.app)', Response::CODE_BAD_REQUEST);
            return false;
        }

        $defaultKey = (string)($data['default_key'] ?? 'default_key');
        $serverUrl = (string)($data['server_url'] ?? '');
        $wsUrl = (string)($data['ws_url'] ?? '');
        $iconBase64 = (string)($data['icon_base64'] ?? '');
        $versionName = (string)($data['version'] ?? '1.0.0');
        // 剥离 data URL 前缀(如 data:image/png;base64,),避免 base64 -d 解码失败
        $iconBase64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $iconBase64);

        // 生成 build_id(手动触发也生成,便于关联)
        $buildId = 'b' . uniqid() . sprintf('%03d', mt_rand(0, 999));

        // 调用 GitHub Actions API 触发 workflow
        try {
            $inputs = [
                'build_id'      => $buildId,
                'app_name'      => $appName,
                'package_name'  => $packageName,
                'default_key'   => $defaultKey,
                'server_url'    => $serverUrl,
                'ws_url'        => $wsUrl,
                'icon_base64'   => $iconBase64,
                'version_name'  => $versionName,
                'version_code'  => (string)crc32($buildId),
            ];
            $result = GitHubActionsService::triggerBuild($inputs);
            if (!$result['dispatched']) {
                Response::fail($response, $result['message'], Response::CODE_INTERNAL);
                return false;
            }

            // 读取仓库信息用于返回 actions 页面链接
            $config = Config::get('github') ?? [];
            $owner = $config['owner'] ?? '';
            $repo = $config['repo'] ?? '';
            $actionsUrl = !empty($owner) && !empty($repo) ? "https://github.com/{$owner}/{$repo}/actions" : '';

            return [
                'build_id'     => $buildId,
                'dispatched'   => true,
                'message'      => '已触发 GitHub Actions 构建,请点击 actions_url 查看运行进度',
                'actions_url'  => $actionsUrl,
                'query_url'    => '/admin/app-build/runs',
            ];
        } catch (\Throwable $e) {
            Response::fail($response, '触发 GitHub Actions 失败：' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }
    }

    /**
     * GET /admin/app-build/runs
     * 获取 GitHub Actions workflow 最近运行列表
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function runs(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        if (!self::isAvailable()) {
            Response::fail($response, 'GitHub Actions 配置不完整', Response::CODE_ERROR, 503);
            return false;
        }

        $perPage = (int)($context['get']['per_page'] ?? 20);
        $page = (int)($context['get']['page'] ?? 1);

        try {
            $result = GitHubActionsService::queryRunsList($perPage, $page);
            return $result;
        } catch (\Throwable $e) {
            Response::fail($response, '获取运行列表失败：' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }
    }

    /**
     * GET /admin/app-build/list
     * 构建历史列表（分页10条）
     *
     * 注意:此接口只读取 Redis 历史记录,不依赖 GitHub Actions 配置。
     * 即使 GitHub 配置缺失,也应允许查看历史构建记录。
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

        $page = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        try {
            return \App\Service\BuildQueue::getBuildList($page, $keyword);
        } catch (\Throwable $e) {
            Response::fail($response, '获取构建列表失败：' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }
    }

    /**
     * GET /admin/app-build/status/{build_id}
     * 查询构建状态
     *
     * 注意:此接口只读取 Redis 历史记录,不依赖 GitHub Actions 配置。
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

        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        $task = \App\Service\BuildQueue::getBuildStatus($buildId);
        if (!$task) {
            Response::fail($response, '构建任务不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        // 构建成功后自动创建分发记录（幂等：已存在则跳过）
        if (($task['status'] ?? '') === 'success' && !empty($task['apk_path'])) {
            $appName = (string)($task['app_name'] ?? 'app');
            $packageName = (string)($task['package_name'] ?? '');
            $adminId = (int)($task['admin_id'] ?? 0);
            $versionName = (string)($task['version_name'] ?? '1.0.0');
            try {
                ApkDistributionService::createFromBuild(
                    $buildId,
                    $task['apk_path'],
                    $appName,
                    $packageName,
                    $versionName,
                    $adminId
                );
            } catch (\Throwable $e) {
                // 分发记录创建失败不影响状态查询
            }
        }

        return $task;
    }

    /**
     * GET /admin/app-build/log/{build_id}
     * 获取构建日志
     *
     * 注意:此接口只读取 Redis/文件历史,不依赖 GitHub Actions 配置。
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

        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        $task = \App\Service\BuildQueue::getBuildStatus($buildId);
        if (!$task) {
            Response::fail($response, '构建任务不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return [
            'build_id' => $buildId,
            'log'      => \App\Service\BuildQueue::getBuildLog($buildId),
        ];
    }

    /**
     * GET /admin/app-build/log/{build_id}/download
     * 下载构建日志文件
     *
     * 注意:此接口只读取 Redis/文件历史,不依赖 GitHub Actions 配置。
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function downloadLog(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        $task = \App\Service\BuildQueue::getBuildStatus($buildId);
        if (!$task) {
            Response::fail($response, '构建任务不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $logContent = \App\Service\BuildQueue::getBuildLog($buildId);
        if ($logContent === null || $logContent === '') {
            $logContent = '（日志为空）';
        }

        // 构建元信息头部
        $header = sprintf(
            "========================================\n" .
            " 构建日志\n" .
            "========================================\n" .
            "应用名称: %s\n" .
            "构建ID:   %s\n" .
            "包名:     %s\n" .
            "状态:     %s\n" .
            "创建时间: %s\n" .
            "========================================\n\n",
            $task['app_name'] ?? '-',
            $buildId,
            $task['package_name'] ?? '-',
            $task['status'] ?? '-',
            $task['created_at'] ?? '-'
        );

        $fullLog = $header . $logContent;

        // 直接输出原始内容（非 JSON），设置下载头
        $filename = sprintf('build-%s.log', $buildId);
        $response->header('Content-Type', 'text/plain; charset=utf-8');
        $response->header('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->header('Content-Length', (string) strlen($fullLog));
        $response->end($fullLog);

        return false;
    }

    /**
     * GET /admin/app-build/download/{build_id}
     * 下载 APK
     *
     * 注意:此接口只读取 Redis/文件历史,不依赖 GitHub Actions 配置。
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

        $buildId = (string)($params['build_id'] ?? '');
        if ($buildId === '') {
            Response::fail($response, '缺少 build_id', Response::CODE_BAD_REQUEST);
            return false;
        }

        $task = \App\Service\BuildQueue::getBuildStatus($buildId);
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

    /**
     * GET /admin/app-build/random-config
     * 生成随机配置（包名、APP名称）
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function randomConfig(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        $prefixes = ['com', 'cn', 'org', 'net', 'io', 'app'];
        $domains = ['push', 'notify', 'msg', 'im', 'chat', 'alert', 'bell', 'signal', 'flash', 'quick'];
        $suffixes = ['app', 'client', 'mobile', 'pro', 'lite', 'plus', 'go', 'hub', 'box', 'lab'];

        $prefix = $prefixes[array_rand($prefixes)];
        $domain = $domains[array_rand($domains)];
        $suffix = $suffixes[array_rand($suffixes)];
        $randomStr = substr(md5((string)mt_rand()), 0, 6);

        $packageName = sprintf('%s.%s.%s', $prefix, $domain, $suffix);

        $appNames = [
            '消息推送助手', '即时通知', '推送管家', '消息精灵', '提醒小助手',
            '极速推送', '闪电通知', '智能提醒', '消息速递', '推送大师',
            '通知中心', '消息盒子', '推送宝', '提醒达人', '消息快线'
        ];
        $appName = $appNames[array_rand($appNames)];

        return [
            'package_name' => $packageName,
            'app_name'     => $appName,
            'random_key'   => $randomStr,
        ];
    }

    /**
     * GET /admin/app-build/generate-icon
     * 生成图标（首字+渐变色背景）
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function generateIcon(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $get = $context['get'] ?? [];

        $text = trim((string)($get['text'] ?? ''));
        if ($text === '') {
            Response::fail($response, '缺少 text 参数', Response::CODE_BAD_REQUEST);
            return false;
        }

        $firstChar = mb_substr($text, 0, 1, 'UTF-8');

        $size = 512;
        $image = imagecreatetruecolor($size, $size);
        imagesavealpha($image, true);

        $gradientPresets = [
            [[102, 126, 234], [118, 75, 162]],
            [[237, 109, 129], [244, 147, 103]],
            [[89, 212, 153], [34, 193, 195]],
            [[255, 175, 123], [255, 119, 198]],
            [[79, 172, 254], [0, 242, 254]],
            [[208, 130, 255], [117, 127, 255]],
            [[255, 189, 184], [255, 139, 139]],
            [[168, 255, 120], [46, 255, 165]],
            [[255, 207, 104], [255, 145, 86]],
            [[138, 188, 255], [70, 133, 255]],
        ];
        $preset = $gradientPresets[array_rand($gradientPresets)];
        [$startColor, $endColor] = $preset;

        for ($y = 0; $y < $size; $y++) {
            $ratio = $y / $size;
            $r = (int)($startColor[0] + ($endColor[0] - $startColor[0]) * $ratio);
            $g = (int)($startColor[1] + ($endColor[1] - $startColor[1]) * $ratio);
            $b = (int)($startColor[2] + ($endColor[2] - $startColor[2]) * $ratio);
            $color = imagecolorallocate($image, $r, $g, $b);
            imageline($image, 0, $y, $size, $y, $color);
        }

        $white = imagecolorallocate($image, 255, 255, 255);

        $fontSize = (int)($size * 0.45);
        $fontFile = dirname(__DIR__, 3) . '/build/fonts/icon-font.ttf';

        $bbox = null;
        $useGdFont = false;

        if (function_exists('imagettftext') && is_file($fontFile)) {
            $bbox = @imagettfbbox($fontSize, 0, $fontFile, $firstChar);
        }
        if (!$bbox) {
            $useGdFont = true;
        }

        if ($useGdFont) {
            $font = 5;
            $fontWidth = imagefontwidth($font);
            $fontHeight = imagefontheight($font);
            $charWidth = $fontWidth * (strlen($firstChar) > 1 ? 2 : 1);
            $x = ($size - $charWidth) / 2;
            $y = ($size - $fontHeight) / 2;
            imagestring($image, $font, (int)$x, (int)$y, $firstChar, $white);
        } else {
            $charWidth = $bbox[2] - $bbox[0];
            $charHeight = $bbox[1] - $bbox[7];
            $x = ($size - $charWidth) / 2 - $bbox[0];
            $y = ($size - $charHeight) / 2 - $bbox[7];
            imagettftext($image, $fontSize, 0, (int)$x, (int)$y, $white, $fontFile, $firstChar);
        }

        ob_start();
        imagepng($image);
        $imageData = ob_get_clean();
        imagedestroy($image);

        $base64 = 'data:image/png;base64,' . base64_encode($imageData);

        return [
            'icon_base64' => $base64,
            'text'        => $firstChar,
            'gradient'    => [
                'start' => sprintf('#%02x%02x%02x', $startColor[0], $startColor[1], $startColor[2]),
                'end'   => sprintf('#%02x%02x%02x', $endColor[0], $endColor[1], $endColor[2]),
            ],
        ];
    }

    /**
     * DELETE /admin/app-build/{build_id}
     * 删除构建记录
     *
     * 注意:此接口只操作 Redis 历史记录,不依赖 GitHub Actions 配置。
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function delete(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $buildId = $params['build_id'] ?? '';

        if ($buildId === '') {
            Response::fail($response, '缺少 build_id 参数', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 校验 build_id 格式,防止误删(如把 list/config-status 当成 build_id)
        if (!preg_match('/^b[0-9a-f]{13}[0-9]{3}$/', $buildId)) {
            Response::fail($response, 'build_id 格式无效', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 检查记录是否存在,不存在返回 404
        $task = \App\Service\BuildQueue::getBuildStatus($buildId);
        if (!$task) {
            Response::fail($response, '构建任务不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        \App\Service\BuildQueue::deleteBuild($buildId);

        return ['message' => '删除成功'];
    }

    /**
     * POST /admin/app-build/config
     * 保存 GitHub Actions 配置（到数据库 + 同步到 .env）
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function saveConfig(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        $token       = trim((string)($data['token'] ?? ''));
        $owner       = trim((string)($data['owner'] ?? ''));
        $repo        = trim((string)($data['repo'] ?? ''));
        $workflowFile = trim((string)($data['workflow_file'] ?? 'build-apk.yml'));
        $ref         = trim((string)($data['ref'] ?? 'main'));
        $apiProxy    = trim((string)($data['api_proxy'] ?? ''));
        $proxyEnabled = isset($data['proxy_enabled'])
            ? ($data['proxy_enabled'] !== false && $data['proxy_enabled'] !== 'false' && $data['proxy_enabled'] !== '0')
            : true;
        $timeout     = (int)($data['timeout'] ?? 30);

        if ($token === '' || $owner === '' || $repo === '') {
            Response::fail($response, 'Token、仓库所有者、仓库名不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 1. 保存到数据库
        try {
            $pdo = \App\Service\Database::pdo();
            $configData = [
                'token'         => $token,
                'owner'         => $owner,
                'repo'          => $repo,
                'workflow_file' => $workflowFile,
                'ref'           => $ref,
                'api_proxy'     => $apiProxy,
                'proxy_enabled' => $proxyEnabled,
                'timeout'       => $timeout,
            ];
            $json = json_encode($configData, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare(
                'INSERT INTO admin_settings (config_key, config_value, description)
                 VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = ?'
            );
            $stmt->execute(['github_actions_config', $json, 'GitHub Actions 构建配置', $json]);
        } catch (\Throwable $e) {
            Response::fail($response, '保存配置到数据库失败: ' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }

        // 2. 同步到 .env 文件
        $envUpdated = self::syncConfigToEnv([
            'GITHUB_TOKEN'          => $token,
            'GITHUB_OWNER'          => $owner,
            'GITHUB_REPO'           => $repo,
            'GITHUB_WORKFLOW_FILE'  => $workflowFile,
            'GITHUB_REF'            => $ref,
            'GITHUB_API_PROXY'      => $apiProxy,
            'GITHUB_PROXY_ENABLED'  => $proxyEnabled ? 'true' : 'false',
            'GITHUB_API_TIMEOUT'    => $timeout,
        ]);

        // 3. 重置配置缓存并重新加载 .env（适配常驻内存环境）
        if ($envUpdated) {
            Config::reloadEnv();
        }
        GitHubActionsService::resetConfig();
        self::$available = null;

        return [
            'message'     => '配置已保存' . ($envUpdated ? '，已同步到 .env 文件' : ''),
            'env_updated' => $envUpdated,
        ];
    }

    /**
     * GET /admin/app-build/config
     * 获取 GitHub Actions 配置（从数据库读取，Token 脱敏）
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function getConfig(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        try {
            $pdo = \App\Service\Database::pdo();
            $stmt = $pdo->prepare('SELECT config_value FROM admin_settings WHERE config_key = ?');
            $stmt->execute(['github_actions_config']);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                // 数据库没有，从 .env 读取作为初始值
                $config = Config::get('github') ?? [];
                $proxyEnabled = $config['proxy_enabled'] ?? true;
                $proxyEnabled = $proxyEnabled !== false && $proxyEnabled !== 'false' && $proxyEnabled !== '0';
                return [
                    'token'         => !empty($config['token']) ? '******' : '',
                    'owner'         => $config['owner'] ?? '',
                    'repo'          => $config['repo'] ?? '',
                    'workflow_file' => $config['workflow_file'] ?? 'build-apk.yml',
                    'ref'           => $config['ref'] ?? 'main',
                    'api_proxy'     => $config['api_proxy'] ?? '',
                    'proxy_enabled' => $proxyEnabled,
                    'timeout'       => $config['timeout'] ?? 30,
                ];
            }

            $data = json_decode($row['config_value'], true);
            if (!is_array($data)) {
                $data = [];
            }

            // Token 脱敏
            if (!empty($data['token'])) {
                $data['token'] = '******';
            }

            // 确保 proxy_enabled 是布尔值
            if (isset($data['proxy_enabled'])) {
                $data['proxy_enabled'] = $data['proxy_enabled'] !== false && $data['proxy_enabled'] !== 'false' && $data['proxy_enabled'] !== '0';
            } else {
                $data['proxy_enabled'] = true;
            }

            return $data;
        } catch (\Throwable $e) {
            return [
                'token'         => '',
                'owner'         => '',
                'repo'          => '',
                'workflow_file' => 'build-apk.yml',
                'ref'           => 'main',
                'api_proxy'     => '',
                'proxy_enabled' => true,
                'timeout'       => 30,
            ];
        }
    }

    /**
     * POST /admin/app-build/config/validate
     * 验证 GitHub Token 和仓库配置是否有效
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function validateConfig(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        $token = trim((string)($data['token'] ?? ''));
        $owner = trim((string)($data['owner'] ?? ''));
        $repo  = trim((string)($data['repo'] ?? ''));

        if ($token === '' || $owner === '' || $repo === '') {
            Response::fail($response, 'Token、仓库所有者、仓库名不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 如果 token 是 ******，从数据库读取真实 token
        if ($token === '******') {
            try {
                $pdo = \App\Service\Database::pdo();
                $stmt = $pdo->prepare('SELECT config_value FROM admin_settings WHERE config_key = ?');
                $stmt->execute(['github_actions_config']);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $dbConfig = json_decode($row['config_value'], true);
                    if (is_array($dbConfig) && !empty($dbConfig['token'])) {
                        $token = $dbConfig['token'];
                    }
                }
            } catch (\Throwable $e) {
                // 读取失败则继续使用传入值
            }
        }

        if ($token === '' || $token === '******') {
            Response::fail($response, '请先配置有效的 GitHub Token', Response::CODE_BAD_REQUEST);
            return false;
        }

        $result = GitHubActionsService::validateToken($token, $owner, $repo);

        return $result;
    }

    /**
     * GET /admin/app-build/config/check
     * 全面检测配置状态（Token、仓库、Workflow、Secrets 等）
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function checkConfig(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];

        if (!self::isAvailable()) {
            Response::fail($response, 'GitHub Actions 配置不完整，请先完成基础配置', Response::CODE_ERROR, 503);
            return false;
        }

        $checks = [];

        // 1. 检查仓库是否存在且有权限
        try {
            $repoInfo = GitHubActionsService::getRepoInfo();
            $checks['repo'] = [
                'status' => $repoInfo !== null ? 'ok' : 'error',
                'message' => $repoInfo !== null ? '仓库访问正常' : '仓库不存在或无权限访问',
            ];
        } catch (\Throwable $e) {
            $checks['repo'] = [
                'status' => 'error',
                'message' => '仓库检查失败: ' . $e->getMessage(),
            ];
        }

        // 2. 检查 Workflow 文件是否存在
        try {
            $config = Config::get('github') ?? [];
            $workflowFile = $config['workflow_file'] ?? 'build-apk.yml';
            $exists = GitHubActionsService::workflowExists($workflowFile);
            $checks['workflow'] = [
                'status'  => $exists ? 'ok' : 'warning',
                'message' => $exists ? "Workflow 文件 {$workflowFile} 存在" : "Workflow 文件 {$workflowFile} 不存在，需手动上传或使用一键配置",
            ];
        } catch (\Throwable $e) {
            $checks['workflow'] = [
                'status'  => 'error',
                'message' => 'Workflow 检查失败: ' . $e->getMessage(),
            ];
        }

        // 3. 检查 Secrets 配置情况
        $requiredSecrets = [
            'APK_KEYSTORE_BASE64',
            'APK_KEYSTORE_PASSWORD',
            'APK_KEY_ALIAS',
            'APK_KEY_PASSWORD',
            'SERVER_SSH_HOST',
            'SERVER_SSH_PORT',
            'SERVER_SSH_USER',
            'SERVER_SSH_KEY',
        ];

        try {
            $secretsResult = GitHubActionsService::listSecrets();
            $existingSecrets = array_column($secretsResult['secrets'] ?? [], 'name');
            $missingSecrets = array_diff($requiredSecrets, $existingSecrets);
            $checks['secrets'] = [
                'status'          => empty($missingSecrets) ? 'ok' : 'warning',
                'message'         => empty($missingSecrets) ? '所有必需 Secrets 已配置' : '缺少 ' . count($missingSecrets) . ' 个 Secrets',
                'total'           => $secretsResult['total'] ?? 0,
                'existing'        => $existingSecrets,
                'missing'         => array_values($missingSecrets),
                'required'        => $requiredSecrets,
            ];
        } catch (\Throwable $e) {
            $checks['secrets'] = [
                'status'   => 'error',
                'message'  => 'Secrets 检查失败: ' . $e->getMessage(),
                'missing'  => $requiredSecrets,
                'required' => $requiredSecrets,
            ];
        }

        // 4. 检查 PHP sodium 扩展（加密 Secrets 需要）
        $checks['sodium'] = [
            'status'  => function_exists('sodium_crypto_box_seal') ? 'ok' : 'error',
            'message' => function_exists('sodium_crypto_box_seal') ? 'PHP sodium 扩展已安装' : 'PHP sodium 扩展未安装，无法自动配置 Secrets',
        ];

        // 总体状态
        $allOk = true;
        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'error') {
                $allOk = false;
                break;
            }
            if (($check['status'] ?? '') === 'warning') {
                // warning 不影响可用性
            }
        }

        return [
            'status'  => $allOk ? 'ready' : 'incomplete',
            'checks'  => $checks,
            'summary' => $allOk ? '配置完整，可以开始构建' : '配置不完整，请检查各项',
        ];
    }

    /**
     * POST /admin/app-build/config/get-user
     * 根据 Token 获取 GitHub 用户信息(自动填充 owner)
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function getUser(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        $token = trim((string)($data['token'] ?? ''));
        if ($token === '') {
            Response::fail($response, 'Token 不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        if ($token === '******') {
            try {
                $pdo = \App\Service\Database::pdo();
                $stmt = $pdo->prepare('SELECT config_value FROM admin_settings WHERE config_key = ?');
                $stmt->execute(['github_actions_config']);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $stored = json_decode($row['config_value'], true);
                    if (is_array($stored) && !empty($stored['token'])) {
                        $token = $stored['token'];
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $apiProxy     = trim((string)($data['api_proxy'] ?? ''));
        $proxyEnabled = isset($data['proxy_enabled'])
            ? ($data['proxy_enabled'] !== false && $data['proxy_enabled'] !== 'false' && $data['proxy_enabled'] !== '0')
            : true;

        $options = [
            'api_proxy'     => $apiProxy,
            'proxy_enabled' => $proxyEnabled,
        ];

        try {
            $user = GitHubActionsService::getCurrentUser($token, $options);
            if ($user === null) {
                Response::fail($response, '获取用户信息失败，请检查 Token 是否有效', Response::CODE_ERROR, 400);
                return false;
            }
            return $user;
        } catch (\Throwable $e) {
            Response::fail($response, '获取用户信息失败: ' . $e->getMessage(), Response::CODE_ERROR, 500);
            return false;
        }
    }

    /**
     * POST /admin/app-build/config/list-repos
     * 列出当前用户的仓库(下拉选择用)
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function listRepos(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        $token = trim((string)($data['token'] ?? ''));
        if ($token === '') {
            Response::fail($response, 'Token 不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        if ($token === '******') {
            try {
                $pdo = \App\Service\Database::pdo();
                $stmt = $pdo->prepare('SELECT config_value FROM admin_settings WHERE config_key = ?');
                $stmt->execute(['github_actions_config']);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $stored = json_decode($row['config_value'], true);
                    if (is_array($stored) && !empty($stored['token'])) {
                        $token = $stored['token'];
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        $apiProxy     = trim((string)($data['api_proxy'] ?? ''));
        $proxyEnabled = isset($data['proxy_enabled'])
            ? ($data['proxy_enabled'] !== false && $data['proxy_enabled'] !== 'false' && $data['proxy_enabled'] !== '0')
            : true;
        $page    = (int)($data['page'] ?? 1);
        $perPage = (int)($data['per_page'] ?? 100);

        $options = [
            'api_proxy'     => $apiProxy,
            'proxy_enabled' => $proxyEnabled,
        ];

        try {
            $repos = GitHubActionsService::listUserRepos($token, $page, $perPage, $options);
            return ['repos' => $repos, 'total' => count($repos)];
        } catch (\Throwable $e) {
            Response::fail($response, '获取仓库列表失败: ' . $e->getMessage(), Response::CODE_ERROR, 500);
            return false;
        }
    }

    /**
     * POST /admin/app-build/config/auto-setup
     * 一键配置：生成 keystore + SSH 密钥 + 配置 GitHub Secrets
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function autoSetup(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        if (!function_exists('sodium_crypto_box_seal')) {
            Response::fail($response, 'PHP sodium 扩展未安装，无法自动配置 Secrets，请先安装 php-sodium 扩展', Response::CODE_ERROR, 500);
            return false;
        }

        $projectRoot = dirname(__DIR__, 3);
        $steps = [];
        $owner = '';
        $repo = '';

        try {
            $token = trim((string)($data['token'] ?? ''));
            $apiProxy     = trim((string)($data['api_proxy'] ?? ''));
            $proxyEnabled = isset($data['proxy_enabled'])
                ? ($data['proxy_enabled'] !== false && $data['proxy_enabled'] !== 'false' && $data['proxy_enabled'] !== '0')
                : true;
            $timeout  = (int)($data['timeout'] ?? 30);
            $repoName = trim((string)($data['repo'] ?? 'im-push-build'));
            $ref      = trim((string)($data['ref'] ?? 'main'));
            $workflowFile = trim((string)($data['workflow_file'] ?? 'build-apk.yml'));

            // 如果传了 token，走全自动模式
            if ($token !== '' && $token !== '******') {
                // 步骤 1: 验证 Token 并获取用户信息
                $steps[] = ['step' => '验证 Token 并获取用户信息', 'status' => 'running'];
                $user = GitHubActionsService::getCurrentUser($token, [
                    'api_proxy'     => $apiProxy,
                    'proxy_enabled' => $proxyEnabled,
                ]);
                if ($user === null) {
                    $steps[count($steps) - 1]['status'] = 'failed';
                    $steps[count($steps) - 1]['message'] = 'Token 无效或无法获取用户信息';
                    return ['steps' => $steps, 'success' => false, 'message' => 'Token 验证失败'];
                }
                $owner = $user['login'];
                $steps[count($steps) - 1]['status'] = 'success';
                $steps[count($steps) - 1]['message'] = "用户: {$owner}";

                // 步骤 2: 检查并创建仓库
                $steps[] = ['step' => '检查并准备仓库', 'status' => 'running'];
                $repoExists = GitHubActionsService::repoExists($owner, $repoName, $token, [
                    'api_proxy'     => $apiProxy,
                    'proxy_enabled' => $proxyEnabled,
                ]);
                if (!$repoExists) {
                    $createResult = GitHubActionsService::createRepo(
                        $repoName,
                        'IM Push System - APK Build Repository',
                        true,
                        $token,
                        ['api_proxy' => $apiProxy, 'proxy_enabled' => $proxyEnabled]
                    );
                    if (!$createResult['success']) {
                        $steps[count($steps) - 1]['status'] = 'failed';
                        $steps[count($steps) - 1]['message'] = $createResult['message'];
                        return ['steps' => $steps, 'success' => false, 'message' => '创建仓库失败'];
                    }
                    $steps[count($steps) - 1]['status'] = 'success';
                    $steps[count($steps) - 1]['message'] = "已创建仓库: {$owner}/{$repoName}";
                } else {
                    $steps[count($steps) - 1]['status'] = 'success';
                    $steps[count($steps) - 1]['message'] = "仓库已存在: {$owner}/{$repoName}";
                }
                $repo = $repoName;

                // 步骤 3: 保存配置
                $steps[] = ['step' => '保存配置', 'status' => 'running'];
                try {
                    $pdo = \App\Service\Database::pdo();
                    $configData = [
                        'token'         => $token,
                        'owner'         => $owner,
                        'repo'          => $repo,
                        'workflow_file' => $workflowFile,
                        'ref'           => $ref,
                        'api_proxy'     => $apiProxy,
                        'proxy_enabled' => $proxyEnabled,
                        'timeout'       => $timeout,
                    ];
                    $json = json_encode($configData, JSON_UNESCAPED_UNICODE);
                    $stmt = $pdo->prepare(
                        'INSERT INTO admin_settings (config_key, config_value, description)
                         VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = ?'
                    );
                    $stmt->execute(['github_actions_config', $json, 'GitHub Actions 构建配置', $json]);

                    // 同步到 .env 文件并重新加载（适配常驻内存环境）
                    $envUpdated = self::syncConfigToEnv([
                        'GITHUB_TOKEN'          => $token,
                        'GITHUB_OWNER'          => $owner,
                        'GITHUB_REPO'           => $repo,
                        'GITHUB_WORKFLOW_FILE'  => $workflowFile,
                        'GITHUB_REF'            => $ref,
                        'GITHUB_API_PROXY'      => $apiProxy,
                        'GITHUB_PROXY_ENABLED'  => $proxyEnabled ? 'true' : 'false',
                        'GITHUB_API_TIMEOUT'    => $timeout,
                    ]);
                    if ($envUpdated) {
                        Config::reloadEnv();
                    }
                    GitHubActionsService::resetConfig();
                    self::$available = null;

                    $steps[count($steps) - 1]['status'] = 'success';
                    $steps[count($steps) - 1]['message'] = $envUpdated ? '配置已保存并同步到 .env' : '配置已保存';
                } catch (\Throwable $e) {
                    $steps[count($steps) - 1]['status'] = 'warning';
                    $steps[count($steps) - 1]['message'] = '保存配置失败: ' . $e->getMessage();
                }

                // 步骤 4: 上传 workflow 文件
                $steps[] = ['step' => '上传 Workflow 文件', 'status' => 'running'];
                $workflowContent = GitHubActionsService::getLocalWorkflowContent($workflowFile);
                if ($workflowContent === null) {
                    $steps[count($steps) - 1]['status'] = 'warning';
                    $steps[count($steps) - 1]['message'] = '本地 workflow 文件不存在，跳过上传';
                } else {
                    $uploadResult = GitHubActionsService::createOrUpdateFileWithToken(
                        $owner,
                        $repo,
                        '.github/workflows/' . $workflowFile,
                        $workflowContent,
                        'feat: add APK build workflow',
                        $ref,
                        $token,
                        ['api_proxy' => $apiProxy, 'proxy_enabled' => $proxyEnabled]
                    );
                    if ($uploadResult['success']) {
                        $steps[count($steps) - 1]['status'] = 'success';
                        $steps[count($steps) - 1]['message'] = $uploadResult['message'];
                    } else {
                        $steps[count($steps) - 1]['status'] = 'warning';
                        $steps[count($steps) - 1]['message'] = $uploadResult['message'];
                    }
                }
            } elseif (self::isAvailable()) {
                // 已有配置，使用已保存的配置
                $config = \App\Service\Config::get('github') ?? [];
                $owner = $config['owner'] ?? '';
                $repo  = $config['repo'] ?? '';
            } else {
                Response::fail($response, '请先完成基础配置（Token、仓库所有者、仓库名）', Response::CODE_ERROR, 503);
                return false;
            }

            // 步骤 5: 生成 keystore
            $steps[] = ['step' => '生成 Keystore', 'status' => 'running'];
            $keystoreResult = self::generateKeystore($projectRoot);
            if (!$keystoreResult['success']) {
                $steps[count($steps) - 1]['status'] = 'failed';
                $steps[count($steps) - 1]['message'] = $keystoreResult['message'];
                return ['steps' => $steps, 'success' => false, 'message' => '生成 Keystore 失败'];
            }
            $steps[count($steps) - 1]['status'] = 'success';
            $steps[count($steps) - 1]['message'] = 'Keystore 生成成功';

            // 步骤 6: 生成 SSH 密钥对
            $steps[] = ['step' => '生成 SSH 密钥对', 'status' => 'running'];
            $sshResult = self::generateSshKey($projectRoot);
            if (!$sshResult['success']) {
                $steps[count($steps) - 1]['status'] = 'failed';
                $steps[count($steps) - 1]['message'] = $sshResult['message'];
                return ['steps' => $steps, 'success' => false, 'message' => '生成 SSH 密钥失败'];
            }
            $steps[count($steps) - 1]['status'] = 'success';
            $steps[count($steps) - 1]['message'] = 'SSH 密钥对生成成功';

            // 步骤 7: 获取服务器信息
            $steps[] = ['step' => '获取服务器信息', 'status' => 'running'];
            $serverHost = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['HTTP_HOST'] ?? '');
            $serverHost = explode(':', $serverHost)[0];
            $sshPort = (string)($data['ssh_port'] ?? '22');
            $sshUser = (string)($data['ssh_user'] ?? get_current_user());
            $steps[count($steps) - 1]['status'] = 'success';
            $steps[count($steps) - 1]['message'] = "服务器: {$sshUser}@{$serverHost}:{$sshPort}";

            // 步骤 8: 配置 GitHub Secrets
            $steps[] = ['step' => '配置 GitHub Secrets', 'status' => 'running'];

            $secrets = [
                'APK_KEYSTORE_BASE64'   => $keystoreResult['keystore_base64'],
                'APK_KEYSTORE_PASSWORD' => $keystoreResult['store_password'],
                'APK_KEY_ALIAS'         => $keystoreResult['key_alias'],
                'APK_KEY_PASSWORD'      => $keystoreResult['key_password'],
                'SERVER_SSH_HOST'       => $serverHost,
                'SERVER_SSH_PORT'       => $sshPort,
                'SERVER_SSH_USER'       => $sshUser,
                'SERVER_SSH_KEY'        => $sshResult['private_key'],
            ];

            $isAutoMode = ($token !== '' && $token !== '******');
            if ($isAutoMode) {
                $secretsResult = GitHubActionsService::setSecretsWithToken(
                    $owner,
                    $repo,
                    $secrets,
                    $token,
                    ['api_proxy' => $apiProxy, 'proxy_enabled' => $proxyEnabled]
                );
            } else {
                $secretsResult = GitHubActionsService::setSecrets($secrets);
            }
            $failedCount = count($secretsResult['failed']);
            $successCount = count($secretsResult['success']);

            if ($failedCount > 0) {
                $steps[count($steps) - 1]['status'] = 'warning';
                $steps[count($steps) - 1]['message'] = "成功配置 {$successCount} 个，失败 {$failedCount} 个";
                $steps[count($steps) - 1]['failed'] = $secretsResult['failed'];
            } else {
                $steps[count($steps) - 1]['status'] = 'success';
                $steps[count($steps) - 1]['message'] = "成功配置 {$successCount} 个 Secrets";
            }

            // 保存 SSH 公钥路径到数据库，方便用户配置 authorized_keys
            try {
                $pdo = \App\Service\Database::pdo();
                $setupData = [
                    'keystore_path' => $keystoreResult['keystore_path'],
                    'ssh_pub_key'   => $sshResult['public_key'],
                    'ssh_pub_path'  => $sshResult['pub_path'],
                    'server_host'   => $serverHost,
                    'ssh_port'      => $sshPort,
                    'ssh_user'      => $sshUser,
                    'setup_at'      => date('Y-m-d H:i:s'),
                ];
                $json = json_encode($setupData, JSON_UNESCAPED_UNICODE);
                $stmt = $pdo->prepare(
                    'INSERT INTO admin_settings (config_key, config_value, description)
                     VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_value = ?'
                );
                $stmt->execute(['github_setup_info', $json, 'GitHub Actions 一键配置信息', $json]);
            } catch (\Throwable $e) {
                // 保存信息失败不影响主流程
            }

            return [
                'success'     => $failedCount === 0,
                'steps'       => $steps,
                'message'     => $failedCount === 0 ? '一键配置完成！' : "配置完成，但有 {$failedCount} 个 Secrets 失败",
                'ssh_pub_key' => $sshResult['public_key'],
                'setup_info'  => [
                    'keystore_path' => $keystoreResult['keystore_path'],
                    'ssh_pub_path'  => $sshResult['pub_path'],
                    'server_host'   => $serverHost,
                    'ssh_port'      => $sshPort,
                    'ssh_user'      => $sshUser,
                ],
                'next_step' => '请将 SSH 公钥添加到服务器的 ~/.ssh/authorized_keys 中，确保 GitHub Actions 能 SCP 上传文件',
            ];
        } catch (\Throwable $e) {
            $steps[] = [
                'step'    => '出错',
                'status'  => 'failed',
                'message' => $e->getMessage(),
            ];
            Response::fail($response, '一键配置失败: ' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }
    }

    /**
     * POST /admin/app-build/config/test-proxy
     * 测试代理连接(直连或代理模式)
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function testProxy(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        $apiProxy     = trim((string)($data['api_proxy'] ?? ''));
        $proxyEnabled = isset($data['proxy_enabled'])
            ? ($data['proxy_enabled'] !== false && $data['proxy_enabled'] !== 'false' && $data['proxy_enabled'] !== '0')
            : true;
        $timeout      = (int)($data['timeout'] ?? 10);

        $result = GitHubActionsService::testProxyConnection($apiProxy, $proxyEnabled, $timeout);

        return $result;
    }

    /**
     * POST /admin/app-build/config/compare-proxy
     * 对比测试直连和代理的连接质量
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function compareProxy(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        $apiProxy = trim((string)($data['api_proxy'] ?? ''));
        $timeout  = (int)($data['timeout'] ?? 10);

        $result = GitHubActionsService::compareConnection($apiProxy, $timeout);

        return $result;
    }

    /**
     * 生成 Android Keystore
     *
     * @param string $projectRoot
     * @return array{success: bool, message: string, keystore_path?: string, keystore_base64?: string, store_password?: string, key_alias?: string, key_password?: string}
     */
    private static function generateKeystore(string $projectRoot): array
    {
        $keystoreDir = $projectRoot . '/build/keystore';
        if (!is_dir($keystoreDir)) {
            if (!@mkdir($keystoreDir, 0700, true)) {
                return ['success' => false, 'message' => '无法创建 keystore 目录: ' . $keystoreDir];
            }
        }

        $keystorePath = $keystoreDir . '/release.keystore';
        $storePassword = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 16);
        $keyAlias = 'release';
        $keyPassword = $storePassword;

        // 如果已存在，直接读取
        if (is_file($keystorePath)) {
            $keystoreContent = file_get_contents($keystorePath);
            if ($keystoreContent !== false) {
                return [
                    'success'         => true,
                    'message'         => '使用已存在的 keystore',
                    'keystore_path'   => $keystorePath,
                    'keystore_base64' => base64_encode($keystoreContent),
                    'store_password'  => $storePassword,
                    'key_alias'       => $keyAlias,
                    'key_password'    => $keyPassword,
                ];
            }
        }

        // 尝试使用 keytool 生成
        $keytool = trim((string)@shell_exec('which keytool 2>/dev/null') ?? '');
        if ($keytool === '') {
            // keytool 不存在，生成一个空的占位 keystore（后续由 GitHub Actions 生成）
            // 实际上我们生成一个自签名证书作为临时 keystore
            if (!function_exists('openssl_pkey_new')) {
                return ['success' => false, 'message' => 'keytool 和 openssl 均不可用，无法生成 keystore'];
            }

            // 使用 PHP 生成一个简单的 PKCS12 再转换（复杂，跳过）
            // 改为：创建空文件，提示用户手动生成
            // 实际上，让我们用 openssl 生成自签名证书
            $config = [
                "digest_alg"       => "sha256",
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ];

            $privateKey = openssl_pkey_new($config);
            if ($privateKey === false) {
                return ['success' => false, 'message' => '生成密钥对失败: ' . openssl_error_string()];
            }

            $csr = openssl_csr_new([
                'countryName'            => 'CN',
                'stateOrProvinceName'    => 'Beijing',
                'localityName'           => 'Beijing',
                'organizationName'       => 'IM Push',
                'organizationalUnitName' => 'Dev',
                'commonName'             => 'im-push-app',
                'emailAddress'           => 'dev@example.com',
            ], $privateKey);

            if ($csr === false) {
                return ['success' => false, 'message' => '生成 CSR 失败'];
            }

            $x509 = openssl_csr_sign($csr, null, $privateKey, 3650); // 10 年有效期
            if ($x509 === false) {
                return ['success' => false, 'message' => '签署证书失败'];
            }

            // 导出为 PKCS12 格式
            $pkcs12 = '';
            if (!openssl_pkcs12_export($x509, $pkcs12, $privateKey, $storePassword)) {
                return ['success' => false, 'message' => '导出 PKCS12 失败'];
            }

            file_put_contents($keystoreDir . '/release.p12', $pkcs12);
            chmod($keystoreDir . '/release.p12', 0600);

            // 尝试用 keytool 转换为 JKS
            if ($keytool !== '') {
                $cmd = sprintf(
                    'keytool -importkeystore -srckeystore %s -srcstoretype PKCS12 -srcstorepass %s -destkeystore %s -deststoretype JKS -deststorepass %s -destkeypass %s -srcalias 1 -destalias %s -noprompt 2>&1',
                    escapeshellarg($keystoreDir . '/release.p12'),
                    escapeshellarg($storePassword),
                    escapeshellarg($keystorePath),
                    escapeshellarg($storePassword),
                    escapeshellarg($keyPassword),
                    escapeshellarg($keyAlias)
                );
                @shell_exec($cmd);
            }

            // 如果 JKS 生成失败，使用 PKCS12 作为 keystore（Android 也支持 PKCS12）
            if (!is_file($keystorePath)) {
                $keystorePath = $keystoreDir . '/release.p12';
            }
        } else {
            // 使用 keytool 生成 JKS keystore
            $cmd = sprintf(
                'keytool -genkeypair -v -keystore %s -alias %s -keyalg RSA -keysize 2048 -validity 10000 -storepass %s -keypass %s -dname "CN=im-push-app, OU=Dev, O=IM Push, L=Beijing, ST=Beijing, C=CN" 2>&1',
                escapeshellarg($keystorePath),
                escapeshellarg($keyAlias),
                escapeshellarg($storePassword),
                escapeshellarg($keyPassword)
            );
            @shell_exec($cmd);
        }

        if (!is_file($keystorePath)) {
            return ['success' => false, 'message' => 'Keystore 文件生成失败'];
        }

        $keystoreContent = file_get_contents($keystorePath);
        if ($keystoreContent === false) {
            return ['success' => false, 'message' => '读取 keystore 文件失败'];
        }

        chmod($keystorePath, 0600);

        return [
            'success'         => true,
            'message'         => 'Keystore 生成成功',
            'keystore_path'   => $keystorePath,
            'keystore_base64' => base64_encode($keystoreContent),
            'store_password'  => $storePassword,
            'key_alias'       => $keyAlias,
            'key_password'    => $keyPassword,
        ];
    }

    /**
     * 生成 SSH 密钥对
     *
     * @param string $projectRoot
     * @return array{success: bool, message: string, private_key?: string, public_key?: string, pub_path?: string, priv_path?: string}
     */
    private static function generateSshKey(string $projectRoot): array
    {
        $sshDir = $projectRoot . '/build/ssh';
        if (!is_dir($sshDir)) {
            if (!@mkdir($sshDir, 0700, true)) {
                return ['success' => false, 'message' => '无法创建 SSH 密钥目录: ' . $sshDir];
            }
        }

        $privPath = $sshDir . '/github_actions_key';
        $pubPath = $sshDir . '/github_actions_key.pub';

        // 如果已存在，直接读取
        if (is_file($privPath) && is_file($pubPath)) {
            $privateKey = file_get_contents($privPath);
            $publicKey = file_get_contents($pubPath);
            if ($privateKey !== false && $publicKey !== false) {
                return [
                    'success'     => true,
                    'message'     => '使用已存在的 SSH 密钥',
                    'private_key' => $privateKey,
                    'public_key'  => trim($publicKey),
                    'pub_path'    => $pubPath,
                    'priv_path'   => $privPath,
                ];
            }
        }

        // 尝试使用 ssh-keygen
        $sshKeygen = trim((string)@shell_exec('which ssh-keygen 2>/dev/null') ?? '');
        if ($sshKeygen !== '') {
            $cmd = sprintf(
                'ssh-keygen -t ed25519 -C "github-actions-build" -f %s -N "" -q 2>&1',
                escapeshellarg($privPath)
            );
            @shell_exec($cmd);

            if (is_file($privPath) && is_file($pubPath)) {
                chmod($privPath, 0600);
                chmod($pubPath, 0644);
                $privateKey = file_get_contents($privPath);
                $publicKey = file_get_contents($pubPath);
                return [
                    'success'     => true,
                    'message'     => 'SSH 密钥生成成功',
                    'private_key' => $privateKey,
                    'public_key'  => trim($publicKey),
                    'pub_path'    => $pubPath,
                    'priv_path'   => $privPath,
                ];
            }
        }

        // 使用 phpseclib 或 openssl 生成（简化：用 openssl 生成 RSA 密钥再转换格式）
        if (function_exists('openssl_pkey_new')) {
            $config = [
                "digest_alg"       => "sha256",
                "private_key_bits" => 2048,
                "private_key_type" => OPENSSL_KEYTYPE_RSA,
            ];

            $privateKeyResource = openssl_pkey_new($config);
            if ($privateKeyResource === false) {
                return ['success' => false, 'message' => '生成 RSA 密钥失败: ' . openssl_error_string()];
            }

            // 导出私钥
            $privateKey = '';
            openssl_pkey_export($privateKeyResource, $privateKey);

            // 导出公钥
            $keyDetails = openssl_pkey_get_details($privateKeyResource);
            $publicKey = $keyDetails['key'] ?? '';

            // 转换为公钥格式（SSH 格式需要 ssh-keygen，这里用 PEM 格式）
            // 注意：GitHub Actions 的 appleboy/scp-action 支持 PEM 格式私钥
            file_put_contents($privPath, $privateKey);
            file_put_contents($pubPath, $publicKey);
            chmod($privPath, 0600);
            chmod($pubPath, 0644);

            return [
                'success'     => true,
                'message'     => 'SSH RSA 密钥生成成功（PEM 格式）',
                'private_key' => $privateKey,
                'public_key'  => trim($publicKey),
                'pub_path'    => $pubPath,
                'priv_path'   => $privPath,
            ];
        }

        return ['success' => false, 'message' => '无法生成 SSH 密钥：ssh-keygen 和 openssl 均不可用'];
    }

    /**
     * 同步配置到 .env 文件
     *
     * @param array $vars [name => value]
     * @return bool
     */
    private static function syncConfigToEnv(array $vars): bool
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        if (!is_file($envFile)) {
            // .env 文件不存在，创建它
            $content = '';
            foreach ($vars as $name => $value) {
                // 值包含空格时用双引号
                if (strpos((string)$value, ' ') !== false) {
                    $content .= "{$name}=\"{$value}\"\n";
                } else {
                    $content .= "{$name}={$value}\n";
                }
            }
            return @file_put_contents($envFile, $content) !== false;
        }

        if (!is_writable($envFile)) {
            return false;
        }

        $content = (string)file_get_contents($envFile);
        $lines = explode("\n", $content);
        $updated = false;
        $found = [];

        foreach ($lines as $i => $line) {
            foreach ($vars as $name => $value) {
                if (preg_match('/^' . preg_quote($name, '/') . '\s*=\s*(.+)$/', $line, $m)) {
                    // 值包含空格时用双引号
                    if (strpos((string)$value, ' ') !== false) {
                        $lines[$i] = "{$name}=\"{$value}\"";
                    } else {
                        $lines[$i] = "{$name}={$value}";
                    }
                    $found[$name] = true;
                    $updated = true;
                }
            }
        }

        // 追加不存在的变量
        foreach ($vars as $name => $value) {
            if (!isset($found[$name])) {
                if (strpos((string)$value, ' ') !== false) {
                    $lines[] = "{$name}=\"{$value}\"";
                } else {
                    $lines[] = "{$name}={$value}";
                }
                $updated = true;
            }
        }

        if ($updated) {
            file_put_contents($envFile, implode("\n", $lines));
        }

        return $updated;
    }

    /**
     * GET /admin/app-build/hbuilderx/templates
     * 获取可用 HBuilderX 模板列表
     */
    public function hbuilderxTemplates(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }
        $service = new \App\Service\HBuilderXService();
        return ['templates' => $service->getAvailableTemplates()];
    }

    /**
     * POST /admin/app-build/hbuilderx/generate
     * 生成 HBuilderX 项目压缩包
     *
     * @param array $context
     * @param array $params
     * @return false
     */
    public function generateHBuilderX(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        $appName = trim((string)($data['app_name'] ?? 'PushApp'));
        $packageName = trim((string)($data['package_name'] ?? 'com.example.pushapp'));
        $defaultKey = trim((string)($data['default_key'] ?? 'default_key'));
        $serverUrl = trim((string)($data['server_url'] ?? ''));
        $wsUrl = trim((string)($data['ws_url'] ?? ''));
        $iconBase64 = (string)($data['icon_base64'] ?? '');
        $version = trim((string)($data['version'] ?? '1.0.0'));
        $template = trim((string)($data['template'] ?? 'new'));
        if (!in_array($template, ['new', 'old'], true)) $template = 'new';

        if ($appName === '') {
            Response::fail($response, '应用名称不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 剥离 data URL 前缀
        $iconBase64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $iconBase64);

        // 项目根目录，根据模板选择目录
        $projectRoot = dirname(__DIR__, 3);
        $templateDir = ($template === 'old')
            ? $projectRoot . '/build/hbuilderx-old'
            : $projectRoot . '/build/hbuilderx';

        if (!is_dir($templateDir)) {
            Response::fail($response, 'HBuilderX 模板目录不存在：' . $templateDir, Response::CODE_INTERNAL);
            return false;
        }

        // 创建临时目录
        $tempDir = sys_get_temp_dir() . '/hbuilderx_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            Response::fail($response, '创建临时目录失败', Response::CODE_INTERNAL);
            return false;
        }

        try {
            // 复制模板文件
            self::copyDir($templateDir, $tempDir);

            // 更新 manifest.json
            $manifestFile = $tempDir . '/manifest.json';
            if (is_file($manifestFile)) {
                $manifest = json_decode(file_get_contents($manifestFile), true);
                if (is_array($manifest)) {
                    $manifest['name'] = $appName;
                    $manifest['versionName'] = $version;
                    $manifest['versionCode'] = (string)(intval(str_replace('.', '', $version)) * 10);
                    if (!empty($packageName)) {
                        $manifest['appid'] = ''; // HBuilderX appid 留空，导入后自动生成
                    }
                    file_put_contents($manifestFile, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }

            // 更新 config.js
            $configFile = $tempDir . '/config.js';
            if (is_file($configFile)) {
                $configJs = "// 应用配置（由构建脚本动态注入）\n";
                $configJs .= "export const APP_CONFIG = {\n";
                $configJs .= "    default_key: '" . addslashes($defaultKey) . "',\n";
                $configJs .= "    server_url: '" . addslashes($serverUrl) . "',\n";
                $configJs .= "    ws_url: '" . addslashes($wsUrl) . "',\n";
                $configJs .= "    version_name: '" . addslashes($version) . "'\n";
                $configJs .= "}\n";
                file_put_contents($configFile, $configJs);
            }

            // 处理图标
            if (!empty($iconBase64)) {
                $iconData = base64_decode($iconBase64);
                if ($iconData !== false) {
                    $iconDir = $tempDir . '/static';
                    if (!is_dir($iconDir)) {
                        mkdir($iconDir, 0755, true);
                    }
                    file_put_contents($iconDir . '/logo.png', $iconData);
                }
            }

            // 创建打包说明
            $readme = "============================================\n";
            $readme .= "HBuilderX uni-app 云打包说明\n";
            $readme .= "============================================\n\n";
            $readme .= "项目类型: uni-app (Vue 3)\n";
            $readme .= "项目名称: {$appName}\n";
            $readme .= "包名: {$packageName}\n";
            $readme .= "版本: {$version}\n\n";
            $readme .= "打包步骤:\n";
            $readme .= "1. 打开 HBuilderX (建议最新版)\n";
            $readme .= "2. 文件 -> 导入 -> 从本地目录导入\n";
            $readme .= "3. 选择本目录（确保识别为 uni-app 项目）\n";
            $readme .= "4. 点击菜单: 发行 -> 原生App-云打包\n";
            $readme .= "5. 在弹出的对话框中:\n";
            $readme .= "   - Android: 勾选\n";
            $readme .= "   - iOS: 按需勾选\n";
            $readme .= "   - 包名: {$packageName}\n";
            $readme .= "   - 证书: 选择自有证书或使用DCloud公用证书\n";
            $readme .= "   - 点击\"打包\"\n";
            $readme .= "6. 等待打包完成，下载 APK/IPA 文件\n\n";
            $readme .= "注意事项:\n";
            $readme .= "- 首次云打包需要在 DCloud 开发者中心实名认证\n";
            $readme .= "- 使用自有证书请确保证书文件和密码正确\n";
            $readme .= "- 包名一旦确定，后续更新请保持一致\n";
            $readme .= "- 服务器地址已内置到应用中，无需额外配置\n";
            $readme .= "- 本项目基于 uni-app Vue 3 开发，支持 Android/iOS\n";
            $readme .= "- 如果导入后不识别为 uni-app 项目，请检查 HBuilderX 版本\n\n";
            $readme .= "项目结构:\n";
            $readme .= "- App.vue         // 应用入口\n";
            $readme .= "- main.js         // 主入口文件\n";
            $readme .= "- manifest.json   // 应用配置\n";
            $readme .= "- pages.json      // 页面路由配置\n";
            $readme .= "- config.js       // 服务器配置\n";
            $readme .= "- pages/          // 页面目录\n";
            $readme .= "- static/         // 静态资源\n\n";
            $readme .= "============================================\n";
            file_put_contents($tempDir . '/README.txt', $readme);

            // 生成 ZIP 文件
            $zipFile = sys_get_temp_dir() . '/' . $appName . '-hbuilderx.zip';
            if (is_file($zipFile)) {
                unlink($zipFile);
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                Response::fail($response, '创建 ZIP 文件失败', Response::CODE_INTERNAL);
                return false;
            }

            // 添加文件到 ZIP
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($tempDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();

            // 输出 ZIP 文件
            $filename = $appName . '-hbuilderx.zip';
            $response->status(200);
            $response->header('Content-Type', 'application/zip');
            $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->header('Content-Length', (string)filesize($zipFile));
            $response->sendfile($zipFile);

            // 清理临时文件
            register_shutdown_function(function () use ($tempDir, $zipFile) {
                self::removeDir($tempDir);
                if (is_file($zipFile)) {
                    unlink($zipFile);
                }
            });

            return false;
        } catch (\Throwable $e) {
            // 清理
            if (is_dir($tempDir)) {
                self::removeDir($tempDir);
            }
            Response::fail($response, '生成 HBuilderX 项目失败: ' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }
    }

    /**
     * POST /admin/app-build/ios/generate
     * 生成 iOS 源码压缩包（含动态注入的服务器配置）
     *
     * 注意：iOS IPA 必须在 macOS + Xcode 环境编译，Linux 服务器无法直接构建。
     * 本接口仅生成"已注入配置的 Xcode 项目源码 ZIP"，用户下载后在 Mac 上用 Xcode 打开编译即可。
     *
     * 注入内容：
     *   - Bundle Identifier（包名）
     *   - 应用显示名（CFBundleDisplayName）
     *   - 版本号（MARKETING_VERSION）
     *   - 服务器地址 / WebSocket 地址 / 默认 Key（注入到 PreferencesManager.swift 默认值）
     *   - APNS 环境（development / production）
     *
     * @param array $context
     * @param array $params
     * @return false
     */
    public function generateIosSource(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        $appName      = trim((string)($data['app_name'] ?? 'PushApp'));
        $bundleId     = trim((string)($data['package_name'] ?? 'com.pushapp.ios'));
        $defaultKey   = trim((string)($data['default_key'] ?? 'default_key'));
        $serverUrl    = trim((string)($data['server_url'] ?? ''));
        $wsUrl        = trim((string)($data['ws_url'] ?? ''));
        $version      = trim((string)($data['version'] ?? '1.0.0'));
        $apnsEnv      = trim((string)($data['apns_environment'] ?? 'development'));
        $iconBase64   = (string)($data['icon_base64'] ?? '');

        if ($appName === '') {
            Response::fail($response, '应用名称不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 校验 APNS 环境
        if (!in_array($apnsEnv, ['development', 'production'], true)) {
            $apnsEnv = 'development';
        }

        // 校验 Bundle ID 格式（反向域名）
        if ($bundleId !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*(\.[a-zA-Z][a-zA-Z0-9_-]*)+$/', $bundleId)) {
            Response::fail($response, 'Bundle ID 格式不正确，需符合反向域名格式（如 com.example.app）', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 剥离 data URL 前缀
        $iconBase64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $iconBase64);

        $projectRoot = dirname(__DIR__, 3);
        $templateDir = $projectRoot . '/ios';

        if (!is_dir($templateDir)) {
            Response::fail($response, 'iOS 源码模板目录不存在（ios/）', Response::CODE_INTERNAL);
            return false;
        }

        // 创建临时目录（使用项目目录下的 .deploy 避免权限问题）
        $tempBase = $projectRoot . '/.deploy/ios_build';
        if (!is_dir($tempBase)) {
            if (!@mkdir($tempBase, 0755, true)) {
                Response::fail($response, '创建临时目录失败: ' . $tempBase, Response::CODE_INTERNAL);
                return false;
            }
        }
        $tempDir = $tempBase . '/ios_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            Response::fail($response, '创建构建目录失败', Response::CODE_INTERNAL);
            return false;
        }

        try {
            // 1. 复制 iOS 模板到临时目录
            self::copyDir($templateDir, $tempDir);

            // 2. 注入配置到 PreferencesManager.swift（添加 AppConfig 默认值）
            $prefFile = $tempDir . '/PushApp/PreferencesManager.swift';
            if (is_file($prefFile)) {
                $content = (string)file_get_contents($prefFile);

                // 在 import Foundation 后注入 AppConfig 结构体
                $appConfigCode = "\n/**\n * AppConfig - 构建时注入的默认配置\n * 由后台生成 iOS 源码时动态写入，用户未在 App 中配置时使用这些默认值\n */\nstruct AppConfig {\n    static let serverUrl = " . self::swiftStr($serverUrl) . "\n    static let pushKey = " . self::swiftStr($defaultKey) . "\n    static let wsUrl = " . self::swiftStr($wsUrl) . "\n    static let version = " . self::swiftStr($version) . "\n}\n";

                // 在 import Foundation 后插入
                $content = preg_replace(
                    '/^(import Foundation\s*\n)/m',
                    '$1' . $appConfigCode,
                    $content,
                    1
                );

                // 修改 serverUrl getter：未配置时返回 AppConfig.serverUrl
                $content = preg_replace(
                    '/var serverUrl: String \{\s*get \{ defaults\.string\(forKey: kServerUrl\) \?\? "" \}\s*set \{ defaults\.set\(newValue, forKey: kServerUrl\) \}\s*\}/',
                    'var serverUrl: String {' . "\n" .
                    '        get {' . "\n" .
                    '            let v = defaults.string(forKey: kServerUrl) ?? ""' . "\n" .
                    '            return v.isEmpty ? AppConfig.serverUrl : v' . "\n" .
                    '        }' . "\n" .
                    '        set { defaults.set(newValue, forKey: kServerUrl) }' . "\n" .
                    '    }',
                    $content
                );

                // 修改 pushKey getter：未配置时返回 AppConfig.pushKey
                $content = preg_replace(
                    '/var pushKey: String \{\s*get \{ defaults\.string\(forKey: kPushKey\) \?\? "" \}\s*set \{ defaults\.set\(newValue, forKey: kPushKey\) \}\s*\}/',
                    'var pushKey: String {' . "\n" .
                    '        get {' . "\n" .
                    '            let v = defaults.string(forKey: kPushKey) ?? ""' . "\n" .
                    '            return v.isEmpty ? AppConfig.pushKey : v' . "\n" .
                    '        }' . "\n" .
                    '        set { defaults.set(newValue, forKey: kPushKey) }' . "\n" .
                    '    }',
                    $content
                );

                file_put_contents($prefFile, $content);
            }

            // 3. 修改 project.pbxproj 中的 Bundle ID、显示名、版本号
            $pbxFile = $tempDir . '/PushApp.xcodeproj/project.pbxproj';
            if (is_file($pbxFile)) {
                $content = (string)file_get_contents($pbxFile);

                // 替换 PRODUCT_BUNDLE_IDENTIFIER（出现 2 次：Debug + Release）
                if ($bundleId !== '') {
                    $content = str_replace(
                        'PRODUCT_BUNDLE_IDENTIFIER = "com.pushapp.ios";',
                        'PRODUCT_BUNDLE_IDENTIFIER = "' . $bundleId . '";',
                        $content
                    );
                }

                // 替换 INFOPLIST_KEY_CFBundleDisplayName（出现 2 次）
                $content = str_replace(
                    'INFOPLIST_KEY_CFBundleDisplayName = "推送助手";',
                    'INFOPLIST_KEY_CFBundleDisplayName = "' . $appName . '";',
                    $content
                );

                // 替换 MARKETING_VERSION（出现 2 次）
                if ($version !== '') {
                    $content = preg_replace(
                        '/MARKETING_VERSION = [^;]+;/',
                        'MARKETING_VERSION = ' . $version . ';',
                        $content
                    );
                }

                file_put_contents($pbxFile, $content);
            }

            // 4. 修改 Info.plist 中的 CFBundleDisplayName
            $infoFile = $tempDir . '/PushApp/Info.plist';
            if (is_file($infoFile)) {
                $content = (string)file_get_contents($infoFile);
                $content = str_replace(
                    '<string>推送助手</string>',
                    '<string>' . htmlspecialchars($appName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</string>',
                    $content
                );
                // 更新版本号
                if ($version !== '') {
                    $content = preg_replace(
                        '/<key>CFBundleShortVersionString<\/key>\s*<string>[^<]*<\/string>/',
                        '<key>CFBundleShortVersionString</key>' . "\n\t" . '<string>' . $version . '</string>',
                        $content
                    );
                }
                file_put_contents($infoFile, $content);
            }

            // 5. 修改 PushApp.entitlements 中的 APNS 环境
            $entFile = $tempDir . '/PushApp/PushApp.entitlements';
            if (is_file($entFile)) {
                $content = (string)file_get_contents($entFile);
                $content = preg_replace(
                    '/<key>aps-environment<\/key>\s*<string>[^<]*<\/string>/',
                    '<key>aps-environment</key>' . "\n\t" . '<string>' . $apnsEnv . '</string>',
                    $content
                );
                file_put_contents($entFile, $content);
            }

            // 6. 处理图标（替换 AppIcon）
            if (!empty($iconBase64)) {
                $iconData = base64_decode($iconBase64);
                if ($iconData !== false) {
                    $iconDir = $tempDir . '/PushApp/Assets.xcassets/AppIcon.appiconset';
                    if (is_dir($iconDir)) {
                        // 生成 1024x1024 的图标（如果 GD 可用）
                        if (function_exists('imagecreatefromstring')) {
                            $srcImg = @imagecreatefromstring($iconData);
                            if ($srcImg !== false) {
                                $srcW = imagesx($srcImg);
                                $srcH = imagesy($srcImg);
                                // 生成 1024x1024 PNG
                                $icon1024 = imagecreatetruecolor(1024, 1024);
                                imagealphablending($icon1024, false);
                                imagesavealpha($icon1024, true);
                                imagecopyresampled($icon1024, $srcImg, 0, 0, 0, 0, 1024, 1024, $srcW, $srcH);
                                imagepng($icon1024, $iconDir . '/icon-1024.png');
                                imagedestroy($icon1024);
                                imagedestroy($srcImg);

                                // 更新 Contents.json，只保留 1024x1024 单尺寸（iOS 14+ 支持单尺寸）
                                $contentsJson = [
                                    'images' => [
                                        [
                                            'filename' => 'icon-1024.png',
                                            'idiom' => 'universal',
                                            'platform' => 'ios',
                                            'size' => '1024x1024',
                                        ],
                                    ],
                                    'info' => [
                                        'author' => 'xcode',
                                        'version' => 1,
                                    ],
                                ];
                                file_put_contents(
                                    $iconDir . '/Contents.json',
                                    json_encode($contentsJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                );
                            }
                        }
                    }
                }
            }

            // 7. 生成 README.txt（Xcode 编译说明）
            $readme = "============================================\n";
            $readme .= "iOS 源码 - Xcode 编译说明\n";
            $readme .= "============================================\n\n";
            $readme .= "项目名称: {$appName}\n";
            $readme .= "Bundle ID: {$bundleId}\n";
            $readme .= "版本: {$version}\n";
            $readme .= "APNS 环境: {$apnsEnv}\n";
            $readme .= "服务器地址: {$serverUrl}\n";
            $readme .= "WebSocket 地址: {$wsUrl}\n";
            $readme .= "默认推送 Key: {$defaultKey}\n\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "编译环境要求\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "- macOS 13.0 或更高版本\n";
            $readme .= "- Xcode 15.0 或更高版本\n";
            $readme .= "- iOS 16.0+ 的 iPhone 或 iPad（真机调试）\n";
            $readme .= "- Apple Developer 账号（免费账号可真机调试，但 APNS 需付费账号）\n\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "编译步骤\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "1. 解压 ZIP 文件，得到 PushApp 文件夹\n";
            $readme .= "2. 双击 PushApp.xcodeproj 打开 Xcode\n";
            $readme .= "3. 在 Xcode 左侧选中 PushApp 项目\n";
            $readme .= "4. 选择 TARGETS -> PushApp -> Signing & Capabilities\n";
            $readme .= "5. 勾选 Automatically manage signing\n";
            $readme .= "6. 在 Team 下拉框选择你的 Apple Developer 账号\n";
            $readme .= "   (免费账号也可，但 Bundle ID 需改为独有的，避免冲突)\n";
            $readme .= "7. 连接 iPhone 真机，在 Xcode 顶部选择目标设备\n";
            $readme .= "8. 按 Cmd+R 编译并运行\n";
            $readme .= "9. 首次运行时，iOS 会弹出推送权限请求，选择\"允许\"\n\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "APNS 推送配置说明\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "APNS 需要 Apple Developer 付费账号（\$99/年）:\n";
            $readme .= "1. 在 Apple Developer 后台创建 APNS Auth Key (.p8 文件)\n";
            $readme .= "2. 在推送系统后台 -> 设置 -> iOS APNS 推送配置 中填入:\n";
            $readme .= "   - Team ID\n";
            $readme .= "   - Key ID\n";
            $readme .= "   - Auth Key (.p8 文件内容)\n";
            $readme .= "   - Bundle ID: {$bundleId}\n";
            $readme .= "3. APNS 环境选择:\n";
            $readme .= "   - development: Xcode 真机调试时使用\n";
            $readme .= "   - production: App Store 上架后使用\n";
            $readme .= "4. App 首次启动会自动注册 APNS 并上报设备 token\n\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "配置说明\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "服务器地址、WebSocket 地址、推送 Key 已内置到源码中:\n";
            $readme .= "- 默认服务器: {$serverUrl}\n";
            $readme .= "- 默认 WebSocket: {$wsUrl}\n";
            $readme .= "- 默认推送 Key: {$defaultKey}\n";
            $readme .= "用户也可在 App 内\"设置\"页面手动修改\n\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "常见问题\n";
            $readme .= "--------------------------------------------\n";
            $readme .= "Q: 编译报错 \"Signing for \\\"PushApp\\\" requires a development team\"?\n";
            $readme .= "A: 在 Xcode -> Signing & Capabilities 中选择你的 Apple Team\n\n";
            $readme .= "Q: 推送收不到?\n";
            $readme .= "A: 1. 检查 App 是否允许通知\n";
            $readme .= "   2. 检查后台 APNS 配置是否正确\n";
            $readme .= "   3. development 环境的 token 不能用于 production，反之亦然\n";
            $readme .= "   4. 真机调试时 APNS 环境必须为 development\n\n";
            $readme .= "Q: WebSocket 连不上?\n";
            $readme .= "A: 1. 检查服务器地址是否正确\n";
            $readme .= "   2. 检查服务器是否启用 SSL（HTTPS/WSS）\n";
            $readme .= "   3. iOS 不允许 HTTP 明文连接（ATS），需启用 SSL 或在 Info.plist 配置例外\n\n";
            $readme .= "============================================\n";
            file_put_contents($tempDir . '/README.txt', $readme);

            // 8. 打包为 ZIP
            $zipFile = $tempBase . '/' . $appName . '-ios-source.zip';
            if (is_file($zipFile)) {
                unlink($zipFile);
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                Response::fail($response, '创建 ZIP 文件失败', Response::CODE_INTERNAL);
                return false;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($tempDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();

            // 9. 输出 ZIP 文件
            $filename = $appName . '-ios-source.zip';
            $response->status(200);
            $response->header('Content-Type', 'application/zip');
            $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->header('Content-Length', (string)filesize($zipFile));
            $response->sendfile($zipFile);

            // 10. 清理临时目录（保留 ZIP 以便 sendfile 完成后清理）
            register_shutdown_function(function () use ($tempDir, $zipFile) {
                self::removeDir($tempDir);
                if (is_file($zipFile)) {
                    @unlink($zipFile);
                }
            });

            return false;
        } catch (\Throwable $e) {
            if (is_dir($tempDir)) {
                self::removeDir($tempDir);
            }
            Response::fail($response, '生成 iOS 源码失败: ' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }
    }

    /**
     * GET /admin/app-build/compose/templates
     * 获取可用 Jetpack Compose 源码模板列表
     */
    public function composeTemplates(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }
        $response = $context['response'];
        $service = new \App\Service\ComposeService();
        Response::success($response, ['templates' => $service->getAvailableTypes()]);
        return false;
    }

    /**
     * POST /admin/app-build/compose/generate
     * 生成 Jetpack Compose 源码 ZIP 并直接返回下载流
     *
     * 请求体：
     *   app_name       应用名称（必填）
     *   package_name   包名（可选，默认 com.push.app）
     *   default_key    默认推送 Key（可选）
     *   server_url     HTTP 服务器地址（必填）
     *   ws_url         WebSocket 地址（必填）
     *   version_name   版本号（可选，默认 1.0.0）
     *   version_code   版本码（可选，默认 1）
     *   icon_base64    图标 base64（可选）
     */
    public function generateComposeSource(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $response = $context['response'];
        $data = $this->parseBody($context);

        $appName     = trim((string)($data['app_name'] ?? 'PushApp'));
        $pkgName     = trim((string)($data['package_name'] ?? 'com.push.app'));
        $defaultKey  = trim((string)($data['default_key'] ?? 'default_key'));
        $serverUrl   = trim((string)($data['server_url'] ?? ''));
        $wsUrl       = trim((string)($data['ws_url'] ?? ''));
        $versionName = trim((string)($data['version_name'] ?? '1.0.0'));
        $versionCode = (int)($data['version_code'] ?? 1);
        $iconBase64  = (string)($data['icon_base64'] ?? '');

        if ($appName === '') {
            Response::fail($response, '应用名称不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }
        if ($serverUrl === '' || $wsUrl === '') {
            Response::fail($response, '服务器地址和 WebSocket 地址不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }
        // 校验包名格式（反向域名）
        if ($pkgName !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*(\.[a-zA-Z][a-zA-Z0-9_-]*)+$/', $pkgName)) {
            Response::fail($response, '包名格式不正确（如 com.example.app）', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 剥离 data URL 前缀
        $iconBase64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $iconBase64);

        try {
            $service = new \App\Service\ComposeService();
            $zipPath = $service->generateZip([
                'user_id'      => (int)($payload['user_id'] ?? 0),
                'app_name'     => $appName,
                'package_name' => $pkgName,
                'default_key'  => $defaultKey,
                'server_url'   => $serverUrl,
                'ws_url'       => $wsUrl,
                'version_name' => $versionName,
                'version_code' => $versionCode,
                'icon_base64'  => $iconBase64,
            ]);

            if (!is_file($zipPath) || filesize($zipPath) === 0) {
                Response::fail($response, '生成 ZIP 失败，文件为空', Response::CODE_INTERNAL);
                return false;
            }

            // 直接下载
            $filename = $appName . '-compose.zip';
            $response->header('Content-Type', 'application/zip');
            $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->header('Content-Length', (string)filesize($zipPath));
            $response->sendfile($zipPath);

            register_shutdown_function(function () use ($zipPath) {
                @unlink($zipPath);
            });

            return false;
        } catch (\Throwable $e) {
            Response::fail($response, '生成 Compose 源码失败: ' . $e->getMessage(), Response::CODE_INTERNAL);
            return false;
        }
    }

    /**
     * 将 PHP 字符串转为 Swift 字符串字面量（带引号和转义）
     *
     * @param string $value
     * @return string  如 "https://example.com"
     */
    private static function swiftStr(string $value): string
    {
        // 转义反斜杠和双引号
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        return '"' . $escaped . '"';
    }

    /**
     * 复制目录
     *
     * @param string $src
     * @param string $dst
     * @return void
     */
    private static function copyDir(string $src, string $dst): void
    {
        $dir = opendir($src);
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        while (false !== ($file = readdir($dir))) {
            if ($file !== '.' && $file !== '..') {
                $srcPath = $src . '/' . $file;
                $dstPath = $dst . '/' . $file;
                if (is_dir($srcPath)) {
                    self::copyDir($srcPath, $dstPath);
                } else {
                    copy($srcPath, $dstPath);
                }
            }
        }
        closedir($dir);
    }

    /**
     * 删除目录
     *
     * @param string $dir
     * @return void
     */
    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
