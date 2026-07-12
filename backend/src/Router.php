<?php
declare(strict_types=1);

namespace App;

/**
 * 简单路由器
 *
 * 支持按 HTTP 方法（GET/POST/PUT/DELETE/OPTIONS）注册路由，
 * 支持路径参数解析（如 /users/{id}）。
 *
 * 路由处理器可以是闭包，也可以是 ['类名', '方法'] 形式的可调用对象。
 */
class Router
{
    /**
     * @var array 已注册的路由表
     *            结构：[method => [pattern => [regex, params, handler]]]
     */
    private array $routes = [];

    /**
     * 注册 GET 路由
     *
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    public function get(string $path, $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    /**
     * 注册 POST 路由
     *
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    public function post(string $path, $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    /**
     * 注册 PUT 路由
     *
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    public function put(string $path, $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    /**
     * 注册 DELETE 路由
     *
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    public function delete(string $path, $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * 注册 OPTIONS 路由
     *
     * @param string $path
     * @param callable|array $handler
     * @return void
     */
    public function options(string $path, $handler): void
    {
        $this->addRoute('OPTIONS', $path, $handler);
    }

    /**
     * 注册路由到路由表
     *
     * @param string $method HTTP 方法
     * @param string $path 路径（支持 {param} 占位）
     * @param callable|array $handler
     * @return void
     */
    private function addRoute(string $method, string $path, $handler): void
    {
        $method = strtoupper($method);
        // 将路径转换为正则，提取参数名
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_]\w*)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);
        $regex = '#^' . $regex . '$#';

        $this->routes[$method][$path] = [
            'regex'   => $regex,
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    /**
     * 分发请求到对应的处理器
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     * @return array [handler, params]；未匹配返回 [null, []]
     */
    public function dispatch(string $method, string $path): array
    {
        $method = strtoupper($method);
        // 统一去掉查询字符串
        if (($pos = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $pos);
        }
        // 末尾斜杠归一化（根路径除外）
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }

        $routes = $this->routes[$method] ?? [];
        foreach ($routes as $route) {
            if (preg_match($route['regex'], $path, $matches)) {
                array_shift($matches); // 去掉完整匹配
                $params = array_combine($route['params'], $matches) ?: [];
                return [$route['handler'], $params];
            }
        }
        return [null, []];
    }

    /**
     * 检查方法是否注册过任意路由
     *
     * @param string $method
     * @return bool
     */
    public function hasMethod(string $method): bool
    {
        return isset($this->routes[strtoupper($method)]);
    }
}
