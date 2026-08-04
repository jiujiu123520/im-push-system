<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Service\Database;

/**
 * 用户端推送记录
 *
 * 路由前缀：/user-api/push-logs
 */
class PushLogController extends BaseUserController
{
    public function index(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        [$page, $perPage, $offset, $keyword] = $this->parsePage($context, 20);

        $targetType = (string)($context['get']['target_type'] ?? $context['get']['targetType'] ?? '');
        $status = isset($context['get']['status']) ? (int)$context['get']['status'] : -1;

        // 用户维度：api_key 属于该用户 OR detail JSON 里 user_id = 该用户 OR
        // push 通过自己的 push_keys 的 key/device 推送 → 使用 (api_keys.user_id OR detail.user_id)
        $where = " WHERE (ak.user_id = ? OR JSON_EXTRACT(COALESCE(pl.detail,'{}'), '$.user_id') = ?)";
        $bind = [$userId, $userId];

        if ($keyword !== '') {
            $where .= ' AND (pl.title LIKE ? OR pl.content LIKE ? OR pl.target_value LIKE ?)';
            $kw = "%{$keyword}%";
            array_push($bind, $kw, $kw, $kw);
        }
        if ($targetType !== '') {
            $where .= ' AND pl.target_type = ?';
            $bind[] = $targetType;
        }
        if ($status >= 0) {
            $where .= ' AND pl.status = ?';
            $bind[] = $status;
        }

        $total = (int)(Database::fetch(
            "SELECT COUNT(*) cnt FROM push_logs pl LEFT JOIN api_keys ak ON ak.id = pl.api_key_id {$where}",
            $bind
        )['cnt'] ?? 0);

        $list = Database::fetchAll(
            "SELECT pl.id, pl.api_key_id, pl.target_type, pl.target_value, pl.title, pl.content,
                    pl.success_count, pl.fail_count, pl.fail_reason, pl.status, pl.elapsed_ms, pl.created_at, pl.detail
             FROM push_logs pl LEFT JOIN api_keys ak ON ak.id = pl.api_key_id
             {$where}
             ORDER BY pl.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $bind
        );
        foreach ($list as &$row) {
            $row['detail']      = $this->tryJsonDecode($row['detail'] ?? null);
            $row['fail_detail'] = $row['detail']['fail_detail'] ?? [];
            $row['push_detail'] = $row['detail']['push_detail'] ?? [];
            unset($row['detail']['user_id']);
        }
        unset($row);

        return $this->pageResult($list, $total, $page, $perPage);
    }

    public function show(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) return $this->fail($context, '无效的日志ID');

        $row = Database::fetch(
            "SELECT pl.* FROM push_logs pl LEFT JOIN api_keys ak ON ak.id = pl.api_key_id
             WHERE pl.id = ? AND (ak.user_id = ? OR JSON_EXTRACT(COALESCE(pl.detail,'{}'), '$.user_id') = ?)
             LIMIT 1",
            [$id, $userId, $userId]
        );
        if ($row === false) return $this->fail($context, '记录不存在', 404, 404);

        $row['detail']      = $this->tryJsonDecode($row['detail'] ?? null);
        unset($row['detail']['user_id']);
        $row['fail_detail'] = $row['detail']['fail_detail'] ?? [];
        $row['push_detail'] = $row['detail']['push_detail'] ?? [];
        return $row;
    }
}
