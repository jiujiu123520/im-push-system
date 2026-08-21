<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 设备掉线延迟通知服务
 *
 * 设计目标：避免瞬时断线（网络切换、APP 切后台）立即触发邮件轰炸，
 * 只对 **持续离线 >= 阈值分钟（默认 30 分钟）** 的设备发邮件提醒。
 *
 * 工作流程：
 *   1. onClose 触发 → markPendingOffline()：设备入队 Redis pending Set
 *   2. Worker 0 定时器（每 5 分钟） → processPendingQueue()：巡检队列
 *      - 设备仍在线？→ 移出队列，不发通知
 *      - 离线已 >= Key 配置阈值（notify_offline_minutes，默认 30 分钟）且冷却期已过
 *        → 发邮件 + 记录已发送标记 + 移出队列
 *      - 离线时长不够 → 保留在队列，下次再巡检
 *   3. onOpen 重连 → handleReconnect()：清理 pending 记录；
 *      若之前发过掉线通知 → 补发"设备已恢复"邮件（含离线时长）
 *
 * Redis Key 设计：
 *   im_push:offline_pending                   Set    — 所有待巡检 device_id
 *   im_push:offline_pending:{deviceId}        Hash   — push_key_id, info_json, offline_at
 *   im_push:notify_last_sent:{deviceId}       String — 上次发送时间戳（冷却期）
 *   im_push:notify_sent_flag:{deviceId}       Hash   — 已发送掉线通知标记（sent_at, offline_at, email, key_name, info_json）
 */
class DeviceOfflineNotifier
{
    /** 默认离线提醒阈值：30 分钟（Key 未配置 notify_offline_minutes 或值为 0 时使用） */
    public const DEFAULT_OFFLINE_MINUTES = 30;

    /** 阈值下限：5 分钟（防止配置过小导致邮件轰炸） */
    public const MIN_OFFLINE_MINUTES = 5;

    /** 阈值上限：1440 分钟（24 小时） */
    public const MAX_OFFLINE_MINUTES = 1440;

    /** Worker 0 巡检间隔：5 分钟 */
    public const SCAN_INTERVAL_SECONDS = 300;

    /** 单次巡检上限（避免卡顿） */
    private const SCAN_BATCH_SIZE = 200;

    /** pending 集合 Key */
    private const PENDING_SET_KEY = 'offline_pending';

    /** pending 详情 Hash Key 模板（{deviceId} 替换真实值） */
    private const PENDING_DETAIL_KEY = 'offline_pending:';

    /** 通知冷却期 Key 前缀 */
    private const NOTIFY_LAST_SENT_KEY = 'notify:last_sent:';

    /** 已发送掉线通知标记 Key 前缀（用于重连时补发恢复通知） */
    private const NOTIFY_SENT_FLAG_KEY = 'notify:sent_flag:';

    /**
     * 设备掉线时调用：标记为待通知（入队 Redis），不立即发邮件。
     *
     * @param string $deviceId   设备 ID
     * @param int    $pushKeyId  推送 Key ID
     * @param array  $deviceInfo 设备详情
     * @return void
     */
    public function markPendingOffline(string $deviceId, int $pushKeyId, array $deviceInfo): void
    {
        try {
            $redis = Redis::getInstance();

            $detailKey = self::PENDING_DETAIL_KEY . $deviceId;
            $infoJson = json_encode($deviceInfo, JSON_UNESCAPED_UNICODE);

            $redis->sAdd(self::PENDING_SET_KEY, $deviceId);
            $redis->hSet($detailKey, 'push_key_id', (string)$pushKeyId);
            $redis->hSet($detailKey, 'info_json', $infoJson);
            $redis->hSet($detailKey, 'offline_at', (string)time());
            // 详情保留 24 小时，防止 Set 里残留孤儿设备后详情 key 被清
            $redis->expire($detailKey, 86400);
        } catch (\Throwable $e) {
            error_log("[DeviceOfflineNotifier] markPendingOffline 异常: {$e->getMessage()}");
        }
    }

    /**
     * 清除设备的 pending 记录（设备重连时调用）
     *
     * @param string $deviceId
     * @return void
     */
    public function clearPendingOffline(string $deviceId): void
    {
        try {
            $redis = Redis::getInstance();
            $redis->sRem(self::PENDING_SET_KEY, $deviceId);
            $redis->del(self::PENDING_DETAIL_KEY . $deviceId);
        } catch (\Throwable $e) {
            error_log("[DeviceOfflineNotifier] clearPendingOffline 异常: {$e->getMessage()}");
        }
    }

    /**
     * 巡检 pending 队列，对离线时长达到 **Key 级阈值** 的设备发邮件。
     *
     * 由 WebSocket Server Worker 0 定时器每 5 分钟触发一次。
     * 阈值优先级：push_keys.notify_offline_minutes（5~1440）
     *           → 未配置/为 0 时用 $fallbackMinutes（默认 30）
     *
     * @param int $fallbackMinutes 全局兜底阈值（分钟），Key 未配置时使用
     * @param int $limit           单次巡检上限，默认 200
     * @return int 本次成功发送的通知数
     */
    public function processPendingQueue(int $fallbackMinutes = self::DEFAULT_OFFLINE_MINUTES, int $limit = self::SCAN_BATCH_SIZE): int
    {
        $sent = 0;

        try {
            $redis = Redis::getInstance();
        } catch (\Throwable $e) {
            error_log("[DeviceOfflineNotifier] processPendingQueue Redis 连接失败: {$e->getMessage()}");
            return 0;
        }

        // 1. 取所有 pending 设备（最多 limit 个）
        $pendingDevices = $redis->sMembers(self::PENDING_SET_KEY);
        if (empty($pendingDevices)) {
            return 0;
        }

        // Key 级配置缓存（同一次巡检内多个设备共享同一 Key 时只查一次库）
        $keyConfigCache = [];

        $pendingDevices = array_slice($pendingDevices, 0, $limit);
        $now = time();

        foreach ($pendingDevices as $deviceId) {
            // 2. 检查设备是否已经在线（重连了）
            //    ws:device:{deviceId} 是当前在线 fd 集合，sCard > 0 表示在线
            $onlineFds = (int)$redis->sCard('ws:device:' . $deviceId);
            if ($onlineFds > 0) {
                // 设备已重连，清理 pending，不发通知
                $this->clearPendingOffline($deviceId);
                continue;
            }

            // 3. 读取 pending 详情
            $detailKey = self::PENDING_DETAIL_KEY . $deviceId;
            $detail = $redis->hGetAll($detailKey);
            if (empty($detail)) {
                // 详情丢失（过期了），只从 Set 移除
                $redis->sRem(self::PENDING_SET_KEY, $deviceId);
                continue;
            }

            $offlineAt = (int)($detail['offline_at'] ?? 0);
            $pushKeyId = (int)($detail['push_key_id'] ?? 0);
            $deviceInfo = json_decode($detail['info_json'] ?? '{}', true) ?: [];

            $offlineMinutes = $offlineAt > 0 ? floor(($now - $offlineAt) / 60) : 0;

            // 4. 检查推送 Key 的通知配置（含 Key 级阈值）
            if (!isset($keyConfigCache[$pushKeyId])) {
                $keyConfigCache[$pushKeyId] = $this->getPushKeyNotifyConfig($pushKeyId);
            }
            $keyConfig = $keyConfigCache[$pushKeyId];

            if (!$keyConfig['enabled'] || $keyConfig['email'] === '') {
                // 该 Key 没开通知，直接移出队列
                $this->clearPendingOffline($deviceId);
                continue;
            }

            // 🔴 Key 级阈值：notify_offline_minutes 优先，未配置（0）用全局兜底
            $thresholdMinutes = $this->normalizeThresholdMinutes($keyConfig['offline_minutes'], $fallbackMinutes);

            if ($offlineMinutes < $thresholdMinutes) {
                // 离线时间还不够，等下次巡检
                continue;
            }

            // 5. 检查通知冷却期（避免反复发）
            $canNotify = $this->checkNotifyInterval($deviceId, $keyConfig['interval']);
            if (!$canNotify) {
                // 还在冷却期，保留 pending 等下次
                continue;
            }

            // 6. 发送邮件通知（异步）
            $this->sendNotificationAsync($deviceInfo, $keyConfig['name'], $keyConfig['email']);

            // 7. 记录发送时间 + 已发送标记（供重连时补发恢复通知） + 从 pending 移出
            $this->recordNotifyTime($deviceId);
            $this->recordSentFlag($deviceId, $offlineAt, $keyConfig['email'], $keyConfig['name'], $deviceInfo);
            $this->clearPendingOffline($deviceId);

            $sent++;
            error_log("[DeviceOfflineNotifier] 离线提醒已发送 device_id={$deviceId} offline_minutes={$offlineMinutes} threshold={$thresholdMinutes}");
        }

        return $sent;
    }

    /**
     * 规范化阈值分钟数：非法值（0/超范围）回退到兜底值
     */
    private function normalizeThresholdMinutes(int $configured, int $fallback): int
    {
        if ($configured < self::MIN_OFFLINE_MINUTES || $configured > self::MAX_OFFLINE_MINUTES) {
            $fallback = max(self::MIN_OFFLINE_MINUTES, min(self::MAX_OFFLINE_MINUTES, $fallback));
            return $fallback;
        }
        return $configured;
    }

    /**
     * 记录"已发送掉线通知"标记（重连时用于补发恢复通知）
     */
    private function recordSentFlag(string $deviceId, int $offlineAt, string $email, string $keyName, array $deviceInfo): void
    {
        try {
            $redis = Redis::getInstance();
            $flagKey = self::NOTIFY_SENT_FLAG_KEY . $deviceId;
            $redis->hSet($flagKey, 'sent_at', (string)time());
            $redis->hSet($flagKey, 'offline_at', (string)$offlineAt);
            $redis->hSet($flagKey, 'email', $email);
            $redis->hSet($flagKey, 'key_name', $keyName);
            $redis->hSet($flagKey, 'info_json', json_encode($deviceInfo, JSON_UNESCAPED_UNICODE));
            // 标记保留 24 小时：超过 24h 才重连的设备不再补发恢复通知（意义不大）
            $redis->expire($flagKey, 86400);
        } catch (\Throwable $e) {
            error_log("[DeviceOfflineNotifier] recordSentFlag 异常: {$e->getMessage()}");
        }
    }

    /**
     * 设备重连时调用：清理 pending 记录；若之前发过掉线通知则补发"已恢复"邮件。
     *
     * 替代原 clearPendingOffline() 的调用点（保留原方法向后兼容）。
     *
     * @param string $deviceId 设备 ID
     * @return void
     */
    public function handleReconnect(string $deviceId): void
    {
        // 1. 无论是否发过通知，都要清 pending
        $this->clearPendingOffline($deviceId);

        try {
            $redis = Redis::getInstance();
            $flagKey = self::NOTIFY_SENT_FLAG_KEY . $deviceId;
            $flag = $redis->hGetAll($flagKey);

            if (empty($flag) || empty($flag['email'])) {
                // 之前没发过掉线通知（快速重连场景），无需恢复通知
                return;
            }

            $offlineAt  = (int)($flag['offline_at'] ?? 0);
            $sentAt     = (int)($flag['sent_at'] ?? 0);
            $email      = (string)$flag['email'];
            $keyName    = (string)($flag['key_name'] ?? '未知');
            $deviceInfo = json_decode($flag['info_json'] ?? '{}', true) ?: [];
            $deviceInfo['device_id'] = $deviceInfo['device_id'] ?? $deviceId;

            // 2. 清掉标记（先清后发，防止发送异常导致重复补发）
            $redis->del($flagKey);

            // 3. 异步补发恢复通知（含离线时长）
            $recoveredAt = time();
            \Swoole\Timer::after(100, function () use ($deviceInfo, $keyName, $email, $offlineAt, $sentAt, $recoveredAt) {
                try {
                    MailService::sendRecoveryNotification($deviceInfo, $keyName, $email, $offlineAt, $sentAt, $recoveredAt);
                    error_log(sprintf(
                        '[DeviceOfflineNotifier] 恢复通知已发送 device_id=%s offline_minutes=%d',
                        $deviceInfo['device_id'] ?? '',
                        $offlineAt > 0 ? intdiv($recoveredAt - $offlineAt, 60) : 0
                    ));
                } catch (\Throwable $e) {
                    error_log("[DeviceOfflineNotifier] 发送恢复通知失败: {$e->getMessage()}");
                }
            });
        } catch (\Throwable $e) {
            error_log("[DeviceOfflineNotifier] handleReconnect 异常: {$e->getMessage()}");
        }
    }

    /**
     * 查询 push_keys 的通知配置
     */
    private function getPushKeyNotifyConfig(int $pushKeyId): array
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT notify_email, notify_enabled, notify_interval, notify_offline_minutes, name
                 FROM push_keys WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$pushKeyId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                return [
                    'enabled'        => (bool)$row['notify_enabled'],
                    'email'          => (string)$row['notify_email'],
                    'interval'       => (int)$row['notify_interval'],
                    'offline_minutes'=> (int)($row['notify_offline_minutes'] ?? 0),
                    'name'           => (string)$row['name'],
                ];
            }
        } catch (\Throwable $e) {
            error_log("[DeviceOfflineNotifier] 查询 Key 配置失败: {$e->getMessage()}");
        }

        return ['enabled' => false, 'email' => '', 'interval' => 300, 'offline_minutes' => 0, 'name' => ''];
    }

    /**
     * 检查通知间隔（冷却期）
     */
    private function checkNotifyInterval(string $deviceId, int $interval): bool
    {
        $redis = Redis::getInstance();
        $key = self::NOTIFY_LAST_SENT_KEY . $deviceId;
        $lastSent = (int)$redis->get($key);

        if ($lastSent > 0 && (time() - $lastSent) < $interval) {
            return false;
        }

        return true;
    }

    /**
     * 记录通知发送时间
     */
    private function recordNotifyTime(string $deviceId): void
    {
        $redis = Redis::getInstance();
        $key = self::NOTIFY_LAST_SENT_KEY . $deviceId;
        // 冷却期记录保留 24 小时
        $redis->setex($key, 86400, (string)time());
    }

    /**
     * 异步发送邮件通知
     */
    private function sendNotificationAsync(array $deviceInfo, string $keyName, string $email): void
    {
        \Swoole\Timer::after(100, function () use ($deviceInfo, $keyName, $email) {
            try {
                MailService::sendOfflineNotification($deviceInfo, $keyName, $email);
            } catch (\Throwable $e) {
                error_log("[DeviceOfflineNotifier] 发送通知失败: {$e->getMessage()}");
            }
        });
    }

    /**
     * 手动触发设备离线通知（保留向后兼容，用于测试）
     */
    public function triggerNotify(string $deviceId, int $pushKeyId, array $deviceInfo): bool
    {
        $keyConfig = $this->getPushKeyNotifyConfig($pushKeyId);
        if (!$keyConfig['enabled'] || $keyConfig['email'] === '') {
            return false;
        }

        $keyName = $keyConfig['name'] ?? '未知';
        return MailService::sendOfflineNotification($deviceInfo, $keyName, $keyConfig['email']);
    }

    /**
     * 标记旧方法为 deprecated（向后兼容，立即发邮件）
     */
    public function notify(string $deviceId, int $pushKeyId, array $deviceInfo): bool
    {
        // 改为延迟模式：只入队，不立即发
        $this->markPendingOffline($deviceId, $pushKeyId, $deviceInfo);
        return true;
    }
}
