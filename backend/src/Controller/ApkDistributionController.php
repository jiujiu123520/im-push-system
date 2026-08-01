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
 *  2. 蓝奏云上传
 *  3. 自定义脚本上传
 *
 * 路由：
 *   GET  /admin/apk-distribution              分发记录列表（分页）
 *   GET  /admin/apk-distribution/{id}         分发记录详情
 *   GET  /admin/apk-distribution/config      获取分发配置
 *   PUT  /admin/apk-distribution/config       保存分发配置
 *   POST /admin/apk-distribution/upload       本地上传 APK 文件
 *   POST /admin/apk-distribution/validate-cookie  验证蓝奏云 Cookie
 *   GET  /admin/apk-distribution/{id}/stats   下载统计数据
 *   POST /admin/apk-distribution/{id}/lanzou  上传到蓝奏云
 *   POST /admin/apk-distribution/{id}/custom  执行自定义上传
 *   DELETE /admin/apk-distribution/{id}       删除分发记录
 *
 * 公开路由（无需鉴权）：
 *   GET  /api/apk-distribution/download/{token}  通过令牌下载 APK
 *   GET  /api/apk-distribution/info/{token}        通过令牌获取 APK 信息
 */
class ApkDistributionController
{
    /**
     * 分发记录列表（分页10条）
     * GET /admin/apk-distribution
     */
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

    /**
     * 分发记录详情
     * GET /admin/apk-distribution/{id}
     */
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

    /**
     * 获取分发配置
     * GET /admin/apk-distribution/config
     */
    public function getConfig(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        return ApkDistributionService::getConfig();
    }

    /**
     * 保存分发配置
     * PUT /admin/apk-distribution/config
     */
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
     * 验证蓝奏云 Cookie 是否有效
     * POST /admin/apk-distribution/validate-cookie
     *
     * 请求体：{ "cookie": "蓝奏云 Cookie 字符串" }
     */
    public function validateCookie(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $body = self::parseJsonBody($context);
        $cookie = (string)($body['cookie'] ?? '');
        if ($cookie === '') {
            Response::fail($context['response'], 'Cookie 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        return ApkDistributionService::validateLanzouCookie($cookie);
    }

    /**
     * 本地上传 APK 文件
     * POST /admin/apk-distribution/upload
     *
     * 表单字段：
     *   file         APK 文件（multipart）
     *   app_name     应用名称
     *   package_name 包名
     *   version_name 版本名
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

    /**
     * 获取下载统计数据
     * GET /admin/apk-distribution/{id}/stats
     */
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
     * 上传到蓝奏云
     * POST /admin/apk-distribution/{id}/lanzou
     */
    public function uploadLanzou(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        $result = ApkDistributionService::uploadToLanzou($id);
        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }
        return $result;
    }

    /**
     * 执行自定义上传
     * POST /admin/apk-distribution/{id}/custom
     */
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

    /**
     * 删除分发记录
     * DELETE /admin/apk-distribution/{id}
     */
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

    /**
     * 公开下载 APK（无需鉴权，通过 token 验证）
     * GET /api/apk-distribution/download/{token}
     *
     * @param array $context
     * @param array $params
     * @return false
     */
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

        $apkPath = $fileInfo['path'];
        $filename = $fileInfo['filename'];

        // 递增下载计数并记录下载日志（失败不影响下载）
        $ip = AdminAuth::getClientIp($context);
        $ua = (string)($context['header']['user-agent'] ?? $context['server']['http_user_agent'] ?? '');
        $referer = (string)($context['header']['referer'] ?? $context['server']['http_referer'] ?? '');
        ApkDistributionService::incrementDownloadCount($token, $ip, $ua, $referer);

        $response->status(200);
        $response->header('Content-Type', 'application/vnd.android.package-archive');
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->header('Content-Length', (string)filesize($apkPath));
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Cache-Control', 'no-cache');
        $response->sendfile($apkPath);

        return false;
    }

    /**
     * 公开获取 APK 信息（无需鉴权，通过 token 验证）
     * GET /api/apk-distribution/info/{token}
     *
     * 返回 APK 基本信息（不含敏感字段），用于下载页面展示。
     */
    public static function infoByToken(array $context, array $params = [])
    {
        $token = (string)($params['token'] ?? '');
        $record = ApkDistributionService::getByToken($token);

        if ($record === null) {
            Response::fail($context['response'], '下载链接无效或已失效', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        // 格式化大小
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

    /**
     * 解析 JSON 请求体
     *
     * @param array $context
     * @return array
     */
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
