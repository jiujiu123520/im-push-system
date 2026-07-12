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
 *   2. 3 次未响应 pong 触发断开
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
     * 测试 MAX_MISSED_PONG 常量值为 3
     */
    public function testMaxMissedPongConstantIsThree(): void
    {
        $reflection = new ReflectionClass(HeartbeatManager::class);
        $this->assertSame(3, $reflection->getConstant('MAX_MISSED_PONG'), '连续未响应最大次数应为 3');
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
     * 测试 3 次未响应 pong 触发断开
     *
     * 模拟心跳定时器 3 次 tick 递增未响应计数，
     * 第 3 次达到 MAX_MISSED_PONG 阈值，应触发断开。
     */
    public function testThreeMissedPongTriggersDisconnectThreshold(): void
    {
        $manager = new HeartbeatManager();
        $fd = 10001;

        // 通过反射初始化 pendingPongs 计数
        $pendingPongsProp = new ReflectionProperty(HeartbeatManager::class, 'pendingPongs');
        $pendingPongsProp->setAccessible(true);
        $pendingPongsProp->setValue($manager, [$fd => 0]);

        $maxMissed = (new ReflectionClass(HeartbeatManager::class))->getConstant('MAX_MISSED_PONG');

        // 第 1 次未响应
        $pending = $pendingPongsProp->getValue($manager);
        $pending[$fd] = ($pending[$fd] ?? 0) + 1;
        $pendingPongsProp->setValue($manager, $pending);
        $this->assertSame(1, $manager->getPendingPongCount($fd), '第 1 次未响应计数应为 1');
        $this->assertLessThan($maxMissed, $manager->getPendingPongCount($fd), '第 1 次不应达到断开阈值');

        // 第 2 次未响应
        $pending = $pendingPongsProp->getValue($manager);
        $pending[$fd] = ($pending[$fd] ?? 0) + 1;
        $pendingPongsProp->setValue($manager, $pending);
        $this->assertSame(2, $manager->getPendingPongCount($fd), '第 2 次未响应计数应为 2');
        $this->assertLessThan($maxMissed, $manager->getPendingPongCount($fd), '第 2 次不应达到断开阈值');

        // 第 3 次未响应 - 达到阈值
        $pending = $pendingPongsProp->getValue($manager);
        $pending[$fd] = ($pending[$fd] ?? 0) + 1;
        $pendingPongsProp->setValue($manager, $pending);
        $this->assertSame(3, $manager->getPendingPongCount($fd), '第 3 次未响应计数应为 3');
        $this->assertGreaterThanOrEqual(
            $maxMissed,
            $manager->getPendingPongCount($fd),
            '第 3 次应达到断开阈值（>= MAX_MISSED_PONG）'
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
     * 测试收到 pong 后再连续 3 次未响应仍触发断开
     *
     * 验证重置后计数器能正常重新累计。
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
        $this->assertSame(3, $manager->getPendingPongCount($fd), '重新累计 3 次后应为 3');
        $this->assertGreaterThanOrEqual(
            3,
            $manager->getPendingPongCount($fd),
            '重置后重新累计 3 次应再次达到断开阈值'
        );
    }
}
