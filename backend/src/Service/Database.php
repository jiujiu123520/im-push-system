<?php
declare(strict_types=1);

namespace App\Service;

use PDO;
use PDOException;

/**
 * 数据库单例服务
 *
 * 基于 PDO MySQL，提供应用内全局唯一的数据库连接。
 * 使用 config/database.php 配置。
 */
class Database
{
    /**
     * @var PDO|null PDO 实例缓存
     */
    private static ?PDO $instance = null;

    /**
     * 获取 PDO 单例
     *
     * @return PDO
     * @throws PDOException
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }

    /**
     * 创建一个新的 PDO 连接
     *
     * @return PDO
     * @throws PDOException
     */
    private static function createConnection(): PDO
    {
        $config = Config::get('database');
        if ($config === null) {
            throw new PDOException('数据库配置加载失败');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
        } catch (PDOException $e) {
            // 抛出带有可读信息的异常
            throw new PDOException('数据库连接失败：' . $e->getMessage(), (int)$e->getCode(), $e);
        }

        return $pdo;
    }

    /**
     * 重新建立连接（用于子进程或长连接断开时）
     *
     * @return PDO
     * @throws PDOException
     */
    public static function reconnect(): PDO
    {
        self::$instance = self::createConnection();
        return self::$instance;
    }

    /**
     * 获取 PDO 实例（getInstance 的别名，供需要直接操作 PDO 的场景使用）
     *
     * @return PDO
     */
    public static function pdo(): PDO
    {
        return self::getInstance();
    }

    /**
     * 判断异常是否为连接断开类型
     *
     * Swoole 常驻进程下，MySQL 连接长时间空闲会被服务端断开，
     * 下次查询时 PDO 会抛出 "server has gone away" / "Broken pipe" 等错误。
     *
     * @param \Throwable $e
     * @return bool
     */
    private static function isConnectionLost(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        foreach (['server has gone away', 'Broken pipe', 'Lost connection', 'Error while sending', 'deadlock'] as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }
        // MySQL 2006 = CR_SERVER_GONE_ERROR
        $code = (int)($e->getCode());
        return $code === 2006 || $code === 2013;
    }

    /**
     * 执行查询并返回预处理语句
     *
     * 在 Swoole 常驻进程模式下，MySQL 连接可能因 wait_timeout 被服务端断开，
     * 此方法会自动检测连接断开并重试一次。
     *
     * @param string $sql SQL 语句
     * @param array $params 绑定参数
     * @return \PDOStatement
     * @throws PDOException
     */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        try {
            $stmt = self::getInstance()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // 连接断开：重连后重试一次
            if (self::isConnectionLost($e)) {
                self::reconnect();
                $stmt = self::getInstance()->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            }
            throw $e;
        }
    }

    /**
     * 查询单行
     *
     * @param string $sql
     * @param array $params
     * @return array|false
     */
    public static function fetch(string $sql, array $params = [])
    {
        return self::query($sql, $params)->fetch();
    }

    /**
     * 查询多行
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * 插入并返回 lastInsertId
     *
     * @param string $sql
     * @param array $params
     * @return string
     */
    public static function insert(string $sql, array $params = []): string
    {
        self::query($sql, $params);
        return self::getInstance()->lastInsertId();
    }

    /**
     * 执行写操作并返回影响行数
     *
     * @param string $sql
     * @param array $params
     * @return int
     */
    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }
}
