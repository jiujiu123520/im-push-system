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
 *   GET    /admin/zombie-connections            获取僵尸连接列表（fd 维度）
 *   GET    /admin/zombie-subscriptions          获取僵尸订阅列表（device_id 维度）
 *   GET    /admin/connections                   获取所有在线连接列表
 *   DELETE /admin/zombie-connections/{fd}       删除单个僵尸连接
 *   DELETE /admin/zombie-subscriptions/{device_id} 删除单个僵尸订阅
 *   POST   /admin/zombie-connections/cleanup    一键清理（僵尸连接 + 僵尸订阅）
 *
 * 注意：处理器必须返回「裸数据数组」，由 HttpServer 统一包一层
 * {code,message,data} 信封；改用 Response::success() 返回会被包两层，
 * 前端 res.data.list 取不到值（历史遗留 Bug，导致列表恒为空）。
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

        return [
            'list'      => $zombies,
            'total'     => count($zombies),
            'threshold' => $threshold,
        ];
    }

    /**
     * 获取僵尸订阅列表
     * 路由：GET /admin/zombie-subscriptions
     *
     * 与僵尸连接的区别：僵尸连接是 fd 维度（还挂在 ws:online 上的死连接），
     * 僵尸订阅是 device_id 维度（Redis 订阅关系还在，但 devices 表已无该设备）。
     */
    public function subscriptions(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $cm = new ConnectionManager();
        $list = $cm->getZombieSubscriptions();

        return [
            'list'  => $list,
            'total' => count($list),
        ];
    }

    /**
     * 删除单个僵尸订阅
     * 路由：DELETE /admin/zombie-subscriptions/{device_id}
     */
    public function deleteSubscription(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $deviceId = urldecode((string)($params['device_id'] ?? ''));
        if ($deviceId === '') {
            Response::fail($context['response'], '无效的 device_id', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $cm = new ConnectionManager();
        $result = $cm->removeZombieSubscription($deviceId);

        return array_merge($result, [
            'removed' => true,
            'message' => "已移除僵尸订阅 {$deviceId}（订阅集合 -{$result['redis_subscribe_rm']}，device:key -{$result['redis_device_key_rm']}）",
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

        return [
            'list'  => $connections,
            'total' => count($connections),
        ];
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

        return [
            'removed' => true,
            'message' => '已移除僵尸连接 fd=' . $fd,
        ];
    }

    /**
     * 一键清理所有僵尸（连接 + 订阅）
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

        // 僵尸订阅（device_id 维度）一并清理
        $subscriptions = $cm->getZombieSubscriptions();
        $subRemoved = 0;
        foreach ($subscriptions as $subscription) {
            $cm->removeZombieSubscription((string)$subscription['device_id']);
            $subRemoved++;
        }

        return [
            'removed'               => $removedCount,
            'checked'               => count($zombies),
            'threshold'             => $threshold,
            'subscriptions_removed' => $subRemoved,
            'subscriptions_checked' => count($subscriptions),
            'message'               => "已清理 {$removedCount} 个僵尸连接、{$subRemoved} 个僵尸订阅",
        ];
    }
}
