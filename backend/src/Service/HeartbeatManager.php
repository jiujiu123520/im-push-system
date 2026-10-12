<?php
declare(strict_types=1);

namespace App\Service;

use Swoole\Timer;
use Swoole\WebSocket\Server;

/**
 * 心跳管理器
 *
 * 基于 Swoole Timer 实现服务端主动心跳检测：
 *   - 为每个连接启动定时器，周期性发送 ping
 *   - 客户端需回复 pong，收到 pong 时重置未响应计数
 *   - 连续未收到 pong 的时长超过 DEATH_WINDOW_SECONDS 才主动断开连接
 *
 * 注意：Swoole Timer 为进程级，每个 fd 的所有事件由同一个 Worker 处理，
 *       因此各 Worker 内部以 PHP 数组维护定时器 ID 即可。
 */
class HeartbeatManager
{
    /** 最小心跳间隔（秒） */
    private const MIN_INTERVAL = 10;

    /** 最大心跳间隔（秒） */
    private const MAX_INTERVAL = 300;

    /** 默认心跳间隔（秒） */
    private const DEFAULT_INTERVAL = 30;

    /**
     * 判定连接死亡的时间窗口（秒）
     *
     * 语义：连续「未收到 pong」的时长达到该值才断开，与心跳间隔解耦。
     *
     * 为什么不再是「固定丢 3 次」：
     *   原实现 = interval × 3，Android 客户端上报 interval=15 → 45 秒即判死。
     *   但手机息屏/切后台时，uni-app 的 JS 线程会被系统冻结，应用层 pong 根本发不出来，
     *   实测「连上 59 秒就被 4002 掐断」，与 4 × 15s = 60s 完全吻合 ——
     *   也就是说不管网络好坏，只要 JS 冻结超过 45 秒就必然掉线。
     *   冻结是系统行为（Doze / 厂商省电），客户端无法规避，只能在服务端放宽容忍窗口。
     *
     * 300 秒的依据：远小于离线通知阈值（notify_offline_minutes 默认 30 分钟），
     * 因此放宽不会让「真离线」的判定明显滞后；同时能扛住常规的后台冻结窗口。
     */
    private const DEATH_WINDOW_SECONDS = 300;

    /**
     * @var Server|null Swoole WebSocket Server 实例
     */
    private ?Server $server = null;

    /**
     * @var array fd => timer_id 映射
     */
    private array $timers = [];

    /**
     * @var array fd => pending_pong 计数
     */
    private array $pendingPongs = [];

    /**
     * @var array fd => 心跳间隔（秒）
     */
    private array $intervals = [];

    /**
     * 设置 WebSocket Server 实例
     *
     * @param Server $server
     * @return void
     */
    public function setServer(Server $server): void
    {
        $this->server = $server;
    }

    /**
     * 为连接启动心跳定时器
     *
     * @param int $fd       连接文件描述符
     * @param int $interval 心跳间隔（秒），范围 10-300，超出范围用默认值
     * @return int|null 定时器 ID
     */
    public function startHeartbeat(int $fd, int $interval): ?int
    {
        // 间隔范围校正
        $interval = $this->clampInterval($interval);

        // 若已有定时器，先停止
        $this->stopHeartbeat($fd);

        // 初始化未响应计数
        $this->pendingPongs[$fd] = 0;
        $this->intervals[$fd]    = $interval;

        // 启动 Swoole 定时器（毫秒）
        $timerId = Timer::tick($interval * 1000, function () use ($fd) {
            $this->onTick($fd);
        });

        $this->timers[$fd] = $timerId;
        return $timerId;
    }

    /**
     * 停止连接的心跳定时器
     *
     * @param int $fd
     * @return void
     */
    public function stopHeartbeat(int $fd): void
    {
        if (isset($this->timers[$fd])) {
            Timer::clear($this->timers[$fd]);
            unset($this->timers[$fd]);
        }
        unset($this->pendingPongs[$fd], $this->intervals[$fd]);
    }

    /**
     * 收到客户端 pong 响应时重置计数
     *
     * @param int $fd
     * @return void
     */
    public function onPong(int $fd): void
    {
        $this->pendingPongs[$fd] = 0;
    }

    /**
     * 获取某连接当前未响应的 pong 次数
     *
     * @param int $fd
     * @return int
     */
    public function getPendingPongCount(int $fd): int
    {
        return $this->pendingPongs[$fd] ?? 0;
    }

    /**
     * 获取某连接实际生效的心跳间隔（秒）
     *
     * @param int $fd
     * @return int 返回间隔，若未启动则返回默认值
     */
    public function getInterval(int $fd): int
    {
        return $this->intervals[$fd] ?? self::DEFAULT_INTERVAL;
    }

    /**
     * 定时器回调：发送 ping 或断开超时连接
     *
     * @param int $fd
     * @return void
     */
    private function onTick(int $fd): void
    {
        if ($this->server === null) {
            $this->stopHeartbeat($fd);
            return;
        }

        // 连接已不存在则清理
        if (!$this->server->isEstablished($fd)) {
            $this->stopHeartbeat($fd);
            return;
        }

        // 先发送 ping（如果还有未响应的 ping，递增计数）
        // 注意：首次 tick 时 pendingPongs=0，先发第 1 个 ping
        // 第 2 次 tick 时若还没收到 pong，pendingPongs=1，发第 2 个 ping
        // ……直到累计未响应次数达到该连接的容忍上限（= DEATH_WINDOW_SECONDS / interval），
        // 此时才判死断开。
        $missed = ($this->pendingPongs[$fd] ?? 0);
        $limit  = $this->maxMissedPongs($this->intervals[$fd] ?? self::DEFAULT_INTERVAL);

        // 如果已经有未响应的 ping，再发一个之前先检查是否达到上限
        if ($missed >= $limit) {
            // 留痕：以前这里静默断开，服务端看不到「谁在什么时候因心跳被掐」，
            // 排查「APP 掉线」只能靠翻 Redis 历史 + 猜，这次就是因此绕了很久。
            error_log('[HeartbeatManager] 心跳超时断开 fd=' . $fd
                . ' interval=' . ($this->intervals[$fd] ?? 0) . 's'
                . ' missed=' . $missed
                . ' 判死窗口≈' . ($missed * ($this->intervals[$fd] ?? self::DEFAULT_INTERVAL)) . 's');
            $this->server->disconnect($fd, 4002, 'heartbeat timeout');
            $this->stopHeartbeat($fd);
            return;
        }

        // 发送 ping 帧（带 type 字段，与 APP 协议一致）
        $ping = json_encode([
            'type' => 'ping',
            'time' => time(),
            'server_time_ms' => (int)(microtime(true) * 1000),
        ], JSON_UNESCAPED_UNICODE);

        $this->server->push($fd, $ping);

        // 发送后递增未响应计数
        $this->pendingPongs[$fd] = $missed + 1;
    }

    /**
     * 按心跳间隔换算出该连接的容忍次数，使判死窗口恒为 DEATH_WINDOW_SECONDS。
     *
     * 用「窗口/间隔」而不是固定次数，是因为各端上报的 interval 不同
     * （Android 15s、Web 30s、桌面端可能 300s）：固定次数会导致间隔越大判死越慢、
     * 间隔越小判死越快，同一套阈值在不同端上表现完全不一致。
     *
     * 次数下限 3：间隔本身已经很大时（如 300s），不至于因为单次丢包就被判死。
     */
    private function maxMissedPongs(int $interval): int
    {
        $interval = max(1, $interval);
        return max(3, (int)ceil(self::DEATH_WINDOW_SECONDS / $interval));
    }

    /**
     * 停止所有心跳定时器（用于服务关闭时）
     *
     * @return void
     */
    public function stopAll(): void
    {
        foreach ($this->timers as $fd => $timerId) {
            Timer::clear($timerId);
        }
        $this->timers = [];
        $this->pendingPongs = [];
        $this->intervals = [];
    }

    /**
     * 校正心跳间隔到合法范围
     *
     * @param int $interval
     * @return int
     */
    private function clampInterval(int $interval): int
    {
        if ($interval < self::MIN_INTERVAL || $interval > self::MAX_INTERVAL) {
            return self::DEFAULT_INTERVAL;
        }
        return $interval;
    }
}
