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
     * 删除推送日志
     * 路由：DELETE /admin/push-logs/{id}
     */
    public function deletePushLog(array $context, array $params)
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
        try {
            $service->deletePushLog($id);
            return Response::success(null, '删除成功');
        } catch (\Exception $e) {
            Response::fail($context['response'], $e->getMessage(), Response::CODE_NOT_FOUND, 404);
            return false;
        }
    }

    /**
     * 重新推送（对失败的推送记录进行重试）
     * 路由：POST /admin/push-logs/{id}/retry
     *
     * 逻辑：
     *   1. 查询原推送日志的 title/content/target_type/target_value
     *   2. 用 PushDispatcher 重新推送（走 WebSocket + APNS 双通道）
     *   3. 返回新的推送结果（成功/失败计数）
     *   4. 原日志状态不变，新推送会生成新日志（通过 message_id 区分）
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function retryPushLog(array $context, array $params)
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

        // 查询原推送日志
        $service = new MessageService();
        $detail = $service->getPushLogDetail($id);
        if ($detail === null) {
            Response::fail($context['response'], '推送日志不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $title      = (string)($detail['title'] ?? '');
        $content    = (string)($detail['content'] ?? '');
        $targetType = (string)($detail['target_type'] ?? '');
        $target     = (string)($detail['target_value'] ?? '');

        if ($title === '' || $target === '' || $targetType === '') {
            Response::fail($context['response'], '原推送记录数据不完整，无法重试', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 构建新的消息体（带 retry 标记）
        $message = [
            'message_id' => uniqid('retry_', true),
            'title'      => $title,
            'content'    => $content,
            'payload'    => [
                'retry_of'    => $id,
                'admin_id'    => $admin['admin_id'] ?? 0,
                'sent_at'     => date('Y-m-d H:i:s'),
            ],
            'priority'   => 'high',
            'timestamp'  => time(),
        ];

        $dispatcher = new \App\Service\PushDispatcher();
        $anyStoredOffline = false;
        $failReasons = [];

        if (in_array($targetType, ['device', 'deviceId'], true)) {
            // 按设备重推（支持多个设备 ID，英文逗号分隔）
            $deviceIds = array_values(array_filter(array_map('trim', explode(',', $target))));
            $result = $dispatcher->pushToDevices($deviceIds, $message);
            if (!empty($result['stored_offline'])) $anyStoredOffline = true;
            if (!empty($result['fail_reason'])) $failReasons[$result['fail_reason']] = 1;
        } elseif ($targetType === 'key') {
            // 按 Key 重推（支持多个 Key，英文逗号分隔）
            $keyValues = array_values(array_filter(array_map('trim', explode(',', $target))));
            $totalSuccess = 0;
            $totalFail = 0;
            $details = [];
            $failDetail = [];
            foreach ($keyValues as $kv) {
                $r = $dispatcher->pushByKey($kv, $message);
                $totalSuccess += $r['success_count'];
                $totalFail += $r['fail_count'];
                $details[] = ['key' => $kv, 'success' => $r['success_count'], 'fail' => $r['fail_count']];
                if (!empty($r['stored_offline'])) $anyStoredOffline = true;
                if (!empty($r['fail_detail'])) $failDetail = array_merge($failDetail, $r['fail_detail']);
                if (!empty($r['fail_reason'])) $failReasons[$r['fail_reason']] = ($failReasons[$r['fail_reason']] ?? 0) + 1;
            }
            $result = [
                'success_count'  => $totalSuccess,
                'fail_count'     => $totalFail,
                'stored_offline' => $anyStoredOffline,
                'detail'         => $details,
                'fail_detail'    => $failDetail,
                'fail_reason'    => $this->buildRetryFailReasonSummary($failReasons),
            ];
        } elseif ($targetType === 'broadcast') {
            // 广播重推：遍历所有启用的 Key
            $keys = \App\Service\Database::fetchAll('SELECT key_value FROM push_keys WHERE status = 1', []);
            $totalSuccess = 0;
            $totalFail = 0;
            $details = [];
            $failDetail = [];
            foreach ($keys as $keyRow) {
                $kv = (string)$keyRow['key_value'];
                $r = $dispatcher->pushByKey($kv, $message);
                $totalSuccess += $r['success_count'];
                $totalFail += $r['fail_count'];
                $details[] = ['key' => $kv, 'success' => $r['success_count'], 'fail' => $r['fail_count']];
                if (!empty($r['stored_offline'])) $anyStoredOffline = true;
                if (!empty($r['fail_detail'])) $failDetail = array_merge($failDetail, $r['fail_detail']);
                if (!empty($r['fail_reason'])) $failReasons[$r['fail_reason']] = ($failReasons[$r['fail_reason']] ?? 0) + 1;
            }
            $result = [
                'success_count'  => $totalSuccess,
                'fail_count'     => $totalFail,
                'stored_offline' => $anyStoredOffline,
                'detail'         => $details,
                'fail_detail'    => $failDetail,
                'fail_reason'    => $this->buildRetryFailReasonSummary($failReasons),
            ];
        } else {
            Response::fail($context['response'], '不支持的目标类型：' . $targetType, Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 构造返回结果
        $successCount = (int)($result['success_count'] ?? 0);
        $failCount = (int)($result['fail_count'] ?? 0);
        $storedOffline = !empty($result['stored_offline']);

        // 判断推送结果状态
        $status = 'failed';
        if ($successCount > 0 && $failCount === 0) {
            $status = 'success';
        } elseif ($successCount > 0 && $failCount > 0) {
            $status = 'partial';
        } elseif ($storedOffline) {
            $status = 'offline';
        }

        $statusText = [
            'success' => '推送成功',
            'partial' => '部分成功',
            'offline' => '已存离线（设备重连后可拉取）',
            'failed'  => '推送失败',
        ][$status] ?? '未知';

        return [
            'message'          => $statusText,
            'status'           => $status,
            'success_count'    => $successCount,
            'fail_count'       => $failCount,
            'stored_offline'   => $storedOffline,
            'fail_reason'      => $result['fail_reason'] ?? '',
            'retry_of'         => $id,
            'new_message_id'   => $message['message_id'],
        ];
    }

    /**
     * 构建失败原因摘要（重试用）
     *
     * @param array $failReasons [reason => count]
     * @return string
     */
    private function buildRetryFailReasonSummary(array $failReasons): string
    {
        if (empty($failReasons)) return '';
        arsort($failReasons);
        $parts = [];
        foreach ($failReasons as $reason => $count) {
            $parts[] = $count > 1 ? "{$reason}（{$count}次）" : $reason;
        }
        return implode('；', $parts);
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
