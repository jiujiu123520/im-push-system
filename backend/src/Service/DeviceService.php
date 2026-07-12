<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 设备服务
 *
 * 负责设备注册、指纹生成、设备列表查询等操作。
 * 使用 PDO 操作 devices 表。
 */
class DeviceService
{
    /**
     * 每页数量
     */
    private const PER_PAGE = 10;

    /**
     * 注册或更新设备记录
     *
     * 若 devices 表中不存在则插入，存在则更新 last_connect_at、IP 等字段。
     *
     * @param array $data 设备数据
     *   - device_id:     string 设备唯一标识
     *   - push_key_id:   int    推送 Key ID
     *   - user_id:       int    用户 ID
     *   - device_name:   string 设备名称
     *   - device_model:  string 设备型号
     *   - os_version:    string 操作系统版本
     *   - ip:            string IP 地址
     *   - ua:            string User-Agent
     *   - fingerprint:   string 设备指纹
     * @return array 设备记录
     */
    public function registerDevice(array $data): array
    {
        $deviceId   = (string)($data['device_id'] ?? '');
        $pushKeyId  = (int)($data['push_key_id'] ?? 0);
        $userId     = (int)($data['user_id'] ?? 0);
        $deviceName = (string)($data['device_name'] ?? '');
        $model      = (string)($data['device_model'] ?? '');
        $osVersion  = (string)($data['os_version'] ?? '');
        $ip         = (string)($data['ip'] ?? '');
        $ua         = (string)($data['ua'] ?? '');
        $fingerprint = (string)($data['fingerprint'] ?? '');

        // 若未提供指纹则自动生成
        if ($fingerprint === '' && $deviceId !== '') {
            $fingerprint = $this->generateFingerprint($deviceId, $model, $osVersion);
        }

        // 查询是否已存在
        $existing = Database::fetch(
            'SELECT * FROM devices WHERE device_id = ? AND push_key_id = ?',
            [$deviceId, $pushKeyId]
        );

        if ($existing === false) {
            // 插入新设备
            $id = Database::insert(
                'INSERT INTO devices (device_id, push_key_id, user_id, device_name, device_model, os_version, ip, ua, fingerprint, status, last_connect_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())',
                [$deviceId, $pushKeyId, $userId, $deviceName, $model, $osVersion, $ip, $ua, $fingerprint]
            );
            $device = Database::fetch('SELECT * FROM devices WHERE id = ?', [$id]);
        } else {
            // 更新已有设备
            Database::execute(
                'UPDATE devices
                 SET user_id = ?, device_name = ?, device_model = ?, os_version = ?, ip = ?, ua = ?, fingerprint = ?, status = 1, last_connect_at = NOW()
                 WHERE id = ?',
                [$userId, $deviceName, $model, $osVersion, $ip, $ua, $fingerprint, $existing['id']]
            );
            $device = Database::fetch('SELECT * FROM devices WHERE id = ?', [$existing['id']]);
        }

        return $device !== false ? $device : [];
    }

    /**
     * 更新设备状态
     *
     * @param string $deviceId 设备唯一标识
     * @param int    $pushKeyId 推送 Key ID
     * @param int    $status   状态：0=离线 1=在线 2=禁用
     * @return bool
     */
    public function updateStatus(string $deviceId, int $pushKeyId, int $status): bool
    {
        return Database::execute(
            'UPDATE devices SET status = ? WHERE device_id = ? AND push_key_id = ?',
            [$status, $deviceId, $pushKeyId]
        ) > 0;
    }

    /**
     * 生成设备指纹（SHA256）
     *
     * @param string $deviceId   设备唯一标识
     * @param string $model      设备型号
     * @param string $osVersion  操作系统版本
     * @return string 64 位十六进制字符串
     */
    public function generateFingerprint(string $deviceId, string $model, string $osVersion): string
    {
        return hash('sha256', $deviceId . '|' . $model . '|' . $osVersion);
    }

    /**
     * 分页查询设备列表
     *
     * @param int    $page    页码（从 1 开始）
     * @param string $keyword 搜索关键词（匹配 device_id / device_name / device_model）
     * @return array
     */
    public function listDevices(int $page, string $keyword = ''): array
    {
        $page    = max(1, $page);
        $perPage = self::PER_PAGE;
        $offset  = ($page - 1) * $perPage;

        $where  = '';
        $params = [];
        if ($keyword !== '') {
            $where  = ' WHERE device_id LIKE ? OR device_name LIKE ? OR device_model LIKE ?';
            $params = ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"];
        }

        $devices = Database::fetchAll(
            "SELECT * FROM devices{$where} ORDER BY last_connect_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $total = (int)(Database::fetch("SELECT COUNT(*) AS total FROM devices{$where}", $params)['total'] ?? 0);

        return [
            'list'        => $devices,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }

    /**
     * 获取设备详情
     *
     * @param string $deviceId 设备唯一标识
     * @return array|null
     */
    public function getDeviceDetail(string $deviceId): ?array
    {
        $device = Database::fetch(
            'SELECT * FROM devices WHERE device_id = ? LIMIT 1',
            [$deviceId]
        );
        return $device !== false ? $device : null;
    }

    /**
     * 根据 device_id 和 push_key_id 获取设备详情
     *
     * 用于精确匹配同一推送 Key 下的设备。
     *
     * @param string $deviceId   设备唯一标识
     * @param int    $pushKeyId  推送 Key ID
     * @return array|null
     */
    public function getDeviceByKey(string $deviceId, int $pushKeyId): ?array
    {
        $device = Database::fetch(
            'SELECT * FROM devices WHERE device_id = ? AND push_key_id = ? LIMIT 1',
            [$deviceId, $pushKeyId]
        );
        return $device !== false ? $device : null;
    }

    /**
     * 根据 ID 获取设备详情
     *
     * @param int $id 主键 ID
     * @return array|null
     */
    public function getDeviceById(int $id): ?array
    {
        $device = Database::fetch('SELECT * FROM devices WHERE id = ? LIMIT 1', [$id]);
        return $device !== false ? $device : null;
    }

    /**
     * 根据 push_key_id 获取设备数量
     *
     * @param int $pushKeyId
     * @return int
     */
    public function countByPushKey(int $pushKeyId): int
    {
        return (int)(Database::fetch(
            'SELECT COUNT(*) AS total FROM devices WHERE push_key_id = ?',
            [$pushKeyId]
        )['total'] ?? 0);
    }
}
