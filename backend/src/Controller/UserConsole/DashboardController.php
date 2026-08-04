<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Service\Database;
use App\Service\Redis;

/**
 * 用户端仪表盘
 *
 * 路由前缀：/user-api/dashboard
 */
class DashboardController extends BaseUserController
{
    public function overview(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        // 今日/昨日推送量（push_logs 通过 api_keys.user_id 或 detail.user_id 关联）
        $todayPush = (int)(Database::fetch(
            "SELECT COALESCE(SUM(pl.success_count + pl.fail_count), 0) cnt
             FROM push_logs pl
             LEFT JOIN api_keys ak ON ak.id = pl.api_key_id
             WHERE DATE(pl.created_at) = ? AND (ak.user_id = ? OR JSON_EXTRACT(COALESCE(pl.detail,'{}'), '$.user_id') = ?)",
            [$today, $userId, $userId]
        )['cnt'] ?? 0);

        $yesterdayPush = (int)(Database::fetch(
            "SELECT COALESCE(SUM(pl.success_count + pl.fail_count), 0) cnt
             FROM push_logs pl
             LEFT JOIN api_keys ak ON ak.id = pl.api_key_id
             WHERE DATE(pl.created_at) = ? AND (ak.user_id = ? OR JSON_EXTRACT(COALESCE(pl.detail,'{}'), '$.user_id') = ?)",
            [$yesterday, $userId, $userId]
        )['cnt'] ?? 0);

        // 自己的 Key 数（push_keys = 用户自己的推送Key）
        $totalKeys = (int)(Database::fetch(
            'SELECT COUNT(*) cnt FROM push_keys WHERE user_id = ?', [$userId]
        )['cnt'] ?? 0);
        $activeKeys = (int)(Database::fetch(
            'SELECT COUNT(*) cnt FROM push_keys WHERE user_id = ? AND status = 1', [$userId]
        )['cnt'] ?? 0);

        // 自己的设备数（devices.user_id = 自己，或 devices.push_key_id IN 自己的 push_keys.id）
        $totalDevices = (int)(Database::fetch(
            "SELECT COUNT(DISTINCT d.device_id) cnt FROM devices d
             LEFT JOIN push_keys pk ON pk.id = d.push_key_id
             WHERE d.user_id = ? OR pk.user_id = ?",
            [$userId, $userId]
        )['cnt'] ?? 0);

        $onlineDevices = 0;
        try {
            $redis = Redis::getInstance();
            $rows = Database::fetchAll(
                "SELECT DISTINCT d.device_id did FROM devices d
                 LEFT JOIN push_keys pk ON pk.id = d.push_key_id
                 WHERE d.user_id = ? OR pk.user_id = ?",
                [$userId, $userId]
            );
            foreach ($rows as $r) {
                if ((int)$redis->sCard('ws:device:' . $r['did']) > 0) {
                    $onlineDevices++;
                }
            }
        } catch (\Throwable $e) {
        }

        // 今日新增设备
        $todayNewDevices = (int)(Database::fetch(
            "SELECT COUNT(DISTINCT d.device_id) cnt FROM devices d
             LEFT JOIN push_keys pk ON pk.id = d.push_key_id
             WHERE (d.user_id = ? OR pk.user_id = ?) AND DATE(d.created_at) = ?",
            [$userId, $userId, $today]
        )['cnt'] ?? 0);

        // 近 7 天推送量
        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} day"));
            $cnt = (int)(Database::fetch(
                "SELECT COALESCE(SUM(pl.success_count + pl.fail_count), 0) cnt
                 FROM push_logs pl
                 LEFT JOIN api_keys ak ON ak.id = pl.api_key_id
                 WHERE DATE(pl.created_at) = ? AND (ak.user_id = ? OR JSON_EXTRACT(COALESCE(pl.detail,'{}'), '$.user_id') = ?)",
                [$d, $userId, $userId]
            )['cnt'] ?? 0);
            $series[] = ['date' => $d, 'count' => $cnt];
        }

        return [
            'today_push'        => $todayPush,
            'yesterday_push'    => $yesterdayPush,
            'total_keys'        => $totalKeys,
            'active_keys'       => $activeKeys,
            'total_devices'     => $totalDevices,
            'online_devices'    => $onlineDevices,
            'today_new_devices' => $todayNewDevices,
            'trend_7d'          => $series,
        ];
    }
}
