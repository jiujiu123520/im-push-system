<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\AudioService;
use App\Service\Response;

/**
 * 音频管理控制器
 *
 * 后台路由（需鉴权）：
 *   GET    /admin/audio              音频列表（分页）
 *   POST   /admin/audio/upload       上传音频
 *   PUT    /admin/audio/{id}         更新音频信息
 *   DELETE /admin/audio/{id}         删除音频
 *   POST   /admin/audio/{id}/default 设为默认播放
 *
 * 公开路由（APP 端用，无需鉴权）：
 *   GET  /api/audio/list              获取启用的音频列表
 *   GET  /api/audio/play/{id}         播放音频（流式输出）
 */
class AudioController
{
    /**
     * 音频列表（后台管理）
     * GET /admin/audio
     */
    public function index(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $page = (int)($context['get']['page'] ?? 1);
        $pageSize = (int)($context['get']['page_size'] ?? 20);
        if ($pageSize > 100) {
            $pageSize = 100;
        }

        return AudioService::getList($page, $pageSize);
    }

    /**
     * 上传音频
     * POST /admin/audio/upload
     */
    public function upload(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $files = $context['files'] ?? [];
        if (!isset($files['file']) || !is_array($files['file'])) {
            Response::fail($context['response'], '请选择要上传的音频文件', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $post = $context['post'] ?? [];
        $title = (string)($post['title'] ?? '');
        $artist = (string)($post['artist'] ?? '');

        $result = AudioService::upload($files['file'], $title, $artist);
        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }

        return $result;
    }

    /**
     * 更新音频信息
     * PUT /admin/audio/{id}
     */
    public function update(array $context, array $params = [])
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

        $body = self::parseJsonBody($context);
        $ok = AudioService::update($id, $body);
        if (!$ok) {
            Response::fail($context['response'], '更新失败', Response::CODE_ERROR);
            return false;
        }

        return ['message' => '更新成功'];
    }

    /**
     * 设置为默认播放
     * POST /admin/audio/{id}/default
     */
    public function setDefault(array $context, array $params = [])
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

        $ok = AudioService::setDefault($id);
        if (!$ok) {
            Response::fail($context['response'], '设置失败', Response::CODE_ERROR);
            return false;
        }

        return ['message' => '设置成功'];
    }

    /**
     * 删除音频
     * DELETE /admin/audio/{id}
     */
    public function delete(array $context, array $params = [])
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

        $ok = AudioService::delete($id);
        if (!$ok) {
            Response::fail($context['response'], '删除失败', Response::CODE_ERROR);
            return false;
        }

        return ['message' => '删除成功'];
    }

    /**
     * 获取启用的音频列表（APP 端用）
     * GET /api/audio/list
     */
    public static function getList(array $context, array $params = [])
    {
        return AudioService::getEnabledList();
    }

    /**
     * 播放音频（流式输出，APP 端用）
     * GET /api/audio/play/{id}
     */
    public static function play(array $context, array $params = [])
    {
        $id = (int)($params['id'] ?? 0);
        $response = $context['response'];

        $fileInfo = AudioService::getPlayFile($id);
        if ($fileInfo === null) {
            Response::fail($response, '音频不存在或已禁用', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $filePath = $fileInfo['path'];
        $size = $fileInfo['size'];
        $mimeType = $fileInfo['mime_type'] ?: 'audio/mpeg';

        // 公共响应头
        $response->header('Content-Type', $mimeType);
        $response->header('Accept-Ranges', 'bytes');
        $response->header('Cache-Control', 'public, max-age=3600');
        $response->header('Access-Control-Allow-Origin', '*');

        // 解析 Range 请求头，支持断点续传 / 拖动进度条
        $rangeHeader = $context['header']['range'] ?? '';
        if ($rangeHeader !== '' && preg_match('/bytes=(\d+)-(\d*)/', $rangeHeader, $m)) {
            $start = (int)$m[1];
            $end = ($m[2] !== '') ? (int)$m[2] : $size - 1;
            if ($start > $end || $start >= $size) {
                $response->status(416);
                $response->header('Content-Range', "bytes */$size");
                return false;
            }
            $length = $end - $start + 1;
            $response->status(206);
            $response->header('Content-Range', "bytes $start-$end/$size");
            $response->header('Content-Length', (string)$length);
            $response->sendfile($filePath, $start, $length);
        } else {
            $response->status(200);
            $response->header('Content-Length', (string)$size);
            $response->sendfile($filePath);
        }

        return false;
    }

    /**
     * 解析 JSON 请求体
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
