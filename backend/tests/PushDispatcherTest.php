<?php
declare(strict_types=1);

namespace Tests;

require_once __DIR__ . '/TestCase.php';

use App\Service\PushDispatcher;
use App\Service\Redis;
use App\Service\Config;

/**
 * 推送分发器测试
 *
 * 测试场景：
 *   1. 单设备推送（设备离线 -> 存离线消息）
 *   2. 单设备推送（设备在线 -> 入 Redis 队列，HTTP 上下文）
 *   3. 多设备推送
 *   4. Key 维度推送（无订阅 / 有订阅全离线）
 *   5. 离线消息存储与回放
 *
 * 注意：本测试在 HTTP 上下文（server=null）下运行，
 * 推送指令写入 Redis 队列而非直接投递 WebSocket。
 */
class PushDispatcherTest extends TestCase
{
    /**
     * 测试单设备推送 - 设备离线时存离线消息
     */
    public function testPushToDeviceOfflineStoresOfflineMessage(): void
    {
        $dispatcher = new PushDispatcher();

        $message = [
            'title'   => '测试消息',
            'content' => '这是一条测试推送',
        ];

        // 设备无在线连接 -> 离线
        $result = $dispatcher->pushToDevice('offline_device_001', $message);

        $this->assertSame(0, $result['success_count'], '离线设备 success_count 应为 0');
        $this->assertSame(1, $result['fail_count'], '离线设备 fail_count 应为 1');
        $this->assertSame('offline', $result['detail'][0]['status'], '状态应为 offline');
        $this->assertSame('offline_device_001', $result['detail'][0]['device_id']);
        $this->assertArrayHasKey('message_id', $message, '消息应自动补充 message_id');
    }

    /**
     * 测试单设备推送 - 设备在线时入 Redis 推送队列
     */
    public function testPushToDeviceOnlineEnqueuesToRedis(): void
    {
        $dispatcher = new PushDispatcher();
        $redis = Redis::getInstance();

        // 模拟设备在线：在 Redis 中建立 device_id -> fd 映射
        $deviceId = 'online_device_001';
        $fd = 10001;
        $redis->sAdd("ws:device:{$deviceId}", (string)$fd);

        $message = [
            'title'   => '在线推送测试',
            'content' => '设备在线，应入队列',
        ];

        $result = $dispatcher->pushToDevice($deviceId, $message);

        // HTTP 上下文（server=null）-> 入队列，success_count = fd 数量
        $this->assertSame(1, $result['success_count'], '在线设备应入队 success_count=1');
        $this->assertSame(0, $result['fail_count'], '在线设备 fail_count 应为 0');
        $this->assertSame('queued', $result['detail'][0]['status'], '状态应为 queued');

        // 验证 Redis 推送队列中确实写入了指令
        $queueLen = $redis->lLen('push:queue');
        $this->assertGreaterThan(0, $queueLen, '推送队列应有待处理指令');

        // 清理
        $redis->del('push:queue');
        $redis->del("ws:device:{$deviceId}");
    }

    /**
     * 测试多设备推送
     */
    public function testPushToMultipleDevices(): void
    {
        $dispatcher = new PushDispatcher();

        $message = ['title' => '群发消息', 'content' => '多设备推送测试'];

        // 3 个离线设备 + 1 个空字符串（应被跳过）
        $deviceIds = ['multi_dev_001', 'multi_dev_002', 'multi_dev_003', ''];
        $result = $dispatcher->pushToDevices($deviceIds, $message);

        $this->assertSame(0, $result['success_count'], '3 个离线设备 success_count 应为 0');
        $this->assertSame(3, $result['fail_count'], '3 个离线设备 fail_count 应为 3');
        $this->assertCount(3, $result['detail'], '空字符串设备应被跳过，detail 应有 3 条');
    }

    /**
     * 测试多设备推送 - 混合在线与离线
     */
    public function testPushToMultipleDevicesMixed(): void
    {
        $dispatcher = new PushDispatcher();
        $redis = Redis::getInstance();

        // dev_online 有在线连接
        $redis->sAdd('ws:device:mixed_dev_online', '20001');
        // dev_offline 无在线连接

        $message = ['title' => '混合推送'];
        $result = $dispatcher->pushToDevices(['mixed_dev_online', 'mixed_dev_offline'], $message);

        // 1 个在线（入队 success=1）+ 1 个离线（fail=1）
        $this->assertSame(1, $result['success_count'], '在线设备 success_count 应为 1');
        $this->assertSame(1, $result['fail_count'], '离线设备 fail_count 应为 1');
        $this->assertCount(2, $result['detail'], '应有 2 条详情');

        // 清理
        $redis->del('ws:device:mixed_dev_online');
        $redis->del('push:queue');
    }

    /**
     * 测试 Key 维度推送 - 无订阅设备
     */
    public function testPushByKeyNoSubscribers(): void
    {
        $dispatcher = new PushDispatcher();

        $message = ['title' => 'Key推送', 'content' => '无订阅测试'];

        // 无任何设备订阅该 Key
        $result = $dispatcher->pushByKey('no_sub_key_001', $message);

        $this->assertSame(0, $result['success_count'], '无订阅 success_count 应为 0');
        $this->assertSame(0, $result['fail_count'], '无订阅 fail_count 应为 0');
        $this->assertSame('no_subscribers', $result['detail'][0]['status'], '状态应为 no_subscribers');
        $this->assertSame('no_sub_key_001', $result['detail'][0]['key']);
    }

    /**
     * 测试 Key 维度推送 - 有订阅但全离线
     */
    public function testPushByKeyAllOffline(): void
    {
        $dispatcher = new PushDispatcher();
        $redis = Redis::getInstance();

        // 模拟两个设备订阅了该 Key，但都无在线连接
        $keyValue = 'test_key_001';
        $redis->sAdd("key:subscribe:{$keyValue}", 'key_dev_001');
        $redis->sAdd("key:subscribe:{$keyValue}", 'key_dev_002');

        $message = ['title' => 'Key推送', 'content' => '全离线测试'];
        $result = $dispatcher->pushByKey($keyValue, $message);

        $this->assertSame(0, $result['success_count'], '全离线 success_count 应为 0');
        $this->assertSame(2, $result['fail_count'], '2 个离线设备 fail_count 应为 2');
        $this->assertSame('all_offline', $result['detail'][0]['status'], '状态应为 all_offline');
        $this->assertSame(2, $result['detail'][0]['count'], '离线设备数应为 2');

        // 清理
        $redis->del("key:subscribe:{$keyValue}");
        $redis->del('offline:key_dev_001');
        $redis->del('offline:key_dev_002');
    }

    /**
     * 测试 Key 维度推送 - 有在线设备
     */
    public function testPushByKeyWithOnlineDevice(): void
    {
        $dispatcher = new PushDispatcher();
        $redis = Redis::getInstance();

        $keyValue = 'test_key_002';
        $deviceId = 'key_online_dev_001';
        $fd = 30001;

        // 模拟设备订阅 Key 且在线
        $redis->sAdd("key:subscribe:{$keyValue}", $deviceId);
        $redis->sAdd("ws:device:{$deviceId}", (string)$fd);

        $message = ['title' => 'Key在线推送'];
        $result = $dispatcher->pushByKey($keyValue, $message);

        // HTTP 上下文 -> 入队列
        $this->assertGreaterThan(0, $result['success_count'], '有在线设备应入队 success>0');
        $this->assertSame(0, $result['fail_count'], '在线设备 fail_count 应为 0');

        // 清理
        $redis->del("key:subscribe:{$keyValue}");
        $redis->del("ws:device:{$deviceId}");
        $redis->del('push:queue');
    }

    /**
     * 测试离线消息存储与回放
     *
     * 存储多条离线消息后，getOfflineMessages 应返回全部并清空。
     */
    public function testOfflineMessageStoreAndReplay(): void
    {
        $dispatcher = new PushDispatcher();
        $deviceId = 'replay_dev_001';

        // 存储 3 条离线消息
        $messages = [
            ['title' => '消息1', 'content' => '第一条'],
            ['title' => '消息2', 'content' => '第二条'],
            ['title' => '消息3', 'content' => '第三条'],
        ];

        foreach ($messages as $msg) {
            $dispatcher->storeOfflineMessage($deviceId, $msg);
        }

        // 回放：获取并清除离线消息
        $replayed = $dispatcher->getOfflineMessages($deviceId);

        $this->assertCount(3, $replayed, '应回放 3 条离线消息');

        // 验证消息内容（lPush + rPop = 先进先出）
        $this->assertSame('消息1', $replayed[0]['title'], '第一条应为先进先出');
        $this->assertSame('消息2', $replayed[1]['title']);
        $this->assertSame('消息3', $replayed[2]['title']);

        // 再次获取应为空（已被消费）
        $secondReplay = $dispatcher->getOfflineMessages($deviceId);
        $this->assertEmpty($secondReplay, '离线消息已被消费，再次获取应为空');

        // 清理
        Redis::getInstance()->del("offline:{$deviceId}");
    }

    /**
     * 测试离线消息 TTL 过期
     *
     * 存储离线消息后 TTL 应从环境变量 OFFLINE_MESSAGE_TTL 读取。
     */
    public function testOfflineMessageTtlFromEnv(): void
    {
        $dispatcher = new PushDispatcher();
        $redis = Redis::getInstance();

        $deviceId = 'ttl_dev_001';
        $dispatcher->storeOfflineMessage($deviceId, ['title' => 'TTL测试']);

        // TTL 应与环境变量一致
        $expectedTtl = (int)Config::env('OFFLINE_MESSAGE_TTL', 86400);
        $actualTtl = $redis->ttl("offline:{$deviceId}");

        $this->assertGreaterThan(0, $actualTtl, '离线消息应有 TTL');
        $this->assertLessThanOrEqual($expectedTtl, $actualTtl, 'TTL 不应超过设定值');
        $this->assertGreaterThanOrEqual($expectedTtl - 5, $actualTtl, 'TTL 应接近设定值');

        // 清理
        $redis->del("offline:{$deviceId}");
    }

    /**
     * 测试断开连接指令入队
     */
    public function testEnqueueDisconnect(): void
    {
        $dispatcher = new PushDispatcher();
        $redis = Redis::getInstance();

        $dispatcher->enqueueDisconnect('device_id', 'disconnect_dev_001');

        // 验证断开连接队列
        $queueLen = $redis->lLen('ws:command:disconnect');
        $this->assertGreaterThan(0, $queueLen, '断开连接队列应有指令');

        // 清理
        $redis->del('ws:command:disconnect');
    }
}
