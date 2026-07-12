<?php
declare(strict_types=1);

namespace App\Service;

use Predis\Client;
use Predis\Connection\ConnectionException;

/**
 * Redis 单例服务
 *
 * 基于 predis/predis，提供应用内全局唯一的 Redis 客户端。
 * 使用 config/redis.php 配置。
 */
class Redis
{
    /**
     * @var Client|null Predis 客户端实例缓存
     */
    private static ?Client $instance = null;

    /**
     * 获取 Predis 客户端单例
     *
     * @return Client
     * @throws ConnectionException
     */
    public static function getInstance(): Client
    {
        if (self::$instance === null) {
            self::$instance = self::createClient();
        }
        return self::$instance;
    }

    /**
     * 创建 Predis 客户端
     *
     * @return Client
     */
    private static function createClient(): Client
    {
        $config = Config::get('redis');
        if ($config === null) {
            throw new \RuntimeException('Redis 配置加载失败');
        }

        $parameters = [
            'scheme'   => 'tcp',
            'host'     => $config['host'],
            'port'     => $config['port'],
            'database' => $config['database'],
            'timeout'  => $config['timeout'],
        ];
        if (!empty($config['password'])) {
            $parameters['password'] = $config['password'];
        }

        $options = [];
        if (!empty($config['prefix'])) {
            $options['prefix'] = $config['prefix'];
        }

        return new Client($parameters, $options);
    }

    /**
     * 重新建立连接
     *
     * @return Client
     */
    public static function reconnect(): Client
    {
        self::$instance = self::createClient();
        return self::$instance;
    }

    /**
     * 静态代理：调用 Predis 客户端方法
     *
     * @param string $method 方法名
     * @param array $args 参数
     * @return mixed
     */
    public static function __callStatic(string $method, array $args)
    {
        return self::getInstance()->{$method}(...$args);
    }
}
