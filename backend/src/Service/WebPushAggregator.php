<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Web Push 聚合器（iOS PWA 防刷屏）
 *
 * 核心机制：
 *   1. 时间窗口聚合：在 3 秒窗口内收集同一设备的所有消息
 *   2. 窗口结束时（3 秒后）只发送 1 条推送：
 *        - 1 条消息：显示具体标题与内容
 *        - 多条消息：显示「收到 N 条消息」，不显示具体内容
 *
 * 与 APNS 的 AlertAggregator 机制一致，但窗口为 3 秒，且只针对 Web Push（PWA）。
 *
 * 工作流程：
 *   tryWebPush → WebPushAggregator::add()
 *     → 窗口内累积消息（Redis List）
 *     → 3 秒后（Swoole\Timer::after）触发 flush()
 *     → WebPushService::send(汇总消息)
 *
 * Redis 数据结构：
 *   - webpush:window:{deviceId}    → List，窗口内的消息条目（JSON）
 *   - webpush:window_ts:{deviceId} → 窗口开始时间戳（用于判断窗口是否到期）
 *   - TTL = 窗口时长 + 缓冲（避免残留）
 */
class WebPushAggregator
{
    /** 聚合窗口（秒），窗口内的消息合并为一条推送 */
    private const WINDOW_SECONDS = 3;

    /** Redis Key 前缀 */
    private const WINDOW_LIST_KEY = 'webpush:window:';
    private const WINDOW_TS_KEY   = 'webpush:window_ts:';

    /**
     * 添加一条消息到聚合窗口
     *
     * 如果是窗口内第一条，创建新窗口并设置 3 秒后 flush 的定时器；
     * 如果窗口已存在，追加到 List。
     *
     * @param string $deviceId 目标设备 ID
     * @param string $title    消息标题
     * @param string $body     消息内容
     * @param array  $payload  自定义数据
     * @return array ['aggregated' => bool, 'count' => int]
     */
    public static function add(string $deviceId, string $title, string $body, array $payload = []): array
    {
        if ($deviceId === '') {
            return ['aggregated' => false, 'count' => 0];
        }

        try {
            $redis = Redis::getInstance();
            $listKey = self::WINDOW_LIST_KEY . $deviceId;
            $tsKey   = self::WINDOW_TS_KEY . $deviceId;
            $now = time();

            $windowStart = $redis->get($tsKey);
            $isNewWindow = !$windowStart;

            if ($isNewWindow) {
                $redis->setex($tsKey, self::WINDOW_SECONDS + 10, (string)$now);
                // 3 秒后触发 flush，保证即使没有后续消息也能发送
                if (class_exists('Swoole\Timer')) {
                    \Swoole\Timer::after(self::WINDOW_SECONDS * 1000, function () use ($deviceId) {
                        self::flush($deviceId);
                    });
                }
            }

            // 追加消息到窗口 List
            $redis->rPush($listKey, json_encode([
                'title'   => $title,
                'body'    => $body,
                'payload' => $payload,
                'time'    => date('Y-m-d H:i:s', $now),
            ], JSON_UNESCAPED_UNICODE));
            $redis->expire($listKey, self::WINDOW_SECONDS + 10);

            $count = (int)$redis->lLen($listKey);

            return ['aggregated' => true, 'count' => $count];
        } catch (\Throwable $e) {
            error_log('[WebPushAggregator] add 异常: ' . $e->getMessage());
            return ['aggregated' => false, 'count' => 0];
        }
    }

    /**
     * 刷新窗口：合并窗口内所有消息，发送一条汇总推送
     *
     * 汇总策略：
     *   - 1 条消息：发送原始标题与内容
     *   - 多条消息：发送「收到 N 条消息」，不显示具体内容
     *
     * @param string $deviceId
     * @return array ['sent' => bool, 'count' => int, 'message' => string]
     */
    public static function flush(string $deviceId): array
    {
        if ($deviceId === '') {
            return ['sent' => false, 'count' => 0, 'message' => '参数为空'];
        }

        try {
            $redis = Redis::getInstance();
            $listKey = self::WINDOW_LIST_KEY . $deviceId;
            $tsKey   = self::WINDOW_TS_KEY . $deviceId;

            // 取出窗口内所有消息
            $items = $redis->lRange($listKey, 0, -1);
            if (empty($items)) {
                $redis->del($tsKey);
                return ['sent' => false, 'count' => 0, 'message' => '窗口内无消息'];
            }

            $count = count($items);

            // 先清窗口，防止重复 flush
            $redis->del($listKey);
            $redis->del($tsKey);

            // 查询订阅（发送前实时查，避免订阅失效）
            $sub = Database::fetch(
                'SELECT endpoint, p256dh, auth FROM web_push_subscriptions WHERE device_id = ? AND status = 1 ORDER BY id DESC LIMIT 1',
                [$deviceId]
            );
            if ($sub === false) {
                return ['sent' => false, 'count' => $count, 'message' => '订阅不存在或已失效'];
            }

            if ($count === 1) {
                // 单条消息：显示具体内容
                $first = json_decode($items[0], true) ?: [];
                $summaryTitle = (string)($first['title'] ?? '新消息');
                $summaryBody  = (string)($first['body'] ?? '');
                $payload      = is_array($first['payload'] ?? null) ? $first['payload'] : [];
            } else {
                // 多条消息：显示「收到 N 条消息」，不显示具体内容
                $summaryTitle = '收到 ' . $count . ' 条消息';
                $summaryBody  = '';
                $payload      = [
                    'alert_count' => $count,
                    'device_id'   => $deviceId,
                ];
            }

            $result = WebPushService::send($sub, $summaryTitle, $summaryBody, $payload);

            return [
                'sent'    => $result['success'],
                'count'   => $count,
                'message' => $result['message'],
            ];
        } catch (\Throwable $e) {
            error_log('[WebPushAggregator] flush 异常: ' . $e->getMessage());
            return ['sent' => false, 'count' => 0, 'message' => $e->getMessage()];
        }
    }

    /**
     * 获取窗口配置信息（供后台展示）
     *
     * @return array
     */
    public static function getConfig(): array
    {
        return [
            'window_seconds' => self::WINDOW_SECONDS,
            'description'    => '同一设备在 ' . self::WINDOW_SECONDS . ' 秒内的多条消息合并为 1 条推送，多条时显示「收到 N 条消息」',
        ];
    }
}
