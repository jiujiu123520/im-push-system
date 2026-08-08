<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 消息服务
 *
 * 负责消息记录查询与导出（CSV / JSON）。
 * 使用 PDO 操作 messages 表与 push_logs 表。
 */
class MessageService
{
    /** 每页数量 */
    private const PER_PAGE = 10;

    /**
     * 消息列表（分页）
     *
     * @param int    $page    页码
     * @param string $keyword 关键词（匹配标题/内容/设备ID）
     * @return array { list, total, page, page_size }
     */
    public function list(int $page, string $keyword = '', int $pushKeyId = 0): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $where = ' WHERE 1=1';
        $args = [];

        if ($keyword !== '') {
            $where .= ' AND (m.title LIKE ? OR m.content LIKE ? OR m.device_id LIKE ?)';
            $escaped = Database::escapeLike($keyword);
            $like = '%' . $escaped . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }
        if ($pushKeyId > 0) {
            $where .= ' AND m.push_key_id = ?';
            $args[] = $pushKeyId;
        }

        // 总数
        $countSql = "SELECT COUNT(*) FROM messages m {$where}";
        $stmt = Database::pdo()->prepare($countSql);
        $stmt->execute($args);
        $total = (int)$stmt->fetchColumn();

        // 列表
        $listSql = "SELECT m.id, m.push_key_id, m.device_id, m.title, m.content, m.payload, m.is_read, m.created_at"
            . " FROM messages m {$where}"
            . " ORDER BY m.id DESC LIMIT " . self::PER_PAGE . " OFFSET {$offset}";
        $stmt = Database::pdo()->prepare($listSql);
        $stmt->execute($args);
        $list = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // payload JSON 解码
        foreach ($list as &$row) {
            $row['is_read'] = (int)$row['is_read'];
            $row['payload'] = $row['payload'] ? json_decode($row['payload'], true) : null;
        }
        unset($row);

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => self::PER_PAGE,
        ];
    }

    /**
     * 推送日志列表（分页）
     *
     * 支持按 target_type 和 status 筛选：
     *   - target_type: device / key / broadcast / user
     *   - status: 0=失败 1=成功 2=部分成功（由 success_count/fail_count 派生）
     *
     * @param int    $page    页码
     * @param string $keyword 关键词（匹配 title/content/target_value）
     * @param array  $filters 筛选条件 ['target_type' => string, 'status' => int]
     * @return array
     */
    public function listPushLogs(int $page, string $keyword = '', array $filters = []): array
    {
        // 7天日志自动清理（每次查询时触发，轻量级，避免引入定时任务依赖）
        $this->cleanExpiredPushLogs(7);

        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $where = ' WHERE 1=1';
        $args = [];

        if ($keyword !== '') {
            $where .= ' AND (p.title LIKE ? OR p.content LIKE ? OR p.target_value LIKE ?)';
            $escaped = Database::escapeLike($keyword);
            $like = '%' . $escaped . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        // 目标类型筛选
        $targetType = (string)($filters['target_type'] ?? '');
        if ($targetType !== '') {
            $where .= ' AND p.target_type = ?';
            $args[] = $targetType;
        }

        // 状态筛选：0=失败 1=成功 2=部分成功 3=进行中
        $statusFilter = (int)($filters['status'] ?? -1);
        if ($statusFilter >= 0 && $statusFilter <= 3) {
            $where .= ' AND p.status = ?';
            $args[] = $statusFilter;
        }

        $countSql = "SELECT COUNT(*) FROM push_logs p {$where}";
        $stmt = Database::pdo()->prepare($countSql);
        $stmt->execute($args);
        $total = (int)$stmt->fetchColumn();

        $listSql = "SELECT p.id, p.api_key_id, p.target_type, p.target_value, p.title, p.content,"
            . " p.success_count, p.fail_count, p.fail_reason, p.status, p.elapsed_ms, p.created_at"
            . " FROM push_logs p {$where}"
            . " ORDER BY p.id DESC LIMIT " . self::PER_PAGE . " OFFSET {$offset}";
        $stmt = Database::pdo()->prepare($listSql);
        $stmt->execute($args);
        $list = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 类型规范化
        foreach ($list as &$row) {
            $row['success_count'] = (int)$row['success_count'];
            $row['fail_count']    = (int)$row['fail_count'];
            $row['status']        = (int)$row['status'];
            $row['elapsed_ms']    = (int)$row['elapsed_ms'];
            $row['fail_reason']   = $row['fail_reason'] ?? '';
        }
        unset($row);

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => self::PER_PAGE,
        ];
    }

    /**
     * 获取推送日志详情
     *
     * @param int $id 日志ID
     * @return array|null
     */
    public function getPushLogDetail(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, api_key_id, target_type, target_value, title, content,'
            . ' success_count, fail_count, fail_reason, status, elapsed_ms, detail, created_at'
            . ' FROM push_logs WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['success_count'] = (int)$row['success_count'];
        $row['fail_count']    = (int)$row['fail_count'];
        $row['status']        = (int)$row['status'];
        $row['elapsed_ms']    = (int)$row['elapsed_ms'];
        $row['fail_reason']   = $row['fail_reason'] ?? '';

        // 解析 detail JSON：兼容新旧两种结构
        // 旧结构：detail 是数组 [{fd, status, message}]
        // 新结构：detail 是对象 {push_detail: [...], fail_detail: [{target, reason}]}
        $rawDetail = $row['detail'] ?? '';
        $decoded = $rawDetail !== '' ? json_decode($rawDetail, true) : null;

        if (is_array($decoded) && isset($decoded['fail_detail'])) {
            // 新结构
            $row['push_detail']  = $decoded['push_detail'] ?? [];
            $row['fail_detail']  = $decoded['fail_detail'] ?? [];
        } elseif (is_array($decoded)) {
            // 旧结构（索引数组）：尝试转换为 fail_detail 格式
            $row['push_detail']  = $decoded;
            $row['fail_detail']  = [];
            foreach ($decoded as $item) {
                if (is_array($item) && ($item['status'] ?? '') === 'failed') {
                    $row['fail_detail'][] = [
                        'target' => isset($item['device_id']) ? $item['device_id']
                                  : (isset($item['key']) ? 'key:' . $item['key']
                                  : (isset($item['fd']) ? 'fd:' . $item['fd'] : '-')),
                        'reason' => $item['message'] ?? '未知原因',
                    ];
                }
            }
        } else {
            $row['push_detail']  = [];
            $row['fail_detail']  = [];
        }
        unset($row['detail']);

        // 补充设备元信息（device_model / platform / app_version / device_status）
        $row['push_detail'] = $this->enrichWithDeviceInfo($row['push_detail']);
        $row['fail_detail'] = $this->enrichFailDetailWithDeviceInfo($row['fail_detail']);

        return $row;
    }

    /**
     * 为 push_detail 项补充设备元信息（device_model / platform / app_version / device_status）
     *
     * @param array $pushDetail
     * @return array
     */
    private function enrichWithDeviceInfo(array $pushDetail): array
    {
        if (empty($pushDetail)) {
            return $pushDetail;
        }

        // 收集所有 device_id
        $deviceIds = [];
        foreach ($pushDetail as $item) {
            if (is_array($item) && !empty($item['device_id'])) {
                $deviceIds[] = $item['device_id'];
            }
        }

        $deviceMeta = $this->fetchDeviceMeta($deviceIds);

        foreach ($pushDetail as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $deviceId = $item['device_id'] ?? '';
            $meta = $deviceId !== '' ? ($deviceMeta[$deviceId] ?? null) : null;
            $item['device_model']   = $meta['device_model'] ?? '';
            $item['platform']       = $meta['platform'] ?? '';
            $item['app_version']    = $meta['app_version'] ?? '';
            $item['device_status']  = $meta['device_status'] ?? '';
            $item['last_active_at'] = $meta['last_active_at'] ?? '';
        }
        unset($item);

        return $pushDetail;
    }

    /**
     * 为 fail_detail 项补充设备元信息
     *
     * @param array $failDetail
     * @return array
     */
    private function enrichFailDetailWithDeviceInfo(array $failDetail): array
    {
        if (empty($failDetail)) {
            return $failDetail;
        }

        // 收集 device_id（target 去掉 key:/fd: 前缀后就是 device_id）
        $deviceIds = [];
        foreach ($failDetail as $item) {
            $target = $item['target'] ?? '';
            if ($target !== '' && strpos($target, 'key:') !== 0 && strpos($target, 'fd:') !== 0) {
                $deviceIds[] = $target;
            }
        }

        $deviceMeta = $this->fetchDeviceMeta($deviceIds);

        foreach ($failDetail as &$item) {
            $target = $item['target'] ?? '';
            $deviceId = '';
            if (strpos($target, 'key:') !== 0 && strpos($target, 'fd:') !== 0) {
                $deviceId = $target;
            }
            $meta = $deviceId !== '' ? ($deviceMeta[$deviceId] ?? null) : null;
            $item['device_model']  = $meta['device_model'] ?? '';
            $item['platform']      = $meta['platform'] ?? '';
            $item['app_version']   = $meta['app_version'] ?? '';
        }
        unset($item);

        return $failDetail;
    }

    /**
     * 批量查询设备元信息
     *
     * @param array $deviceIds
     * @return array<string, array>  device_id => [device_model, platform, app_version, device_status, last_active_at]
     */
    private function fetchDeviceMeta(array $deviceIds): array
    {
        $deviceIds = array_values(array_filter(array_unique($deviceIds)));
        if (empty($deviceIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
        $sql = "SELECT device_id, device_model, platform, app_version, status, last_active_at"
             . " FROM devices WHERE device_id IN ({$placeholders})";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($deviceIds);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $result[$r['device_id']] = [
                'device_model'   => $r['device_model'] ?? '',
                'platform'       => $r['platform'] ?? '',
                'app_version'    => $r['app_version'] ?? '',
                'device_status'  => isset($r['status']) ? (int)$r['status'] : 0,
                'last_active_at' => $r['last_active_at'] ?? '',
            ];
        }

        return $result;
    }

    /**
     * 删除单条推送日志
     *
     * @param int $id 日志ID
     * @return bool 成功返回 true
     * @throws \Exception
     */
    public function deletePushLog(int $id): bool
    {
        $stmt = Database::pdo()->prepare('DELETE FROM push_logs WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new \Exception('推送日志不存在');
        }
        return true;
    }

    /**
     * 清理过期推送日志（默认保留 7 天）
     *
     * 使用低频触发策略：每次列表查询时调用，但仅在距离上次清理超过 6 小时时真正执行 DELETE
     * 通过 Redis 标记 last_clean_time，避免高频 DELETE 影响数据库性能
     *
     * @param int $retainDays 保留天数
     * @return int 删除的记录数
     */
    public function cleanExpiredPushLogs(int $retainDays = 7): int
    {
        try {
            $redis = Redis::getInstance();
            $lockKey = 'push_logs:last_clean_at';
            $lastClean = (int)$redis->get($lockKey);
            $now = time();
            // 6 小时内已清理过则跳过
            if ($lastClean > 0 && ($now - $lastClean) < 21600) {
                return 0;
            }
            // 设置清理标记（即使清理失败也避免频繁尝试）
            $redis->set($lockKey, (string)$now, 'ex', 21600);

            $cutoff = date('Y-m-d H:i:s', $now - $retainDays * 86400);
            $stmt = Database::pdo()->prepare('DELETE FROM push_logs WHERE created_at < ?');
            $stmt->execute([$cutoff]);
            $deleted = $stmt->rowCount();

            if ($deleted > 0) {
                error_log("[PushLogs] 清理 {$retainDays} 天前过期日志：删除 {$deleted} 条，截止时间 {$cutoff}");
            }
            return $deleted;
        } catch (\Throwable $e) {
            error_log('[PushLogs] 清理过期日志失败：' . $e->getMessage());
            return 0;
        }
    }

    /**
     * 导出消息为 CSV 字符串
     *
     * @param string $keyword 关键词过滤（空则导出全部）
     * @return string CSV 内容（含 UTF-8 BOM）
     */
    public function exportMessagesCsv(string $keyword = ''): string
    {
        $rows = $this->fetchAllMessages($keyword);

        // UTF-8 BOM 确保 Excel 正确识别中文
        $csv = "\uFEFF";
        $csv .= "ID,推送KeyID,设备ID,标题,内容,是否已读,创建时间\r\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row['id'],
                $row['push_key_id'],
                $this->csvEscape($row['device_id']),
                $this->csvEscape($row['title']),
                $this->csvEscape($row['content']),
                $row['is_read'] ? '是' : '否',
                $row['created_at'],
            ]) . "\r\n";
        }
        return $csv;
    }

    /**
     * 导出消息为 JSON 字符串
     *
     * @param string $keyword
     * @return string
     */
    public function exportMessagesJson(string $keyword = ''): string
    {
        $rows = $this->fetchAllMessages($keyword);
        foreach ($rows as &$row) {
            $row['payload'] = $row['payload'] ? json_decode($row['payload'], true) : null;
            $row['is_read'] = (int)$row['is_read'];
        }
        unset($row);
        return json_encode([
            'export_time' => date('Y-m-d H:i:s'),
            'total'       => count($rows),
            'messages'    => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * 导出推送日志为 CSV
     */
    public function exportPushLogsCsv(string $keyword = ''): string
    {
        $rows = $this->fetchAllPushLogs($keyword);

        $csv = "\uFEFF";
        $csv .= "ID,APIKeyID,目标类型,目标值,标题,内容,成功数,失败数,创建时间\r\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row['id'],
                $row['api_key_id'],
                $row['target_type'],
                $this->csvEscape($row['target_value']),
                $this->csvEscape($row['title']),
                $this->csvEscape($row['content']),
                $row['success_count'],
                $row['fail_count'],
                $row['created_at'],
            ]) . "\r\n";
        }
        return $csv;
    }

    /**
     * 导出推送日志为 JSON
     */
    public function exportPushLogsJson(string $keyword = ''): string
    {
        $rows = $this->fetchAllPushLogs($keyword);
        foreach ($rows as &$row) {
            $row['detail'] = $row['detail'] ? json_decode($row['detail'], true) : null;
            $row['success_count'] = (int)$row['success_count'];
            $row['fail_count'] = (int)$row['fail_count'];
        }
        unset($row);
        return json_encode([
            'export_time' => date('Y-m-d H:i:s'),
            'total'       => count($rows),
            'push_logs'   => $rows,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    // ========== 内部方法 ==========

    /**
     * 按设备 ID 查询历史消息（APP 端绑定设备后同步用）
     *
     * 仅返回该设备在指定 push_key 下的消息，按 ID 倒序排列。
     * 通过 push_key_id 校验归属，防止越权查询其他 Key 下的消息。
     *
     * @param string $deviceId  设备 ID
     * @param int    $pushKeyId 推送 Key ID（用于归属校验，0 表示不校验）
     * @param int    $limit     返回条数（最大 100）
     * @param int    $beforeId  分页游标：返回 ID 小于此值的消息（0 表示从头开始）
     * @return array { list, total, has_more }
     */
    public function listByDevice(string $deviceId, int $pushKeyId = 0, int $limit = 50, int $beforeId = 0): array
    {
        $limit = max(1, min(100, $limit));

        $where  = ' WHERE device_id = ?';
        $args   = [$deviceId];

        if ($pushKeyId > 0) {
            $where .= ' AND push_key_id = ?';
            $args[] = $pushKeyId;
        }
        if ($beforeId > 0) {
            $where .= ' AND id < ?';
            $args[] = $beforeId;
        }

        // 总数（不受 beforeId 影响，表示该设备消息总量）
        $countSql = "SELECT COUNT(*) FROM messages WHERE device_id = ?";
        $countArgs = [$deviceId];
        if ($pushKeyId > 0) {
            $countSql .= ' AND push_key_id = ?';
            $countArgs[] = $pushKeyId;
        }
        $stmt = Database::pdo()->prepare($countSql);
        $stmt->execute($countArgs);
        $total = (int)$stmt->fetchColumn();

        // 列表
        $listSql = 'SELECT id, message_id, push_key_id, device_id, title, content, payload, is_read, created_at'
            . " FROM messages {$where}"
            . ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = Database::pdo()->prepare($listSql);
        $stmt->execute($args);
        $list = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($list as &$row) {
            $row['is_read'] = (int)$row['is_read'];
            $row['payload'] = $row['payload'] ? json_decode($row['payload'], true) : null;
        }
        unset($row);

        return [
            'list'     => $list,
            'total'    => $total,
            'has_more' => count($list) === $limit && $total > count($list),
        ];
    }

    /**
     * 统计设备在指定 push_key 下的消息总数（不含 beforeId 过滤）。
     *
     * @param string $deviceId
     * @param int    $pushKeyId
     * @return int
     */
    public function countByDevice(string $deviceId, int $pushKeyId = 0): int
    {
        $countSql = "SELECT COUNT(*) FROM messages WHERE device_id = ?";
        $args = [$deviceId];
        if ($pushKeyId > 0) {
            $countSql .= ' AND push_key_id = ?';
            $args[] = $pushKeyId;
        }
        $stmt = Database::pdo()->prepare($countSql);
        $stmt->execute($args);
        return (int)$stmt->fetchColumn();
    }

    /** 查询全部消息（不分页，用于导出） */
    private function fetchAllMessages(string $keyword = ''): array
    {
        $where = ' WHERE 1=1';
        $args = [];
        if ($keyword !== '') {
            $where .= ' AND (title LIKE ? OR content LIKE ? OR device_id LIKE ?)';
            $escaped = Database::escapeLike($keyword);
            $like = '%' . $escaped . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }
        $sql = "SELECT id, push_key_id, device_id, title, content, payload, is_read, created_at"
            . " FROM messages {$where} ORDER BY id DESC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** 查询全部推送日志（不分页，用于导出） */
    private function fetchAllPushLogs(string $keyword = ''): array
    {
        $where = ' WHERE 1=1';
        $args = [];
        if ($keyword !== '') {
            $where .= ' AND (title LIKE ? OR content LIKE ? OR target_value LIKE ?)';
            $escaped = Database::escapeLike($keyword);
            $like = '%' . $escaped . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }
        $sql = "SELECT id, api_key_id, target_type, target_value, title, content, success_count, fail_count, detail, created_at"
            . " FROM push_logs {$where} ORDER BY id DESC";
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** CSV 字段转义 */
    private function csvEscape(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (strpbrk($value, ",\"\n\r") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
