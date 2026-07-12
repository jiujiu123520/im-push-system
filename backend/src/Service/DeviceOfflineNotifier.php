<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 设备掉线通知服务
 *
 * 负责：
 *   - 检测设备离线事件
 *   - 检查通知配置（是否启用、通知间隔、收件邮箱）
 *   - 发送邮件通知（避免频繁通知）
 *
 * 通知间隔通过 Redis 实现：同一设备在间隔时间内只发一次通知。
 */
class DeviceOfflineNotifier
{
    /** 通知间隔键前缀（Redis） */
    private const NOTIFY_LAST_SENT_KEY = 'notify:last_sent:';

    /**
     * 处理设备离线事件
     *
     * @param string $deviceId   设备 ID
     * @param int    $pushKeyId  推送 Key ID
     * @param array  $deviceInfo 设备详情（ip, device_name, device_model, os_version）
     * @return bool 是否成功发送通知
     */
    public function notify(string $deviceId, int $pushKeyId, array $deviceInfo): bool
    {
        // 1. 查询 Key 的通知配置
        $keyConfig = $this->getPushKeyNotifyConfig($pushKeyId);
        if (!$keyConfig['enabled'] || $keyConfig['email'] === '') {
            return false;
        }

        // 2. 检查通知间隔（避免频繁通知）
        $canNotify = $this->checkNotifyInterval($deviceId, $keyConfig['interval']);
        if (!$canNotify) {
            return false;
        }

        // 3. 查询 Key 名称
        $keyName = $keyConfig['name'] ?? '未知';

        // 4. 发送邮件通知（异步执行，不阻塞主流程）
        $this->sendNotificationAsync($deviceInfo, $keyName, $keyConfig['email']);

        // 5. 记录通知时间
        $this->recordNotifyTime($deviceId);

        return true;
    }

    /**
     * 查询 push_keys 的通知配置
     *
     * @param int $pushKeyId
     * @return array
     */
    private function getPushKeyNotifyConfig(int $pushKeyId): array
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT notify_email, notify_enabled, notify_interval, name
                 FROM push_keys WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$pushKeyId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                return [
                    'enabled'   => (bool)$row['notify_enabled'],
                    'email'     => (string)$row['notify_email'],
                    'interval'  => (int)$row['notify_interval'],
                    'name'      => (string)$row['name'],
                ];
            }
        } catch (\Throwable $e) {
            error_log("[DeviceOfflineNotifier] 查询 Key 配置失败: {$e->getMessage()}");
        }

        return ['enabled' => false, 'email' => '', 'interval' => 300, 'name' => ''];
    }

    /**
     * 检查通知间隔
     *
     * @param string $deviceId 设备 ID
     * @param int    $interval 间隔（秒）
     * @return bool 是否可以发送通知
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
     * 记录通知时间
     *
     * @param string $deviceId 设备 ID
     */
    private function recordNotifyTime(string $deviceId): void
    {
        $redis = Redis::getInstance();
        $key = self::NOTIFY_LAST_SENT_KEY . $deviceId;
        $redis->set($key, (string)time());
    }

    /**
     * 异步发送通知（不阻塞主流程）
     *
     * @param array  $deviceInfo 设备信息
     * @param string $keyName    Key 名称
     * @param string $email      收件人邮箱
     */
    private function sendNotificationAsync(array $deviceInfo, string $keyName, string $email): void
    {
        // 使用 Swoole 定时器在当前事件循环中异步执行，避免阻塞
        \Swoole\Timer::after(100, function () use ($deviceInfo, $keyName, $email) {
            try {
                MailService::sendOfflineNotification($deviceInfo, $keyName, $email);
            } catch (\Throwable $e) {
                error_log("[DeviceOfflineNotifier] 发送通知失败: {$e->getMessage()}");
            }
        });
    }

    /**
     * 检查指定设备是否在通知冷却期内
     *
     * @param string $deviceId
     * @param int    $interval
     * @return bool
     */
    public function isInCooldown(string $deviceId, int $interval): bool
    {
        return !$this->checkNotifyInterval($deviceId, $interval);
    }

    /**
     * 手动触发设备离线通知（用于测试）
     *
     * @param string $deviceId
     * @param int    $pushKeyId
     * @param array  $deviceInfo
     * @return bool
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
}
