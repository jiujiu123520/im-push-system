<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\ConnectionManager;
use App\Service\Database;
use App\Service\Redis;
use App\Service\Response;

/**
 * 仪表盘控制器
 *
 * 提供管理后台首页所需的聚合统计数据：
 *   - 概览卡片（在线设备、今日推送、Key 数、用户数）
 *   - 在线设备列表（实时，按 device_id 聚合）
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
     *     "online_devices": int,          // 在线设备总数 = online_ws_devices + online_webpush_devices
     *     "online_ws_devices": int,       // 其中：WebSocket 实时在线设备（已按 device_id 去重）
     *     "online_webpush_devices": int,  // 其中：Web Push 有效订阅设备（PWA，不含已有实时连接的）
     *     "online_connections": int,      // 在线连接（fd）数，仅 WebSocket 计入
     *     "today_push": int,              // 今日推送总数
     *     "yesterday_push": int,          // 昨日推送总数（用于计算趋势）
     *     "active_keys": int,             // 活跃 Key 数（status=1）
     *     "total_keys": int,              // Key 总数
     *     "total_users": int,             // 注册用户总数
     *     "today_new_users": int,         // 今日新增用户
     *     "today_new_devices": int        // 今日新增设备
     *   }
     */
    public function overview(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $redis = Redis::getInstance();

        // 1. 在线设备数
        // 注意：device:key 是"订阅关系"（设备离线时不清理，用于存离线消息），
        // 不能用 hLen('device:key') 统计在线设备数，否则会把历史离线设备都算上。
        // 正确方式：遍历 ws:fd:device（fd → device_id 映射）对 device_id 去重，
        // 再并入 web_push_subscriptions 中 status = 1 的 PWA 设备
        // （PWA 不走 WebSocket，不并入会导致卡片数比「在线设备列表」少）。
        $wsDeviceIds = [];
        $onlineConnections = 0;
        try {
            $fdToDevice = $redis->hGetAll('ws:fd:device');
            if (is_array($fdToDevice)) {
                $onlineConnections = count($fdToDevice);
                foreach ($fdToDevice as $deviceId) {
                    $deviceId = trim((string)$deviceId);
                    if ($deviceId !== '') {
                        $wsDeviceIds[$deviceId] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Redis 不可用时降级到数据库查询（仅做近似估算，因为 status 不实时）
            try {
                foreach (Database::fetchAll('SELECT device_id FROM devices WHERE status = 1') as $row) {
                    $deviceId = trim((string)$row['device_id']);
                    if ($deviceId !== '') {
                        $wsDeviceIds[$deviceId] = true;
                    }
                }
            } catch (\Throwable $e2) {
                // 保持为空，不阻断其余统计
            }
        }

        $onlineWsDevices = count($wsDeviceIds);

        // Web Push（PWA）设备：订阅仍有效即视为可达；已建立实时连接的不重复计数
        $onlineWebPushDevices = 0;
        try {
            foreach (Database::fetchAll('SELECT device_id FROM web_push_subscriptions WHERE status = 1') as $row) {
                $deviceId = trim((string)$row['device_id']);
                if ($deviceId !== '' && !isset($wsDeviceIds[$deviceId])) {
                    $onlineWebPushDevices++;
                }
            }
        } catch (\Throwable $e) {
            $onlineWebPushDevices = 0;
        }

        $onlineDevices = $onlineWsDevices + $onlineWebPushDevices;

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
            'online_devices'         => $onlineDevices,
            'online_ws_devices'      => $onlineWsDevices,
            'online_webpush_devices' => $onlineWebPushDevices,
            'online_connections'     => $onlineConnections,
            'today_push'             => $todayPush,
            'yesterday_push'         => $yesterdayPush,
            'active_keys'            => $activeKeys,
            'total_keys'             => $totalKeys,
            'total_users'            => $totalUsers,
            'today_new_users'        => $todayNewUsers,
            'today_new_devices'      => $todayNewDevices,
        ];
    }

    /**
     * GET /admin/dashboard/online-devices
     * 在线设备列表（实时）
     *
     * 数据来源为 Redis 连接表（ws:online + ws:conn:*），按 device_id 聚合
     * —— 同一台设备可能存在多条 WebSocket 连接（多实例 / 重连残留），
     * 聚合后 connections 即为该设备当前连接数。
     *
     * 原始连接记录里只有 device_id / key_value / IP，展示所需的平台、型号、
     * 系统版本需回查 devices（原生 App）或 web_push_subscriptions（PWA），
     * 同一 device_id 同时存在时以 devices 为准。
     *
     * 另外合并 Web Push（PWA）设备：iOS Safari 主屏幕这类 PWA 不维持 WebSocket
     * 长连接，Redis 里没有 fd，只看连接表会永远看不到它们。判定依据为订阅仍有效
     * （web_push_subscriptions.status = 1），channel 标记为 webpush 以示区分。
     *
     * 返回：
     *   {
     *     "list": [
     *       {
     *         "device_id": "app-71e82d8d244f",
     *         "key_value": "sQhrg...",
     *         "key_name": "默认 Key",
     *         "platform": "Android",
     *         "device_name": "小米手机",
     *         "device_model": "2311DRK48C",
     *         "os_version": "Android 16",
     *         "app_version": "1.0.0",
     *         "channel": "ws",              // ws=WebSocket 实时在线；webpush=Web Push 订阅有效
     *         "connections": 1,
     *         "connect_at": 1790764258,
     *         "last_active": 1790764258,
     *         "idle_seconds": 3,
     *         "ip": "1.2.3.4"
     *       }
     *     ],
     *     "total": 1
     *   }
     */
    public function onlineDevices(array $context, array $params)
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        try {
            $connections = (new ConnectionManager())->getAllConnections();
        } catch (\Throwable $e) {
            // Redis 不可用时降级为空列表，不影响仪表盘其余卡片
            $connections = [];
        }

        // 1) 按 device_id 聚合
        $grouped = [];
        foreach ($connections as $conn) {
            $deviceId = trim((string)($conn['device_id'] ?? ''));
            if ($deviceId === '') {
                continue;
            }
            if (!isset($grouped[$deviceId])) {
                $grouped[$deviceId] = [
                    'device_id'    => $deviceId,
                    'key_value'    => '',
                    'connections'  => 0,
                    'connect_at'   => 0,
                    'last_active'  => 0,
                    'idle_seconds' => 0,
                    'ip'           => '',
                ];
            }

            $item = &$grouped[$deviceId];
            $item['connections']++;

            if ($item['key_value'] === '' && (string)($conn['key_value'] ?? '') !== '') {
                $item['key_value'] = (string)$conn['key_value'];
            }

            // connect_at 取最早（该设备最早建立连接的时间）
            $connectAt = (int)($conn['connect_at'] ?? 0);
            if ($connectAt > 0 && ($item['connect_at'] === 0 || $connectAt < $item['connect_at'])) {
                $item['connect_at'] = $connectAt;
            }

            // last_active 取最新，IP 跟随最近活跃的那条连接
            $lastActive = (int)($conn['last_active'] ?? 0);
            if ($lastActive >= $item['last_active']) {
                $item['last_active']  = $lastActive;
                $item['idle_seconds'] = (int)($conn['idle_seconds'] ?? 0);
                $item['ip']           = (string)($conn['ip'] ?? '');
            }
            unset($item);
        }

        // 注意：这里不能因为 WebSocket 连接为空就提前返回 ——
        // Web Push（PWA）设备不走 WebSocket，下面还要把它们合并进来。
        $deviceIds = array_keys($grouped);

        // 2) 补齐设备元信息（devices 优先，PWA 设备回退到 web_push_subscriptions）
        $metaMap = [];
        if (!empty($deviceIds)) {
            try {
                $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
                $metaRows = Database::fetchAll(
                    "SELECT t.device_id AS device_id, t.platform AS platform,
                            t.device_name AS device_name, t.device_model AS device_model,
                            t.os_version AS os_version, t.app_version AS app_version
                     FROM (
                         SELECT d.device_id,
                                COALESCE(d.platform, '') AS platform,
                                COALESCE(d.device_name, '') AS device_name,
                                COALESCE(d.device_model, '') AS device_model,
                                COALESCE(d.os_version, '') AS os_version,
                                COALESCE(d.app_version, '') AS app_version
                         FROM devices d
                         WHERE d.device_id IN ($placeholders)
                         UNION ALL
                         SELECT w.device_id,
                                COALESCE(w.platform, '') AS platform,
                                COALESCE(w.device_name, '') AS device_name,
                                COALESCE(w.device_model, '') AS device_model,
                                COALESCE(w.os_version, '') AS os_version,
                                COALESCE(w.app_version, '') AS app_version
                         FROM web_push_subscriptions w
                         WHERE w.device_id IN ($placeholders)
                           AND NOT EXISTS (SELECT 1 FROM devices d2 WHERE d2.device_id = w.device_id)
                     ) t",
                    array_merge($deviceIds, $deviceIds)
                );
                foreach ($metaRows as $row) {
                    $did = (string)$row['device_id'];
                    if (!isset($metaMap[$did])) {
                        $metaMap[$did] = $row;
                    }
                }
            } catch (\Throwable $e) {
                $metaMap = [];
            }
        }

        // 3) Key 映射（Key 数量少，直接全量取）：按 key_value（小写）与 id 两种索引
        $keyNames = [];
        $keyById  = [];
        try {
            foreach (Database::fetchAll('SELECT id, key_value, name FROM push_keys') as $row) {
                $kv = (string)$row['key_value'];
                $keyNames[strtolower($kv)] = (string)$row['name'];
                $keyById[(int)$row['id']] = ['key_value' => $kv, 'name' => (string)$row['name']];
            }
        } catch (\Throwable $e) {
            $keyNames = [];
            $keyById  = [];
        }

        // 4) 组装输出（WebSocket 在线设备）
        $list = [];
        foreach ($grouped as $deviceId => $item) {
            $meta = $metaMap[$deviceId] ?? [];
            $keyValue = (string)$item['key_value'];

            $list[] = [
                'device_id'    => $deviceId,
                'key_value'    => $keyValue,
                'key_name'     => $keyNames[strtolower($keyValue)] ?? '',
                'platform'     => self::platformDisplayName((string)($meta['platform'] ?? '')),
                'device_name'  => (string)($meta['device_name'] ?? ''),
                'device_model' => (string)($meta['device_model'] ?? ''),
                'os_version'   => (string)($meta['os_version'] ?? ''),
                'app_version'  => (string)($meta['app_version'] ?? ''),
                'channel'      => 'ws',
                'connections'  => (int)$item['connections'],
                'connect_at'   => (int)$item['connect_at'],
                'last_active'  => (int)$item['last_active'],
                'idle_seconds' => (int)$item['idle_seconds'],
                'ip'           => (string)$item['ip'],
            ];
        }

        // 5) 合并 Web Push（PWA）设备
        //    iOS Safari 主屏幕等 PWA 设备不维持 WebSocket 长连接，Redis 里没有 fd，
        //    因此只统计 WebSocket 会导致「在线设备」里永远看不到它们。
        //    这类设备"在线"的判定依据是订阅仍有效（status = 1）——PWA 每次打开都会
        //    调用心跳接口 /api/web-push/subscribe 刷新 last_active_at。
        try {
            $webPushRows = Database::fetchAll(
                "SELECT device_id, push_key_id, platform, device_name, device_model,
                        os_version, app_version, ip,
                        UNIX_TIMESTAMP(last_active_at) AS last_active_ts
                 FROM web_push_subscriptions
                 WHERE status = 1"
            );
        } catch (\Throwable $e) {
            $webPushRows = [];
        }

        $now = time();
        foreach ($webPushRows as $row) {
            $deviceId = trim((string)$row['device_id']);
            // 已建立 WebSocket 连接的设备不重复列出（同一设备以实时连接为准）
            if ($deviceId === '' || isset($grouped[$deviceId])) {
                continue;
            }

            $keyInfo    = $keyById[(int)($row['push_key_id'] ?? 0)] ?? [];
            $lastActive = (int)($row['last_active_ts'] ?? 0);

            $list[] = [
                'device_id'    => $deviceId,
                'key_value'    => (string)($keyInfo['key_value'] ?? ''),
                'key_name'     => (string)($keyInfo['name'] ?? ''),
                'platform'     => self::platformDisplayName((string)($row['platform'] ?? '')),
                'device_name'  => (string)($row['device_name'] ?? ''),
                'device_model' => (string)($row['device_model'] ?? ''),
                'os_version'   => (string)($row['os_version'] ?? ''),
                'app_version'  => (string)($row['app_version'] ?? ''),
                'channel'      => 'webpush',
                'connections'  => 0,
                'connect_at'   => 0,
                'last_active'  => $lastActive,
                'idle_seconds' => $lastActive > 0 ? max(0, $now - $lastActive) : -1,
                'ip'           => (string)($row['ip'] ?? ''),
            ];
        }

        // WebSocket 实时连接排前面，其后按最后活跃倒序
        usort($list, function ($a, $b) {
            if ($a['channel'] !== $b['channel']) {
                return $a['channel'] === 'ws' ? -1 : 1;
            }
            return $b['last_active'] <=> $a['last_active'];
        });

        return ['list' => $list, 'total' => count($list)];
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
