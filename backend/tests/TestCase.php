<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use App\Service\Config;
use App\Service\Database;
use App\Service\Redis;
use App\Service\Aes;

/**
 * 测试基类
 *
 * 职责：
 *   - 加载 Composer 自动加载与环境变量
 *   - 初始化数据库与 Redis 连接（连接不可用时自动跳过测试）
 *   - 每个测试方法执行前清空数据表与 Redis 缓存，保证测试隔离
 *   - 提供验证码注入等通用辅助方法
 *
 * 注意：测试需要 MySQL 与 Redis 服务运行中，且已执行 001_init.sql 建表。
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * 每个测试前需要清空的数据表列表
     */
    protected array $truncateTables = [
        'users',
        'admins',
        'push_keys',
        'devices',
        'messages',
        'blacklists',
        'push_logs',
        'admin_logs',
        'api_keys',
    ];

    /**
     * 测试前置：加载环境、清理数据
     */
    protected function setUp(): void
    {
        // 1. 确保测试所需环境变量存在（不会被 .env 覆盖）
        $this->ensureTestEnv();

        // 2. 加载 .env 环境变量（Dotenv 不可变模式，不覆盖已设置的变量）
        Config::loadEnv();

        // 3. 初始化数据库连接并清表（不可用则跳过测试）
        try {
            $this->cleanDatabase();
        } catch (\Throwable $e) {
            $this->markTestSkipped('数据库不可用，跳过测试：' . $e->getMessage());
            return;
        }

        // 4. 初始化 Redis 连接并清缓存（不可用则跳过测试）
        try {
            $this->cleanRedis();
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis 不可用，跳过测试：' . $e->getMessage());
            return;
        }

        // 5. 初始化默认超级管理员（admin / admin123）
        $this->seedDefaultAdmin();
    }

    /**
     * 确保测试所需的环境变量存在（仅在未设置时填充默认值）
     */
    protected function ensureTestEnv(): void
    {
        $defaults = [
            'AES_KEY'              => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            'JWT_SECRET'           => 'test_jwt_secret_key_for_phpunit',
            'JWT_EXPIRE'           => '7200',
            'OFFLINE_MESSAGE_TTL'  => '86400',
        ];
        foreach ($defaults as $key => $value) {
            $current = getenv($key);
            if ($current === false || $current === '') {
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * 清空数据库表（关闭外键检查后批量 TRUNCATE）
     */
    protected function cleanDatabase(): void
    {
        $pdo = Database::getInstance();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->truncateTables as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * 清空 Redis 中带前缀的测试数据
     *
     * predis 客户端配置了 prefix='im_push:'，调用 keys('*') 实际发送
     * KEYS im_push:* 并返回带前缀的完整 key。删除时需先去除前缀，
     * 让 predis 在 del 时重新添加前缀。
     */
    protected function cleanRedis(): void
    {
        $redis = Redis::getInstance();
        $prefix = 'im_push:';
        $prefixLen = strlen($prefix);

        // 获取所有带前缀的 key
        $keys = $redis->keys('*');
        if (empty($keys)) {
            return;
        }

        // 去除 predis 返回的 key 中的前缀
        $strippedKeys = array_map(function (string $k) use ($prefixLen): string {
            return substr($k, $prefixLen);
        }, $keys);

        // 分批删除（避免参数过多）
        foreach (array_chunk($strippedKeys, 500) as $chunk) {
            $redis->del($chunk);
        }
    }

    /**
     * 初始化默认超级管理员账号
     *
     * username: admin
     * password: admin123
     * role:     super_admin
     */
    protected function seedDefaultAdmin(): void
    {
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $now = date('Y-m-d H:i:s');
        Database::query(
            'INSERT INTO admins (username, password_hash, role, status, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, ?)',
            ['admin', $hash, 'super_admin', $now, $now]
        );
    }

    // ================================================================
    // 验证码注入辅助方法
    // ================================================================

    /**
     * 注入短信验证码到 Redis（绕过实际发送，直接写入已知验证码）
     *
     * @param string $phone 手机号
     * @param string $code  验证码（默认 123456）
     * @return void
     */
    protected function injectSmsCode(string $phone, string $code = '123456'): void
    {
        $this->injectCode('sms', $phone, $code);
    }

    /**
     * 注入邮箱验证码到 Redis
     *
     * @param string $email 邮箱
     * @param string $code   验证码（默认 123456）
     * @return void
     */
    protected function injectEmailCode(string $email, string $code = '123456'): void
    {
        $this->injectCode('email', $email, $code);
    }

    /**
     * 注入验证码到 Redis（使用与 CaptchaService 相同的存储格式）
     *
     * 存储格式：AES 加密后的 JSON {code, expire}
     *
     * @param string $type   类型：sms / email
     * @param string $target 手机号或邮箱
     * @param string $code   验证码
     * @return void
     */
    private function injectCode(string $type, string $target, string $code): void
    {
        $plain = json_encode([
            'code'   => $code,
            'expire' => time() + 300,
        ], JSON_UNESCAPED_UNICODE);
        $encrypted = Aes::encryptString($plain);
        Redis::setex("captcha:{$type}:{$target}", 300, $encrypted);
    }

    /**
     * 注入图形验证码到 Redis，返回 token 与验证码
     *
     * @param string $code 验证码明文（默认 TEST）
     * @return array ['token' => string, 'code' => string]
     */
    protected function injectImageCaptcha(string $code = 'TEST'): array
    {
        $plain = json_encode([
            'code'   => $code,
            'expire' => time() + 300,
        ], JSON_UNESCAPED_UNICODE);
        $token = Aes::encryptString($plain);
        Redis::setex('captcha:image:' . $token, 300, $token);
        return ['token' => $token, 'code' => $code];
    }

    // ================================================================
    // 连接获取辅助方法
    // ================================================================

    /**
     * 获取 PDO 数据库连接
     *
     * @return \PDO
     */
    protected function pdo(): \PDO
    {
        return Database::getInstance();
    }

    /**
     * 获取 Redis 客户端
     *
     * @return \Predis\Client
     */
    protected function redis(): \Predis\Client
    {
        return Redis::getInstance();
    }
}
