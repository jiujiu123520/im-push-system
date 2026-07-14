<?php
declare(strict_types=1);

namespace BuildServer;

use App\Service\Redis;

/**
 * APK 打包任务队列
 *
 * 基于 Redis List 实现的打包任务队列：
 *  - 队列：build:queue（Redis List，RPUSH 入队 / LPOP 出队，FIFO）
 *  - 任务详情：build:task:{build_id}（Redis Hash）
 *  - 任务索引：build:tasks（Sorted Set，score=提交时间戳，便于按时间倒序分页）
 *
 * 任务状态流转：pending -> processing -> success / failed
 */
class BuildQueue
{
    /** 队列名（Redis List） */
    public const QUEUE_KEY = 'build:queue';
    /** 任务哈希前缀 */
    public const TASK_PREFIX = 'build:task:';
    /** 任务索引（Sorted Set） */
    public const INDEX_KEY = 'build:tasks';
    /** 单页条数 */
    public const PAGE_SIZE = 10;
    /** 构建超时时间（秒）—— 首次构建需下载 Gradle 依赖，设为 2 小时 */
    public const BUILD_TIMEOUT = 7200;

    /**
     * 获取项目根目录
     *
     * @return string
     */
    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * 获取 build 目录
     *
     * @return string
     */
    private static function buildDir(): string
    {
        return dirname(__DIR__);
    }

    /**
     * 批量写入 Hash 字段（兼容所有 predis 版本）
     *
     * @param string $key
     * @param array $fields
     * @return void
     */
    private static function hsetAll(string $key, array $fields): void
    {
        $redis = Redis::getInstance();
        foreach ($fields as $field => $value) {
            $redis->hset($key, (string)$field, (string)$value);
        }
    }

    /**
     * 检测系统命令是否存在
     *
     * @param string $cmd
     * @return bool
     */
    private static function commandExists(string $cmd): bool
    {
        $result = trim((string)shell_exec("command -v $cmd 2>/dev/null"));
        return $result !== '';
    }

    /**
     * 提交打包任务到队列
     *
     * @param array $config {
     *     app_name: string, default_key: string, server_url: string,
     *     ws_url: string, icon_path: string, admin_id: int
     * }
     * @return string build_id
     */
    public static function submitBuild(array $config): string
    {
        // 生成唯一 build_id
        $buildId = 'b' . uniqid() . sprintf('%03d', mt_rand(0, 999));
        $now = date('Y-m-d H:i:s');

        $task = [
            'build_id'       => $buildId,
            'app_name'       => $config['app_name'] ?? 'PushApp',
            'default_key'    => $config['default_key'] ?? 'default_key',
            'server_url'     => $config['server_url'] ?? '',
            'ws_url'         => $config['ws_url'] ?? '',
            'icon_path'      => $config['icon_path'] ?? '',
            'package_name'   => $config['package_name'] ?? '',
            'admin_id'       => (string)($config['admin_id'] ?? 0),
            'status'         => 'pending',
            'apk_path'       => '',
            'result_message' => '',
            'created_at'     => $now,
            'updated_at'     => $now,
            'started_at'     => '',
            'finished_at'    => '',
        ];

        $redis = Redis::getInstance();

        // 1. 存储任务详情
        self::hsetAll(self::TASK_PREFIX . $buildId, $task);

        // 2. 加入索引（score=时间戳）
        $redis->zadd(self::INDEX_KEY, time(), $buildId);

        // 3. 入队（尾部），FIFO
        $redis->rpush(self::QUEUE_KEY, json_encode([
            'build_id' => $buildId,
            'config'   => $config,
        ], JSON_UNESCAPED_UNICODE));

        return $buildId;
    }

    /**
     * 处理队列中的一个任务
     *
     * 从队列 LPOP 取出任务，调用 build_apk.sh 执行打包，更新任务状态。
     *
     * @return array|null 处理结果，无任务时返回 null
     */
    public function processQueue(): ?array
    {
        $redis = Redis::getInstance();

        // 非阻塞取出队首任务
        $raw = $redis->lpop(self::QUEUE_KEY);
        if (!$raw) {
            return null;
        }

        $payload = json_decode($raw, true) ?: [];
        $buildId = $payload['build_id'] ?? '';
        $config = $payload['config'] ?? [];

        if (!$buildId) {
            return ['build_id' => '', 'status' => 'failed', 'message' => '无效的任务数据'];
        }

        // 标记为处理中
        $now = date('Y-m-d H:i:s');
        self::hsetAll(self::TASK_PREFIX . $buildId, [
            'status'      => 'processing',
            'started_at'  => $now,
            'updated_at'  => $now,
        ]);

        // 准备日志与输出目录
        $buildDir = self::buildDir();
        @mkdir($buildDir . '/logs', 0755, true);
        @mkdir($buildDir . '/output/' . $buildId, 0755, true);
        $logFile = $buildDir . '/logs/' . $buildId . '.log';

        // 组装并执行构建命令
        $cmd = self::buildCommand($buildId, $config);
        file_put_contents($logFile, "[$now] 开始处理构建 $buildId\n执行命令: $cmd\n\n", FILE_APPEND);

        $exec = self::execBuild($cmd, $logFile);
        $exitCode = $exec['exit_code'];

        // 读取 build_apk.sh 写入的结果文件
        $result = self::readResult($buildId);
        $finishedAt = date('Y-m-d H:i:s');

        if ($exitCode === 0 && ($result['status'] ?? '') === 'success') {
            $apkPath = $result['apk_path'] ?? '';
            $message = $result['message'] ?? '构建成功';
            self::hsetAll(self::TASK_PREFIX . $buildId, [
                'status'         => 'success',
                'apk_path'       => $apkPath,
                'result_message' => $message,
                'finished_at'    => $finishedAt,
                'updated_at'     => $finishedAt,
            ]);
            file_put_contents($logFile, "[$finishedAt] 构建成功：$apkPath\n", FILE_APPEND);
            return [
                'build_id' => $buildId,
                'status'   => 'success',
                'apk_path' => $apkPath,
            ];
        }

        // 失败
        $message = $result['message'] ?? "构建失败（退出码 $exitCode）";
        if ($exitCode === 124) {
            $message = '构建超时（超过 ' . self::BUILD_TIMEOUT . ' 秒）';
        }
        self::hsetAll(self::TASK_PREFIX . $buildId, [
            'status'         => 'failed',
            'result_message' => $message,
            'finished_at'    => $finishedAt,
            'updated_at'     => $finishedAt,
        ]);
        file_put_contents($logFile, "[$finishedAt] 构建失败：$message\n", FILE_APPEND);

        return [
            'build_id' => $buildId,
            'status'   => 'failed',
            'message'  => $message,
        ];
    }

    /**
     * 组装 build_apk.sh 命令
     *
     * @param string $buildId
     * @param array $config
     * @return string
     */
    private static function buildCommand(string $buildId, array $config): string
    {
        $projectRoot = self::projectRoot();
        $appName = $config['app_name'] ?? 'PushApp';
        $defaultKey = $config['default_key'] ?? 'default_key';
        $serverUrl = $config['server_url'] ?? '';
        $wsUrl = $config['ws_url'] ?? '';
        $iconPath = $config['icon_path'] ?? '';
        $packageName = $config['package_name'] ?? '';

        $args = '--build-id ' . escapeshellarg($buildId);
        $args .= ' --app-name ' . escapeshellarg($appName);
        $args .= ' --default-key ' . escapeshellarg($defaultKey);
        $args .= ' --server-url ' . escapeshellarg($serverUrl);
        $args .= ' --ws-url ' . escapeshellarg($wsUrl);
        // 图标可选
        if ($iconPath !== '' && is_file($iconPath)) {
            $args .= ' --icon-path ' . escapeshellarg($iconPath);
        }
        // 包名可选
        if ($packageName !== '') {
            $args .= ' --package-name ' . escapeshellarg($packageName);
        }

        return sprintf('cd %s && bash build/build_apk.sh %s', escapeshellarg($projectRoot), $args);
    }

    /**
     * 执行构建命令并捕获退出码
     *
     * @param string $cmd 完整命令（输出重定向到日志文件）
     * @param string $logFile 日志文件路径
     * @return array{exit_code:int,output:string}
     */
    private static function execBuild(string $cmd, string $logFile): array
    {
        // 构建输出重定向到日志文件，命令尾部追加退出码标记
        // 使用多行脚本确保 echo 总能执行（即使构建被 timeout 杀死）
        $inner = $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1; EC=$?; echo "__EXIT__:${EC}"';

        // 用 timeout 包裹防止构建卡死（如存在 timeout 命令）
        $timeoutBin = self::commandExists('timeout') ? '/usr/bin/timeout ' . self::BUILD_TIMEOUT . ' ' : '';
        $wrapped = $timeoutBin . 'bash -c ' . escapeshellarg($inner);

        // shell_exec 捕获标准输出（仅含退出码标记）
        $output = (string)shell_exec($wrapped . ' 2>&1');

        $exitCode = 1;
        if (preg_match('/__EXIT__:(\d+)/', $output, $m)) {
            $exitCode = (int)$m[1];
        }

        // timeout 命令终止时退出码为 124
        if ($exitCode === 124) {
            $msg = '[超时] 构建超过 ' . self::BUILD_TIMEOUT . ' 秒被强制终止';
            file_put_contents($logFile, "\n" . date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
        }

        return ['exit_code' => $exitCode, 'output' => $output];
    }

    /**
     * 读取 build_apk.sh 写入的结果文件
     *
     * @param string $buildId
     * @return array
     */
    private static function readResult(string $buildId): array
    {
        $resultFile = self::buildDir() . '/output/' . $buildId . '/result.json';
        if (!is_file($resultFile)) {
            return [];
        }
        $content = (string)file_get_contents($resultFile);
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * 查询构建状态
     *
     * @param string $buildId
     * @return array|null
     */
    public static function getBuildStatus(string $buildId): ?array
    {
        $redis = Redis::getInstance();
        $exists = $redis->exists(self::TASK_PREFIX . $buildId);
        if (!$exists) {
            return null;
        }
        $task = $redis->hgetall(self::TASK_PREFIX . $buildId);
        return $task ?: null;
    }

    /**
     * 构建历史列表（分页）
     *
     * @param int $page 页码（从 1 开始）
     * @param string $keyword 搜索关键词（匹配 build_id 或 app_name）
     * @return array{list:array,total:int,page:int,page_size:int}
     */
    public static function getBuildList(int $page = 1, string $keyword = ''): array
    {
        $redis = Redis::getInstance();
        $page = max(1, $page);
        $pageSize = self::PAGE_SIZE;

        if ($keyword === '') {
            // 无关键词：直接按索引分页
            $total = (int)$redis->zcard(self::INDEX_KEY);
            $start = ($page - 1) * $pageSize;
            $stop = $start + $pageSize - 1;
            $buildIds = $redis->zrevrange(self::INDEX_KEY, $start, $stop) ?: [];
        } else {
            // 有关键词：加载全部，过滤后再分页
            $allIds = $redis->zrevrange(self::INDEX_KEY, 0, -1) ?: [];
            $matched = [];
            $kw = strtolower($keyword);
            foreach ($allIds as $bid) {
                $task = $redis->hgetall(self::TASK_PREFIX . $bid) ?: [];
                $haystack = strtolower(($task['build_id'] ?? '') . ' ' . ($task['app_name'] ?? ''));
                if (strpos($haystack, $kw) !== false) {
                    $matched[] = $bid;
                }
            }
            $total = count($matched);
            $start = ($page - 1) * $pageSize;
            $buildIds = array_slice($matched, $start, $pageSize);
        }

        $list = [];
        foreach ($buildIds as $bid) {
            $task = $redis->hgetall(self::TASK_PREFIX . $bid) ?: [];
            if ($task) {
                $list[] = $task;
            }
        }

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 获取构建日志
     *
     * @param string $buildId
     * @return string
     */
    public static function getBuildLog(string $buildId): string
    {
        $logFile = self::buildDir() . '/logs/' . $buildId . '.log';
        if (!is_file($logFile)) {
            return '';
        }
        return (string)file_get_contents($logFile);
    }

    /**
     * 获取下载链接
     *
     * 返回相对下载 URL 与 APK 文件绝对路径。
     *
     * @param string $buildId
     * @return array{url:string,path:string,exists:bool}|null
     */
    public static function getDownloadUrl(string $buildId): ?array
    {
        $task = self::getBuildStatus($buildId);
        if (!$task) {
            return null;
        }
        $apkPath = $task['apk_path'] ?? '';
        return [
            'url'     => '/admin/app-build/download/' . $buildId,
            'path'    => $apkPath,
            'exists'  => $apkPath !== '' && is_file($apkPath),
        ];
    }

    /**
     * 删除构建记录（从索引与任务详情移除，可选删除 APK）
     *
     * @param string $buildId
     * @return bool
     */
    public static function deleteBuild(string $buildId): bool
    {
        $redis = Redis::getInstance();
        $redis->del(self::TASK_PREFIX . $buildId);
        $redis->zrem(self::INDEX_KEY, $buildId);
        // 日志与产物保留，由运维清理
        return true;
    }
}
