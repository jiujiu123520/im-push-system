<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 用户公告服务
 *
 * 负责：用户端公告列表、首页公告、登录弹窗公告、已读标记
 */
class UserNoticeService
{
    /**
     * 获取发布中的公告列表（分页）
     *
     * @param int   $page
     * @param int   $perPage
     * @param array $filters ['keyword'=>'', 'type'=>0, 'show_home'=>0]
     * @param int|null $userId  登录用户ID（用于判断已读）
     * @return array
     */
    public function listPublished(int $page, int $perPage, array $filters = [], ?int $userId = null): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset  = ($page - 1) * $perPage;

        $now = date('Y-m-d H:i:s');
        $where = " WHERE status = 1
                     AND (start_at IS NULL OR start_at <= ?)
                     AND (end_at   IS NULL OR end_at   >= ?)";
        $bind  = [$now, $now];

        if (!empty($filters['keyword'])) {
            $where .= ' AND (title LIKE ? OR content LIKE ?)';
            $kw = '%' . (string)$filters['keyword'] . '%';
            array_push($bind, $kw, $kw);
        }
        if (!empty($filters['type'])) {
            $where .= ' AND type = ?';
            $bind[] = (int)$filters['type'];
        }
        if (!empty($filters['show_home'])) {
            $where .= ' AND show_home = 1';
        }

        $total = (int)(Database::fetch(
            "SELECT COUNT(*) cnt FROM user_notices {$where}",
            $bind
        )['cnt'] ?? 0);

        $list = Database::fetchAll(
            "SELECT id, title, LEFT(content, 300) AS summary, type, level, show_dialog, show_home,
                    is_sticky, sort, status, start_at, end_at, publish_at, created_at, updated_at
             FROM user_notices
             {$where}
             ORDER BY is_sticky DESC, sort DESC, publish_at DESC, id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bind
        );

        if ($userId !== null && $userId > 0) {
            $ids = array_map('intval', array_column($list, 'id'));
            if (!empty($ids)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $readRows = Database::fetchAll(
                    "SELECT notice_id FROM user_notice_reads WHERE user_id = ? AND notice_id IN ({$ph})",
                    array_merge([$userId], $ids)
                );
                $readMap = [];
                foreach ($readRows as $r) {
                    $readMap[(int)$r['notice_id']] = true;
                }
                foreach ($list as &$row) {
                    $row['read'] = isset($readMap[(int)$row['id']]) ? 1 : 0;
                }
                unset($row);
            }
        } else {
            foreach ($list as &$row) {
                $row['read'] = 0;
            }
            unset($row);
        }

        return [
            'list'        => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }

    /**
     * 获取登录弹窗需要展示的公告（未读、show_dialog=1）
     */
    public function getDialogNotices(?int $userId): array
    {
        $now = date('Y-m-d H:i:s');
        $where = " WHERE status = 1 AND show_dialog = 1
                     AND (start_at IS NULL OR start_at <= ?)
                     AND (end_at   IS NULL OR end_at   >= ?)";
        $bind = [$now, $now];

        $list = Database::fetchAll(
            "SELECT id, title, content, type, level, publish_at, created_at
             FROM user_notices {$where}
             ORDER BY is_sticky DESC, sort DESC, publish_at DESC, id DESC
             LIMIT 20",
            $bind
        );

        if ($userId !== null && $userId > 0) {
            $ids = array_map('intval', array_column($list, 'id'));
            if (!empty($ids)) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $readRows = Database::fetchAll(
                    "SELECT notice_id FROM user_notice_reads WHERE user_id = ? AND notice_id IN ({$ph})",
                    array_merge([$userId], $ids)
                );
                $readMap = [];
                foreach ($readRows as $r) {
                    $readMap[(int)$r['notice_id']] = true;
                }
                // 未读才弹窗
                $list = array_values(array_filter($list, function ($row) use ($readMap) {
                    return !isset($readMap[(int)$row['id']]);
                }));
            }
        }

        return $list;
    }

    /**
     * 获取单个发布中的公告详情
     */
    public function getPublished(int $id, ?int $userId): ?array
    {
        if ($id <= 0) return null;
        $now = date('Y-m-d H:i:s');
        $row = Database::fetch(
            "SELECT * FROM user_notices
             WHERE id = ? AND status = 1
               AND (start_at IS NULL OR start_at <= ?)
               AND (end_at   IS NULL OR end_at   >= ?)
             LIMIT 1",
            [$id, $now, $now]
        );
        if ($row === false) return null;

        $row['read'] = 0;
        if ($userId !== null && $userId > 0) {
            $r = Database::fetch(
                'SELECT 1 FROM user_notice_reads WHERE user_id = ? AND notice_id = ? LIMIT 1',
                [$userId, $id]
            );
            $row['read'] = $r !== false ? 1 : 0;
        }
        return $row;
    }

    /**
     * 标记已读
     */
    public function markRead(int $userId, array $noticeIds): void
    {
        if ($userId <= 0 || empty($noticeIds)) return;
        $noticeIds = array_values(array_unique(array_map('intval', array_filter($noticeIds, fn($v) => (int)$v > 0))));
        if (empty($noticeIds)) return;
        $now = date('Y-m-d H:i:s');
        foreach ($noticeIds as $nid) {
            // INSERT IGNORE 防止重复
            Database::execute(
                'INSERT IGNORE INTO user_notice_reads (user_id, notice_id, read_at) VALUES (?, ?, ?)',
                [$userId, $nid, $now]
            );
        }
    }

    /**
     * 全部标记已读
     */
    public function markAllRead(int $userId): void
    {
        if ($userId <= 0) return;
        $now = date('Y-m-d H:i:s');
        $nowBind = $now;
        Database::execute(
            "INSERT IGNORE INTO user_notice_reads (user_id, notice_id, read_at)
             SELECT ?, n.id, ?
             FROM user_notices n
             WHERE n.status = 1
               AND (n.end_at IS NULL OR n.end_at >= ?)
               AND NOT EXISTS (
                 SELECT 1 FROM user_notice_reads r WHERE r.user_id = ? AND r.notice_id = n.id
               )",
            [$userId, $nowBind, $nowBind, $userId]
        );
    }

    // ---------------- 后台 CRUD（管理端 NoticeController 复用）----------------

    public function adminList(int $page, int $perPage, array $filters = []): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where = ' WHERE 1=1';
        $bind  = [];
        if (!empty($filters['keyword'])) {
            $where .= ' AND (title LIKE ? OR content LIKE ?)';
            $kw = '%' . (string)$filters['keyword'] . '%';
            array_push($bind, $kw, $kw);
        }
        if (isset($filters['status']) && (int)$filters['status'] >= 0) {
            $where .= ' AND status = ?';
            $bind[] = (int)$filters['status'];
        }
        if (!empty($filters['type'])) {
            $where .= ' AND type = ?';
            $bind[] = (int)$filters['type'];
        }

        $total = (int)(Database::fetch("SELECT COUNT(*) cnt FROM user_notices {$where}", $bind)['cnt'] ?? 0);
        $list = Database::fetchAll(
            "SELECT * FROM user_notices {$where}
             ORDER BY is_sticky DESC, sort DESC, publish_at DESC, id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $bind
        );
        return [
            'list'        => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }

    public function adminCreate(array $data, int $adminId): int
    {
        $title       = trim((string)($data['title'] ?? ''));
        $content     = (string)($data['content'] ?? '');
        if ($title === '') throw new \InvalidArgumentException('标题不能为空');
        $type        = (int)($data['type'] ?? 1);
        $level       = (int)($data['level'] ?? 1);
        $showDialog  = (int)($data['show_dialog'] ?? 1);
        $showHome    = (int)($data['show_home']   ?? 1);
        $isSticky    = (int)($data['is_sticky']   ?? 0);
        $sort        = (int)($data['sort']        ?? 0);
        $status      = (int)($data['status']      ?? 1);
        // 空字符串转 null，避免 MySQL DATETIME 严格模式报错
        $startAt     = !empty($data['start_at']) ? $data['start_at'] : null;
        $endAt       = !empty($data['end_at'])   ? $data['end_at']   : null;
        $publishAt   = !empty($data['publish_at']) ? $data['publish_at'] : ($status === 1 ? date('Y-m-d H:i:s') : null);

        return (int)Database::insert(
            'INSERT INTO user_notices
             (title, content, type, level, show_dialog, show_home, is_sticky, sort, status,
              start_at, end_at, publish_at, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $title, $content, $type, $level, $showDialog, $showHome, $isSticky, $sort, $status,
                $startAt, $endAt, $publishAt, $adminId, $adminId,
            ]
        );
    }

    public function adminUpdate(int $id, array $data, int $adminId): bool
    {
        if ($id <= 0) return false;
        $row = Database::fetch('SELECT id FROM user_notices WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) return false;
        $sets = [];
        $bind = [];
        $fields = [
            'title', 'content', 'type', 'level', 'show_dialog', 'show_home',
            'is_sticky', 'sort', 'status', 'start_at', 'end_at', 'publish_at',
        ];
        // DATETIME 字段：空字符串转 null，避免 MySQL 严格模式报错
        $dateFields = ['start_at', 'end_at', 'publish_at'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $val = $data[$f];
                if (in_array($f, $dateFields, true) && $val === '') {
                    $val = null;
                }
                $sets[] = "`{$f}` = ?";
                $bind[] = $val;
            }
        }
        if (empty($sets)) return true;
        $sets[] = 'updated_by = ?';
        $bind[] = $adminId;
        $bind[] = $id;
        Database::execute('UPDATE user_notices SET ' . implode(', ', $sets) . ' WHERE id = ?', $bind);
        return true;
    }

    public function adminDelete(int $id): bool
    {
        if ($id <= 0) return false;
        Database::execute('DELETE FROM user_notice_reads WHERE notice_id = ?', [$id]);
        Database::execute('DELETE FROM user_notices WHERE id = ?', [$id]);
        return true;
    }
}
