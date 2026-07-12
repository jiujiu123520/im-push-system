<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 黑名单服务
 *
 * 负责黑名单的增删查改，使用 PDO 操作 blacklists 表。
 * type 取值：device_id / ip / fingerprint
 */
class BlacklistService
{
    /**
     * 每页数量
     */
    private const PER_PAGE = 10;

    /**
     * 加入黑名单
     *
     * @param string $type    类型：device_id / ip / fingerprint
     * @param string $value   值
     * @param string $reason  原因
     * @param int    $adminId 操作管理员 ID
     * @return int 黑名单记录 ID（已存在则返回已有 ID）
     */
    public function add(string $type, string $value, string $reason, int $adminId): int
    {
        // 检查是否已存在
        $existing = Database::fetch(
            'SELECT id FROM blacklists WHERE type = ? AND value = ? LIMIT 1',
            [$type, $value]
        );

        if ($existing !== false) {
            return (int)$existing['id'];
        }

        return (int)Database::insert(
            'INSERT INTO blacklists (type, value, reason, admin_id) VALUES (?, ?, ?, ?)',
            [$type, $value, $reason, $adminId]
        );
    }

    /**
     * 移出黑名单
     *
     * @param int $id 黑名单记录 ID
     * @return bool 是否成功
     */
    public function remove(int $id): bool
    {
        return Database::execute('DELETE FROM blacklists WHERE id = ?', [$id]) > 0;
    }

    /**
     * 检查是否在黑名单中
     *
     * @param string $type  类型：device_id / ip / fingerprint
     * @param string $value 值
     * @return bool
     */
    public function check(string $type, string $value): bool
    {
        $result = Database::fetch(
            'SELECT id FROM blacklists WHERE type = ? AND value = ? LIMIT 1',
            [$type, $value]
        );
        return $result !== false;
    }

    /**
     * 分页查询黑名单
     *
     * @param int    $page    页码
     * @param string $keyword 搜索关键词（匹配 type / value / reason）
     * @return array
     */
    public function list(int $page, string $keyword = ''): array
    {
        $page    = max(1, $page);
        $perPage = self::PER_PAGE;
        $offset  = ($page - 1) * $perPage;

        $where  = '';
        $params = [];
        if ($keyword !== '') {
            $where  = ' WHERE type LIKE ? OR value LIKE ? OR reason LIKE ?';
            $params = ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"];
        }

        $list = Database::fetchAll(
            "SELECT * FROM blacklists{$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $total = (int)(Database::fetch("SELECT COUNT(*) AS total FROM blacklists{$where}", $params)['total'] ?? 0);

        return [
            'list'        => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }

    /**
     * 根据 ID 获取黑名单记录
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $row = Database::fetch('SELECT * FROM blacklists WHERE id = ? LIMIT 1', [$id]);
        return $row !== false ? $row : null;
    }
}
