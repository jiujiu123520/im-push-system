<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\MessageService;
use App\Service\Response;

/**
 * 消息记录控制器（需管理员鉴权）
 *
 * 路由：
 *   GET  /admin/messages           消息列表（分页10条，支持 keyword 搜索）
 *   GET  /admin/messages/export    导出消息（format=csv|json）
 *   GET  /admin/push-logs          推送日志列表（分页10条）
 *   GET  /admin/push-logs/export   导出推送日志（format=csv|json）
 */
class MessageController
{
    /**
     * 消息列表
     * 路由：GET /admin/messages
     */
    public function index(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $page    = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        $service = new MessageService();
        return $service->list($page, $keyword);
    }

    /**
     * 导出消息
     * 路由：GET /admin/messages/export?format=csv|json&keyword=
     *
     * 直接输出文件流，触发浏览器下载。
     */
    public function exportMessages(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $format  = strtolower((string)($context['get']['format'] ?? 'csv'));
        $keyword = (string)($context['get']['keyword'] ?? '');

        $service = new MessageService();
        $response = $context['response'];

        if ($format === 'json') {
            $content = $service->exportMessagesJson($keyword);
            $filename = 'messages_' . date('Ymd_His') . '.json';
            $mime = 'application/json; charset=utf-8';
        } else {
            $content = $service->exportMessagesCsv($keyword);
            $filename = 'messages_' . date('Ymd_His') . '.csv';
            $mime = 'text/csv; charset=utf-8';
        }

        // 直接输出文件流
        $response->status(200);
        $response->header('Content-Type', $mime);
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->header('Content-Length', (string)strlen($content));
        $response->end($content);
        return false; // 已自行输出
    }

    /**
     * 推送日志列表
     * 路由：GET /admin/push-logs
     *
     * 支持参数：page, keyword, target_type, status
     */
    public function pushLogs(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $page    = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        $filters = [
            'target_type' => (string)($context['get']['targetType'] ?? $context['get']['target_type'] ?? ''),
            'status'      => isset($context['get']['status']) ? (int)$context['get']['status'] : -1,
        ];

        $service = new MessageService();
        return $service->listPushLogs($page, $keyword, $filters);
    }

    /**
     * 推送日志详情
     * 路由：GET /admin/push-logs/{id}
     *
     * 返回单条推送日志详情，包含解析后的 fail_detail 失败明细
     */
    public function pushLogDetail(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的日志ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $service = new MessageService();
        $detail = $service->getPushLogDetail($id);
        if ($detail === null) {
            Response::fail($context['response'], '推送日志不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return $detail;
    }

    /**
     * 导出推送日志
     * 路由：GET /admin/push-logs/export?format=csv|json&keyword=
     */
    public function exportPushLogs(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $format  = strtolower((string)($context['get']['format'] ?? 'csv'));
        $keyword = (string)($context['get']['keyword'] ?? '');

        $service = new MessageService();
        $response = $context['response'];

        if ($format === 'json') {
            $content = $service->exportPushLogsJson($keyword);
            $filename = 'push_logs_' . date('Ymd_His') . '.json';
            $mime = 'application/json; charset=utf-8';
        } else {
            $content = $service->exportPushLogsCsv($keyword);
            $filename = 'push_logs_' . date('Ymd_His') . '.csv';
            $mime = 'text/csv; charset=utf-8';
        }

        $response->status(200);
        $response->header('Content-Type', $mime);
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->header('Content-Length', (string)strlen($content));
        $response->end($content);
        return false;
    }
}
