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
 *   GET    /admin/devices                  列表（分页10条，支持 keyword 搜索）
 *   GET    /admin/devices/{id}             设备详情
 *   PUT    /admin/devices/{id}/status      切换设备状态（禁用/启用）
 *   DELETE /admin/devices/{id}             删除设备
 */
class DeviceController
{
    /**
     * 设备列表
     * 路由：GET /admin/devices
     *
     * 支持参数：
     *   page     页码
     *   keyword  搜索关键词
     *   platform 平台筛选（android/ios/web/harmony）
     *   online   在线状态筛选（1=在线 0=全部）
     *   status   启用状态筛选（1=启用 2=禁用）
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

        // 筛选条件
        $filters = [
            'platform' => (string)($context['get']['platform'] ?? ''),
            'online'   => (int)($context['get']['online'] ?? 0),
            'status'   => (int)($context['get']['status'] ?? 0),
        ];

        $service = new DeviceService();
        return $service->listDevices($page, $keyword, $filters);
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

    /**
     * 切换设备状态（禁用/启用）
     * 路由：PUT /admin/devices/{id}/status
     *
     * 禁用设备会主动断开该设备的所有在线连接
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function toggleStatus(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '参数错误：缺少设备 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $service = new DeviceService();
        $device = $service->toggleStatus($id);
        if ($device === null) {
            Response::fail($context['response'], '设备不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return [
            'id' => (int)$device['id'],
            'status' => (int)$device['status'],
            'message' => (int)$device['status'] === 2 ? '设备已禁用' : '设备已启用'
        ];
    }

    /**
     * 删除设备
     * 路由：DELETE /admin/devices/{id}
     *
     * 删除前主动断开该设备的所有在线连接
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function destroy(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '参数错误：缺少设备 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $service = new DeviceService();
        $ok = $service->deleteDevice($id);
        if (!$ok) {
            Response::fail($context['response'], '设备不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return ['id' => $id, 'message' => '删除成功'];
    }

    /**
     * 按 Key 值查询设备列表（含在线状态）
     * 路由：GET /admin/devices/by-key?key=xxx
     *
     * 返回该 Key 下所有设备，包含 Redis 实时在线状态和 fd 数。
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function getByKey(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $keyValue = (string)($context['get']['key'] ?? '');
        if ($keyValue === '') {
            Response::fail($context['response'], '参数错误：缺少 key', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $service = new DeviceService();
        return $service->listByKeyValue($keyValue);
    }

    /**
     * 强制断开设备的所有在线连接（踢出）
     * 路由：POST /admin/devices/{id}/kick
     *
     * 不断开设备与 Key 的绑定关系，仅断开当前 WebSocket 连接。
     * 设备下次重连仍可正常鉴权（除非设备状态为禁用）。
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public function kick(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '参数错误：缺少设备 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $service = new DeviceService();
        $result = $service->kickDevice($id);
        if ($result === null) {
            Response::fail($context['response'], '设备不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return [
            'id'       => $id,
            'kicked'   => $result['kicked'],
            'message'  => $result['kicked'] > 0 ? "已断开 {$result['kicked']} 个连接" : '设备当前无在线连接',
        ];
    }
}
