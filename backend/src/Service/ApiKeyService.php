<?php
declare(strict_types=1);

namespace App\Service;

/**
 * API Key 服务
 *
 * 负责开放 API 所需的 Key 生成、校验、禁用等操作。
 * Key 格式：pk_ + 32 位随机 hex（共 35 字符）。
 */
class ApiKeyService
{
    /**
     * 每页数量
     */
    private const PER_PAGE = 10;

    /**
     * Key 前缀
     */
    private const KEY_PREFIX = 'pk_';

    /**
     * 生成 API Key
     *
     * @param string      $name     Key 名称（备注）
     * @param string|null $expireAt 过期时间（Y-m-d H:i:s 或时间戳），为 null 则永不过期
     * @return array 包含 id、key_value、name、expire_at 的数组
     */
    public function generateKey(string $name, ?string $expireAt = null): array
    {
        // 生成 32 位随机 hex（16 字节随机数据）
        $keyValue = self::KEY_PREFIX . bin2hex(random_bytes(16));

        // 格式化过期时间
        $expireDate = null;
        if ($expireAt !== null && $expireAt !== '') {
            $ts = is_numeric($expireAt) ? (int)$expireAt : strtotime($expireAt);
            if ($ts !== false) {
                $expireDate = date('Y-m-d H:i:s', $ts);
            }
        }

        $id = Database::insert(
            'INSERT INTO api_keys (key_value, name, status, expire_at) VALUES (?, ?, 1, ?)',
            [$keyValue, $name, $expireDate]
        );

        return [
            'id'        => (int)$id,
            'key_value' => $keyValue,
            'name'      => $name,
            'expire_at' => $expireDate,
        ];
    }

    /**
     * 校验 API Key 有效性
     *
     * @param string $keyValue API Key 值
     * @return array ['valid' => bool, 'key' => array|null, 'reason' => string]
     */
    public function validateKey(string $keyValue): array
    {
        $key = Database::fetch(
            'SELECT * FROM api_keys WHERE key_value = ? AND status = 1 LIMIT 1',
            [$keyValue]
        );

        if ($key === false) {
            return ['valid' => false, 'key' => null, 'reason' => 'key 不存在或已禁用'];
        }

        // 检查是否过期
        if ($key['expire_at'] !== null) {
            if (strtotime($key['expire_at']) < time()) {
                return ['valid' => false, 'key' => null, 'reason' => 'key 已过期'];
            }
        }

        return ['valid' => true, 'key' => $key, 'reason' => ''];
    }

    /**
     * 禁用 API Key
     *
     * @param int $id API Key ID
     * @return bool
     */
    public function revokeKey(int $id): bool
    {
        return Database::execute('UPDATE api_keys SET status = 0 WHERE id = ?', [$id]) > 0;
    }

    /**
     * 更新 API Key
     *
     * @param int         $id
     * @param array       $data  可更新字段：name, status, expire_at
     * @return bool
     */
    public function update(int $id, array $data): bool
    {
        $sets = [];
        $params = [];

        if (isset($data['name'])) {
            $sets[]   = 'name = ?';
            $params[] = $data['name'];
        }
        if (isset($data['status'])) {
            $sets[]   = 'status = ?';
            $params[] = (int)$data['status'];
        }
        if (array_key_exists('expire_at', $data)) {
            $expire = $data['expire_at'];
            if ($expire !== null && $expire !== '') {
                $ts = is_numeric($expire) ? (int)$expire : strtotime($expire);
                $expire = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
            } else {
                $expire = null;
            }
            $sets[]   = 'expire_at = ?';
            $params[] = $expire;
        }

        if (empty($sets)) {
            return false;
        }

        $params[] = $id;
        return Database::execute('UPDATE api_keys SET ' . implode(', ', $sets) . ' WHERE id = ?', $params) > 0;
    }

    /**
     * 删除 API Key
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        return Database::execute('DELETE FROM api_keys WHERE id = ?', [$id]) > 0;
    }

    /**
     * 根据 ID 获取 API Key
     *
     * @param int $id
     * @return array|null
     */
    public function getById(int $id): ?array
    {
        $row = Database::fetch('SELECT * FROM api_keys WHERE id = ? LIMIT 1', [$id]);
        return $row !== false ? $row : null;
    }

    /**
     * 分页查询 API Key 列表
     *
     * @param int    $page    页码
     * @param string $keyword 搜索关键词（匹配 name / key_value）
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
            $where  = ' WHERE name LIKE ? OR key_value LIKE ?';
            $params = ["%{$keyword}%", "%{$keyword}%"];
        }

        $list = Database::fetchAll(
            "SELECT * FROM api_keys{$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $total = (int)(Database::fetch("SELECT COUNT(*) AS total FROM api_keys{$where}", $params)['total'] ?? 0);

        return [
            'list'        => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }
}
