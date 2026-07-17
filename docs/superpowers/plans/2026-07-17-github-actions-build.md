# GitHub Actions 构建 APP 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将 APP 构建从服务器本地(2H2G + Android SDK + Gradle)迁移到 GitHub Actions,通过后端 API 触发 workflow_dispatch,Runner 构建完成后 SCP 直传 APK 到服务器并 SSH 回调更新 Redis 状态。

**Architecture:** 后端 `AppBuildController::submit()` 调用 GitHub Actions API(通过 gh.jasonzeng.dev 代理)触发 workflow,传入 build_id/app_name/package_name 等参数。GitHub Runner checkout 代码、setup JDK 17 + Android SDK、解码 keystore secret、执行 inject_config.sh + build_apk.sh 构建 APK,然后通过 SCP 上传 APK 到服务器 `build/output/{build_id}/`,通过 SSH 执行 `php bin/update_build_status.php` 更新 Redis 中的构建状态。前端 API 接口完全兼容,无需改动。

**Tech Stack:** GitHub Actions(workflow_dispatch + appleboy/scp-action + appleboy/ssh-action)、PHP 8+、Redis、JDK 17、Android SDK 34、Gradle 8.7、Kotlin 1.9.24

---

## 文件结构

### 新建文件

| 文件 | 职责 |
|------|------|
| `.github/workflows/build-apk.yml` | GitHub Actions workflow,接收 inputs,执行构建,上传 APK |
| `backend/src/Service/GitHubActionsService.php` | 封装 GitHub API 调用(触发 workflow、查询状态) |
| `backend/bin/update_build_status.php` | CLI 脚本,供 Runner SSH 调用,更新 Redis 构建状态 |
| `backend/config/github.php` | GitHub Actions 配置(token/owner/repo/proxy) |

### 修改文件

| 文件 | 改动 |
|------|------|
| `backend/src/Controller/AppBuildController.php` | `submit()` 改为调用 GitHubActionsService,不再入 Redis 队列 |
| `backend/.env.example` | 添加 GitHub 相关环境变量 |
| `build/build_apk.sh` | 添加 GitHub Actions 环境检测,跳过资源预检 |
| `gradle.properties` | 放宽 JVM 内存到 2048m(Runner 内存充足) |
| `app/build.gradle.kts` | 无需改动(脚本动态注入) |
| `.gitignore` | 添加 `build/keystore/`(确保不被提交) |

### 删除文件(本地构建相关)

| 文件 | 原因 |
|------|------|
| `build/queue/BuildWorker.php` | 不再需要本地 worker 进程 |
| `deploy/systemd/push-build-worker.service` | 不再需要 systemd 服务 |
| `build/supervisor-build.conf` | 不再需要 supervisor 配置 |
| `build/setup.sh` | 服务器不再需要安装 Android 工具链 |
| `build/config.gradle` | 构建参数改为通过 workflow inputs 传递 |

### 保留文件(被 GitHub Actions workflow 复用)

| 文件 | 说明 |
|------|------|
| `build/build_apk.sh` | 主构建脚本,workflow 中调用 |
| `build/inject_config.sh` | 配置注入脚本,workflow 中调用 |
| `build/templates/build_config.json.template` | 运行时配置模板 |
| `build/generate_keystore.sh` | 参考用,实际 keystore 从 Secret 解码 |

---

## Task 1: 创建 GitHub Actions workflow

**Files:**
- Create: `.github/workflows/build-apk.yml`

- [ ] **Step 1: 创建 workflow 文件**

```yaml
# .github/workflows/build-apk.yml
name: Build Android APK

on:
  workflow_dispatch:
    inputs:
      build_id:
        description: '构建ID(后端生成,唯一)'
        required: true
        type: string
      app_name:
        description: '应用名称'
        required: true
        type: string
      package_name:
        description: '包名(如 com.example.app)'
        required: false
        default: ''
        type: string
      default_key:
        description: '推送默认 Key'
        required: true
        type: string
      server_url:
        description: 'HTTP 服务器地址'
        required: true
        type: string
      ws_url:
        description: 'WebSocket 地址'
        required: true
        type: string
      icon_base64:
        description: '图标 base64(可选,不含 data: 前缀)'
        required: false
        default: ''
        type: string

env:
  JAVA_VERSION: '17'
  ANDROID_SDK_VERSION: '34'
  GRADLE_VERSION: '8.7'

jobs:
  build:
    name: Build APK
    runs-on: ubuntu-latest
    timeout-minutes: 30
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Set up JDK 17
        uses: actions/setup-java@v4
        with:
          java-version: ${{ env.JAVA_VERSION }}
          distribution: 'temurin'

      - name: Set up Android SDK
        uses: android-actions/setup-android@v3
        with:
          api-level: ${{ env.ANDROID_SDK_VERSION }}
          build-tools-version: '34.0.0'
          ndk-version: ''

      - name: Setup Gradle
        uses: gradle/actions/setup-gradle@v3
        with:
          gradle-version: ${{ env.GRADLE_VERSION }}

      - name: Make build scripts executable
        run: chmod +x build/build_apk.sh build/inject_config.sh

      - name: Decode keystore
        env:
          KEYSTORE_BASE64: ${{ secrets.APK_KEYSTORE_BASE64 }}
          KEYSTORE_PASSWORD: ${{ secrets.APK_KEYSTORE_PASSWORD }}
          KEY_ALIAS: ${{ secrets.APK_KEY_ALIAS }}
          KEY_PASSWORD: ${{ secrets.APK_KEY_PASSWORD }}
        run: |
          if [ -z "$KEYSTORE_BASE64" ]; then
            echo "未配置 keystore Secret,将使用 debug 签名"
            exit 0
          fi
          mkdir -p build/keystore
          echo "$KEYSTORE_BASE64" | base64 -d > build/keystore/release.keystore
          cat > build/keystore/keystore.properties <<EOF
          STORE_FILE=$GITHUB_WORKSPACE/build/keystore/release.keystore
          KEY_ALIAS=$KEY_ALIAS
          STORE_PASSWORD=$KEYSTORE_PASSWORD
          KEY_PASSWORD=$KEY_PASSWORD
          EOF
          chmod 600 build/keystore/keystore.properties build/keystore/release.keystore
          echo "Keystore 已解码"

      - name: Decode icon (if provided)
        if: ${{ github.event.inputs.icon_base64 != '' }}
        run: |
          mkdir -p /tmp/build-icon
          echo "${{ github.event.inputs.icon_base64 }}" | base64 -d > /tmp/build-icon/icon.png
          echo "图标已解码: $(file /tmp/build-icon/icon.png)"

      - name: Run build script
        env:
          BUILD_ID: ${{ github.event.inputs.build_id }}
          APP_NAME: ${{ github.event.inputs.app_name }}
          PACKAGE_NAME: ${{ github.event.inputs.package_name }}
          DEFAULT_KEY: ${{ github.event.inputs.default_key }}
          SERVER_URL: ${{ github.event.inputs.server_url }}
          WS_URL: ${{ github.event.inputs.ws_url }}
          ICON_PATH: ${{ github.event.inputs.icon_base64 != '' && '/tmp/build-icon/icon.png' || '' }}
        run: |
          bash build/build_apk.sh \
            --build-id "$BUILD_ID" \
            --app-name "$APP_NAME" \
            --default-key "$DEFAULT_KEY" \
            --server-url "$SERVER_URL" \
            --ws-url "$WS_URL" \
            --package-name "$PACKAGE_NAME" \
            --icon-path "$ICON_PATH" \
            --build-type release

      - name: Read build result
        id: result
        if: always()
        run: |
          RESULT_FILE="build/output/${{ github.event.inputs.build_id }}/result.json"
          if [ -f "$RESULT_FILE" ]; then
            STATUS=$(jq -r '.status // "failed"' "$RESULT_FILE")
            MESSAGE=$(jq -r '.message // "构建失败"' "$RESULT_FILE")
            APK_PATH=$(jq -r '.apk_path // ""' "$RESULT_FILE")
            echo "status=$STATUS" >> $GITHUB_OUTPUT
            echo "message=$MESSAGE" >> $GITHUB_OUTPUT
            echo "apk_path=$APK_PATH" >> $GITHUB_OUTPUT
          else
            echo "status=failed" >> $GITHUB_OUTPUT
            echo "message=构建未产出 result.json" >> $GITHUB_OUTPUT
            echo "apk_path=" >> $GITHUB_OUTPUT
          fi

      - name: Upload APK to server via SCP
        if: success() && steps.result.outputs.status == 'success'
        uses: appleboy/scp-action@v0.1.7
        with:
          host: ${{ secrets.SERVER_SSH_HOST }}
          port: ${{ secrets.SERVER_SSH_PORT }}
          username: ${{ secrets.SERVER_SSH_USER }}
          key: ${{ secrets.SERVER_SSH_KEY }}
          source: "build/output/${{ github.event.inputs.build_id }}/app-release.apk"
          target: "/www/push-system/build/output/${{ github.event.inputs.build_id }}/"
          strip_components: 3

      - name: Upload build log to server via SCP
        if: always()
        uses: appleboy/scp-action@v0.1.7
        with:
          host: ${{ secrets.SERVER_SSH_HOST }}
          port: ${{ secrets.SERVER_SSH_PORT }}
          username: ${{ secrets.SERVER_SSH_USER }}
          key: ${{ secrets.SERVER_SSH_KEY }}
          source: "build/logs/${{ github.event.inputs.build_id }}.log"
          target: "/www/push-system/build/logs/"
          strip_components: 2

      - name: Update build status via SSH
        if: always()
        uses: appleboy/ssh-action@v1.0.3
        with:
          host: ${{ secrets.SERVER_SSH_HOST }}
          port: ${{ secrets.SERVER_SSH_PORT }}
          username: ${{ secrets.SERVER_SSH_USER }}
          key: ${{ secrets.SERVER_SSH_KEY }}
          script: |
            php /www/push-system/backend/bin/update_build_status.php \
              --build-id "${{ github.event.inputs.build_id }}" \
              --status "${{ steps.result.outputs.status }}" \
              --message "${{ steps.result.outputs.message }}" \
              --apk-path "/www/push-system/build/output/${{ github.event.inputs.build_id }}/app-release.apk"
```

- [ ] **Step 2: 验证 YAML 语法**

Run: `python -c "import yaml; yaml.safe_load(open('.github/workflows/build-apk.yml'))"`
Expected: 无异常输出

- [ ] **Step 3: 提交**

```bash
git add .github/workflows/build-apk.yml
git commit -m "feat: 添加 GitHub Actions APK 构建 workflow"
git push origin HEAD
```

---

## Task 2: 创建 GitHub Actions 配置文件

**Files:**
- Create: `backend/config/github.php`

- [ ] **Step 1: 创建配置文件**

```php
<?php
declare(strict_types=1);

/**
 * GitHub Actions 构建配置
 *
 * 用于触发 workflow_dispatch 构建 APK。
 * 国内服务器通过 gh.jasonzeng.dev 代理访问 GitHub API。
 */
return [
    // GitHub Personal Access Token(需要 repo 和 workflow 权限)
    'token' => env('GITHUB_TOKEN', ''),

    // 仓库所有者(用户名或组织名)
    'owner' => env('GITHUB_OWNER', 'jiujiu123520'),

    // 仓库名
    'repo' => env('GITHUB_REPO', 'im-push-system'),

    // Workflow 文件名(不带路径)
    'workflow_file' => env('GITHUB_WORKFLOW_FILE', 'build-apk.yml'),

    // GitHub API 代理(国内服务器使用 gh.jasonzeng.dev,留空则直连)
    'api_proxy' => env('GITHUB_API_PROXY', 'https://gh.jasonzeng.dev/'),

    // API 请求超时(秒)
    'timeout' => (int)env('GITHUB_API_TIMEOUT', 30),
];
```

- [ ] **Step 2: 提交**

```bash
git add backend/config/github.php
git commit -m "feat: 添加 GitHub Actions 配置文件"
git push origin HEAD
```

---

## Task 3: 创建 GitHubActionsService 服务类

**Files:**
- Create: `backend/src/Service/GitHubActionsService.php`

- [ ] **Step 1: 创建服务类**

```php
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
        self::$config = Config::get('github', []);
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
}
```

- [ ] **Step 2: 提交**

```bash
git add backend/src/Service/GitHubActionsService.php
git commit -m "feat: 添加 GitHubActionsService 封装 GitHub API 调用"
git push origin HEAD
```

---

## Task 4: 创建 update_build_status.php CLI 脚本

**Files:**
- Create: `backend/bin/update_build_status.php`

- [ ] **Step 1: 创建 CLI 脚本**

```php
#!/usr/bin/env php
<?php
/**
 * 更新构建状态 CLI 脚本
 *
 * 由 GitHub Actions Runner 通过 SSH 调用,更新 Redis 中的构建状态。
 *
 * 用法:
 *   php bin/update_build_status.php \
 *     --build-id "b610xxx" \
 *     --status "success" \
 *     --message "构建成功" \
 *     --apk-path "/www/push-system/build/output/b610xxx/app-release.apk"
 *
 * 状态值: success / failed
 */

declare(strict_types=1);

// 项目根目录
$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/backend/vendor/autoload.php';

// 加载 .env
\App\Service\Config::loadEnv();

use App\Service\Redis;

// 解析命令行参数
$options = getopt('', ['build-id:', 'status:', 'message:', 'apk-path::']);
$buildId = $options['build-id'] ?? '';
$status = $options['status'] ?? 'failed';
$message = $options['message'] ?? '';
$apkPath = $options['apk-path'] ?? '';

if (empty($buildId)) {
    fwrite(STDERR, "错误: --build-id 参数必填\n");
    exit(1);
}

// 状态映射(GitHub Actions → Redis task status)
$statusMap = [
    'success' => 'success',
    'failed' => 'failed',
    'failure' => 'failed',
    'cancelled' => 'failed',
    'canceled' => 'failed',
];
$redisStatus = $statusMap[$status] ?? 'failed';

$now = date('Y-m-d H:i:s');
$taskKey = 'build:task:' . $buildId;

try {
    $redis = Redis::getInstance();

    // 检查 task 是否存在
    if (!$redis->exists($taskKey)) {
        fwrite(STDERR, "警告: 任务 {$buildId} 不存在于 Redis\n");
        // 仍然创建一条记录,便于查询
        $redis->hset($taskKey, 'build_id', $buildId);
        $redis->hset($taskKey, 'app_name', '(未知)');
        $redis->zadd('build:tasks', time(), $buildId);
    }

    // 更新状态字段
    $redis->hset($taskKey, 'status', $redisStatus);
    $redis->hset($taskKey, 'result_message', $message);
    $redis->hset($taskKey, 'updated_at', $now);
    $redis->hset($taskKey, 'finished_at', $now);

    // 成功时填充 apk_path
    if ($redisStatus === 'success' && !empty($apkPath)) {
        $redis->hset($taskKey, 'apk_path', $apkPath);
    }

    // 输出结果
    echo "已更新构建状态: build_id={$buildId} status={$redisStatus} message={$message}\n";
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "错误: 更新构建状态失败: " . $e->getMessage() . "\n");
    exit(1);
}
```

- [ ] **Step 2: 设置可执行权限(在服务器上执行)**

```bash
chmod +x /www/push-system/backend/bin/update_build_status.php
chown www-data:www-data /www/push-system/backend/bin/update_build_status.php
```

- [ ] **Step 3: 提交**

```bash
git add backend/bin/update_build_status.php
git commit -m "feat: 添加 update_build_status.php CLI 脚本"
git push origin HEAD
```

---

## Task 5: 修改 AppBuildController::submit()

**Files:**
- Modify: `backend/src/Controller/AppBuildController.php:86-138`

- [ ] **Step 1: 修改 submit 方法,改为调用 GitHubActionsService**

替换 `submit()` 方法(原第 86-138 行):

```php
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
            $redis = \App\Service\Redis::getInstance();
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
            $result = \App\Service\GitHubActionsService::triggerBuild($inputs);
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
                $redis = \App\Service\Redis::getInstance();
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
            $redis = \App\Service\Redis::getInstance();
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
```

- [ ] **Step 2: 移除 isAvailable 检查对 BuildQueue 的依赖**

修改 `isAvailable()` 方法,改为检查 GitHubActionsService 配置:

```php
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
            $config = \App\Service\Config::get('github', []);
            $token = $config['token'] ?? '';
            $owner = $config['owner'] ?? '';
            $repo = $config['repo'] ?? '';
            self::$available = !empty($token) && !empty($owner) && !empty($repo);
        } catch (\Throwable $e) {
            self::$available = false;
        }
        return self::$available;
    }
```

- [ ] **Step 3: 移除顶部 require_once BuildQueue 的引用**

删除原 `isAvailable()` 中 `require_once $path` 和 `$path` 变量(已被上面新版本替代)。

- [ ] **Step 4: 提交**

```bash
git add backend/src/Controller/AppBuildController.php
git commit -m "feat: AppBuildController::submit() 改为调用 GitHub Actions API"
git push origin HEAD
```

---

## Task 6: 更新 .env.example

**Files:**
- Modify: `backend/.env.example`

- [ ] **Step 1: 在 .env.example 末尾添加 GitHub 配置**

```ini
# ============================
# GitHub Actions 构建(替代本地构建)
# ============================
# GitHub Personal Access Token(需要 repo 和 workflow 权限)
# 创建方式: GitHub → Settings → Developer settings → Personal access tokens → Tokens (classic) → Generate new token
GITHUB_TOKEN=
# 仓库所有者(用户名或组织名)
GITHUB_OWNER=jiujiu123520
# 仓库名
GITHUB_REPO=im-push-system
# Workflow 文件名(不带路径)
GITHUB_WORKFLOW_FILE=build-apk.yml
# GitHub API 代理(国内服务器使用 gh.jasonzeng.dev,留空则直连)
GITHUB_API_PROXY=https://gh.jasonzeng.dev/
# API 请求超时(秒)
GITHUB_API_TIMEOUT=30
```

- [ ] **Step 2: 提交**

```bash
git add backend/.env.example
git commit -m "docs: 更新 .env.example 添加 GitHub Actions 配置"
git push origin HEAD
```

---

## Task 7: 修改 build_apk.sh 适配 GitHub Actions

**Files:**
- Modify: `build/build_apk.sh:34-62` (check_resources 函数)

- [ ] **Step 1: 在 check_resources 函数顶部添加 GitHub Actions 环境检测**

在 `check_resources()` 函数开头(第 35 行后)添加:

```bash
check_resources() {
    # GitHub Actions 环境跳过资源检查(Runner 7GB 内存,资源充足)
    if [ -n "$GITHUB_ACTIONS" ] && [ "$GITHUB_ACTIONS" = "true" ]; then
        echo "[BUILD] 检测到 GitHub Actions 环境,跳过资源预检"
        return 0
    fi

    # 检查可用内存(至少 80MB 即可启动,配合 swap 兜底)
    # 2G 服务器 MySQL+Redis+PHP 常驻后可用内存常低于 150MB,阈值过高会导致永远无法构建
    local available_mem
    available_mem=$(awk '/MemAvailable/{print int($2/1024)}' /proc/meminfo 2>/dev/null || echo 0)
    if [[ "${available_mem}" -lt 80 ]]; then
        echo "[ERROR] 可用内存不足 80MB（当前 ${available_mem}MB），拒绝构建以防止服务器卡死"
        exit 1
    fi
    # ... 后续检查保持不变
}
```

- [ ] **Step 2: 在 JAVA_HOME 设置中添加 GITHUB_ENV 兼容**

找到 `build_apk.sh` 中的 JAVA_HOME 设置段(约第 251-266 行),在末尾追加:

```bash
# GitHub Actions 环境:将 JAVA_HOME 写入 GITHUB_ENV(供后续步骤使用)
if [ -n "$GITHUB_ENV" ] && [ -n "$JAVA_HOME" ]; then
    echo "JAVA_HOME=$JAVA_HOME" >> "$GITHUB_ENV"
    echo "PATH=$PATH" >> "$GITHUB_ENV"
fi
```

- [ ] **Step 3: 提交**

```bash
git add build/build_apk.sh
git commit -m "feat: build_apk.sh 适配 GitHub Actions 环境"
git push origin HEAD
```

---

## Task 8: 放宽 gradle.properties 内存限制

**Files:**
- Modify: `gradle.properties`

- [ ] **Step 1: 查看当前 gradle.properties 内容**

Run: `cat gradle.properties`
Expected: 看到 `-Xmx768m` 等服务器优化参数

- [ ] **Step 2: 修改为 GitHub Actions 友好的配置**

```properties
# JVM 参数(GitHub Actions Runner 7GB 内存,放宽到 2048m)
# 原 2H2G 服务器配置: -Xmx768m -XX:MaxMetaspaceSize=256m
org.gradle.jvmargs=-Xmx2048m -XX:MaxMetaspaceSize=512m -XX:+UseG1GC -Dfile.encoding=UTF-8
# 并行构建(GitHub Actions 2 核 CPU 可启用)
org.gradle.parallel=true
# 禁用 daemon(GitHub Actions 每次构建是新环境)
org.gradle.daemon=false
# 单 worker(GitHub Actions 限制并发)
org.gradle.workers.max=2
# 启用配置缓存
org.gradle.caching=true
org.gradle.configureondemand=true
android.useAndroidX=true
android.nonTransitiveRClass=true
```

- [ ] **Step 3: 提交**

```bash
git add gradle.properties
git commit -m "perf: 放宽 gradle.properties 内存限制适配 GitHub Actions"
git push origin HEAD
```

---

## Task 9: 删除本地构建相关文件

**Files:**
- Delete: `build/queue/BuildWorker.php`
- Delete: `deploy/systemd/push-build-worker.service`
- Delete: `build/supervisor-build.conf`
- Delete: `build/setup.sh`
- Delete: `build/config.gradle`

- [ ] **Step 1: 删除文件**

```bash
git rm build/queue/BuildWorker.php
git rm deploy/systemd/push-build-worker.service
git rm build/supervisor-build.conf
git rm build/setup.sh
git rm build/config.gradle
```

- [ ] **Step 2: 保留 BuildQueue.php 的状态查询方法**

注意: `build/queue/BuildQueue.php` 中的 `submitBuild()` 不再使用,但其中的常量定义(`QUEUE_KEY`, `TASK_PREFIX`, `INDEX_KEY`)和查询方法(`getBuild()`, `listBuilds()`)仍被 `AppBuildController` 的其他方法使用。**保留此文件,仅删除 `processQueue()` 等队列处理方法**(可选,保留也不影响)。

实际上,`AppBuildController::list()` 和 `status()` 方法直接调用 `BuildQueue::listBuilds()` 和 `BuildQueue::getBuild()`,所以 `BuildQueue.php` 必须保留。

- [ ] **Step 3: 提交**

```bash
git commit -m "refactor: 移除本地构建相关文件(BuildWorker/systemd/setup)"
git push origin HEAD
```

---

## Task 10: 更新 .gitignore

**Files:**
- Modify: `.gitignore`

- [ ] **Step 1: 确认 .gitignore 包含敏感文件**

检查 `.gitignore` 是否包含:
- `build/keystore/`(签名密钥)
- `build/output/`(构建产物)
- `build/logs/`(构建日志)
- `app/inject.gradle`(注入文件)
- `app/signing.gradle`(签名配置)
- `local.properties`(SDK 路径)

如缺失则补充。

- [ ] **Step 2: 提交(如有改动)**

```bash
git add .gitignore
git commit -m "chore: 补充 .gitignore 忽略构建敏感文件"
git push origin HEAD
```

---

## Task 11: 更新服务器端配置

**Files:**
- 服务器上执行(无项目文件改动)

- [ ] **Step 1: 在服务器 .env 中添加 GitHub 配置**

```bash
sudo tee -a /www/push-system/backend/.env > /dev/null <<'EOF'

# GitHub Actions 构建
GITHUB_TOKEN=ghp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
GITHUB_OWNER=jiujiu123520
GITHUB_REPO=im-push-system
GITHUB_WORKFLOW_FILE=build-apk.yml
GITHUB_API_PROXY=https://gh.jasonzeng.dev/
GITHUB_API_TIMEOUT=30
EOF
sudo chown www-data:www-data /www/push-system/backend/.env
sudo chmod 600 /www/push-system/backend/.env
```

- [ ] **Step 2: 创建 GitHub Personal Access Token**

在 GitHub 网页创建 token(Settings → Developer settings → Personal access tokens → Tokens (classic) → Generate new token),勾选权限:
- `repo`(完整仓库访问)
- `workflow`(修改 workflow)

将生成的 token 填入上面 .env 的 `GITHUB_TOKEN=`。

- [ ] **Step 3: 在 GitHub 仓库配置 Secrets**

在 GitHub 仓库(Settings → Secrets and variables → Actions → New repository secret)添加以下 Secrets:

| Secret 名 | 值 |
|-----------|-----|
| `APK_KEYSTORE_BASE64` | `base64 release.keystore`(在服务器上执行 `base64 -w 0 build/keystore/release.keystore`) |
| `APK_KEYSTORE_PASSWORD` | keystore 密码 |
| `APK_KEY_ALIAS` | 密钥别名(通常 `release`) |
| `APK_KEY_PASSWORD` | 密钥密码 |
| `SERVER_SSH_HOST` | `124.220.64.209` |
| `SERVER_SSH_PORT` | `22` |
| `SERVER_SSH_USER` | `ubuntu` |
| `SERVER_SSH_KEY` | 服务器 SSH 私钥(完整内容,含 BEGIN/END 行) |

- [ ] **Step 4: 停止并禁用旧的 push-build-worker 服务**

```bash
sudo systemctl stop push-build-worker
sudo systemctl disable push-build-worker
sudo rm /etc/systemd/system/push-build-worker.service
sudo systemctl daemon-reload
sudo systemctl reset-failed push-build-worker 2>/dev/null || true
```

- [ ] **Step 5: 创建 SSH 专用密钥对(可选,用于 GitHub Actions)**

```bash
# 在服务器上生成专用密钥对(如已有可跳过)
ssh-keygen -t ed25519 -C "github-actions-build" -f ~/.ssh/github_actions_key -N ""

# 将公钥添加到 authorized_keys
cat ~/.ssh/github_actions_key.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys

# 查看私钥(完整内容复制到 GitHub Secret SERVER_SSH_KEY)
cat ~/.ssh/github_actions_key
```

- [ ] **Step 6: 重启 push-http 服务加载新配置**

```bash
sudo systemctl restart push-http
sudo systemctl status push-http --no-pager -l | head -10
```

- [ ] **Step 7: 验证 GitHub API 连通性(通过代理)**

```bash
# 测试代理访问 GitHub API(应返回 200 和 JSON)
curl -s -o /dev/null -w "%{http_code}\n" https://gh.jasonzeng.dev/https://api.github.com/zen
# 应输出 200
```

---

## Task 12: 验证测试

**Files:**
- 无文件改动,执行测试

- [ ] **Step 1: 通过前端提交构建任务**

在 `http://124.220.64.209/` 管理后台 APP 生成页面提交构建任务,填写应用名称、包名等。

- [ ] **Step 2: 检查 GitHub Actions 是否触发**

```bash
# 查看后端日志
sudo journalctl -u push-http -f --since "1 minute ago"

# 同时查看 GitHub Actions 运行状态(在 GitHub 网页 Actions 页面查看)
```

- [ ] **Step 3: 等待构建完成,检查服务器文件**

```bash
# 检查 APK 是否上传
ls -la /www/push-system/build/output/

# 检查日志是否上传
ls -la /www/push-system/build/logs/

# 检查 Redis 状态
redis-cli -n 0 hgetall im_push:build:task:<build_id>
```

- [ ] **Step 4: 测试下载 APK**

在管理后台构建历史中点击下载,或:
```bash
# 直接 curl 下载(需登录 token)
curl -O -J "http://124.220.64.209/admin/app-build/download/<build_id>" \
  -H "Authorization: Bearer <admin_jwt_token>"
```

- [ ] **Step 5: 测试查看构建日志**

在管理后台点击查看日志,或:
```bash
curl "http://124.220.64.209/admin/app-build/log/<build_id>" \
  -H "Authorization: Bearer <admin_jwt_token>"
```

---

## 自查清单

实施完成后,确认以下各项:

- [ ] `.github/workflows/build-apk.yml` 已创建并可触发
- [ ] `backend/src/Service/GitHubActionsService.php` 已创建
- [ ] `backend/bin/update_build_status.php` 已创建
- [ ] `backend/config/github.php` 已创建
- [ ] `backend/src/Controller/AppBuildController.php` 的 `submit()` 已改造
- [ ] `backend/.env.example` 已添加 GitHub 配置
- [ ] `build/build_apk.sh` 已适配 GitHub Actions 环境
- [ ] `gradle.properties` 已放宽内存限制
- [ ] 本地构建相关文件已删除(BuildWorker.php、systemd、setup.sh、supervisor)
- [ ] `.gitignore` 包含 keystore 等敏感文件
- [ ] 服务器 .env 已添加 GitHub 配置
- [ ] GitHub Secrets 已全部配置(keystore + SSH)
- [ ] 旧 push-build-worker 服务已停止和禁用
- [ ] 端到端测试通过(提交构建 → GitHub Actions 触发 → APK 上传 → 状态更新 → 下载成功)

---

## 回滚方案

如 GitHub Actions 构建失败,可临时回滚到本地构建:

```bash
# 1. 恢复本地构建文件
git revert HEAD~10..HEAD  # 回退最近 10 个 commit
# 或手动恢复:
git checkout HEAD~10 -- build/queue/BuildWorker.php deploy/systemd/push-build-worker.service build/setup.sh

# 2. 重启本地构建服务
sudo systemctl daemon-reload
sudo systemctl restart push-build-worker
```

---

## 注意事项

1. **GitHub Actions 免费额度**: 公开仓库无限,私有仓库每月 2000 分钟。每次构建约 5-10 分钟。
2. **Secret 安全**: `SERVER_SSH_KEY` 必须是完整私钥(含 `-----BEGIN OPENSSH PRIVATE KEY-----` 和 `-----END OPENSSH PRIVATE KEY-----`)。
3. **代理 URL 格式**: `https://gh.jasonzeng.dev/https://api.github.com`(代理 + 完整目标 URL)。
4. **APK 路径一致性**: Runner 上 SCP 的源路径是 `build/output/{build_id}/app-release.apk`,服务器目标路径是 `/www/push-system/build/output/{build_id}/`。`strip_components: 3` 用于去掉 `build/output/{build_id}/` 前缀。
5. **图标 base64 处理**: 前端传的 `icon_path` 实际是 base64 字符串,在 workflow inputs 中重命名为 `icon_base64` 以明确语义,Runner 中解码为文件后传给 `build_apk.sh --icon-path`。
6. **BuildQueue.php 保留**: 虽然 `submitBuild()` 不再使用,但 `listBuilds()` 和 `getBuild()` 仍被其他 Controller 方法调用,必须保留。
7. **Redis 前缀**: 实际 Redis key 是 `im_push:build:task:{build_id}`(Config::get('redis.prefix')='im_push:'),`update_build_status.php` 通过 `Redis::getInstance()` 自动应用前缀。
