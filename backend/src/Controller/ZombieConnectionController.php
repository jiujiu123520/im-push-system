<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\ConnectionManager;
use App\Service\Response;

/**
 * 僵尸连接管理控制器（需管理员鉴权）
 *
 * 路由：
 *   GET    /admin/zombie-connections          获取僵尸连接列表
 *   GET    /admin/connections                 获取所有在线连接列表
 *   DELETE /admin/zombie-connections/{fd}     删除单个僵尸连接
 *   POST   /admin/zombie-connections/cleanup   一键清理所有僵尸连接
 */
class ZombieConnectionController
{
    /**
     * 获取僵尸连接列表
     * 路由：GET /admin/zombie-connections
     */
    public function index(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $threshold = (int)($context['get']['threshold'] ?? 600);
        $cm = new ConnectionManager();
        $zombies = $cm->getZombieConnections($threshold);

        return Response::success([
            'list'      => $zombies,
            'total'     => count($zombies),
            'threshold' => $threshold,
        ]);
    }

    /**
     * 获取所有在线连接列表
     * 路由：GET /admin/connections
     */
    public function all(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $cm = new ConnectionManager();
        $connections = $cm->getAllConnections();

        return Response::success([
            'list'  => $connections,
            'total' => count($connections),
        ]);
    }

    /**
     * 删除单个僵尸连接
     * 路由：DELETE /admin/zombie-connections/{fd}
     */
    public function delete(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $fd = (int)($params['fd'] ?? 0);
        if ($fd <= 0) {
            Response::fail($context['response'], '无效的 fd', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $cm = new ConnectionManager();
        $removed = $cm->forceRemoveConnection($fd);
        if (!$removed) {
            Response::fail($context['response'], '连接不存在或已被移除', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return Response::success(null, '已移除僵尸连接 fd=' . $fd);
    }

    /**
     * 一键清理所有僵尸连接
     * 路由：POST /admin/zombie-connections/cleanup
     */
    public function cleanup(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $threshold = (int)($context['post']['threshold'] ?? $context['get']['threshold'] ?? 600);
        $cm = new ConnectionManager();
        $zombies = $cm->getZombieConnections($threshold);

        $removedCount = 0;
        foreach ($zombies as $zombie) {
            if ($cm->forceRemoveConnection($zombie['fd'])) {
                $removedCount++;
            }
        }

        return Response::success([
            'removed'    => $removedCount,
            'checked'    => count($zombies),
            'threshold'  => $threshold,
        ], "已清理 {$removedCount} 个僵尸连接");
    }
}
