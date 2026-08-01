<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\ApkDistributionService;
use App\Service\Response;

/**
 * APK 分发控制器
 *
 * 管理构建完成后 APK 的分发记录，支持三种分发方式：
 *  1. 自托管下载（服务器直接提供下载）
 *  2. 小飞机网盘上传（feejii.com）
 *  3. 自定义脚本上传
 *
 * 路由：
 *   GET  /admin/apk-distribution              分发记录列表（分页）
 *   GET  /admin/apk-distribution/{id}         分发记录详情
 *   GET  /admin/apk-distribution/config      获取分发配置
 *   PUT  /admin/apk-distribution/config       保存分发配置
 *   POST /admin/apk-distribution/upload       本地上传 APK 文件
 *   POST /admin/apk-distribution/validate-credentials  验证小飞机网盘凭证
 *   GET  /admin/apk-distribution/{id}/stats   下载统计数据
 *   POST /admin/apk-distribution/{id}/feijipan 上传到小飞机网盘
 *   POST /admin/apk-distribution/{id}/custom  执行自定义上传
 *   DELETE /admin/apk-distribution/{id}       删除分发记录
 *
 * 公开路由（无需鉴权）：
 *   GET  /api/apk-distribution/download/{token}  通过令牌下载 APK
 *   GET  /api/apk-distribution/info/{token}        通过令牌获取 APK 信息
 */
class ApkDistributionController
{
    public function index(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $page = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        return ApkDistributionService::getList($page, $keyword);
    }

    public function show(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        $detail = ApkDistributionService::getDetail($id);
        if ($detail === null) {
            Response::fail($context['response'], '分发记录不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        return $detail;
    }

    public function getConfig(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        return ApkDistributionService::getConfig();
    }

    public function saveConfig(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $body = self::parseJsonBody($context);
        $ok = ApkDistributionService::saveConfig($body);
        if (!$ok) {
            Response::fail($context['response'], '保存配置失败', Response::CODE_ERROR);
            return false;
        }
        return ['message' => '配置保存成功'];
    }

    /**
     * 验证小飞机网盘凭证
     * POST /admin/apk-distribution/validate-credentials
     *
     * 请求体：{ "app_token": "...", "uuid": "...", "dev_code": "..." }
     */
    public function validateCredentials(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $body = self::parseJsonBody($context);
        $appToken = (string)($body['app_token'] ?? $body['feijii_app_token'] ?? '');
        $uuid     = (string)($body['uuid'] ?? $body['feijii_uuid'] ?? '');
        $devCode  = (string)($body['dev_code'] ?? $body['feijii_dev_code'] ?? '');

        if ($appToken === '' || $uuid === '' || $devCode === '') {
            Response::fail($context['response'], 'AppToken、UUID、DevCode 三项均不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        return ApkDistributionService::validateFeijiiCredentials($appToken, $uuid, $devCode);
    }

    /**
     * 本地上传 APK 文件
     * POST /admin/apk-distribution/upload
     */
    public function uploadApk(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $files = $context['files'] ?? [];
        if (!isset($files['file']) || !is_array($files['file'])) {
            Response::fail($context['response'], '请选择要上传的 APK 文件', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $post = $context['post'] ?? [];
        $appName = (string)($post['app_name'] ?? '');
        $packageName = (string)($post['package_name'] ?? '');
        $versionName = (string)($post['version_name'] ?? '');
        $adminId = (int)($payload['admin_id'] ?? 0);

        $result = ApkDistributionService::createFromFile(
            $files['file'],
            $appName,
            $packageName,
            $versionName,
            $adminId
        );

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }
        return $result;
    }

    public function downloadStats(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        return ApkDistributionService::getDownloadStats($id);
    }

    /**
     * 上传到小飞机网盘
     * POST /admin/apk-distribution/{id}/feijipan
     */
    public function uploadFeijipan(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        $result = ApkDistributionService::uploadToFeijii($id);
        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }
        return $result;
    }

    public function uploadCustom(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        $result = ApkDistributionService::uploadCustom($id);
        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }
        return $result;
    }

    public function delete(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        $ok = ApkDistributionService::delete($id);
        if (!$ok) {
            Response::fail($context['response'], '删除失败', Response::CODE_ERROR);
            return false;
        }
        return ['message' => '删除成功'];
    }

    public static function downloadByToken(array $context, array $params = [])
    {
        $token = (string)($params['token'] ?? '');
        $response = $context['response'];

        $fileInfo = ApkDistributionService::getDownloadFile($token);
        if (!$fileInfo['found']) {
            $msg = $fileInfo['record'] !== null ? 'APK 文件不存在，可能已被删除' : '下载链接无效或已失效';
            Response::fail($response, $msg, Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $record  = $fileInfo['record'];
        $apkPath = $fileInfo['path'];
        $filename= $fileInfo['filename'];
        $apkSize = @filesize($apkPath);
        $distributionId = (int)($record['id'] ?? 0);

        // ===== 统计：先计数 + 写日志（保证「先统计、后跳转/发送」，307 跳转绝不丢数）=====
        $ip = AdminAuth::getClientIp($context);
        $ua = (string)($context['header']['user-agent'] ?? $context['server']['http_user_agent'] ?? '');
        $referer = (string)($context['header']['referer'] ?? $context['server']['http_referer'] ?? '');
        ApkDistributionService::incrementDownloadCount($token, $ip, $ua, $referer);

        // ===== 分支 A：小飞机直链 307（优先走缓存 → 懒解析 → 再失败就回退）=====
        if ($distributionId > 0 && !empty($record['feijipan_url'])) {
            $cache = ApkDistributionService::getCachedFeijiiDirectUrl($distributionId);
            $directUrl = '';
            if ($cache['hit']) {
                $directUrl = $cache['url'];
            } else {
                $parsed = ApkDistributionService::resolveFeijiiDirectUrl((string)$record['feijipan_url']);
                if ($parsed !== '') {
                    ApkDistributionService::saveCachedFeijiiDirectUrl($distributionId, $parsed);
                    $directUrl = $parsed;
                }
            }
            if ($directUrl !== '') {
                // 用 307 跳转（部分安卓浏览器对 302 APK 下载拦截更严，307 兼容性更好）
                $response->status(307);
                $response->header('Location', $directUrl);
                $response->header('Access-Control-Allow-Origin', '*');
                $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
                $response->header('Pragma', 'no-cache');
                $response->header('Expires', '0');
                // Swoole Response：直接 end 结束；FPM/原生 PHP 兜底用 header()
                if (method_exists($response, 'end')) {
                    $response->end('');
                } else {
                    if (!headers_sent()) {
                        header('HTTP/1.1 307 Temporary Redirect', true, 307);
                        header('Location: ' . $directUrl);
                    }
                }
                return false;
            }
            // 解析失败：不阻塞，回退到「分支 B：自托管 sendfile」
        }

        // ===== 分支 B：自托管下载（服务器直出）=====
        $response->status(200);
        $response->header('Content-Type', 'application/vnd.android.package-archive');
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        if ($apkSize !== false && $apkSize > 0) {
            $response->header('Content-Length', (string)$apkSize);
        }
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Cache-Control', 'no-cache');
        $response->sendfile($apkPath);

        return false;
    }

    public static function infoByToken(array $context, array $params = [])
    {
        $token = (string)($params['token'] ?? '');
        $record = ApkDistributionService::getByToken($token);

        if ($record === null) {
            Response::fail($context['response'], '下载链接无效或已失效', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $apkSize = (int)($record['apk_size'] ?? 0);
        $sizeText = '';
        if ($apkSize < 1024) {
            $sizeText = $apkSize . ' B';
        } elseif ($apkSize < 1024 * 1024) {
            $sizeText = round($apkSize / 1024, 1) . ' KB';
        } elseif ($apkSize < 1024 * 1024 * 1024) {
            $sizeText = round($apkSize / (1024 * 1024), 2) . ' MB';
        } else {
            $sizeText = round($apkSize / (1024 * 1024 * 1024), 2) . ' GB';
        }

        return [
            'app_name'     => $record['app_name'],
            'package_name' => $record['package_name'],
            'version_name' => $record['version_name'],
            'apk_size'     => $apkSize,
            'apk_size_text'=> $sizeText,
            'md5'          => $record['md5'],
            'created_at'   => $record['created_at'],
            'download_url' => '/api/apk-distribution/download/' . $token,
        ];
    }

    private static function parseJsonBody(array $context): array
    {
        $raw = $context['raw'] ?? '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $context['post'] ?? [];
    }
}
