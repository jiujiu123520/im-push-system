<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Service\Database;

/**
 * 用户端 APP 下载/生成
 *
 * 路由前缀：/user-api/app
 */
class AppController extends BaseUserController
{
    public function info(array $context, array $params)
    {
        // 登录用户即可查看（无需鉴权也能看，做兜底）
        $row = Database::fetch(
            "SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1",
            ['settings_user_app']
        );
        $cfg = [
            'apk_download_url' => '',
            'ipa_download_url' => '',
            'apk_version'      => '',
            'ipa_version'      => '',
            'update_log'       => '',
            'force_update'     => 0,
            'user_hbx_enabled' => 1,
        ];
        if ($row !== false) {
            $parsed = json_decode((string)$row['config_value'], true);
            if (is_array($parsed)) {
                $cfg = array_merge($cfg, $parsed);
            }
        }
        // 保证 bool/int 类型
        $cfg['force_update']     = (int)($cfg['force_update'] ?? 0);
        $cfg['user_hbx_enabled'] = (int)($cfg['user_hbx_enabled'] ?? 1);

        $settingsPaths = Database::fetch(
            "SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1",
            ['settings_paths']
        );
        $paths = [
            'admin_path'       => '/admin/',
            'admin_api_prefix' => '/api/',
            'user_path'        => '/user/',
            'user_api_prefix'  => '/user-api/',
        ];
        if ($settingsPaths !== false) {
            $p = json_decode((string)$settingsPaths['config_value'], true);
            if (is_array($p)) {
                $paths = array_merge($paths, $p);
            }
        }

        return [
            'download'   => $cfg,
            'api_config' => [
                'api_base' => $paths['user_api_prefix'] ?? '/user-api/',
                'ws_base'  => '/ws',
            ],
        ];
    }

    /**
     * GET /user-api/app/hbuilderx/templates
     * 获取可用 HBuilderX 模板列表
     */
    public function hbuilderxTemplates(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $service = new \App\Service\HBuilderXService();
        return ['templates' => $service->getAvailableTemplates()];
    }

    public function downloadQr(array $context, array $params)
    {
        $info = $this->info($context, $params);
        return [
            'apk_url' => (string)($info['download']['apk_download_url'] ?? ''),
            'ipa_url' => (string)($info['download']['ipa_download_url'] ?? ''),
            'version' => (string)($info['download']['apk_version'] ?: ($info['download']['ipa_version'] ?: '')),
        ];
    }

    public function hbuilderxGenerate(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        // 后台总开关：user_hbx_enabled
        $cfg = Database::fetch("SELECT config_value FROM admin_settings WHERE config_key = 'settings_user_app' LIMIT 1");
        $hbxEnabled = 1;
        if ($cfg !== false) {
            $p = json_decode((string)$cfg['config_value'], true);
            $hbxEnabled = (int)($p['user_hbx_enabled'] ?? 1);
        }
        if ($hbxEnabled !== 1) {
            return $this->fail($context, '管理员已关闭用户自建 HBuilderX 打包功能');
        }

        $body = $this->parseBody($context);
        $appName    = trim((string)($body['app_name'] ?? ''));
        $packageId  = trim((string)($body['package_id'] ?? ''));
        $apiBaseUrl = trim((string)($body['api_base_url'] ?? ''));
        $wsUrl      = trim((string)($body['ws_url'] ?? ''));
        $iconBase64 = trim((string)($body['icon_base64'] ?? ''));
        $template   = trim((string)($body['template'] ?? 'new'));

        if ($appName === '') return $this->fail($context, 'app_name 不能为空');
        if ($packageId === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_\.]*$/', $packageId)) {
            return $this->fail($context, 'package_id 格式不正确，需形如 com.example.push');
        }
        if ($apiBaseUrl !== '' && !filter_var($apiBaseUrl, FILTER_VALIDATE_URL)) {
            return $this->fail($context, 'api_base_url 不是合法 URL');
        }

        // 从请求推导当前站点 scheme://host[:port]（api 和 ws 兜底共用）
        $server = $context['server'] ?? [];
        $isTls = !empty($server['https']) || ($server['server_port'] ?? null) == 443;
        $scheme = $isTls ? 'https' : 'http';
        $wsScheme = $isTls ? 'wss' : 'ws';
        $host = $server['http_host'] ?? ($server['server_name'] ?? 'localhost');
        $port = $server['server_port'] ?? null;
        $portSuffix = '';
        if ($port && !(($scheme === 'http' && $port == 80) || ($scheme === 'https' && $port == 443))) {
            $portSuffix = ':' . $port;
        }
        $siteBase = "{$scheme}://{$host}{$portSuffix}";

        // 如果用户没提供 api_base_url，用当前站点根地址
        // 注意：设备端 API 挂在根路径 /api/*（/api/device/messages 等），
        // 不能用用户控制台的 /user-api/ 前缀，否则 APP 内所有请求 404
        if ($apiBaseUrl === '') {
            $apiBaseUrl = $siteBase . '/';
        }
        if ($wsUrl === '') {
            $wsUrl = "{$wsScheme}://{$host}{$portSuffix}/ws";
        }

        // 默认推送 Key：表单没传时取当前用户第一个启用中的 Key，
        // 之前不传导致生成的 config.js default_key 为空，APP 连不上（no_key）
        $defaultKey = trim((string)($body['default_key'] ?? ''));
        if ($defaultKey === '') {
            $keyRow = Database::fetch(
                'SELECT key_value FROM api_keys WHERE user_id = ? AND status = 1 ORDER BY id ASC LIMIT 1',
                [$userId]
            );
            if (is_array($keyRow) && !empty($keyRow['key_value'])) {
                $defaultKey = (string)$keyRow['key_value'];
            }
        }

        $service = new \App\Service\HBuilderXService();
        try {
            $zipPath = $service->generateZip([
                'user_id'     => $userId,
                'app_name'    => $appName,
                'package_id'  => $packageId,
                'api_base_url'=> rtrim($apiBaseUrl, '/') . '/',
                'ws_url'      => rtrim($wsUrl, '/') . '/',
                'default_key' => $defaultKey,
                'icon_base64' => $iconBase64,
                'template'    => $template,
            ]);
        } catch (\Throwable $e) {
            return $this->fail($context, '生成失败：' . $e->getMessage(), 500, 500);
        }

        $filename = 'hbuilderx-' . $packageId . '-' . date('Ymd_His') . '.zip';
        if (!is_file($zipPath)) {
            return $this->fail($context, '生成失败：找不到 ZIP 文件', 500, 500);
        }
        $response = $context['response'];
        $size = filesize($zipPath);
        $response->status(200);
        $response->header('Content-Type', 'application/zip');
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->header('Content-Length', (string)$size);
        // 分块发送（避免大文件内存溢出）
        $fh = fopen($zipPath, 'rb');
        if ($fh) {
            while (!feof($fh)) {
                $chunk = fread($fh, 8192);
                if ($chunk !== false && $chunk !== '') {
                    $response->write($chunk);
                }
            }
            fclose($fh);
            $response->end();
        } else {
            $response->end(file_get_contents($zipPath));
        }
        @unlink($zipPath);
        return false; // 已自行输出
    }
}
