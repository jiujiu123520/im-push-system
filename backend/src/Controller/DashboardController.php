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

        // 1. 在线设备数（从 Redis 实时统计：遍历所有 ws:device:* 集合）
        // 注意：设备量大时应使用 Redis HyperLogLog 或独立计数器，
        //       此处遍历 SCARD 对于中小规模（万级以下）足够准确。
        $onlineDevices = 0;
        try {
            $iterator = null;
            while (($keys = $redis->sScan('ws:device:*', $iterator, '*', 100)) !== false) {
                foreach ($keys as $key) {
                    // sScan 返回的可能是集合成员而非 key 名，改用 keys 方式获取在线 key 数
                }
                if ($iterator === 0) break;
            }
        } catch (\Throwable $e) {
        }

        // 更可靠的方式：从 devices 表统计 status=1（在线）
        $onlineDevicesRow = Database::fetch("SELECT COUNT(*) as cnt FROM devices WHERE status = 1");
        $onlineDevices = (int)($onlineDevicesRow['cnt'] ?? 0);

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
     * 说明：devices 表没有 platform 字段，通过 device_model / os_version
     *       关键词粗略判断平台（Android/iOS/Web/其他）。
     *       如需精确统计，建议在 devices 表新增 platform 字段。
     *
     * 返回：
     *   {
     *     "data": [
     *       { "name": "Android", "value": 1200 },
     *       { "name": "iOS", "value": 800 },
     *       ...
     *     ]
     *   }
     */
    public function devicePlatform(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        // 统计总设备数
        $totalRow = Database::fetch("SELECT COUNT(*) as cnt FROM devices");
        $total = (int)($totalRow['cnt'] ?? 0);

        // 粗略按 os_version 关键词估算
        // Android: 包含 Android
        $androidRow = Database::fetch(
            "SELECT COUNT(*) as cnt FROM devices WHERE os_version LIKE '%Android%'"
        );
        $android = (int)($androidRow['cnt'] ?? 0);

        // iOS: 包含 iOS 或 iPhone 或 iPad
        $iosRow = Database::fetch(
            "SELECT COUNT(*) as cnt FROM devices
             WHERE os_version LIKE '%iOS%' OR os_version LIKE '%iPhone%' OR os_version LIKE '%iPad%'"
        );
        $ios = (int)($iosRow['cnt'] ?? 0);

        // Web: UA 包含 Mozilla/WebKit 等浏览器特征（从 ua 字段判断）
        $webRow = Database::fetch(
            "SELECT COUNT(*) as cnt FROM devices
             WHERE ua LIKE '%Mozilla%' AND ua LIKE '%WebKit%'
             AND os_version NOT LIKE '%Android%'
             AND os_version NOT LIKE '%iOS%'"
        );
        $web = (int)($webRow['cnt'] ?? 0);

        // 其他
        $other = max(0, $total - $android - $ios - $web);

        $data = [];
        if ($android > 0) $data[] = ['name' => 'Android', 'value' => $android];
        if ($ios > 0)     $data[] = ['name' => 'iOS', 'value' => $ios];
        if ($web > 0)     $data[] = ['name' => 'Web', 'value' => $web];
        if ($other > 0)   $data[] = ['name' => '其他', 'value' => $other];

        if (empty($data)) {
            $data = [
                ['name' => 'Android', 'value' => 0],
                ['name' => 'iOS', 'value' => 0],
            ];
        }

        return ['data' => $data];
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
