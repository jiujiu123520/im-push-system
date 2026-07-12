<?php
declare(strict_types=1);

namespace Tests;

require_once __DIR__ . '/TestCase.php';

use App\Service\BlacklistService;
use App\Service\Database;

/**
 * 黑名单测试
 *
 * 测试场景：
 *   1. 加入黑名单
 *   2. 黑名单检查（存在 / 不存在）
 *   3. 移出黑名单
 *   4. 重复加入（幂等性）
 *   5. 分页查询
 */
class BlacklistTest extends TestCase
{
    /**
     * 测试加入黑名单
     */
    public function testAddToBlacklist(): void
    {
        $service = new BlacklistService();

        $id = $service->add('device_id', 'blacklisted_dev_001', '测试拉黑原因', 1);

        $this->assertGreaterThan(0, $id, '加入黑名单应返回正整数 ID');

        // 验证记录已写入数据库
        $record = $service->getById($id);
        $this->assertNotNull($record, '通过 ID 应能查到记录');
        $this->assertSame('device_id', $record['type']);
        $this->assertSame('blacklisted_dev_001', $record['value']);
        $this->assertSame('测试拉黑原因', $record['reason']);
        $this->assertSame(1, (int)$record['admin_id']);
    }

    /**
     * 测试加入黑名单 - 不同类型（ip / fingerprint）
     */
    public function testAddBlacklistDifferentTypes(): void
    {
        $service = new BlacklistService();

        // IP 类型
        $ipId = $service->add('ip', '192.168.1.100', '异常IP', 1);
        $this->assertGreaterThan(0, $ipId);

        $ipRecord = $service->getById($ipId);
        $this->assertSame('ip', $ipRecord['type']);
        $this->assertSame('192.168.1.100', $ipRecord['value']);

        // fingerprint 类型
        $fpId = $service->add('fingerprint', 'fp_abc123def456', '设备指纹异常', 1);
        $this->assertGreaterThan(0, $fpId);

        $fpRecord = $service->getById($fpId);
        $this->assertSame('fingerprint', $fpRecord['type']);
        $this->assertSame('fp_abc123def456', $fpRecord['value']);
    }

    /**
     * 测试黑名单检查 - 已在黑名单中
     */
    public function testCheckBlacklisted(): void
    {
        $service = new BlacklistService();
        $service->add('device_id', 'check_dev_001', '拉黑测试', 1);

        $isBlacklisted = $service->check('device_id', 'check_dev_001');

        $this->assertTrue($isBlacklisted, '已加入黑名单的设备应返回 true');
    }

    /**
     * 测试黑名单检查 - 不在黑名单中
     */
    public function testCheckNotBlacklisted(): void
    {
        $service = new BlacklistService();

        $isBlacklisted = $service->check('device_id', 'not_blacklisted_dev');

        $this->assertFalse($isBlacklisted, '未加入黑名单的设备应返回 false');
    }

    /**
     * 测试黑名单检查 - 同值不同类型不匹配
     */
    public function testCheckDifferentTypeDoesNotMatch(): void
    {
        $service = new BlacklistService();
        // 以 device_id 类型加入
        $service->add('device_id', 'shared_value_001', '测试', 1);

        // 以 ip 类型检查同值，应不在黑名单
        $result = $service->check('ip', 'shared_value_001');
        $this->assertFalse($result, '不同类型不应匹配');
    }

    /**
     * 测试移出黑名单
     */
    public function testRemoveFromBlacklist(): void
    {
        $service = new BlacklistService();

        // 先加入
        $id = $service->add('device_id', 'remove_dev_001', '待移除', 1);
        $this->assertTrue($service->check('device_id', 'remove_dev_001'), '加入后应在黑名单中');

        // 移出
        $result = $service->remove($id);
        $this->assertTrue($result, '移出应返回 true');

        // 验证已不在黑名单
        $this->assertFalse($service->check('device_id', 'remove_dev_001'), '移出后应不在黑名单中');

        // getById 应返回 null
        $this->assertNull($service->getById($id), '移出后 getById 应返回 null');
    }

    /**
     * 测试移出黑名单 - 不存在的记录
     */
    public function testRemoveNonExistentBlacklist(): void
    {
        $service = new BlacklistService();

        $result = $service->remove(99999);
        $this->assertFalse($result, '移出不存在的记录应返回 false');
    }

    /**
     * 测试重复加入黑名单 - 幂等性
     *
     * 同一 type + value 重复加入应返回已有 ID，不创建新记录。
     */
    public function testDuplicateAddReturnsSameId(): void
    {
        $service = new BlacklistService();

        $id1 = $service->add('device_id', 'dup_blacklist_dev', '第一次拉黑', 1);
        $id2 = $service->add('device_id', 'dup_blacklist_dev', '第二次拉黑', 1);

        $this->assertSame($id1, $id2, '重复加入应返回相同的 ID');

        // 验证数据库中只有一条记录
        $count = Database::fetch(
            'SELECT COUNT(*) AS cnt FROM blacklists WHERE type = ? AND value = ?',
            ['device_id', 'dup_blacklist_dev']
        );
        $this->assertSame(1, (int)$count['cnt'], '数据库中应只有一条记录');
    }

    /**
     * 测试黑名单分页查询
     */
    public function testListBlacklistWithPagination(): void
    {
        $service = new BlacklistService();

        // 加入 5 条记录
        for ($i = 1; $i <= 5; $i++) {
            $service->add('device_id', "page_dev_{$i}", "分页测试{$i}", 1);
        }

        // 查询第 1 页
        $result = $service->list(1, '');

        $this->assertSame(1, $result['page'], '当前页码应为 1');
        $this->assertGreaterThanOrEqual(5, $result['total'], '总数应 >= 5');
        $this->assertSame(10, $result['per_page'], '每页应为 10 条');
        $this->assertNotEmpty($result['list'], '列表不应为空');
    }

    /**
     * 测试黑名单分页查询 - 关键词搜索
     */
    public function testListBlacklistWithKeyword(): void
    {
        $service = new BlacklistService();

        $service->add('device_id', 'keyword_dev_001', '搜索测试AAA', 1);
        $service->add('device_id', 'keyword_dev_002', '搜索测试BBB', 1);
        $service->add('ip', '192.168.99.99', '其他记录', 1);

        // 搜索 "AAA"
        $result = $service->list(1, 'AAA');
        $this->assertGreaterThanOrEqual(1, $result['total'], '搜索 AAA 应至少有 1 条');

        // 搜索 "keyword_dev"
        $result2 = $service->list(1, 'keyword_dev');
        $this->assertGreaterThanOrEqual(2, $result2['total'], '搜索 keyword_dev 应至少有 2 条');
    }

    /**
     * 测试完整流程：加入 -> 检查 -> 移出
     */
    public function testFullBlacklistLifecycle(): void
    {
        $service = new BlacklistService();
        $type = 'device_id';
        $value = 'lifecycle_dev_001';

        // 1. 初始不在黑名单
        $this->assertFalse($service->check($type, $value), '初始应不在黑名单');

        // 2. 加入黑名单
        $id = $service->add($type, $value, '生命周期测试', 1);
        $this->assertGreaterThan(0, $id);

        // 3. 检查在黑名单
        $this->assertTrue($service->check($type, $value), '加入后应在黑名单');

        // 4. 移出黑名单
        $this->assertTrue($service->remove($id), '移出应成功');

        // 5. 检查不在黑名单
        $this->assertFalse($service->check($type, $value), '移出后应不在黑名单');

        // 6. 再次加入
        $id2 = $service->add($type, $value, '重新拉黑', 1);
        $this->assertGreaterThan(0, $id2, '再次加入应成功');
        $this->assertTrue($service->check($type, $value), '再次加入后应在黑名单');
    }
}
