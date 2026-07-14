<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\ApkDistributionService;
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

        $packageName = trim((string)($data['package_name'] ?? ''));
        if ($packageName !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $packageName)) {
            Response::fail($response, '包名格式不正确，需符合 Java 包名规范（如 com.example.app）', Response::CODE_BAD_REQUEST);
            return false;
        }

        $config = [
            'app_name'     => $appName,
            'default_key'  => (string)($data['default_key'] ?? 'default_key'),
            'server_url'   => (string)($data['server_url'] ?? ''),
            'ws_url'       => (string)($data['ws_url'] ?? ''),
            'icon_path'    => (string)($data['icon_path'] ?? ''),
            'package_name' => $packageName,
            'admin_id'     => (int)($payload['admin_id'] ?? 0),
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
}
