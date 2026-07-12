<?php
declare(strict_types=1);

namespace App;

use App\Service\Config;
use App\Service\Response;
use Swoole\Http\Request;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server;
use Throwable;

/**
 * HTTP API 服务器
 *
 * 基于 Swoole HTTP Server，配合 Router 实现 RESTful API。
 * 默认监听端口从环境变量 HTTP_PORT 读取（默认 9501）。
 */
class HttpServer
{
    /**
     * @var Server Swoole HTTP 服务器实例
     */
    private Server $server;

    /**
     * @var Router 路由器实例
     */
    private Router $router;

    /**
     * @var callable 路由注册回调，由调用方传入
     */
    private $routeRegistrar;

    /**
     * 构造方法
     *
     * @param callable $routeRegistrar 路由注册回调，接收 Router 参数
     */
    public function __construct(callable $routeRegistrar)
    {
        $this->routeRegistrar = $routeRegistrar;
        $this->router = new Router();

        $host = (string)Config::env('WEBSOCKET_HOST', '0.0.0.0');
        $port = (int)Config::env('HTTP_PORT', 9501);

        $this->server = new Server($host, $port);
        $this->configure();
        $this->bindEvents();
    }

    /**
     * 配置 Swoole HTTP Server 运行参数
     *
     * @return void
     */
    private function configure(): void
    {
        $this->server->set([
            'worker_num'      => swoole_cpu_num(),
            'task_worker_num' => 4,
            'daemonize'       => false,
            'log_file'        => dirname(__DIR__, 2) . '/runtime/logs/http_server.log',
            'pid_file'        => dirname(__DIR__, 2) . '/runtime/http_server.pid',
            'document_root'   => dirname(__DIR__, 2) . '/public',
            'enable_static_handler' => true,
            'static_handler_locations' => ['/static'],
        ]);
    }

    /**
     * 绑定服务器事件
     *
     * @return void
     */
    private function bindEvents(): void
    {
        $this->server->on('Start', [$this, 'onStart']);
        $this->server->on('ManagerStart', [$this, 'onManagerStart']);
        $this->server->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->server->on('Request', [$this, 'onRequest']);
    }

    /**
     * 主进程启动事件
     *
     * @param Server $server
     * @return void
     */
    public function onStart(Server $server): void
    {
        echo sprintf(
            "[HTTP] 服务已启动，监听 %s:%d，master pid=%d\n",
            $server->host,
            $server->port,
            $server->master_pid
        );
        // 设置进程名
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title('im-push-http-master');
        }
    }

    /**
     * manager 进程启动事件
     *
     * @param Server $server
     * @return void
     */
    public function onManagerStart(Server $server): void
    {
        if (function_exists('cli_set_process_title')) {
            cli_set_process_title('im-push-http-manager');
        }
    }

    /**
     * Worker 启动事件
     *
     * 在每个 Worker 进程启动时加载 .env 并重新建立数据库/Redis 连接，
     * 避免跨进程共享连接。
     *
     * @param Server $server
     * @param int $workerId
     * @return void
     */
    public function onWorkerStart(Server $server, int $workerId): void
    {
        // 加载环境变量
        Config::loadEnv();
        // 重新建立连接，避免跨进程共享
        \App\Service\Database::reconnect();
        \App\Service\Redis::reconnect();
        // 注册路由
        call_user_func($this->routeRegistrar, $this->router);
    }

    /**
     * HTTP 请求事件
     *
     * @param Request $request
     * @param SwooleResponse $response
     * @return void
     */
    public function onRequest(Request $request, SwooleResponse $response): void
    {
        try {
            // 处理 CORS 预检请求（OPTIONS）
            if (strtoupper($request->server['request_method']) === 'OPTIONS') {
                $response->status(204);
                $response->header('Content-Type', 'application/json; charset=utf-8');
                $response->header('Access-Control-Allow-Origin', '*');
                $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
                $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Api-Key');
                $response->header('Access-Control-Max-Age', '86400');
                $response->end();
                return;
            }

            $path = $request->server['request_uri'];
            $method = strtoupper($request->server['request_method']);

            // 路由分发
            [$handler, $params] = $this->router->dispatch($method, $path);

            if ($handler === null) {
                Response::fail($response, '路由不存在：' . $path, Response::CODE_NOT_FOUND, 404);
                return;
            }

            // 调用处理器
            $context = [
                'request'  => $request,
                'response' => $response,
                'get'      => $request->get ?? [],
                'post'     => $request->post ?? [],
                'server'   => $request->server ?? [],
                'header'   => $request->header ?? [],
                'raw'      => $request->rawContent() ?: '',
            ];

            $result = $this->invokeHandler($handler, $params, $context);
            // 如果处理器已经自行输出响应，跳过
            if ($result === false) {
                return;
            }

            Response::ok($response, $result);
        } catch (Throwable $e) {
            // 统一异常处理
            Response::fail($response, '服务器内部错误：' . $e->getMessage(), Response::CODE_INTERNAL, 500);
        }
    }

    /**
     * 调用路由处理器
     *
     * @param callable|array $handler 处理器
     * @param array $params 路径参数
     * @param array $context 请求上下文
     * @return mixed 返回数据；返回 false 表示处理器已自行输出响应
     */
    private function invokeHandler($handler, array $params, array $context)
    {
        // ['类名', '方法']
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = new $class();
            return $instance->{$method}($context, $params);
        }

        // 闭包/函数
        if (is_callable($handler)) {
            return call_user_func($handler, $context, $params);
        }

        throw new \InvalidArgumentException('无效的路由处理器');
    }

    /**
     * 启动 HTTP 服务器
     *
     * @return void
     */
    public function start(): void
    {
        $this->server->start();
    }

    /**
     * 获取 Swoole HTTP Server 实例
     *
     * @return Server
     */
    public function getServer(): Server
    {
        return $this->server;
    }

    /**
     * 获取路由器
     *
     * @return Router
     */
    public function getRouter(): Router
    {
        return $this->router;
    }
}
