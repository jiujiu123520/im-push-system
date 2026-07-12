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
 *   - 连续 3 次未收到 pong 则主动断开连接
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

    /** 连续未收到 pong 的最大次数，超过则断开 */
    private const MAX_MISSED_PONG = 3;

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

        // 递增未响应计数
        $this->pendingPongs[$fd] = ($this->pendingPongs[$fd] ?? 0) + 1;

        // 连续 3 次未收到 pong，断开连接
        if ($this->pendingPongs[$fd] >= self::MAX_MISSED_PONG) {
            $this->server->disconnect($fd, 4002, 'heartbeat timeout');
            $this->stopHeartbeat($fd);
            return;
        }

        // 发送 ping 帧（文本形式，便于客户端统一处理）
        $ping = json_encode([
            'type' => 'ping',
            'time' => time(),
        ], JSON_UNESCAPED_UNICODE);

        $this->server->push($fd, $ping);
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
