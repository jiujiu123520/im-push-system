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
            $like = '%' . $keyword . '%';
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
     * @param int    $page    页码
     * @param string $keyword 关键词
     * @return array
     */
    public function listPushLogs(int $page, string $keyword = ''): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * self::PER_PAGE;

        $where = ' WHERE 1=1';
        $args = [];

        if ($keyword !== '') {
            $where .= ' AND (p.title LIKE ? OR p.content LIKE ? OR p.target_value LIKE ?)';
            $like = '%' . $keyword . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        $countSql = "SELECT COUNT(*) FROM push_logs p {$where}";
        $stmt = Database::pdo()->prepare($countSql);
        $stmt->execute($args);
        $total = (int)$stmt->fetchColumn();

        $listSql = "SELECT p.id, p.api_key_id, p.target_type, p.target_value, p.title, p.content,"
            . " p.success_count, p.fail_count, p.created_at"
            . " FROM push_logs p {$where}"
            . " ORDER BY p.id DESC LIMIT " . self::PER_PAGE . " OFFSET {$offset}";
        $stmt = Database::pdo()->prepare($listSql);
        $stmt->execute($args);
        $list = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => self::PER_PAGE,
        ];
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

    /** 查询全部消息（不分页，用于导出） */
    private function fetchAllMessages(string $keyword = ''): array
    {
        $where = ' WHERE 1=1';
        $args = [];
        if ($keyword !== '') {
            $where .= ' AND (title LIKE ? OR content LIKE ? OR device_id LIKE ?)';
            $like = '%' . $keyword . '%';
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
            $like = '%' . $keyword . '%';
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
