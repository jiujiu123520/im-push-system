<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Response;

/**
 * APP 打包控制器
 *
 * 提供管理后台生成 APP 打包产物的 HTTP 接口，需要管理员鉴权。
 *
 * 当前只产出「源码/项目包」，APK 由 HBuilderX 云打包生成：
 *   - HBuilderX：生成 uni-app 项目 ZIP，导入 HBuilderX 后「发行 → 原生 App-云打包」
 *   - 玻璃拟态：生成 uni-app 源码 ZIP，导入 HBuilderX 后同样云打包
 *
 * 路由：
 *   GET  /admin/app-build/random-config        生成随机配置（包名、APP名称）
 *   GET  /admin/app-build/generate-icon        生成图标（首字+渐变色）
 *   GET  /admin/app-build/hbuilderx/templates  可用 HBuilderX 模板列表
 *   POST /admin/app-build/hbuilderx/generate   生成 HBuilderX 项目包
 *   GET  /admin/app-build/compose/templates    可用玻璃拟态模板列表
 *   POST /admin/app-build/compose/generate     生成玻璃拟态源码包
 */
class AppBuildController
{
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
        // 剥离首尾空白/反引号/引号（防止从文档复制的 markdown 代码样式装饰字符混入 URL/Key）
        $defaultKey = trim((string)($data['default_key'] ?? 'default_key'), " \t\n\r\0\x0B`'\"");
        $serverUrl = trim((string)($data['server_url'] ?? ''), " \t\n\r\0\x0B`'\"");
        $wsUrl = trim((string)($data['ws_url'] ?? ''), " \t\n\r\0\x0B`'\"");
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
        // 剥离首尾空白/反引号/引号（防止从文档复制的 markdown 代码样式装饰字符混入 URL/Key）
        $defaultKey  = trim((string)($data['default_key'] ?? 'default_key'), " \t\n\r\0\x0B`'\"");
        $serverUrl   = trim((string)($data['server_url'] ?? ''), " \t\n\r\0\x0B`'\"");
        $wsUrl       = trim((string)($data['ws_url'] ?? ''), " \t\n\r\0\x0B`'\"");
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
