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
        // 游标分页（推荐，移动端无限滚动）：limit + before_id
        $limit    = (int)($get['limit'] ?? 0);
        $beforeId = (int)($get['before_id'] ?? 0);
        // 页码分页（兼容）：page + page_size
        $page     = (int)($get['page'] ?? 0);
        $pageSize = (int)($get['page_size'] ?? 0);

        if ($pushKey === '' || $deviceId === '') {
            Response::fail($response, 'push_key 和 device_id 不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 默认每页 20 条，上限 100 条
        if ($page > 0) {
            // 页码分页优先
            $page = max(1, $page);
            if ($pageSize <= 0) {
                $pageSize = 20;
            }
            $pageSize = max(1, min(100, $pageSize));
            $limit = $pageSize;
        } else {
            // 游标分页或无参数：默认 limit=20
            if ($limit <= 0) {
                $limit = 20;
            }
            $limit = max(1, min(100, $limit));
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

        // 如果是页码分页，转换为 before_id
        if ($page > 0) {
            $offset = ($page - 1) * $pageSize;
            // 查第 offset 条的 id 作为游标（因为是 ORDER BY id DESC，offset=0 是最新的）
            try {
                $whereOff  = ' WHERE device_id = ? AND push_key_id = ?';
                $stmtOff = Database::pdo()->prepare(
                    "SELECT id FROM messages {$whereOff} ORDER BY id DESC LIMIT 1 OFFSET {$offset}"
                );
                $stmtOff->execute([$deviceId, $pushKeyId]);
                $row = $stmtOff->fetch(\PDO::FETCH_ASSOC);
                if ($row) {
                    $beforeId = (int)$row['id'] + 1; // +1 让 id < beforeId 包含该条
                } else {
                    // 超出范围，直接返回空
                    $svc = new MessageService();
                    $total = $svc->countByDevice($deviceId, $pushKeyId);
                    return Response::success([
                        'list'           => [],
                        'total'          => $total,
                        'page'           => $page,
                        'page_size'      => $pageSize,
                        'total_pages'    => (int)ceil($total / max(1, $pageSize)),
                        'has_more'       => false,
                        'next_before_id' => 0,
                    ], 'ok');
                }
            } catch (\Throwable $e) {
                error_log('[DeviceApi] messages 分页游标计算失败: ' . $e->getMessage());
            }
        }

        // 查询设备历史消息
        $service = new MessageService();
        $result  = $service->listByDevice($deviceId, $pushKeyId, $limit, $beforeId);

        // 计算下一页游标：取本页最后一条（最旧）的 id - 1
        $list = $result['list'];
        $nextBeforeId = 0;
        if (!empty($list)) {
            $lastIdx = count($list) - 1;
            $lastId  = (int)($list[$lastIdx]['id'] ?? 0);
            $nextBeforeId = max(0, $lastId - 1);
        }

        $total = (int)($result['total'] ?? 0);
        $responseData = [
            'list'           => $list,
            'total'          => $total,
            'has_more'       => (bool)($result['has_more'] ?? false),
            'next_before_id' => $nextBeforeId,
            'limit'          => $limit,
            'before_id'      => $beforeId,
        ];
        if ($page > 0) {
            $responseData['page']        = $page;
            $responseData['page_size']   = $pageSize;
            $responseData['total_pages'] = (int)ceil($total / max(1, $pageSize));
        }

        return Response::success($responseData, 'ok');
    }
}
