<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Middleware\UserApiAuth;
use App\Service\Database;
use App\Service\Response;

/**
 * 用户端控制器基类
 *
 * 提供：
 *  - 统一鉴权封装：auth() -> null 表示已输出错误
 *  - 分页/参数解析辅助方法
 *  - 通用 JSON 字段追加（fail_detail 反序列化）
 */
abstract class BaseUserController
{
    protected const PER_PAGE = 10;

    /**
     * 鉴权并返回 JWT payload，失败返回 null（并已输出错误响应）
     */
    protected function auth(array &$context): ?array
    {
        return UserApiAuth::authenticate($context);
    }

    /**
     * 从请求上下文解析分页参数
     */
    protected function parsePage(array $context, int $defaultPerPage = self::PER_PAGE): array
    {
        $get = $context['get'] ?? [];
        $page = max(1, (int)($get['page'] ?? 1));
        $perPage = (int)($get['per_page'] ?? $get['pageSize'] ?? $defaultPerPage);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;
        $keyword = trim((string)($get['keyword'] ?? ''));
        return [$page, $perPage, $offset, $keyword];
    }

    /**
     * 解析请求体（JSON 优先，POST 表单兜底）
     */
    protected function parseBody(array $context): array
    {
        $body = $context['post'] ?? [];
        if (!empty($body)) {
            return $body;
        }
        $raw = $context['raw'] ?? '';
        if ($raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $json;
            }
        }
        return [];
    }

    /**
     * 解析 push_logs.detail / fail_detail JSON 字段（兼容字符串与数组）
     */
    protected function tryJsonDecode($val): array
    {
        if (is_array($val)) {
            return $val;
        }
        if (is_string($val) && $val !== '') {
            $d = json_decode($val, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    /**
     * 统一分页返回结构
     */
    protected function pageResult(array $list, int $total, int $page, int $perPage): array
    {
        return [
            'list'        => array_values($list),
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }

    /**
     * 快捷错误响应
     */
    protected function fail(array $context, string $msg, int $code = Response::CODE_BAD_REQUEST, int $http = 400): false
    {
        Response::fail($context['response'] ?? null, $msg, $code, $http);
        return false;
    }
}
