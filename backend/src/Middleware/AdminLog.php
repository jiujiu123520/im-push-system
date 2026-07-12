<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Service\AdminService;

/**
 * 管理员操作日志中间件
 *
 * 职责：
 *  - 记录所有 POST/PUT/DELETE 管理员请求
 *  - 记录字段：admin_id、action、target_type、target_id、detail、ip、created_at
 *
 * 由于当前框架不支持中间件链式调用，本中间件以"被动记录器"的形式存在：
 * 控制器在完成业务处理后，主动调用 AdminLog::record() 写入一条操作日志。
 * 这样可以在 detail 中携带业务结果，比前置中间件更准确。
 */
class AdminLog
{
    /**
     * 记录一条管理员操作日志
     *
     * @param array  $context     请求上下文（用于提取 IP、HTTP 方法等）
     * @param int    $adminId     管理员ID
     * @param string $action      操作动作（如 admin_create、admin_update、admin_delete）
     * @param string $targetType  目标类型（如 admin、user、config）
     * @param mixed  $targetId    目标ID
     * @param mixed  $detail      操作详情（数组会被 JSON 序列化）
     * @return int 日志ID（失败为 0）
     */
    public static function record(
        array $context,
        int $adminId,
        string $action,
        string $targetType,
        $targetId,
        $detail
    ): int {
        $ip = self::extractIp($context);

        // 自动补充请求元信息到 detail
        $meta = [
            'method' => $context['server']['request_method'] ?? '',
            'path'   => $context['server']['request_uri'] ?? '',
            'time'   => date('Y-m-d H:i:s'),
        ];
        if (is_array($detail)) {
            $detail = array_merge($meta, $detail);
        } elseif (is_string($detail) && $detail !== '') {
            $detail = array_merge($meta, ['info' => $detail]);
        } else {
            $detail = $meta;
        }

        return AdminService::logAction($adminId, $action, $targetType, $targetId, $detail, $ip);
    }

    /**
     * 从请求上下文中提取客户端 IP
     *
     * @param array $context
     * @return string
     */
    private static function extractIp(array $context): string
    {
        $header = $context['header'] ?? [];
        $server = $context['server'] ?? [];
        $ip = $header['x-forwarded-for'] ?? ($header['X-Forwarded-For'] ?? '');
        if ($ip !== '' && strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        if ($ip === '') {
            $ip = $server['remote_addr'] ?? '0.0.0.0';
        }
        return (string)$ip;
    }
}
