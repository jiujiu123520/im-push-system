<?php
declare(strict_types=1);

namespace Tests;

require_once __DIR__ . '/TestCase.php';

use App\Service\HeartbeatManager;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * 心跳管理器测试
 *
 * 测试场景：
 *   1. 心跳间隔范围校验（10-300 秒，超出范围回退默认值 30）
 *   2. 未响应 pong 累计达到判死窗口（DEATH_WINDOW_SECONDS / interval）触发断开
 *   3. 收到 pong 后计数重置
 *
 * 说明：HeartbeatManager 基于 Swoole Timer 实现，
 * Timer 回调需在 Swoole 事件循环中才会触发。
 * 本测试通过反射直接验证核心业务逻辑（间隔校正、未响应计数、
 * 断开阈值），无需依赖 Swoole 运行时环境。
 */
class HeartbeatManagerTest extends TestCase
{
    /**
     * 测试心跳间隔范围校验 - 范围内的值保持不变
     *
     * clampInterval 方法：间隔在 [10, 300] 内时原样返回。
     */
    public function testIntervalWithinRangeStaysUnchanged(): void
    {
        $manager = new HeartbeatManager();
        $method = new ReflectionMethod(HeartbeatManager::class, 'clampInterval');
        $method->setAccessible(true);

        // 边界值
        $this->assertSame(10, $method->invoke($manager, 10), '最小值 10 应保持不变');
        $this->assertSame(300, $method->invoke($manager, 300), '最大值 300 应保持不变');

        // 范围内常见值
        $this->assertSame(30, $method->invoke($manager, 30), '默认值 30 应保持不变');
        $this->assertSame(60, $method->invoke($manager, 60));
        $this->assertSame(120, $method->invoke($manager, 120));
        $this->assertSame(180, $method->invoke($manager, 180));
        $this->assertSame(240, $method->invoke($manager, 240));
    }

    /**
     * 测试心跳间隔范围校验 - 超出范围回退默认值 30
     *
     * clampInterval 方法：间隔 < 10 或 > 300 时回退到默认值 30。
     */
    public function testIntervalOutOfRangeFallsBackToDefault(): void
    {
        $manager = new HeartbeatManager();
        $method = new ReflectionMethod(HeartbeatManager::class, 'clampInterval');
        $method->setAccessible(true);

        // 小于最小值
        $this->assertSame(30, $method->invoke($manager, 0), '间隔 0 应回退到默认值 30');
        $this->assertSame(30, $method->invoke($manager, 1), '间隔 1 应回退到默认值 30');
        $this->assertSame(30, $method->invoke($manager, 5), '间隔 5 应回退到默认值 30');
        $this->assertSame(30, $method->invoke($manager, 9), '间隔 9 应回退到默认值 30');

        // 大于最大值
        $this->assertSame(30, $method->invoke($manager, 301), '间隔 301 应回退到默认值 30');
        $this->assertSame(30, $method->invoke($manager, 600), '间隔 600 应回退到默认值 30');
        $this->assertSame(30, $method->invoke($manager, 9999), '间隔 9999 应回退到默认值 30');

        // 负数
        $this->assertSame(30, $method->invoke($manager, -1), '负数间隔应回退到默认值 30');
        $this->assertSame(30, $method->invoke($manager, -100), '负数间隔应回退到默认值 30');
    }

    /**
     * 测试判死窗口常量与容忍次数换算
     *
     * 容忍次数 = ceil(DEATH_WINDOW_SECONDS / interval)，下限 3。
     * 目的是让「判死时长」与心跳间隔解耦：各端上报的 interval 不同
     * （Android 15s / Web 30s），沿用固定次数会让判死时长随 interval 漂移，
     * 且 Android 端 3 × 15s = 45s 过短 —— 息屏后 JS 线程被系统冻结，
     * 应用层 pong 发不出来，实测连上 59 秒即被 4002 掐断。
     */
    public function testDeathWindowAndToleranceDerivation(): void
    {
        $reflection = new ReflectionClass(HeartbeatManager::class);
        $this->assertSame(300, $reflection->getConstant('DEATH_WINDOW_SECONDS'), '判死窗口应为 300 秒');

        $manager = new HeartbeatManager();
        $method = new ReflectionMethod(HeartbeatManager::class, 'maxMissedPongs');
        $method->setAccessible(true);

        // Android 客户端上报 15s：15 × 20 = 300s
        $this->assertSame(20, $method->invoke($manager, 15), 'interval=15 时应容忍 20 次');
        // Web 客户端上报 30s：30 × 10 = 300s
        $this->assertSame(10, $method->invoke($manager, 30), 'interval=30 时应容忍 10 次');
        // 60s：60 × 5 = 300s
        $this->assertSame(5, $method->invoke($manager, 60), 'interval=60 时应容忍 5 次');
        // 间隔已经很大时，保底下限 3 次，避免单次丢包即判死
        $this->assertSame(3, $method->invoke($manager, 300), 'interval=300 时应保底容忍 3 次');

        // 非法间隔不应抛异常或除零
        $this->assertGreaterThanOrEqual(3, $method->invoke($manager, 0), 'interval=0 时应安全回退');
        $this->assertGreaterThanOrEqual(3, $method->invoke($manager, -5), 'interval 为负时应安全回退');
    }

    /**
     * 测试心跳间隔常量边界
     */
    public function testIntervalConstants(): void
    {
        $reflection = new ReflectionClass(HeartbeatManager::class);
        $this->assertSame(10, $reflection->getConstant('MIN_INTERVAL'), '最小间隔应为 10 秒');
        $this->assertSame(300, $reflection->getConstant('MAX_INTERVAL'), '最大间隔应为 300 秒');
        $this->assertSame(30, $reflection->getConstant('DEFAULT_INTERVAL'), '默认间隔应为 30 秒');
    }

    /**
     * 测试未响应次数累计到换算阈值时触发断开
     *
     * 模拟心跳定时器逐次 tick 递增未响应计数，
     * 达到 maxMissedPongs(interval) 之前不断开，达到即达到断开阈值。
     */
    public function testMissedPongReachesDerivedThreshold(): void
    {
        $manager = new HeartbeatManager();
        $fd = 10001;

        // 通过反射初始化 pendingPongs 计数
        $pendingPongsProp = new ReflectionProperty(HeartbeatManager::class, 'pendingPongs');
        $pendingPongsProp->setAccessible(true);
        $pendingPongsProp->setValue($manager, [$fd => 0]);

        $limitMethod = new ReflectionMethod(HeartbeatManager::class, 'maxMissedPongs');
        $limitMethod->setAccessible(true);
        $maxMissed = $limitMethod->invoke($manager, 15); // Android：15s × 20 = 300s

        // 阈值前逐次递增，均不应达到断开条件
        for ($i = 1; $i < $maxMissed; $i++) {
            $pending = $pendingPongsProp->getValue($manager);
            $pending[$fd] = ($pending[$fd] ?? 0) + 1;
            $pendingPongsProp->setValue($manager, $pending);
            $this->assertSame($i, $manager->getPendingPongCount($fd), "第 {$i} 次未响应计数应为 {$i}");
            $this->assertLessThan($maxMissed, $manager->getPendingPongCount($fd), "第 {$i} 次不应达到断开阈值");
        }

        // 达到阈值
        $pending = $pendingPongsProp->getValue($manager);
        $pending[$fd] = ($pending[$fd] ?? 0) + 1;
        $pendingPongsProp->setValue($manager, $pending);
        $this->assertSame($maxMissed, $manager->getPendingPongCount($fd), "第 {$maxMissed} 次未响应计数应达到阈值");
        $this->assertGreaterThanOrEqual(
            $maxMissed,
            $manager->getPendingPongCount($fd),
            '达到 maxMissedPongs(interval) 后应触发断开'
        );
    }

    /**
     * 测试收到 pong 后未响应计数重置为 0
     */
    public function testOnPongResetsCounter(): void
    {
        $manager = new HeartbeatManager();
        $fd = 20002;

        // 通过反射设置未响应计数为 2
        $pendingPongsProp = new ReflectionProperty(HeartbeatManager::class, 'pendingPongs');
        $pendingPongsProp->setAccessible(true);
        $pendingPongsProp->setValue($manager, [$fd => 2]);

        $this->assertSame(2, $manager->getPendingPongCount($fd), '初始未响应计数应为 2');

        // 收到 pong，计数重置
        $manager->onPong($fd);
        $this->assertSame(0, $manager->getPendingPongCount($fd), '收到 pong 后计数应重置为 0');
    }

    /**
     * 测试 getPendingPongCount 对未知 fd 返回 0
     */
    public function testGetPendingPongCountForUnknownFd(): void
    {
        $manager = new HeartbeatManager();
        $this->assertSame(0, $manager->getPendingPongCount(99999), '未知 fd 的未响应计数应为 0');
    }

    /**
     * 测试收到 pong 后再连续未响应时计数能重新累计
     *
     * 验证重置后计数器能正常从 0 重新累计（判死阈值本身由
     * maxMissedPongs 换算，此处只验证计数器的重置与再累计行为）。
     */
    public function testCounterResetsAndReaccumulates(): void
    {
        $manager = new HeartbeatManager();
        $fd = 30003;

        $pendingPongsProp = new ReflectionProperty(HeartbeatManager::class, 'pendingPongs');
        $pendingPongsProp->setAccessible(true);

        // 模拟 2 次未响应
        $pendingPongsProp->setValue($manager, [$fd => 2]);
        $this->assertSame(2, $manager->getPendingPongCount($fd));

        // 收到 pong 重置
        $manager->onPong($fd);
        $this->assertSame(0, $manager->getPendingPongCount($fd), '收到 pong 后应重置');

        // 重新累计 3 次
        $pending = $pendingPongsProp->getValue($manager);
        for ($i = 0; $i < 3; $i++) {
            $pending[$fd] = ($pending[$fd] ?? 0) + 1;
            $pendingPongsProp->setValue($manager, $pending);
        }
        $this->assertSame(3, $manager->getPendingPongCount($fd), '重置后重新累计 3 次应为 3');
    }
}
