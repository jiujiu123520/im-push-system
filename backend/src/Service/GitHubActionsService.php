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
        $proxy = $config['api_proxy'] ?? '';
        if (!empty($proxy)) {
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

        if (empty($owner) || empty($repo)) {
            throw new \RuntimeException('GitHub 仓库配置不完整(GITHUB_OWNER/GITHUB_REPO)');
        }

        $path = "/repos/{$owner}/{$repo}/actions/workflows/{$workflowFile}/dispatches";

        $response = self::request('POST', $path, [
            'ref' => 'main',
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
}
