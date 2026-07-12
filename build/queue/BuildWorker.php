<?php
declare(strict_types=1);

/**
 * APK 打包工作进程（PHP CLI 常驻）
 *
 * 循环监听 Redis 队列，收到任务后调用 BuildQueue::processQueue() 执行打包。
 * 支持 SIGTERM / SIGINT 优雅退出。
 *
 * 启动方式：
 *   php build/queue/BuildWorker.php
 *
 * 配合 supervisor 守护见 build/supervisor-build.conf
 */

// 1. 引入后端 Composer 自动加载（复用 App\Service\Redis 等服务）
$projectRoot = dirname(__DIR__, 2);
$autoload = $projectRoot . '/backend/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "[Worker] 未找到 backend/vendor/autoload.php，请先执行 composer install\n");
    exit(1);
}
require $autoload;

// 2. 引入 BuildQueue
require_once __DIR__ . '/BuildQueue.php';

use App\Service\Config;
use App\Service\Redis;
use BuildServer\BuildQueue;

// 3. 加载环境变量（读取 backend/.env 中的 Redis 配置）
Config::loadEnv();
// 建立首次 Redis 连接
Redis::reconnect();

// ----------------------------------------------------------------
// 工作进程
// ----------------------------------------------------------------
final class BuildWorker
{
    /** @var bool 是否收到退出信号 */
    private bool $shouldStop = false;

    /** @var string 日志文件 */
    private string $logFile;

    /** @var BuildQueue */
    private BuildQueue $queue;

    /**
     * 构造方法
     *
     * @param string $logFile
     */
    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
        $this->queue = new BuildQueue();
        $this->ensureLogDir();
    }

    /**
     * 确保日志目录存在
     *
     * @return void
     */
    private function ensureLogDir(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * 写日志
     *
     * @param string $message
     * @return void
     */
    public function log(string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        echo $line . "\n";
        file_put_contents($this->logFile, $line . "\n", FILE_APPEND);
    }

    /**
     * 注册信号处理器（优雅退出）
     *
     * @return void
     */
    private function registerSignals(): void
    {
        // SIGTERM / SIGINT 触发优雅退出
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->log('收到 SIGTERM，准备优雅退出 ...');
            $this->shouldStop = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->log('收到 SIGINT，准备优雅退出 ...');
            $this->shouldStop = true;
        });
        // SIGHUP 忽略（守护进程常见行为）
        pcntl_signal(SIGHUP, SIG_IGN);
    }

    /**
     * 主循环
     *
     * @return void
     */
    public function run(): void
    {
        $this->registerSignals();
        $this->log('BuildWorker 启动，监听队列 build:queue ...');

        $idleSeconds = 0;
        while (!$this->shouldStop) {
            // 每次循环前检查信号
            pcntl_signal_dispatch();

            try {
                $result = $this->queue->processQueue();
            } catch (\Throwable $e) {
                $this->log('处理任务异常：' . $e->getMessage());
                // 短暂休眠避免异常时 CPU 空转
                usleep(500000);
                continue;
            }

            if ($result === null) {
                // 队列为空，休眠 1 秒后重试
                $idleSeconds++;
                sleep(1);
                // 每隔 60 秒输出一次心跳
                if ($idleSeconds % 60 === 0) {
                    $this->log('心跳：队列空闲 ' . $idleSeconds . 's');
                }
                continue;
            }

            // 处理完一个任务，重置空闲计数
            $idleSeconds = 0;
            $status = $result['status'] ?? 'unknown';
            $buildId = $result['build_id'] ?? '';
            if ($status === 'success') {
                $this->log("任务 $buildId 构建成功，APK：{$result['apk_path']}");
            } else {
                $this->log("任务 $buildId 构建失败：{$result['message']}");
            }
        }

        $this->log('BuildWorker 已退出');
    }
}

// ----------------------------------------------------------------
// 启动入口
// ----------------------------------------------------------------
try {
    $worker = new BuildWorker(dirname(__DIR__) . '/queue/worker.log');
    $worker->run();
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[Worker] 启动失败：' . $e->getMessage() . "\n");
    exit(1);
}
