<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Service\ApiKeyService;
use App\Service\Database;
use App\Service\PushDispatcher;
use App\Service\Response;

/**
 * 用户端推送消息
 *
 * 路由前缀：/user-api/push
 */
class PushController extends BaseUserController
{
    public function send(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $body = $this->parseBody($context);
        $targetType  = strtolower((string)($body['target_type'] ?? ''));
        $targetValue = (string)($body['target_value'] ?? '');
        $title       = (string)($body['title'] ?? '');
        $content     = (string)($body['content'] ?? '');
        $payloadObj  = $body['payload'] ?? [];
        $priority    = (string)($body['priority'] ?? 'normal');

        if (!in_array($targetType, ['device', 'key', 'broadcast'], true)) {
            return $this->fail($context, 'target_type 必须为 device / key / broadcast');
        }
        if ($title === '' && $content === '') {
            return $this->fail($context, 'title 或 content 至少填一个');
        }
        if ($targetType !== 'broadcast' && $targetValue === '') {
            return $this->fail($context, 'target_value 不能为空');
        }

        // 解析 target 并限制为用户自己的 push_key / device
        $targets = array_filter(array_map('trim', explode(',', $targetValue)), fn($v) => $v !== '');

        $ownKeyMap = [];
        $ownKeys = Database::fetchAll(
            'SELECT id, key_value, status FROM push_keys WHERE user_id = ?', [$userId]
        );
        foreach ($ownKeys as $k) {
            $ownKeyMap[(string)$k['key_value']] = $k;
        }

        if ($targetType === 'key') {
            foreach ($targets as $t) {
                $row = $ownKeyMap[$t] ?? null;
                if ($row === null) {
                    return $this->fail($context, "推送 Key [{$t}] 不存在或不属于你");
                }
                if ((int)$row['status'] !== 1) {
                    return $this->fail($context, "推送 Key [{$t}] 已被禁用");
                }
            }
        } elseif ($targetType === 'device') {
            if (empty($targets)) {
                return $this->fail($context, 'target_value 不能为空');
            }
            $ph = implode(',', array_fill(0, count($targets), '?'));
            $rows = Database::fetchAll(
                "SELECT d.device_id, d.status, pk.user_id pk_uid
                 FROM devices d LEFT JOIN push_keys pk ON pk.id = d.push_key_id
                 WHERE d.device_id IN ({$ph}) AND (d.user_id = ? OR pk.user_id = ?)",
                array_merge($targets, [$userId, $userId])
            );
            $found = array_column($rows, 'device_id');
            if (count($found) !== count($targets)) {
                $missing = array_diff($targets, $found);
                return $this->fail($context, '设备不存在或不属于你：' . implode(',', $missing));
            }
            foreach ($rows as $r) {
                if ((int)($r['status'] ?? 1) === 2) {
                    return $this->fail($context, '设备 [' . $r['device_id'] . '] 已被禁用');
                }
            }
        } elseif ($targetType === 'broadcast') {
            // broadcast 只允许对用户自己的所有 key 广播，防止影响其他用户
            $targets = array_keys($ownKeyMap);
            // 过滤启用
            $targets = array_values(array_filter($targets, function ($kv) use ($ownKeyMap) {
                return (int)($ownKeyMap[$kv]['status'] ?? 0) === 1;
            }));
            if (empty($targets)) {
                return $this->fail($context, '没有可用的推送 Key，无法广播');
            }
        }

        // 频率限制（per_minute / per_hour / per_day）
        $limits = self::getRateLimits();
        $violation = $this->checkRateLimit($userId, $limits);
        if ($violation !== null) {
            Response::fail($context['response'], $violation, 429, 429);
            return false;
        }

        // 生成 message
        $message = [
            'message_id' => uniqid('msg_', true),
            'title'      => $title,
            'content'    => $content,
            'payload'    => is_array($payloadObj) ? $payloadObj : [],
            'priority'   => $priority,
            'timestamp'  => time(),
        ];

        $startTime = microtime(true);
        $dispatcher = new PushDispatcher();
        $summary = ['success_count' => 0, 'fail_count' => 0, 'detail' => [], 'fail_detail' => [], 'stored_offline' => false, 'fail_reason' => ''];

        if ($targetType === 'device') {
            $r = $dispatcher->pushToDevices($targets, $message);
            $summary = $r + $summary;
        } else {
            $failReasons = [];
            foreach ($targets as $kv) {
                $r = $dispatcher->pushByKey($kv, $message);
                $summary['success_count'] += (int)($r['success_count'] ?? 0);
                $summary['fail_count']    += (int)($r['fail_count'] ?? 0);
                $summary['detail']         = array_merge($summary['detail'], (array)($r['detail'] ?? []));
                if (!empty($r['stored_offline'])) {
                    $summary['stored_offline'] = true;
                }
                if (!empty($r['fail_detail'])) {
                    $summary['fail_detail'] = array_merge($summary['fail_detail'], (array)$r['fail_detail']);
                }
                if (!empty($r['fail_reason'])) {
                    $failReasons[(string)$r['fail_reason']] = ($failReasons[(string)$r['fail_reason']] ?? 0) + 1;
                }
            }
            if (!empty($failReasons)) {
                $parts = [];
                foreach ($failReasons as $reason => $c) {
                    $parts[] = $c > 1 ? "{$reason}（{$c}次）" : $reason;
                }
                $summary['fail_reason'] = implode('；', $parts);
            }
        }

        $elapsedMs = (int)((microtime(true) - $startTime) * 1000);
        $successCount = (int)$summary['success_count'];
        $failCount    = (int)$summary['fail_count'];
        if ($successCount > 0 && $failCount === 0) {
            $status = 1;
        } elseif ($successCount > 0 && $failCount > 0) {
            $status = 2;
        } elseif (!empty($summary['stored_offline'])) {
            $status = 4;
        } else {
            $status = 0;
        }

        // 写 push_logs：用户端推送用 api_key_id = 0，因为用户可能没有 API Key。
        // 用 user_id 字段（如果表存在）；否则 target_value 保留原目标。
        try {
            $finalTargetValue = $targetType === 'broadcast' ? 'ALL_MY_KEYS:' . implode(',', $targets) : $targetValue;
            Database::insert(
                'INSERT INTO push_logs (api_key_id, target_type, target_value, title, content, success_count, fail_count, fail_reason, status, elapsed_ms, detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    0,
                    $targetType,
                    $finalTargetValue,
                    $title,
                    $content,
                    $successCount,
                    $failCount,
                    (string)($summary['fail_reason'] ?? ''),
                    $status,
                    $elapsedMs,
                    json_encode([
                        'push_detail' => $summary['detail'],
                        'fail_detail' => $summary['fail_detail'],
                        'user_id'     => $userId,
                        'source'      => 'user_console',
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (\Throwable $e) {
        }

        $this->recordRateLimit($userId);

        return [
            'status'        => $status,
            'success_count' => $successCount,
            'fail_count'    => $failCount,
            'fail_reason'   => (string)($summary['fail_reason'] ?? ''),
            'detail'        => $summary['detail'],
            'elapsed_ms'    => $elapsedMs,
            'stored_offline' => !empty($summary['stored_offline']),
        ];
    }

    private static function getRateLimits(): array
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $defaults = [
            'rate_limit_push_per_min'  => 20,
            'rate_limit_push_per_hour' => 500,
            'rate_limit_push_per_day'  => 3000,
        ];
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_security']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg)) {
                    $cached = array_merge($defaults, $cfg);
                    return $cached;
                }
            }
        } catch (\Throwable $e) {
        }
        $cached = $defaults;
        return $cached;
    }

    private function checkRateLimit(int $userId, array $limits): ?string
    {
        try {
            $now = time();
            $r = \App\Service\Redis::getInstance();
            $keyMin  = "user_push:rate:min:{$userId}:" . date('YmdHi', $now);
            $keyHour = "user_push:rate:hour:{$userId}:" . date('YmdH', $now);
            $keyDay  = "user_push:rate:day:{$userId}:" . date('Ymd', $now);
            $perMin  = (int)($limits['rate_limit_push_per_min'] ?? 20);
            $perHour = (int)($limits['rate_limit_push_per_hour'] ?? 500);
            $perDay  = (int)($limits['rate_limit_push_per_day'] ?? 3000);
            if ($perMin > 0 && (int)$r->get($keyMin) >= $perMin) {
                return '推送频率超过限制（每分钟最多 ' . $perMin . ' 次）';
            }
            if ($perHour > 0 && (int)$r->get($keyHour) >= $perHour) {
                return '推送频率超过限制（每小时最多 ' . $perHour . ' 次）';
            }
            if ($perDay > 0 && (int)$r->get($keyDay) >= $perDay) {
                return '推送频率超过限制（每天最多 ' . $perDay . ' 次）';
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    private function recordRateLimit(int $userId): void
    {
        try {
            $now = time();
            $r = \App\Service\Redis::getInstance();
            $keys = [
                ["user_push:rate:min:{$userId}:"  . date('YmdHi', $now), 70],
                ["user_push:rate:hour:{$userId}:" . date('YmdH', $now), 3700],
                ["user_push:rate:day:{$userId}:"  . date('Ymd', $now),  86410],
            ];
            foreach ($keys as [$k, $ttl]) {
                $cur = (int)$r->incr($k);
                if ($cur === 1) {
                    $r->expire($k, $ttl);
                }
            }
        } catch (\Throwable $e) {
        }
    }
}
