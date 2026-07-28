<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\Database;
use App\Service\MessageService;
use App\Service\Response;

/**
 * APP 端设备 API 控制器（无需管理员鉴权，通过 push_key + device_id 鉴权）
 *
 * 路由：
 *   GET /api/device/messages  查询设备历史消息（绑定新设备 ID 后同步用）
 *
 * 鉴权方式：请求参数 push_key + device_id
 *           通过校验 push_key 是否存在且启用，确保调用方为合法 Key 持有者
 */
class DeviceApiController
{
    /**
     * 查询设备历史消息
     * 路由：GET /api/device/messages
     *
     * 参数：
     *   push_key    (string, 必填) 推送 Key 值
     *   device_id   (string, 必填) 设备 ID
     *   limit       (int,    可选) 返回条数，默认 50，最大 100
     *   before_id   (int,    可选) 分页游标，返回 ID 小于此值的消息
     *
     * 返回：
     *   {
     *     "code": 0,
     *     "message": "ok",
     *     "data": {
     *       "list": [...],
     *       "total": 100,
     *       "has_more": true
     *     }
     *   }
     */
    public function messages(array $context, array $params)
    {
        $response = $context['response'];
        $get      = $context['get'] ?? [];

        $pushKey  = (string)($get['push_key'] ?? '');
        $deviceId = (string)($get['device_id'] ?? '');
        $limit    = (int)($get['limit'] ?? 50);
        $beforeId = (int)($get['before_id'] ?? 0);

        if ($pushKey === '' || $deviceId === '') {
            Response::fail($response, 'push_key 和 device_id 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 校验 push_key 有效性并获取 push_key_id（用于归属校验）
        try {
            $stmt = Database::pdo()->prepare('SELECT id, status FROM push_keys WHERE key_value = ?');
            $stmt->execute([$pushKey]);
            $keyRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[DeviceApi] messages 查询 push_key 失败: ' . $e->getMessage());
            Response::fail($response, '服务器错误，请稍后再试', Response::CODE_INTERNAL, 500);
            return false;
        }

        if (!$keyRow) {
            Response::fail($response, 'push_key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        if ((int)$keyRow['status'] !== 1) {
            Response::fail($response, 'push_key 已被禁用', Response::CODE_FORBIDDEN, 403);
            return false;
        }

        $pushKeyId = (int)$keyRow['id'];

        // 查询设备历史消息
        $service = new MessageService();
        $result  = $service->listByDevice($deviceId, $pushKeyId, $limit, $beforeId);

        return Response::success($result, 'ok');
    }
}
