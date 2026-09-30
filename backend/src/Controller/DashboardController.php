<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Database;
use App\Service\Redis;
use App\Service\Response;

/**
 * 仪表盘控制器
 *
 * 提供管理后台首页所需的聚合统计数据：
 *   - 概览卡片（在线设备、今日推送、Key 数、用户数）
 *   - 在线设备趋势（近7天/30天）
 *   - 今日推送量（按小时）
 *   - Key 状态分布
 *   - 设备平台分布
 *   - 最新推送记录
 *
 * 路由前缀：/admin/dashboard
 */
class DashboardController
{
    /**
     * GET /admin/dashboard/overview
     * 获取概览统计数据（数据卡片）
     *
     * 返回：
     *   {
     *     "online_devices": int,      // 当前在线设备数（Redis 实时）
     *     "today_push": int,          // 今日推送总数
     *     "yesterday_push": int,      // 昨日推送总数（用于计算趋势）
     *     "active_keys": int,         // 活跃 Key 数（status=1）
     *     "total_keys": int,          // Key 总数
     *     "total_users": int,         // 注册用户总数
     *     "today_new_users": int,     // 今日新增用户
     *     "today_new_devices": int    // 今日新增设备
     *   }
     */
    public function overview(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $redis = Redis::getInstance();

        // 1. 在线设备数（从 Redis 实时统计）
        // 注意：device:key 是"订阅关系"（设备离线时不清理，用于存离线消息），
        // 不能用 hLen('device:key') 统计在线设备数，否则会把历史离线设备都算上。
        // 正确方式：遍历 ws:fd:device（fd → device_id 映射），对 device_id 去重计数；
        // 同时把在线连接数（fd 数）一并返回给前端展示。
        $onlineDevices = 0;
        $onlineConnections = 0;
        try {
            $fdToDevice = $redis->hGetAll('ws:fd:device');
            if (is_array($fdToDevice)) {
                $onlineConnections = count($fdToDevice);
                $uniqueDevices = array_values(array_unique(array_map('strval', $fdToDevice)));
                $onlineDevices = count($uniqueDevices);
            }
        } catch (\Throwable $e) {
            // Redis 不可用时降级到数据库查询（仅做近似估算，因为 status 不实时）
            $onlineDevicesRow = Database::fetch("SELECT COUNT(*) as cnt FROM devices WHERE status = 1");
            $onlineDevices = (int)($onlineDevicesRow['cnt'] ?? 0);
        }

        // 2. 今日推送量 & 昨日推送量
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $todayPushRow = Database::fetch(
            "SELECT COALESCE(SUM(success_count + fail_count), 0) as cnt
             FROM push_logs WHERE DATE(created_at) = ?",
            [$today]
        );
        $todayPush = (int)($todayPushRow['cnt'] ?? 0);

        $yesterdayPushRow = Database::fetch(
            "SELECT COALESCE(SUM(success_count + fail_count), 0) as cnt
             FROM push_logs WHERE DATE(created_at) = ?",
            [$yesterday]
        );
        $yesterdayPush = (int)($yesterdayPushRow['cnt'] ?? 0);

        // 3. Key 统计
        $keyStatsRow = Database::fetch(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active
             FROM push_keys"
        );
        $totalKeys = (int)($keyStatsRow['total'] ?? 0);
        $activeKeys = (int)($keyStatsRow['active'] ?? 0);

        // 4. 用户统计
        $userStatsRow = Database::fetch(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as today_new
             FROM users",
            [$today]
        );
        $totalUsers = (int)($userStatsRow['total'] ?? 0);
        $todayNewUsers = (int)($userStatsRow['today_new'] ?? 0);

        // 5. 今日新增设备
        $todayNewDevicesRow = Database::fetch(
            "SELECT COUNT(*) as cnt FROM devices WHERE DATE(created_at) = ?",
            [$today]
        );
        $todayNewDevices = (int)($todayNewDevicesRow['cnt'] ?? 0);

        return [
            'online_devices'    => $onlineDevices,
            'online_connections'=> $onlineConnections,
            'today_push'        => $todayPush,
            'yesterday_push'    => $yesterdayPush,
            'active_keys'       => $activeKeys,
            'total_keys'        => $totalKeys,
            'total_users'       => $totalUsers,
            'today_new_users'   => $todayNewUsers,
            'today_new_devices' => $todayNewDevices,
        ];
    }

    /**
     * GET /admin/dashboard/online-trend?days=7
     * 在线设备趋势（按天）
     *
     * 返回：
     *   {
     *     "dates": ["2026-07-07", ...],
     *     "values": [1200, 1350, ...]
     *   }
     *
     * 注意：当前没有历史在线快照表，使用 devices 表的 last_connect_at
     *       近似估算每日在线峰值（当天至少连接过的设备数）。
     *       如需精确趋势，应新增 device_online_daily 快照表。
     */
    public function onlineTrend(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $days = (int)($context['get']['days'] ?? 7);
        if ($days < 1) $days = 7;
        if ($days > 90) $days = 90;

        $dates = [];
        $values = [];

        // 使用 last_connect_at 近似：每天有多少设备至少连接过一次
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i day"));
            $dates[] = $date;

            $row = Database::fetch(
                "SELECT COUNT(DISTINCT device_id) as cnt
                 FROM devices
                 WHERE DATE(last_connect_at) = ?",
                [$date]
            );
            $values[] = (int)($row['cnt'] ?? 0);
        }

        return [
            'dates'  => $dates,
            'values' => $values,
        ];
    }

    /**
     * GET /admin/dashboard/today-push
     * 今日推送量（按小时）
     *
     * 返回：
     *   {
     *     "hours": ["00:00", "01:00", ..., "23:00"],
     *     "values": [120, 80, ...]
     *   }
     */
    public function todayPush(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $today = date('Y-m-d');

        // 按小时统计
        $rows = Database::fetchAll(
            "SELECT HOUR(created_at) as hour,
                    COALESCE(SUM(success_count + fail_count), 0) as cnt
             FROM push_logs
             WHERE DATE(created_at) = ?
             GROUP BY HOUR(created_at)
             ORDER BY hour",
            [$today]
        );

        $hourMap = [];
        foreach ($rows as $row) {
            $hourMap[(int)$row['hour']] = (int)$row['cnt'];
        }

        $hours = [];
        $values = [];
        for ($i = 0; $i < 24; $i++) {
            $hours[] = sprintf('%02d:00', $i);
            $values[] = $hourMap[$i] ?? 0;
        }

        return [
            'hours'  => $hours,
            'values' => $values,
        ];
    }

    /**
     * GET /admin/dashboard/key-distribution
     * Key 状态分布
     *
     * 返回：
     *   {
     *     "data": [
     *       { "name": "活跃", "value": 120 },
     *       { "name": "禁用", "value": 12 },
     *       ...
     *     ]
     *   }
     */
    public function keyDistribution(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $rows = Database::fetchAll(
            "SELECT status, COUNT(*) as cnt FROM push_keys GROUP BY status"
        );

        $statusMap = [
            1 => '活跃',
            0 => '已禁用',
        ];

        $data = [];
        $active = 0;
        $disabled = 0;

        foreach ($rows as $row) {
            $status = (int)$row['status'];
            $cnt = (int)$row['cnt'];
            if ($status === 1) {
                $active = $cnt;
            } else {
                $disabled += $cnt;
            }
        }

        if ($active > 0) {
            $data[] = ['name' => '活跃', 'value' => $active];
        }
        if ($disabled > 0) {
            $data[] = ['name' => '已禁用', 'value' => $disabled];
        }

        // 确保至少有数据
        if (empty($data)) {
            $data = [
                ['name' => '活跃', 'value' => 0],
                ['name' => '已禁用', 'value' => 0],
            ];
        }

        return ['data' => $data];
    }

    /**
     * GET /admin/dashboard/device-platform
     * 设备平台分布
     *
     * 数据来源 = 原生 App 设备（devices 表）+ PWA / Web Push 设备（web_push_subscriptions 表）。
     * PWA 设备不写 devices 表，只统计 devices 会漏掉 iPhone / iPad 这类 Web Push 设备，
     * 导致仪表盘上永远看不到 iOS。同一 device_id 同时存在于两张表时以 devices 表为准，
     * 避免重复计数。
     *
     * 平台优先取 platform 列（android/ios/web/harmony/edge/chrome），
     * 存量设备该列为空时退回 os_version / ua 关键词粗略判断。
     *
     * 返回：
     *   {
     *     "data": [
     *       { "name": "Android", "value": 1 },
     *       { "name": "iOS", "value": 2 }
     *     ]
     *   }
     */
    public function devicePlatform(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        // 1) 按 platform 列分组（NOT EXISTS 保证同一 device_id 不重复计数）
        $rows = Database::fetchAll(
            "SELECT t.platform AS platform, COUNT(*) AS cnt FROM (
                 SELECT COALESCE(d.platform, '') AS platform
                 FROM devices d
                 UNION ALL
                 SELECT COALESCE(w.platform, '') AS platform
                 FROM web_push_subscriptions w
                 WHERE NOT EXISTS (SELECT 1 FROM devices d2 WHERE d2.device_id = w.device_id)
             ) t
             WHERE t.platform <> ''
             GROUP BY t.platform"
        );

        $counts = [];
        foreach ($rows as $row) {
            $name = self::platformDisplayName((string)$row['platform']);
            $counts[$name] = ($counts[$name] ?? 0) + (int)$row['cnt'];
        }

        // 2) 存量设备 platform 为空时，用 os_version / ua 关键词兜底判断
        //    （platform 列由 migration 012 引入，此前的老设备该列为空）
        $legacyRows = Database::fetchAll(
            "SELECT t.os_version AS os_version, t.ua AS ua FROM (
                 SELECT COALESCE(d.platform, '') AS platform,
                        COALESCE(d.os_version, '') AS os_version,
                        COALESCE(d.ua, '') AS ua
                 FROM devices d
                 UNION ALL
                 SELECT COALESCE(w.platform, '') AS platform,
                        COALESCE(w.os_version, '') AS os_version,
                        COALESCE(w.user_agent, '') AS ua
                 FROM web_push_subscriptions w
                 WHERE NOT EXISTS (SELECT 1 FROM devices d2 WHERE d2.device_id = w.device_id)
             ) t
             WHERE t.platform = ''"
        );

        foreach ($legacyRows as $row) {
            $name = self::platformNameFromHint((string)$row['os_version'] . ' ' . (string)$row['ua']);
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        // 3) 按数量降序输出（不再硬编码兜底项：没有数据就返回空，避免凭空出现 iOS 图例）
        $data = [];
        foreach ($counts as $name => $value) {
            if ($value > 0) {
                $data[] = ['name' => $name, 'value' => $value];
            }
        }
        usort($data, fn($a, $b) => $b['value'] <=> $a['value']);

        return ['data' => $data];
    }

    /**
     * platform 列取值 → 展示名
     *
     * @param string $platform devices.platform / web_push_subscriptions.platform
     * @return string
     */
    private static function platformDisplayName(string $platform): string
    {
        $map = [
            'android'  => 'Android',
            'ios'      => 'iOS',
            'harmony'  => 'HarmonyOS',
            'harmonyos'=> 'HarmonyOS',
            'web'      => 'Web',
            'edge'      => 'Edge',
            'chrome'   => 'Chrome',
        ];
        $key = strtolower(trim($platform));
        return $map[$key] ?? '其他';
    }

    /**
     * platform 列为空时的兜底判断：用 os_version / ua 关键词粗略识别平台
     *
     * @param string $hint os_version + ua 拼接串
     * @return string
     */
    private static function platformNameFromHint(string $hint): string
    {
        $h = strtolower($hint);
        if ($h === ' ' || trim($h) === '') {
            return '其他';
        }
        if (str_contains($h, 'ios') || str_contains($h, 'iphone') || str_contains($h, 'ipad')) {
            return 'iOS';
        }
        if (str_contains($h, 'android')) {
            return 'Android';
        }
        if (str_contains($h, 'harmony') || str_contains($h, 'openharmony')) {
            return 'HarmonyOS';
        }
        if (str_contains($h, 'mozilla') || str_contains($h, 'webkit')) {
            return 'Web';
        }
        return '其他';
    }

    /**
     * GET /admin/dashboard/recent-push?limit=5
     * 最新推送记录
     *
     * 返回：
     *   {
     *     "list": [
     *       {
     *         "id": 1,
     *         "title": "消息标题",
     *         "target_type": "key",
     *         "target_value": "xxx",
     *         "success_count": 100,
     *         "fail_count": 2,
     *         "created_at": "2026-07-12 14:23:08"
     *       },
     *       ...
     *     ]
     *   }
     */
    public function recentPush(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $limit = (int)($context['get']['limit'] ?? 5);
        if ($limit < 1) $limit = 5;
        if ($limit > 50) $limit = 50;

        $list = Database::fetchAll(
            "SELECT id, title, target_type, target_value,
                    success_count, fail_count, created_at
             FROM push_logs
             ORDER BY id DESC
             LIMIT " . $limit
        );

        return ['list' => $list];
    }
}
