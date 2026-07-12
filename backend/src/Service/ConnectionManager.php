<?php
declare(strict_types=1);

namespace App\Service;

use Swoole\Table;

/**
 * 连接管理器
 *
 * 维护 WebSocket 连接的各类映射关系：
 *   1. fd ↔ device_id 映射（Swoole\Table 内存表，跨 Worker 共享）
 *   2. key ↔ [device_id] 映射（Redis Set: key:subscribe:{keyValue}）
 *   3. device_id ↔ key 映射（Redis Hash: device:key）
 *   4. device_id ↔ [fd] 映射（Redis Set: ws:device:{deviceId}）
 *
 * 同时提供黑名单检查能力（查询 blacklists 表）。
 */
class ConnectionManager
{
    /**
     * @var Table|null Swoole 内存表（仅在 WebSocket 进程中创建）
     */
    private ?Table $table = null;

    /**
     * @var \Predis\Client Redis 客户端
     */
    private $redis;

    /**
     * 构造方法
     *
     * @param bool $createTable 是否创建 Swoole 内存表（WebSocket 进程传 true）
     */
    public function __construct(bool $createTable = false)
    {
        if ($createTable && class_exists(Table::class)) {
            $this->table = new Table(65536);
            $this->table->column('device_id', Table::TYPE_STRING, 128);
            $this->table->column('key_value', Table::TYPE_STRING, 128);
            $this->table->column('push_key_id', Table::TYPE_INT, 8);
            $this->table->column('connect_at', Table::TYPE_INT, 8);
            $this->table->column('ip', Table::TYPE_STRING, 45);
            $this->table->column('fingerprint', Table::TYPE_STRING, 128);
            $this->table->create();
        }
        $this->redis = Redis::getInstance();
    }

    /**
     * 重建 Redis 连接（Worker 进程启动后调用，避免使用主进程的连接）
     *
     * @return void
     */
    public function reconnect(): void
    {
        $this->redis = Redis::getInstance();
    }

    /**
     * 注册设备连接
     *
     * @param int    $fd         连接文件描述符
     * @param string $deviceId   设备唯一标识
     * @param string $keyValue   推送 Key 值
     * @param int    $pushKeyId  推送 Key ID
     * @param array  $deviceInfo 设备附加信息（ip, fingerprint 等）
     * @return void
     */
    public function registerDevice(int $fd, string $deviceId, string $keyValue, int $pushKeyId, array $deviceInfo = []): void
    {
        $ip = $deviceInfo['ip'] ?? '';
        $fingerprint = $deviceInfo['fingerprint'] ?? '';

        // 写入 Swoole 内存表
        if ($this->table !== null) {
            $this->table->set((string)$fd, [
                'device_id'   => $deviceId,
                'key_value'   => $keyValue,
                'push_key_id' => $pushKeyId,
                'connect_at'  => time(),
                'ip'          => $ip,
                'fingerprint' => $fingerprint,
            ]);
        }

        // Redis：key -> device_id 集合
        $this->redis->sAdd("key:subscribe:{$keyValue}", $deviceId);

        // Redis：device_id -> key_value 哈希
        $this->redis->hSet('device:key', $deviceId, $keyValue);

        // Redis：device_id -> fd 集合
        $this->redis->sAdd("ws:device:{$deviceId}", (string)$fd);

        // Redis：在线 fd 集合
        $this->redis->sAdd('ws:online', (string)$fd);
    }

    /**
     * 注销设备连接
     *
     * @param int $fd 连接文件描述符
     * @return array|null 返回被注销的设备信息，不存在则返回 null
     */
    public function unregisterDevice(int $fd): ?array
    {
        $info = $this->getDeviceInfo($fd);
        if ($info === null) {
            // 内存表中无记录，仅从在线集合移除
            $this->redis->sRem('ws:online', (string)$fd);
            return null;
        }

        $deviceId = $info['device_id'];
        $keyValue  = $info['key_value'];

        // 从内存表删除
        if ($this->table !== null) {
            $this->table->del((string)$fd);
        }

        // 从 device_id -> fd 集合移除
        $this->redis->sRem("ws:device:{$deviceId}", (string)$fd);

        // 若该设备已无任何在线 fd，则从 key 订阅集合和 device:key 哈希中移除
        if ($this->redis->sCard("ws:device:{$deviceId}") == 0) {
            $this->redis->sRem("key:subscribe:{$keyValue}", $deviceId);
            $this->redis->hDel('device:key', $deviceId);
        }

        // 从在线集合移除
        $this->redis->sRem('ws:online', (string)$fd);

        return $info;
    }

    /**
     * 获取某 Key 下所有在线设备的 fd 列表
     *
     * @param string $keyValue 推送 Key 值
     * @return int[] fd 数组
     */
    public function getDevicesByKey(string $keyValue): array
    {
        $deviceIds = $this->redis->sMembers("key:subscribe:{$keyValue}");
        $fds = [];
        foreach ($deviceIds as $deviceId) {
            $deviceFds = $this->redis->sMembers("ws:device:{$deviceId}");
            foreach ($deviceFds as $fd) {
                $fds[] = (int)$fd;
            }
        }
        return array_unique($fds);
    }

    /**
     * 获取某设备的所有在线 fd
     *
     * @param string $deviceId 设备唯一标识
     * @return int[] fd 数组
     */
    public function getFdsByDevice(string $deviceId): array
    {
        $fds = $this->redis->sMembers("ws:device:{$deviceId}");
        return array_values(array_unique(array_map('intval', $fds)));
    }

    /**
     * 获取 fd 对应的设备信息
     *
     * @param int $fd
     * @return array|null
     */
    public function getDeviceInfo(int $fd): ?array
    {
        if ($this->table !== null) {
            $row = $this->table->get((string)$fd);
            return $row !== false ? $row : null;
        }
        return null;
    }

    /**
     * 获取设备订阅的 Key
     *
     * @param string $deviceId
     * @return string|null
     */
    public function getDeviceKey(string $deviceId): ?string
    {
        $key = $this->redis->hGet('device:key', $deviceId);
        return $key !== null && $key !== false ? (string)$key : null;
    }

    /**
     * 切换设备的 Key 订阅
     *
     * @param int    $fd
     * @param string $newKeyValue 新的 Key 值
     * @param int    $newPushKeyId 新的推送 Key ID
     * @return void
     */
    public function switchKey(int $fd, string $newKeyValue, int $newPushKeyId): void
    {
        $info = $this->getDeviceInfo($fd);
        if ($info === null) {
            return;
        }

        $oldKeyValue = $info['key_value'];
        $deviceId    = $info['device_id'];

        // 从旧 Key 集合移除
        $this->redis->sRem("key:subscribe:{$oldKeyValue}", $deviceId);

        // 加入新 Key 集合
        $this->redis->sAdd("key:subscribe:{$newKeyValue}", $deviceId);

        // 更新 device:key 哈希
        $this->redis->hSet('device:key', $deviceId, $newKeyValue);

        // 更新内存表
        if ($this->table !== null) {
            $this->table->set((string)$fd, [
                'device_id'   => $deviceId,
                'key_value'   => $newKeyValue,
                'push_key_id' => $newPushKeyId,
                'connect_at'  => $info['connect_at'],
                'ip'          => $info['ip'],
                'fingerprint' => $info['fingerprint'],
            ]);
        }
    }

    /**
     * 检查某值是否在黑名单中
     *
     * @param string $type  类型：device_id / ip / fingerprint
     * @param string $value 值
     * @return bool
     */
    public function isBlacklisted(string $type, string $value): bool
    {
        $stmt = Database::query(
            'SELECT id FROM blacklists WHERE type = ? AND value = ? LIMIT 1',
            [$type, $value]
        );
        return $stmt->fetch() !== false;
    }

    /**
     * 根据条件查找在线 fd（用于黑名单断开连接）
     *
     * @param string $field 字段名：device_id / ip / fingerprint
     * @param string $value 值
     * @return int[] fd 数组
     */
    public function findFdsByField(string $field, string $value): array
    {
        if ($field === 'device_id') {
            return $this->getFdsByDevice($value);
        }

        // ip 或 fingerprint 需要遍历内存表
        $fds = [];
        if ($this->table !== null) {
            foreach ($this->table as $fdStr => $row) {
                if (isset($row[$field]) && $row[$field] === $value) {
                    $fds[] = (int)$fdStr;
                }
            }
        }

        return $fds;
    }

    /**
     * 获取所有在线 fd
     *
     * @return int[]
     */
    public function getAllOnlineFds(): array
    {
        $fds = $this->redis->sMembers('ws:online');
        return array_map('intval', $fds);
    }
}
