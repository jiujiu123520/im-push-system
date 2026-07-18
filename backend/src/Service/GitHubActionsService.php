<?php
declare(strict_types=1);

namespace App\Service;

/**
 * GitHub Actions API 客户端
 *
 * 封装 GitHub Actions workflow_dispatch 触发和状态查询。
 * 国内服务器通过 gh.jasonzeng.dev 代理访问 api.github.com。
 *
 * 所需 GitHub Secrets(在仓库 Settings → Secrets and variables → Actions 中配置):
 * - APK_KEYSTORE_BASE64: keystore 文件 base64 编码
 * - APK_KEYSTORE_PASSWORD: keystore 密码
 * - APK_KEY_ALIAS: 密钥别名
 * - APK_KEY_PASSWORD: 密钥密码
 * - SERVER_SSH_HOST: 服务器 IP
 * - SERVER_SSH_PORT: SSH 端口(通常 22)
 * - SERVER_SSH_USER: SSH 用户(如 ubuntu)
 * - SERVER_SSH_KEY: SSH 私钥(用于 SCP 和 SSH 调用)
 */
class GitHubActionsService
{
    /** @var array|null 配置缓存 */
    private static $config = null;

    /** @var string|null GitHub API base URL(应用代理后) */
    private static $apiBase = null;

    /**
     * 加载配置
     *
     * @return array
     */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }
        self::$config = Config::get('github') ?? [];
        return self::$config;
    }

    /**
     * 获取 GitHub API base URL(应用代理)
     *
     * 代理格式: https://gh.jasonzeng.dev/https://api.github.com
     * 直连格式: https://api.github.com
     *
     * @return string
     */
    private static function apiBase(): string
    {
        if (self::$apiBase !== null) {
            return self::$apiBase;
        }
        $config = self::config();
        $proxyEnabled = !empty($config['proxy_enabled']) && $config['proxy_enabled'] !== false && $config['proxy_enabled'] !== 'false' && $config['proxy_enabled'] !== '0';
        $proxy = $config['api_proxy'] ?? '';
        if ($proxyEnabled && !empty($proxy)) {
            // 代理 URL 末尾确保有 /
            if (substr($proxy, -1) !== '/') {
                $proxy .= '/';
            }
            self::$apiBase = $proxy . 'https://api.github.com';
        } else {
            self::$apiBase = 'https://api.github.com';
        }
        return self::$apiBase;
    }

    /**
     * 重置配置缓存(修改配置后调用)
     */
    public static function resetConfig(): void
    {
        self::$config = null;
        self::$apiBase = null;
    }

    /**
     * 发送 GitHub API 请求
     *
     * @param string $method HTTP 方法(GET/POST/PUT/DELETE)
     * @param string $path API 路径(如 /repos/{owner}/{repo}/actions/runs)
     * @param array|null $data 请求数据(POST 时使用)
     * @return array{status: int, body: string, json: ?array}
     * @throws \RuntimeException 当 cURL 失败时抛出
     */
    private static function request(string $method, string $path, ?array $data = null): array
    {
        $config = self::config();
        $token = $config['token'] ?? '';
        if (empty($token)) {
            throw new \RuntimeException('GitHub Token 未配置(GITHUB_TOKEN)');
        }

        $url = self::apiBase() . $path;
        $timeout = $config['timeout'] ?? 30;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'Content-Type: application/json',
                'User-Agent: im-push-system/1.0',
            ],
        ]);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException("GitHub API 请求失败(cURL error $errno): $errmsg");
        }

        $json = null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'status' => (int)$status,
            'body' => (string)$body,
            'json' => $json,
        ];
    }

    /**
     * 触发 workflow_dispatch 构建 APK
     *
     * @param array $inputs {
     *     build_id: string,
     *     app_name: string,
     *     package_name: string,
     *     default_key: string,
     *     server_url: string,
     *     ws_url: string,
     *     icon_base64: string (可选)
     * }
     * @return array{dispatched: bool, message: string}
     * @throws \RuntimeException
     */
    public static function triggerBuild(array $inputs): array
    {
        $config = self::config();
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';
        $workflowFile = $config['workflow_file'] ?? 'build-apk.yml';
        $ref = $config['ref'] ?? 'main';

        if (empty($owner) || empty($repo)) {
            throw new \RuntimeException('GitHub 仓库配置不完整(GITHUB_OWNER/GITHUB_REPO)');
        }

        $path = "/repos/{$owner}/{$repo}/actions/workflows/{$workflowFile}/dispatches";

        $response = self::request('POST', $path, [
            'ref' => $ref,
            'inputs' => $inputs,
        ]);

        if ($response['status'] === 204) {
            return [
                'dispatched' => true,
                'message' => '已触发 GitHub Actions 构建',
            ];
        }

        $errMsg = '触发构建失败';
        if (isset($response['json']['message'])) {
            $errMsg .= ': ' . $response['json']['message'];
        } else {
            $errMsg .= '(HTTP ' . $response['status'] . ')';
        }
        return [
            'dispatched' => false,
            'message' => $errMsg,
        ];
    }

    /**
     * 查询最近的 workflow run 状态(通过 build_id 反查)
     *
     * 注意: GitHub API 不支持按 inputs 过滤 runs,只能获取最近 runs 后在客户端匹配。
     * 本方法用于备用查询,主流程通过 SSH 回调更新状态。
     *
     * @param string $buildId
     * @return array{found: bool, status: string, conclusion: string, html_url: string}
     */
    public static function queryRunStatus(string $buildId): array
    {
        $config = self::config();
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';

        $path = "/repos/{$owner}/{$repo}/actions/runs?per_page=20";
        $response = self::request('GET', $path);

        if ($response['status'] !== 200 || !isset($response['json']['workflow_runs'])) {
            return ['found' => false, 'status' => 'unknown', 'conclusion' => '', 'html_url' => ''];
        }

        foreach ($response['json']['workflow_runs'] as $run) {
            $runInputs = $run['display_title'] ?? '';
            // 在 run name 中查找 build_id(workflow_dispatch 触发的 run name 默认是 trigger 信息)
            if (strpos($runInputs, $buildId) !== false ||
                (isset($run['name']) && strpos((string)$run['name'], $buildId) !== false)) {
                return [
                    'found' => true,
                    'status' => (string)($run['status'] ?? 'unknown'),       // queued, in_progress, completed
                    'conclusion' => (string)($run['conclusion'] ?? ''),      // success, failure, cancelled
                    'html_url' => (string)($run['html_url'] ?? ''),
                ];
            }
        }

        return ['found' => false, 'status' => 'unknown', 'conclusion' => '', 'html_url' => ''];
    }

    /**
     * 列出最近的 workflow runs(用于手动构建页面展示运行历史)
     *
     * @param int $perPage 每页数量(最大 100)
     * @param int $page 页码
     * @return array{total: int, runs: array}
     */
    public static function queryRunsList(int $perPage = 20, int $page = 1): array
    {
        $config = self::config();
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';
        $workflowFile = $config['workflow_file'] ?? 'build-apk.yml';

        if (empty($owner) || empty($repo)) {
            return ['total' => 0, 'runs' => []];
        }

        // 限定单 workflow 文件,避免其他 workflow 干扰
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $path = "/repos/{$owner}/{$repo}/actions/workflows/{$workflowFile}/runs?per_page={$perPage}&page={$page}";
        $response = self::request('GET', $path);

        if ($response['status'] !== 200 || !isset($response['json']['workflow_runs'])) {
            return ['total' => 0, 'runs' => []];
        }

        $runs = [];
        foreach ($response['json']['workflow_runs'] as $run) {
            // 提取 inputs 中的 build_id(若存在)
            $buildId = '';
            if (isset($run['name']) && preg_match('/b[0-9a-f]{13}/', (string)$run['name'], $m)) {
                $buildId = $m[0];
            }

            $runs[] = [
                'id'           => (int)($run['id'] ?? 0),
                'name'         => (string)($run['name'] ?? ''),
                'build_id'     => $buildId,
                'status'       => (string)($run['status'] ?? 'unknown'),        // queued, in_progress, completed
                'conclusion'   => (string)($run['conclusion'] ?? ''),           // success, failure, cancelled
                'display_title'=> (string)($run['display_title'] ?? ''),
                'html_url'     => (string)($run['html_url'] ?? ''),
                'created_at'   => (string)($run['created_at'] ?? ''),
                'updated_at'   => (string)($run['updated_at'] ?? ''),
                'run_started_at' => (string)($run['run_started_at'] ?? ''),
                'actor'        => (string)($run['actor']['login'] ?? ''),
                'event'        => (string)($run['event'] ?? ''),                 // workflow_dispatch, push, etc.
                'head_branch'  => (string)($run['head_branch'] ?? ''),
            ];
        }

        return [
            'total' => (int)($response['json']['total_count'] ?? count($runs)),
            'runs'  => $runs,
        ];
    }

    /**
     * 验证 GitHub Token 是否有效以及仓库是否存在
     *
     * @param string $token
     * @param string $owner
     * @param string $repo
     * @return array{valid: bool, message: string, repo_exists: bool, can_push: bool, scopes: array}
     */
    public static function validateToken(string $token, string $owner, string $repo): array
    {
        try {
            // 1. 验证 token 基本有效性（获取 user 信息）
            $userResponse = self::requestWithToken($token, 'GET', '/user');
            if ($userResponse['status'] !== 200) {
                $msg = $userResponse['json']['message'] ?? 'Token 无效';
                return ['valid' => false, 'message' => 'Token 验证失败: ' . $msg, 'repo_exists' => false, 'can_push' => false, 'scopes' => []];
            }

            // 从响应头获取 scopes
            $scopes = [];
            $headers = $userResponse['headers'] ?? [];
            // 不区分大小写查找 X-OAuth-Scopes 头
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'x-oauth-scopes') {
                    $scopes = array_map('trim', explode(',', $value));
                    break;
                }
            }

            // 2. 验证仓库是否存在
            $repoResponse = self::requestWithToken($token, 'GET', "/repos/{$owner}/{$repo}");
            $repoExists = $repoResponse['status'] === 200;

            // 3. 检查是否有 push 权限
            $canPush = false;
            if ($repoExists && isset($repoResponse['json']['permissions'])) {
                $permissions = $repoResponse['json']['permissions'];
                $canPush = !empty($permissions['push']) || !empty($permissions['admin']);
            }

            // 4. 检查是否有 workflow 权限（需要检查 token scope）
            $hasWorkflowScope = in_array('workflow', $scopes, true);
            $hasRepoScope = in_array('repo', $scopes, true);

            $message = 'Token 有效';
            if (!$repoExists) {
                $message = 'Token 有效，但仓库不存在';
            } elseif (!$canPush) {
                $message = 'Token 有效，仓库存在，但无 push 权限';
            } elseif (!$hasWorkflowScope) {
                $message = 'Token 有效，但缺少 workflow 权限，无法触发 Actions';
            }

            return [
                'valid'       => true,
                'message'     => $message,
                'repo_exists' => $repoExists,
                'can_push'    => $canPush,
                'has_workflow_scope' => $hasWorkflowScope,
                'has_repo_scope'     => $hasRepoScope,
                'scopes'      => $scopes,
                'user'        => $userResponse['json']['login'] ?? '',
            ];
        } catch (\Throwable $e) {
            return ['valid' => false, 'message' => '验证失败: ' . $e->getMessage(), 'repo_exists' => false, 'can_push' => false, 'scopes' => []];
        }
    }

    /**
     * 使用临时配置发送请求（用于验证新 token / 测试代理连接）
     *
     * @param string $token
     * @param string $method
     * @param string $path
     * @param array|null $data
     * @param array $options {
     *     @type string|null $api_proxy  自定义代理地址(覆盖配置)
     *     @type bool|null   $proxy_enabled 是否启用代理(覆盖配置)
     *     @type int|null    $timeout  超时时间(秒)
     * }
     * @return array{status: int, body: string, json: ?array, headers: array}
     */
    private static function requestWithToken(string $token, string $method, string $path, ?array $data = null, array $options = []): array
    {
        $config = self::config();

        // 应用选项覆盖
        $proxy = isset($options['api_proxy']) ? (string)$options['api_proxy'] : ($config['api_proxy'] ?? '');
        $proxyEnabled = isset($options['proxy_enabled'])
            ? ($options['proxy_enabled'] !== false && $options['proxy_enabled'] !== 'false' && $options['proxy_enabled'] !== '0')
            : (!empty($config['proxy_enabled']) && $config['proxy_enabled'] !== false && $config['proxy_enabled'] !== 'false' && $config['proxy_enabled'] !== '0');
        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : ($config['timeout'] ?? 30);

        if ($proxyEnabled && !empty($proxy)) {
            if (substr($proxy, -1) !== '/') {
                $proxy .= '/';
            }
            $apiBase = $proxy . 'https://api.github.com';
        } else {
            $apiBase = 'https://api.github.com';
        }

        $url = $apiBase . $path;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'Content-Type: application/json',
                'User-Agent: im-push-system/1.0',
            ],
        ]);

        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
        }

        // 获取响应头
        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$headers) {
            $len = strlen($header);
            $header = trim($header);
            if (strpos($header, ':') !== false) {
                [$key, $value] = explode(':', $header, 2);
                $headers[trim($key)] = trim($value);
            }
            return $len;
        });

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException("GitHub API 请求失败(cURL error $errno): $errmsg");
        }

        $json = null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }

        return [
            'status'  => (int)$status,
            'body'    => (string)$body,
            'json'    => $json,
            'headers' => $headers,
        ];
    }

    /**
     * 获取仓库的公钥（用于加密 Secrets）
     *
     * @param string $owner
     * @param string $repo
     * @return array{key_id: string, key: string}
     * @throws \RuntimeException
     */
    public static function getRepoPublicKey(string $owner, string $repo): array
    {
        $path = "/repos/{$owner}/{$repo}/actions/secrets/public-key";
        $response = self::request('GET', $path);

        if ($response['status'] !== 200 || !isset($response['json']['key'], $response['json']['key_id'])) {
            throw new \RuntimeException('获取仓库公钥失败: ' . ($response['json']['message'] ?? 'HTTP ' . $response['status']));
        }

        return [
            'key_id' => $response['json']['key_id'],
            'key'    => $response['json']['key'],
        ];
    }

    /**
     * 使用 sodium 加密 secret 值
     *
     * @param string $publicKey Base64 编码的公钥
     * @param string $secretValue 要加密的明文
     * @return string Base64 编码的加密值
     * @throws \RuntimeException
     */
    private static function encryptSecret(string $publicKey, string $secretValue): string
    {
        if (!function_exists('sodium_crypto_box_seal')) {
            throw new \RuntimeException('PHP sodium 扩展未安装，无法加密 Secrets');
        }

        $keyBytes = base64_decode($publicKey);
        if ($keyBytes === false) {
            throw new \RuntimeException('公钥 base64 解码失败');
        }

        $encrypted = sodium_crypto_box_seal($secretValue, $keyBytes);
        return base64_encode($encrypted);
    }

    /**
     * 创建或更新仓库 Secret
     *
     * @param string $secretName Secret 名称
     * @param string $secretValue Secret 值
     * @return bool
     * @throws \RuntimeException
     */
    public static function setSecret(string $secretName, string $secretValue): bool
    {
        $config = self::config();
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';

        if (empty($owner) || empty($repo)) {
            throw new \RuntimeException('仓库配置不完整');
        }

        // 1. 获取公钥
        $publicKey = self::getRepoPublicKey($owner, $repo);

        // 2. 加密 secret
        $encryptedValue = self::encryptSecret($publicKey['key'], $secretValue);

        // 3. 上传 secret
        $path = "/repos/{$owner}/{$repo}/actions/secrets/" . $secretName;
        $response = self::request('PUT', $path, [
            'encrypted_value' => $encryptedValue,
            'key_id'          => $publicKey['key_id'],
        ]);

        // 201 = 创建成功, 204 = 更新成功
        if (!in_array($response['status'], [201, 204], true)) {
            $msg = $response['json']['message'] ?? 'HTTP ' . $response['status'];
            throw new \RuntimeException("设置 Secret {$secretName} 失败: {$msg}");
        }

        return true;
    }

    /**
     * 批量设置 Secrets
     *
     * @param array $secrets [name => value]
     * @return array{success: array, failed: array}
     */
    public static function setSecrets(array $secrets): array
    {
        $success = [];
        $failed = [];

        foreach ($secrets as $name => $value) {
            try {
                self::setSecret($name, $value);
                $success[] = $name;
            } catch (\Throwable $e) {
                $failed[$name] = $e->getMessage();
            }
        }

        return ['success' => $success, 'failed' => $failed];
    }

    /**
     * 检查 workflow 文件是否存在于仓库
     *
     * @param string $workflowFile
     * @return bool
     */
    public static function workflowExists(string $workflowFile = 'build-apk.yml'): bool
    {
        $config = self::config();
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';
        $ref = $config['ref'] ?? 'main';

        if (empty($owner) || empty($repo)) {
            return false;
        }

        $path = "/repos/{$owner}/{$repo}/contents/.github/workflows/{$workflowFile}?ref=" . urlencode($ref);
        try {
            $response = self::request('GET', $path);
            return $response['status'] === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 列出仓库的 Secrets（只返回名称，不返回值）
     *
     * @return array{total: int, secrets: array}
     */
    public static function listSecrets(): array
    {
        $config = self::config();
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';

        if (empty($owner) || empty($repo)) {
            return ['total' => 0, 'secrets' => []];
        }

        $path = "/repos/{$owner}/{$repo}/actions/secrets?per_page=100";
        try {
            $response = self::request('GET', $path);
            if ($response['status'] !== 200 || !isset($response['json']['secrets'])) {
                return ['total' => 0, 'secrets' => []];
            }

            $secrets = [];
            foreach ($response['json']['secrets'] as $s) {
                $secrets[] = [
                    'name'       => $s['name'] ?? '',
                    'created_at' => $s['created_at'] ?? '',
                    'updated_at' => $s['updated_at'] ?? '',
                ];
            }

            return [
                'total'   => (int)($response['json']['total_count'] ?? count($secrets)),
                'secrets' => $secrets,
            ];
        } catch (\Throwable $e) {
            return ['total' => 0, 'secrets' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * 获取仓库信息
     *
     * @return array|null
     */
    public static function getRepoInfo(): ?array
    {
        $config = self::config();
        $owner = $config['owner'] ?? '';
        $repo = $config['repo'] ?? '';

        if (empty($owner) || empty($repo)) {
            return null;
        }

        try {
            $response = self::request('GET', "/repos/{$owner}/{$repo}");
            if ($response['status'] === 200) {
                return $response['json'];
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 测试代理连接(使用指定代理配置测试 GitHub API 连通性)
     *
     * @param string $apiProxy     代理地址
     * @param bool   $proxyEnabled 是否启用代理
     * @param int    $timeout      超时时间(秒)
     * @return array{
     *     success: bool,
     *     message: string,
     *     latency_ms: int,
     *     direct_success?: bool,
     *     direct_latency_ms?: int,
     *     proxy_success?: bool,
     *     proxy_latency_ms?: int
     * }
     */
    public static function testProxyConnection(string $apiProxy = '', bool $proxyEnabled = true, int $timeout = 10): array
    {
        $result = [
            'success'      => false,
            'message'      => '',
            'latency_ms'   => 0,
        ];

        // 使用一个公开的 GitHub API 端点测试(不需要 Token)
        $testPath = '/rate_limit';

        try {
            $startTime = microtime(true);

            // 测试当前选择的模式(代理/直连)
            $ch = curl_init();
            if ($proxyEnabled && !empty($apiProxy)) {
                if (substr($apiProxy, -1) !== '/') {
                    $apiProxy .= '/';
                }
                $url = $apiProxy . 'https://api.github.com' . $testPath;
            } else {
                $url = 'https://api.github.com' . $testPath;
            }

            curl_setopt_array($ch, [
                CURLOPT_URL            => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/vnd.github+json',
                    'X-GitHub-Api-Version: 2022-11-28',
                    'User-Agent: im-push-system/1.0',
                ],
            ]);

            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno  = curl_errno($ch);
            $errmsg = curl_error($ch);
            curl_close($ch);

            $endTime  = microtime(true);
            $latencyMs = (int)(($endTime - $startTime) * 1000);

            $result['latency_ms'] = $latencyMs;

            if ($errno) {
                $mode = $proxyEnabled ? '代理' : '直连';
                $result['success'] = false;
                $result['message'] = "{$mode}连接失败(cURL error $errno): $errmsg";
                return $result;
            }

            if ($status >= 200 && $status < 500) {
                $mode = $proxyEnabled ? '代理' : '直连';
                $result['success'] = true;
                $result['message'] = "{$mode}连接正常(HTTP {$status}, 延迟 {$latencyMs}ms)";
                return $result;
            }

            $mode = $proxyEnabled ? '代理' : '直连';
            $result['success'] = false;
            $result['message'] = "{$mode}连接异常(HTTP {$status})";
            return $result;
        } catch (\Throwable $e) {
            $result['success'] = false;
            $result['message'] = '测试异常: ' . $e->getMessage();
            return $result;
        }
    }

    /**
     * 对比测试:同时测试直连和代理,返回对比结果
     *
     * @param string $apiProxy 代理地址
     * @param int    $timeout  超时时间(秒)
     * @return array{
     *     direct: array{success: bool, message: string, latency_ms: int},
     *     proxy: array{success: bool, message: string, latency_ms: int},
     *     recommendation: string
     * }
     */
    public static function compareConnection(string $apiProxy = '', int $timeout = 10): array
    {
        // 测试直连
        $direct = self::testProxyConnection($apiProxy, false, $timeout);

        // 测试代理
        $proxy = self::testProxyConnection($apiProxy, true, $timeout);

        // 给出建议
        $recommendation = '';
        if (!$direct['success'] && !$proxy['success']) {
            $recommendation = '直连和代理均无法连接 GitHub API，请检查网络或代理地址';
        } elseif (!$direct['success']) {
            $recommendation = '直连失败，建议使用代理';
        } elseif (!$proxy['success']) {
            $recommendation = '代理失败，建议使用直连';
        } elseif ($proxy['latency_ms'] < $direct['latency_ms'] * 0.8) {
            $recommendation = '代理延迟更低，建议使用代理';
        } else {
            $recommendation = '直连延迟更低或相近，建议使用直连';
        }

        return [
            'direct'         => $direct,
            'proxy'          => $proxy,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * 获取当前 Token 对应的用户信息
     *
     * @param string|null $token 不传则用已配置的 token
     * @return array{login: string, name: string, avatar_url: string, type: string, scopes: array}|null
     */
    public static function getCurrentUser(?string $token = null): ?array
    {
        try {
            if ($token !== null) {
                $response = self::requestWithToken($token, 'GET', '/user');
            } else {
                $response = self::request('GET', '/user');
            }

            if ($response['status'] !== 200 || !isset($response['json']['login'])) {
                return null;
            }

            $scopes = [];
            $headers = $response['headers'] ?? [];
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'x-oauth-scopes') {
                    $scopes = array_map('trim', explode(',', $value));
                    break;
                }
            }

            return [
                'login'      => $response['json']['login'],
                'name'       => $response['json']['name'] ?? $response['json']['login'],
                'avatar_url' => $response['json']['avatar_url'] ?? '',
                'type'       => $response['json']['type'] ?? 'User',
                'scopes'     => $scopes,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 列出当前用户的仓库(用于下拉选择)
     *
     * @param int    $page
     * @param int    $perPage
     * @param string|null $token
     * @return array<int, array{name: string, full_name: string, private: bool, description: string}>
     */
    public static function listUserRepos(int $page = 1, int $perPage = 100, ?string $token = null): array
    {
        try {
            $path = '/user/repos?page=' . $page . '&per_page=' . $perPage . '&sort=updated';
            if ($token !== null) {
                $response = self::requestWithToken($token, 'GET', $path);
            } else {
                $response = self::request('GET', $path);
            }

            if ($response['status'] !== 200 || !is_array($response['json'])) {
                return [];
            }

            $repos = [];
            foreach ($response['json'] as $repo) {
                $repos[] = [
                    'name'        => $repo['name'] ?? '',
                    'full_name'   => $repo['full_name'] ?? '',
                    'private'     => !empty($repo['private']),
                    'description' => $repo['description'] ?? '',
                ];
            }
            return $repos;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 检查仓库是否存在
     *
     * @param string $owner
     * @param string $repo
     * @param string|null $token
     * @return bool
     */
    public static function repoExists(string $owner, string $repo, ?string $token = null): bool
    {
        try {
            if ($token !== null) {
                $response = self::requestWithToken($token, 'GET', "/repos/{$owner}/{$repo}");
            } else {
                $response = self::request('GET', "/repos/{$owner}/{$repo}");
            }
            return $response['status'] === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 创建新仓库(私有)
     *
     * @param string $name 仓库名
     * @param string $description 描述
     * @param bool   $private 是否私有
     * @param string|null $token
     * @return array{success: bool, message: string, full_name?: string}
     */
    public static function createRepo(string $name, string $description = '', bool $private = true, ?string $token = null): array
    {
        try {
            $data = [
                'name'        => $name,
                'description' => $description,
                'private'     => $private,
                'auto_init'   => false,
            ];

            if ($token !== null) {
                $response = self::requestWithToken($token, 'POST', '/user/repos', $data);
            } else {
                $response = self::request('POST', '/user/repos', $data);
            }

            if ($response['status'] === 201 && isset($response['json']['full_name'])) {
                return [
                    'success'   => true,
                    'message'   => '仓库创建成功',
                    'full_name' => $response['json']['full_name'],
                    'html_url'  => $response['json']['html_url'] ?? '',
                ];
            }

            $msg = $response['json']['message'] ?? ('HTTP ' . $response['status']);
            return ['success' => false, 'message' => '仓库创建失败: ' . $msg];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '仓库创建失败: ' . $e->getMessage()];
        }
    }

    /**
     * 上传文件到仓库(通过 Contents API)
     *
     * @param string $path 文件在仓库中的路径(如 .github/workflows/build-apk.yml)
     * @param string $content 文件内容(base64编码前的原文)
     * @param string $message commit message
     * @param string $branch 分支
     * @return array{success: bool, message: string}
     */
    public static function createOrUpdateFile(string $path, string $content, string $message = 'init', string $branch = 'main'): array
    {
        try {
            $config = self::config();
            $owner = $config['owner'] ?? '';
            $repo = $config['repo'] ?? '';

            if (empty($owner) || empty($repo)) {
                return ['success' => false, 'message' => '未配置仓库所有者和仓库名'];
            }

            // 先获取文件 sha(如果已存在则更新)
            $getResponse = self::request('GET', "/repos/{$owner}/{$repo}/contents/{$path}?ref={$branch}");
            $sha = null;
            if ($getResponse['status'] === 200 && isset($getResponse['json']['sha'])) {
                $sha = $getResponse['json']['sha'];
            }

            $data = [
                'message' => $message,
                'content' => base64_encode($content),
                'branch'  => $branch,
            ];
            if ($sha !== null) {
                $data['sha'] = $sha;
            }

            $response = self::request('PUT', "/repos/{$owner}/{$repo}/contents/{$path}", $data);

            if (in_array($response['status'], [200, 201], true)) {
                return ['success' => true, 'message' => $sha !== null ? '文件已更新' : '文件已创建'];
            }

            $msg = $response['json']['message'] ?? ('HTTP ' . $response['status']);
            return ['success' => false, 'message' => '文件上传失败: ' . $msg];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '文件上传失败: ' . $e->getMessage()];
        }
    }

    /**
     * 获取 workflow 文件内容(从本地项目 .github/workflows 目录读取)
     *
     * @param string $workflowFile
     * @return string|null
     */
    public static function getLocalWorkflowContent(string $workflowFile = 'build-apk.yml'): ?string
    {
        $projectRoot = dirname(__DIR__, 3);
        $filePath = $projectRoot . '/.github/workflows/' . $workflowFile;
        if (!file_exists($filePath)) {
            return null;
        }
        return (string)file_get_contents($filePath);
    }

    /**
     * 上传 workflow 文件到目标仓库
     *
     * @param string $workflowFile
     * @param string $branch
     * @return array{success: bool, message: string}
     */
    public static function uploadWorkflow(string $workflowFile = 'build-apk.yml', string $branch = 'main'): array
    {
        $content = self::getLocalWorkflowContent($workflowFile);
        if ($content === null) {
            return ['success' => false, 'message' => '本地 workflow 文件不存在: ' . $workflowFile];
        }

        return self::createOrUpdateFile(
            '.github/workflows/' . $workflowFile,
            $content,
            'feat: add APK build workflow',
            $branch
        );
    }

    /**
     * 使用指定 token 上传文件到仓库(通过 Contents API)
     *
     * @param string $owner
     * @param string $repo
     * @param string $path 文件在仓库中的路径(如 .github/workflows/build-apk.yml)
     * @param string $content 文件内容(base64编码前的原文)
     * @param string $message commit message
     * @param string $branch 分支
     * @param string|null $token
     * @param array $options
     * @return array{success: bool, message: string}
     */
    public static function createOrUpdateFileWithToken(
        string $owner,
        string $repo,
        string $path,
        string $content,
        string $message = 'init',
        string $branch = 'main',
        ?string $token = null,
        array $options = []
    ): array {
        try {
            if (empty($owner) || empty($repo)) {
                return ['success' => false, 'message' => '未配置仓库所有者和仓库名'];
            }

            $requestToken = $token;
            $requestOptions = $options;

            $getResponse = self::requestWithToken(
                $requestToken ?? '',
                'GET',
                "/repos/{$owner}/{$repo}/contents/{$path}?ref={$branch}",
                null,
                $requestOptions
            );
            $sha = null;
            if ($getResponse['status'] === 200 && isset($getResponse['json']['sha'])) {
                $sha = $getResponse['json']['sha'];
            }

            $data = [
                'message' => $message,
                'content' => base64_encode($content),
                'branch'  => $branch,
            ];
            if ($sha !== null) {
                $data['sha'] = $sha;
            }

            $response = self::requestWithToken(
                $requestToken ?? '',
                'PUT',
                "/repos/{$owner}/{$repo}/contents/{$path}",
                $data,
                $requestOptions
            );

            if (in_array($response['status'], [200, 201], true)) {
                return ['success' => true, 'message' => $sha !== null ? '文件已更新' : '文件已创建'];
            }

            $msg = $response['json']['message'] ?? ('HTTP ' . $response['status']);
            return ['success' => false, 'message' => '文件上传失败: ' . $msg];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '文件上传失败: ' . $e->getMessage()];
        }
    }

    /**
     * 使用指定 token 批量设置 Secrets
     *
     * @param string $owner
     * @param string $repo
     * @param array $secrets [name => value]
     * @param string|null $token
     * @param array $options
     * @return array{success: array, failed: array}
     */
    public static function setSecretsWithToken(string $owner, string $repo, array $secrets, ?string $token = null, array $options = []): array
    {
        $success = [];
        $failed = [];

        if ($token === null || $token === '') {
            return ['success' => [], 'failed' => ['error' => 'Token 不能为空']];
        }

        try {
            $pubKeyResponse = self::requestWithToken(
                $token,
                'GET',
                "/repos/{$owner}/{$repo}/actions/secrets/public-key",
                null,
                $options
            );
            if ($pubKeyResponse['status'] !== 200 || !isset($pubKeyResponse['json']['key'], $pubKeyResponse['json']['key_id'])) {
                $msg = $pubKeyResponse['json']['message'] ?? ('HTTP ' . $pubKeyResponse['status']);
                return ['success' => [], 'failed' => ['public_key' => '获取公钥失败: ' . $msg]];
            }
            $publicKey = $pubKeyResponse['json']['key'];
            $keyId = $pubKeyResponse['json']['key_id'];

            foreach ($secrets as $name => $value) {
                try {
                    $encryptedValue = self::encryptSecret($publicKey, $value);
                    $response = self::requestWithToken(
                        $token,
                        'PUT',
                        "/repos/{$owner}/{$repo}/actions/secrets/" . $name,
                        [
                            'encrypted_value' => $encryptedValue,
                            'key_id'          => $keyId,
                        ],
                        $options
                    );
                    if (in_array($response['status'], [201, 204], true)) {
                        $success[] = $name;
                    } else {
                        $msg = $response['json']['message'] ?? ('HTTP ' . $response['status']);
                        $failed[$name] = $msg;
                    }
                } catch (\Throwable $e) {
                    $failed[$name] = $e->getMessage();
                }
            }
        } catch (\Throwable $e) {
            $failed['error'] = $e->getMessage();
        }

        return ['success' => $success, 'failed' => $failed];
    }
}
