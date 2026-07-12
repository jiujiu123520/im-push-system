<?php
/**
 * Redis 配置
 *
 * 从环境变量读取连接参数，由 Service\Redis 单例使用。
 */

return [
    // Redis 主机地址
    'host'     => getenv('REDIS_HOST') ?: '127.0.0.1',
    // Redis 端口
    'port'     => (int)(getenv('REDIS_PORT') ?: 6379),
    // 密码（为空则不认证）
    'password' => getenv('REDIS_PASSWORD') ?: null,
    // 选择的数据库
    'database' => (int)(getenv('REDIS_DB') ?: 0),
    // 连接超时（秒）
    'timeout'  => 2.5,
    // 前缀，便于区分不同环境
    'prefix'   => 'im_push:',
];
