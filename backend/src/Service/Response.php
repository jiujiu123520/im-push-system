<?php
declare(strict_types=1);

namespace App\Service;

/**
 * JSON 响应封装
 *
 * 统一 API 返回格式：
 * {
 *   "code": 0,           // 业务码，0 表示成功，非 0 表示失败
 *   "message": "ok",     // 提示信息
 *   "data": { ... }      // 业务数据
 * }
 */
class Response
{
    /** 成功码 */
    public const CODE_SUCCESS = 0;
    /** 通用错误码 */
    public const CODE_ERROR = 1;
    /** 未授权 */
    public const CODE_UNAUTHORIZED = 401;
    /** 禁止访问 */
    public const CODE_FORBIDDEN = 403;
    /** 资源不存在 */
    public const CODE_NOT_FOUND = 404;
    /** 参数错误 */
    public const CODE_BAD_REQUEST = 400;
    /** 服务器内部错误 */
    public const CODE_INTERNAL = 500;

    /**
     * 构造统一格式的响应数组
     *
     * @param mixed $data
     * @param int $code
     * @param string $message
     * @return array
     */
    public static function build($data = null, int $code = self::CODE_SUCCESS, string $message = 'ok'): array
    {
        return [
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
        ];
    }

    /**
     * 成功响应
     *
     * @param mixed $data
     * @param string $message
     * @return array
     */
    public static function success($data = null, string $message = 'ok'): array
    {
        return self::build($data, self::CODE_SUCCESS, $message);
    }

    /**
     * 失败响应
     *
     * @param string $message
     * @param int $code
     * @param mixed $data
     * @return array
     */
    public static function error(string $message = 'error', int $code = self::CODE_ERROR, $data = null): array
    {
        return self::build($data, $code, $message);
    }

    /**
     * 输出 JSON 到 Swoole HTTP Response
     *
     * @param \Swoole\Http\Response $response
     * @param mixed $data
     * @param int $status HTTP 状态码
     * @return void
     */
    public static function json(\Swoole\Http\Response $response, $data, int $status = 200): void
    {
        $response->status($status);
        $response->header('Content-Type', 'application/json; charset=utf-8');
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Api-Key');
        $response->end(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 直接输出成功 JSON
     *
     * @param \Swoole\Http\Response $response
     * @param mixed $data
     * @param string $message
     * @return void
     */
    public static function ok(\Swoole\Http\Response $response, $data = null, string $message = 'ok'): void
    {
        self::json($response, self::success($data, $message), 200);
    }

    /**
     * 直接输出错误 JSON
     *
     * @param \Swoole\Http\Response $response
     * @param string $message
     * @param int $code
     * @param int $status
     * @return void
     */
    public static function fail(\Swoole\Http\Response $response, string $message = 'error', int $code = self::CODE_ERROR, int $status = 200): void
    {
        self::json($response, self::error($message, $code), $status);
    }
}
