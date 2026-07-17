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
            $config = Config::get('github', []);
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
        $iconBase64 = (string)($data['icon_path'] ?? '');  // 前端传的是 base64 字符串

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
        $config = Config::get('github', []);
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
            ];
            $result = GitHubActionsService::triggerBuild($inputs);
            if (!$result['dispatched']) {
                Response::fail($response, $result['message'], Response::CODE_INTERNAL);
                return false;
            }

            // 读取仓库信息用于返回 actions 页面链接
            $config = Config::get('github', []);
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

        $perPage = (int)($_GET['per_page'] ?? 20);
        $page = (int)($_GET['page'] ?? 1);

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
     * GET /admin/app-build/log/{build_id}/download
     * 下载构建日志文件
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

        $logContent = \BuildServer\BuildQueue::getBuildLog($buildId);
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

        if (!self::isAvailable()) {
            Response::fail($response, '打包服务未配置');
            return false;
        }

        \BuildServer\BuildQueue::deleteBuild($buildId);

        return ['message' => '删除成功'];
    }
}
