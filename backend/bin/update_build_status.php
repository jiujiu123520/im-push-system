#!/usr/bin/env php
<?php
/**
 * 更新构建状态 CLI 脚本
 *
 * 由 GitHub Actions Runner 通过 SSH 调用,更新 Redis 中的构建状态。
 *
 * 用法:
 *   php bin/update_build_status.php \
 *     --build-id "b610xxx" \
 *     --status "success" \
 *     --message "构建成功" \
 *     --apk-path "/www/push-system/build/output/b610xxx/app-release.apk"
 *
 * 状态值: success / failed
 */

declare(strict_types=1);

// 项目根目录
$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/backend/vendor/autoload.php';

// 加载 .env
\App\Service\Config::loadEnv();

use App\Service\Redis;

// 解析命令行参数
$options = getopt('', ['build-id:', 'status:', 'message:', 'apk-path::']);
$buildId = $options['build-id'] ?? '';
$status = $options['status'] ?? 'failed';
$message = $options['message'] ?? '';
$apkPath = $options['apk-path'] ?? '';

if (empty($buildId)) {
    fwrite(STDERR, "错误: --build-id 参数必填\n");
    exit(1);
}

// 状态映射(GitHub Actions → Redis task status)
$statusMap = [
    'success' => 'success',
    'failed' => 'failed',
    'failure' => 'failed',
    'cancelled' => 'failed',
    'canceled' => 'failed',
];
$redisStatus = $statusMap[$status] ?? 'failed';

$now = date('Y-m-d H:i:s');
$taskKey = 'build:task:' . $buildId;

try {
    $redis = Redis::getInstance();

    // 检查 task 是否存在
    if (!$redis->exists($taskKey)) {
        fwrite(STDERR, "警告: 任务 {$buildId} 不存在于 Redis\n");
        // 仍然创建一条记录,便于查询
        $redis->hset($taskKey, 'build_id', $buildId);
        $redis->hset($taskKey, 'app_name', '(未知)');
        $redis->zadd('build:tasks', time(), $buildId);
    }

    // 更新状态字段
    $redis->hset($taskKey, 'status', $redisStatus);
    $redis->hset($taskKey, 'result_message', $message);
    $redis->hset($taskKey, 'updated_at', $now);
    $redis->hset($taskKey, 'finished_at', $now);

    // 成功时填充 apk_path
    if ($redisStatus === 'success' && !empty($apkPath)) {
        $redis->hset($taskKey, 'apk_path', $apkPath);
    }

    // 输出结果
    echo "已更新构建状态: build_id={$buildId} status={$redisStatus} message={$message}\n";
    exit(0);

} catch (\Throwable $e) {
    fwrite(STDERR, "错误: 更新构建状态失败: " . $e->getMessage() . "\n");
    exit(1);
}
