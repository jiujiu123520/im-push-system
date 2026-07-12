<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\DeviceService;
use App\Service\Response;

/**
 * 设备管理控制器（需管理员鉴权）
 *
 * 路由：
 *   GET /admin/devices          列表（分页10条，支持 keyword 搜索）
 *   GET /admin/devices/{id}     设备详情
 */
class DeviceController
{
    /**
     * 设备列表
     * 路由：GET /admin/devices
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function index(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $page    = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        $service = new DeviceService();
        return $service->listDevices($page, $keyword);
    }

    /**
     * 设备详情
     * 路由：GET /admin/devices/{id}
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function show(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (string)($params['id'] ?? '');

        $service = new DeviceService();

        // 支持按主键 ID 或 device_id 查询
        if (ctype_digit($id)) {
            $device = $service->getDeviceById((int)$id);
        } else {
            $device = $service->getDeviceDetail($id);
        }

        if ($device === null) {
            Response::fail($context['response'], '设备不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return $device;
    }
}
