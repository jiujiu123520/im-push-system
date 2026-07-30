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
            $this->table->column('last_active', Table::TYPE_INT, 8);
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
        $now = time();

        // 写入 Swoole 内存表
        if ($this->table !== null) {
            $this->table->set((string)$fd, [
                'device_id'   => $deviceId,
                'key_value'   => $keyValue,
                'push_key_id' => $pushKeyId,
                'connect_at'  => $now,
                'last_active' => $now,
                'ip'          => $ip,
                'fingerprint' => $fingerprint,
            ]);
        }

        // Redis：key -> device_id 集合
        $this->redis->sAdd("key:subscribe:{$keyValue}", $deviceId);

        // Redis：device_id -> key_value 哈希
        $this->redis->hSet('device:key', $deviceId, $keyValue);

        // 兜底：大小写不敏感重放一次，避免客户端发送的 key_value 与数据库里的大小写不一致导致
        //       查询 subscribers 时"设备在线但订阅集合里找不到"。
        //       （集合 sAdd 是幂等的，集合里写一份大小写不敏感的备份成本极低；hSet 也幂等。）
        $kvLower = strtolower($keyValue);
        if ($kvLower !== $keyValue) {
            $this->redis->sAdd("key:subscribe:{$kvLower}", $deviceId);
        }
        // hSet device:key 也强制覆写一次为标准 key_value（修正历史错乱的映射值）
        $existing = $this->redis->hGet('device:key', $deviceId);
        if ($existing === false || $existing === null || (string)$existing !== $keyValue) {
            $this->redis->hSet('device:key', $deviceId, $keyValue);
        }

        // Redis：device_id -> fd 集合
        $this->redis->sAdd("ws:device:{$deviceId}", (string)$fd);

        // Redis：fd -> device_id 映射（用于僵尸连接清理）
        $this->redis->hSet('ws:fd:device', (string)$fd, $deviceId);

        // Redis：在线 fd 集合
        $this->redis->sAdd('ws:online', (string)$fd);

        // Redis：连接详情（供后台 HTTP 进程读取，跨进程共享）
        $this->redis->hMSet("ws:conn:{$fd}", [
            'device_id'   => $deviceId,
            'key_value'   => $keyValue,
            'push_key_id' => (string)$pushKeyId,
            'connect_at'  => (string)$now,
            'last_active' => (string)$now,
            'ip'          => $ip,
        ]);
    }

    /**
     * 注销设备连接
     *
     * 注意：仅清理在线连接相关数据（ws:device / ws:fd:device / ws:online / Swoole Table），
     * 不清理 key:subscribe 和 device:key 映射 —— 这两者是"设备订阅关系"，应持久保留，
     * 用于 APP 离线时存离线消息、APP 重连时识别已绑定设备。
     * 若此处清理 key:subscribe，会导致后续推送找不到订阅设备、无法存离线消息。
     *
     * @param int $fd 连接文件描述符
     * @return array|null 返回被注销的设备信息，不存在则返回 null
     */
    public function unregisterDevice(int $fd): ?array
    {
        $info = $this->getDeviceInfo($fd);
        if ($info === null) {
            // 内存表中无记录，尝试通过 ws:fd:device 反查 deviceId 兜底清理
            $deviceId = $this->redis->hGet('ws:fd:device', (string)$fd);
            if ($deviceId !== false && $deviceId !== null) {
                $deviceId = (string)$deviceId;
                $this->redis->sRem("ws:device:{$deviceId}", (string)$fd);
                $this->redis->hDel('ws:fd:device', (string)$fd);
            }
            $this->redis->sRem('ws:online', (string)$fd);
            $this->redis->del("ws:conn:{$fd}");
            return null;
        }

        $deviceId = $info['device_id'];
        $keyValue  = $info['key_value'];

        // 从内存表删除
        if ($this->table !== null) {
            $this->table->del((string)$fd);
        }

        // 从 device_id -> fd 集合移除（仅清理在线 fd 映射）
        $this->redis->sRem("ws:device:{$deviceId}", (string)$fd);

        // 从 fd -> device_id 映射移除
        $this->redis->hDel('ws:fd:device', (string)$fd);

        // 保留 key:subscribe:{keyValue} 和 device:key 哈希
        // —— 订阅关系是持久的，APP 离线不应移除，否则推送时找不到订阅设备无法存离线消息

        // 从在线集合移除
        $this->redis->sRem('ws:online', (string)$fd);

        // 删除连接详情
        $this->redis->del("ws:conn:{$fd}");

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
     * 更新连接的最后活跃时间（写入 Swoole Table，跨 worker 共享）
     *
     * 用于僵尸连接巡检（cleanupDeadConnections）判断连接是否存活：
     * Swoole 的 isEstablished 在跨 worker 调用时不可靠，因此改用
     * last_active 时间戳 + 阈值来判断连接是否真的死亡。
     *
     * 应在 onMessage 的 auth/ping/pong 等事件中调用。
     *
     * @param int $fd 连接文件描述符
     * @return void
     */
    public function updateLastActive(int $fd): void
    {
        $now = time();
        if ($this->table !== null) {
            // 仅更新 last_active 字段，避免覆盖其他字段
            $this->table->set((string)$fd, ['last_active' => $now]);
        }
        // 同步到 Redis（供后台 HTTP 进程读取，跨进程共享）
        $this->redis->hSet("ws:conn:{$fd}", 'last_active', (string)$now);
    }

    /**
     * 获取连接的最后活跃时间戳
     *
     * @param int $fd 连接文件描述符
     * @return int|null 返回时间戳，无记录返回 null
     */
    public function getLastActive(int $fd): ?int
    {
        if ($this->table === null) {
            return null;
        }
        $row = $this->table->get((string)$fd);
        if ($row === false) {
            return null;
        }
        return (int)($row['last_active'] ?? 0);
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

    /**
     * 清理设备的旧连接（重连时调用）
     *
     * 当设备重新鉴权时，关闭其所有旧 fd 并清理 Redis 映射，
     * 防止僵尸连接导致"同一设备多个在线连接"的问题。
     *
     * 注意：此方法应在 registerDevice 之前调用，且不清理当前 $excludeFd。
     *
     * @param string $deviceId  设备唯一标识
     * @param int    $excludeFd 当前新连接的 fd（不清理此 fd）
     * @return int 被清理的旧连接数
     */
    public function cleanupOldConnections(string $deviceId, int $excludeFd = 0): int
    {
        $oldFds = $this->redis->sMembers("ws:device:{$deviceId}");
        if (empty($oldFds) || !is_array($oldFds)) {
            return 0;
        }

        $cleaned = 0;
        foreach ($oldFds as $fdStr) {
            $fd = (int)$fdStr;
            if ($fd === $excludeFd) {
                continue;
            }
            // 从 Redis 各集合中移除旧 fd
            $this->redis->sRem("ws:device:{$deviceId}", (string)$fd);
            $this->redis->hDel('ws:fd:device', (string)$fd);
            $this->redis->sRem('ws:online', (string)$fd);
            $this->redis->del("ws:conn:{$fd}");

            // 从内存表删除
            if ($this->table !== null) {
                $this->table->del((string)$fd);
            }
            $cleaned++;
        }

        return $cleaned;
    }

    /**
     * 获取设备在线 fd 数量
     *
     * @param string $deviceId
     * @return int
     */
    public function getDeviceFdCount(string $deviceId): int
    {
        return (int)$this->redis->sCard("ws:device:{$deviceId}");
    }

    /**
     * 获取某 Key 下所有在线设备 ID 及其 fd 数
     *
     * @param string $keyValue
     * @return array<array{device_id: string, fd_count: int}>
     */
    public function getOnlineDevicesByKey(string $keyValue): array
    {
        $deviceIds = $this->redis->sMembers("key:subscribe:{$keyValue}");
        $result = [];
        foreach ($deviceIds as $deviceId) {
            $fdCount = (int)$this->redis->sCard("ws:device:{$deviceId}");
            if ($fdCount > 0) {
                $result[] = [
                    'device_id' => $deviceId,
                    'fd_count'  => $fdCount,
                ];
            }
        }
        return $result;
    }

    /**
     * 获取所有在线连接列表（含连接详情，从 Redis 读取，跨进程可用）
     *
     * @return array<array{fd: int, device_id: string, key_value: string, push_key_id: int, connect_at: int, last_active: int, ip: string}>
     */
    public function getAllConnections(): array
    {
        $fds = $this->redis->sMembers('ws:online');
        if (empty($fds)) {
            return [];
        }

        $result = [];
        $now = time();
        foreach ($fds as $fdStr) {
            $fd = (int)$fdStr;
            $conn = $this->redis->hGetAll("ws:conn:{$fd}");
            if (empty($conn)) {
                // ws:conn 不存在但 ws:online 有记录：孤儿 fd
                $deviceId = (string)($this->redis->hGet('ws:fd:device', $fdStr) ?: '');
                $conn = [
                    'device_id'   => $deviceId,
                    'key_value'   => '',
                    'push_key_id' => '0',
                    'connect_at'  => '0',
                    'last_active' => '0',
                    'ip'          => '',
                ];
            }
            $lastActive = (int)($conn['last_active'] ?? 0);
            $result[] = [
                'fd'          => $fd,
                'device_id'   => (string)($conn['device_id'] ?? ''),
                'key_value'   => (string)($conn['key_value'] ?? ''),
                'push_key_id' => (int)($conn['push_key_id'] ?? 0),
                'connect_at'  => (int)($conn['connect_at'] ?? 0),
                'last_active' => $lastActive,
                'idle_seconds' => $lastActive > 0 ? ($now - $lastActive) : -1,
                'ip'          => (string)($conn['ip'] ?? ''),
            ];
        }
        // 按 idle_seconds 降序（最久未活跃的排前面）
        usort($result, fn($a, $b) => $b['idle_seconds'] <=> $a['idle_seconds']);
        return $result;
    }

    /**
     * 获取僵尸连接列表
     *
     * 僵尸连接定义：last_active 超过阈值（默认 600 秒 = 10 分钟），
     * 或 ws:conn 不存在但 ws:online 仍有记录的孤儿 fd。
     *
     * @param int $threshold 僵尸判定阈值（秒）
     * @return array 僵尸连接列表
     */
    public function getZombieConnections(int $threshold = 600): array
    {
        $all = $this->getAllConnections();
        return array_values(array_filter($all, fn($c) => $c['idle_seconds'] < 0 || $c['idle_seconds'] > $threshold));
    }

    /**
     * 强制移除连接（后台手动清理僵尸连接用）
     *
     * 清理 Redis 中的在线标记和连接详情，以及 Swoole Table（如果可用）。
     * 注意：此方法不会调用 $server->disconnect()（HTTP 进程无 $server 引用），
     * 仅清理 Redis 数据层。Swoole 内置心跳会在 heartbeat_idle_time 后自动断开 TCP。
     *
     * @param int $fd 连接文件描述符
     * @return bool 是否成功移除
     */
    public function forceRemoveConnection(int $fd): bool
    {
        $existed = $this->redis->sIsMember('ws:online', (string)$fd);
        if (!$existed) {
            return false;
        }

        // 获取 device_id 用于清理 ws:device 集合
        $deviceId = (string)($this->redis->hGet('ws:fd:device', (string)$fd) ?: '');
        if ($deviceId !== '') {
            $this->redis->sRem("ws:device:{$deviceId}", (string)$fd);
        }

        $this->redis->hDel('ws:fd:device', (string)$fd);
        $this->redis->sRem('ws:online', (string)$fd);
        $this->redis->del("ws:conn:{$fd}");

        // Swoole Table（仅在 WebSocket 进程中可用）
        if ($this->table !== null) {
            $this->table->del((string)$fd);
        }

        return true;
    }
}
