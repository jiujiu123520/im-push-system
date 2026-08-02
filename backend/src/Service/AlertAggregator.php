<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 告警合并器（防 APNS 刷屏 / 防 DoS 风控）
 *
 * 核心机制：
 *   1. 时间窗口聚合：在窗口内（默认 30 秒）收集同一设备的所有告警
 *   2. 窗口结束时只发送 1 条汇总推送："共 N 条故障，请打开 App 查看"
 *   3. 使用 apns-collapse-id：同类告警刷新同一条通知，锁屏不刷屏
 *
 * 这是苹果官方推荐的防刷屏机制，也是最有效的防封号措施：
 *   - 避免短时间内向同一设备发送大量推送（被判定为 DoS 攻击）
 *   - 避免锁屏通知堆积（用户投诉→封号）
 *   - 减少 APNS 请求总量（避免触发限流）
 *
 * 工作流程：
 *   pushByKey/pushToDevice → AlertAggregator::add()
 *     → 窗口内累积告警（Redis List）
 *     → 窗口到期或达到阈值 → flush() → ApnsService::send(汇总消息, collapse_id)
 *
 * Redis 数据结构：
 *   - alert:window:{deviceId}    → List，窗口内的告警条目（JSON）
 *   - alert:window_ts:{deviceId} → 窗口开始时间戳（用于判断窗口是否到期）
 *   - TTL = 窗口时长 + 缓冲（避免残留）
 */
class AlertAggregator
{
    /** 告警聚合窗口（秒），窗口内的告警合并为一条推送 */
    private const WINDOW_SECONDS = 30;

    /** 单窗口最大告警数（达到则立即 flush，不等窗口结束） */
    private const WINDOW_MAX_ALERTS = 10;

    /** Redis Key 前缀 */
    private const WINDOW_LIST_KEY = 'alert:window:';
    private const WINDOW_TS_KEY   = 'alert:window_ts:';

    /** collapse-id 前缀（同一设备的告警共用一个 collapse-id，锁屏只显示最新一条） */
    private const COLLAPSE_ID_PREFIX = 'alert:';

    /**
     * 添加一条告警到聚合窗口
     *
     * 如果是窗口内第一条，创建新窗口并设置时间戳；
     * 如果窗口已存在，追加到 List；
     * 如果窗口到期或达到最大告警数，触发 flush 发送汇总推送。
     *
     * @param string $deviceId  目标设备 ID
     * @param string $apnsToken 目标设备 APNS token
     * @param string $title     告警标题
     * @param string $body      告警内容
     * @param array  $payload   自定义数据
     * @return array ['aggregated' => bool, 'flushed' => bool, 'count' => int]
     *               aggregated=true 表示已加入窗口，flushed=true 表示已触发推送
     */
    public static function add(string $deviceId, string $apnsToken, string $title, string $body, array $payload = []): array
    {
        if ($deviceId === '' || $apnsToken === '') {
            return ['aggregated' => false, 'flushed' => false, 'count' => 0];
        }

        try {
            $redis = Redis::getInstance();
            $listKey = self::WINDOW_LIST_KEY . $deviceId;
            $tsKey   = self::WINDOW_TS_KEY . $deviceId;
            $now = time();

            // 检查窗口是否已存在
            $windowStart = $redis->get($tsKey);
            $isNewWindow = !$windowStart;

            if ($isNewWindow) {
                // 新窗口：设置开始时间戳
                $redis->setex($tsKey, self::WINDOW_SECONDS + 60, (string)$now);
                $windowStart = $now;
            }

            // 追加告警到窗口 List
            $alertItem = json_encode([
                'title'   => $title,
                'body'    => $body,
                'payload' => $payload,
                'time'    => date('Y-m-d H:i:s', $now),
            ], JSON_UNESCAPED_UNICODE);
            $redis->rPush($listKey, $alertItem);
            $redis->expire($listKey, self::WINDOW_SECONDS + 60);

            // 获取当前窗口内告警数
            $count = (int)$redis->lLen($listKey);

            // 判断是否需要立即 flush：
            //   1. 达到最大告警数（避免窗口内堆积过多）
            //   2. 窗口已到期（时间超过窗口时长）
            $windowElapsed = $now - (int)$windowStart;
            $shouldFlush = ($count >= self::WINDOW_MAX_ALERTS) || ($windowElapsed >= self::WINDOW_SECONDS);

            if ($shouldFlush) {
                $flushResult = self::flush($deviceId, $apnsToken);
                return ['aggregated' => true, 'flushed' => true, 'count' => $flushResult['count']];
            }

            // 未触发 flush，告警已暂存
            return ['aggregated' => true, 'flushed' => false, 'count' => $count];

        } catch (\Throwable $e) {
            error_log('[AlertAggregator] add 异常: ' . $e->getMessage());
            // 降级：直接发送单条推送（不聚合）
            return ['aggregated' => false, 'flushed' => false, 'count' => 0];
        }
    }

    /**
     * 刷新窗口：合并窗口内所有告警，发送一条汇总推送
     *
     * 汇总策略：
     *   - 1 条告警：直接发送原始内容
     *   - 多条告警：发送汇总 "共 N 条通知，最新：{第一条标题}"
     *   - 使用 collapse-id，同设备告警刷新同一条锁屏通知
     *
     * @param string $deviceId
     * @param string $apnsToken
     * @return array ['sent' => bool, 'count' => int, 'message' => string]
     */
    public static function flush(string $deviceId, string $apnsToken): array
    {
        if ($deviceId === '' || $apnsToken === '') {
            return ['sent' => false, 'count' => 0, 'message' => '参数为空'];
        }

        try {
            $redis = Redis::getInstance();
            $listKey = self::WINDOW_LIST_KEY . $deviceId;
            $tsKey   = self::WINDOW_TS_KEY . $deviceId;

            // 取出窗口内所有告警
            $items = $redis->lRange($listKey, 0, -1);
            if (empty($items)) {
                return ['sent' => false, 'count' => 0, 'message' => '窗口内无告警'];
            }

            $count = count($items);

            // 清除窗口数据（防止重复 flush）
            $redis->del($listKey);
            $redis->del($tsKey);

            // 解析第一条告警（作为汇总的"最新"内容）
            $firstAlert = json_decode($items[0], true) ?: [];
            $latestTitle = $firstAlert['title'] ?? '新消息';
            $latestBody  = $firstAlert['body'] ?? '';

            // 构造汇总推送内容
            if ($count === 1) {
                // 单条告警：直接发送原始内容
                $summaryTitle = $latestTitle;
                $summaryBody  = $latestBody;
            } else {
                // 多条告警：发送汇总
                $summaryTitle = '收到 ' . $count . ' 条新消息';
                $summaryBody  = '最新：' . $latestTitle;
                if (mb_strlen($summaryBody) > 50) {
                    $summaryBody = mb_substr($summaryBody, 0, 50) . '...';
                }
                $summaryBody .= '（打开 App 查看全部）';
            }

            // 生成 collapse-id（同设备共用，锁屏只显示最新一条）
            // 格式：alert:{deviceId 的 md5 前 16 位}
            $collapseId = self::COLLAPSE_ID_PREFIX . substr(md5($deviceId), 0, 16);

            // 合并所有告警的 payload（保留最后一条的自定义数据）
            $mergedPayload = [];
            $mergedPayload['alert_count'] = $count;
            $mergedPayload['device_id'] = $deviceId;
            if (isset($firstAlert['payload']) && is_array($firstAlert['payload'])) {
                $mergedPayload['data'] = $firstAlert['payload'];
            }

            // 调用 ApnsService 发送汇总推送（带 collapse-id）
            $result = ApnsService::send($apnsToken, $summaryTitle, $summaryBody, $mergedPayload, 0, $collapseId);

            return [
                'sent'    => $result['success'],
                'count'   => $count,
                'message' => $result['message'],
                'apns_id' => $result['apns_id'] ?? '',
            ];

        } catch (\Throwable $e) {
            error_log('[AlertAggregator] flush 异常: ' . $e->getMessage());
            return ['sent' => false, 'count' => 0, 'message' => $e->getMessage()];
        }
    }

    /**
     * 检查设备是否有待刷新的告警窗口
     *
     * 可由定时任务调用，处理窗口到期但未触发 flush 的情况
     *
     * @param string $deviceId
     * @return bool
     */
    public static function hasPendingWindow(string $deviceId): bool
    {
        try {
            $redis = Redis::getInstance();
            $tsKey = self::WINDOW_TS_KEY . $deviceId;
            $windowStart = $redis->get($tsKey);
            if (!$windowStart) return false;

            // 窗口已到期但数据还在（未 flush）
            return (time() - (int)$windowStart) >= self::WINDOW_SECONDS;
        } catch (\Throwable $e) {
            return false;
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
            'window_seconds'    => self::WINDOW_SECONDS,
            'window_max_alerts' => self::WINDOW_MAX_ALERTS,
            'description'       => '同一设备在 ' . self::WINDOW_SECONDS . ' 秒内的多条告警合并为 1 条推送，使用 collapse-id 避免锁屏刷屏',
        ];
    }
}
