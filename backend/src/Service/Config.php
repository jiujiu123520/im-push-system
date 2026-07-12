<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 配置加载器
 *
 * 负责加载 .env 环境变量以及 config/ 目录下的 PHP 配置文件。
 * 使用 vlucas/phpdotenv 解析 .env，配置文件以数组形式返回。
 */
class Config
{
    /**
     * @var array 已加载的配置缓存（按文件名分组）
     */
    private static array $configCache = [];

    /**
     * @var bool 标记 .env 是否已加载
     */
    private static bool $envLoaded = false;

    /**
     * 加载 .env 文件到环境变量
     *
     * @param string|null $envFile .env 文件绝对路径，默认 backend 根目录
     * @return void
     */
    public static function loadEnv(?string $envFile = null): void
    {
        if (self::$envLoaded) {
            return;
        }
        // 默认指向 backend 根目录下的 .env
        if ($envFile === null) {
            $envFile = dirname(__DIR__, 2) . '/.env';
        }
        if (file_exists($envFile)) {
            $dotenv = \Dotenv\Dotenv::createImmutable(dirname($envFile), basename($envFile));
            $dotenv->load();
        }
        self::$envLoaded = true;
    }

    /**
     * 获取 config/ 目录下指定配置文件的某项配置
     *
     * @param string $name    配置文件名（不含 .php 后缀），如 database、redis
     * @param string|null $key 配置键（支持点号分隔多级，如 "options.1"），为空则返回整个文件
     * @param mixed $default 默认值
     * @return mixed
     */
    public static function get(string $name, ?string $key = null, $default = null)
    {
        if (!isset(self::$configCache[$name])) {
            $file = dirname(__DIR__, 2) . '/config/' . $name . '.php';
            if (!file_exists($file)) {
                return $default;
            }
            self::$configCache[$name] = require $file;
        }

        $config = self::$configCache[$name];
        if ($key === null) {
            return $config;
        }

        // 支持点号取多级
        foreach (explode('.', $key) as $segment) {
            if (!is_array($config) || !array_key_exists($segment, $config)) {
                return $default;
            }
            $config = $config[$segment];
        }
        return $config;
    }

    /**
     * 获取环境变量
     *
     * @param string $key 环境变量名
     * @param mixed $default 默认值
     * @return string|mixed
     */
    public static function env(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return $value;
    }

    /**
     * 获取环境变量并转为整数
     */
    public static function envInt(string $key, int $default = 0): int
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }
        return (int)$value;
    }
}
