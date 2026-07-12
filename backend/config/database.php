<?php
/**
 * 数据库配置（PDO MySQL）
 *
 * 从环境变量读取连接参数，由 Service\Database 单例使用。
 */

return [
    // 数据库主机地址
    'host'      => \App\Service\Config::env('DB_HOST', '127.0.0.1'),
    // 数据库端口
    'port'      => (int)\App\Service\Config::env('DB_PORT', 3306),
    // 数据库名
    'database'  => \App\Service\Config::env('DB_NAME', 'im_push'),
    // 用户名
    'username'  => \App\Service\Config::env('DB_USER', 'root'),
    // 密码
    'password'  => \App\Service\Config::env('DB_PASS', ''),
    // 字符集
    'charset'   => 'utf8mb4',
    // 表前缀
    'prefix'    => '',
    // PDO 选项
    'options'   => [
        // 异常模式：出错抛异常
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        // 关联数组返回
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // 关闭预处理模拟，使用真正的预处理
        PDO::ATTR_EMULATE_PREPARES   => false,
        // 持久连接
        PDO::ATTR_PERSISTENT         => false,
    ],
];
