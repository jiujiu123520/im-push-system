<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Middleware\AdminLog;
use App\Service\AdminService;
use App\Service\Database;
use App\Service\Response;

/**
 * 管理员控制器
 *
 * 所有接口（除 /admin/login 外）都需通过 AdminAuth 中间件鉴权；
 * 所有 POST/PUT/DELETE 接口在完成后通过 AdminLog 中间件记录操作日志。
 *
 * 返回值约定（与 HttpServer 配合）：
 *  - 成功：返回原始数据数组（HttpServer 会用 Response::ok 自动包装）
 *  - 失败：直接调用 Response::fail 写入响应，并返回 false 跳过自动包装
 *  - 鉴权失败：AdminAuth::authenticate 已写入响应，控制器直接 return false
 *
 * 路由：
 *   POST   /admin/login           管理员登录（无需鉴权）
 *   GET    /admin/info            获取当前登录管理员信息
 *   GET    /admin/list            管理员列表（分页 10 条，支持 keyword）
 *   POST   /admin/create           创建管理员
 *   PUT    /admin/update/{id}      更新管理员
 *   DELETE /admin/delete/{id}      删除管理员
 *   PUT    /admin/change-password  修改自己的密码
 *   GET    /admin/logs            操作日志列表（分页 10 条）
 */
class AdminController
{
    /**
     * 管理员登录（无需鉴权）
     * POST /admin/login
     * Body: { username, password, captcha_token, captcha_input }
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function login(array $context, array $params = [])
    {
        $body = self::parseJsonBody($context);

        $username      = (string)($body['username'] ?? '');
        $password      = (string)($body['password'] ?? '');
        $captchaToken = (string)($body['captcha_token'] ?? '');
        $captchaInput = (string)($body['captcha_input'] ?? '');

        $result = AdminService::login($username, $password, $captchaToken, $captchaInput);

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }

        // 记录登录日志（此时已拿到 admin_id）
        $ip = AdminAuth::getClientIp($context);
        $adminId = (int)($result['admin']['id'] ?? 0);
        AdminService::logAction(
            $adminId,
            'admin_login',
            'admin',
            $adminId,
            ['username' => $username, 'ip' => $ip],
            $ip
        );

        // 记录详细登录日志到 admin_login_logs 表
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? $context['server']['http_user_agent'] ?? $context['header']['user-agent'] ?? '');
        $deviceInfo = self::parseUserAgent($ua);
        try {
            \App\Service\Database::execute(
                'INSERT INTO admin_login_logs (admin_id, username, ip, user_agent, device, browser, os, status, message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)',
                [$adminId, $username, $ip, $ua, $deviceInfo['device'], $deviceInfo['browser'], $deviceInfo['os'], '登录成功']
            );
        } catch (\Throwable $e) {
            // admin_login_logs 表可能不存在，忽略
        }

        return [
            'token' => $result['token'],
            'admin' => $result['admin'],
        ];
    }

    /**
     * 获取当前登录管理员信息
     * GET /admin/info
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function info(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false; // 鉴权失败已输出响应
        }

        $adminId = (int)$payload['admin_id'];
        $info = AdminService::getInfo($adminId);
        if ($info === null) {
            Response::fail($context['response'], '管理员不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        return $info;
    }

    /**
     * 管理员登出
     * POST /admin/logout
     *
     * JWT 为无状态令牌，登出仅记录日志，由前端清除本地 Token。
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function logout(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $adminId = (int)$payload['admin_id'];
        $ip = AdminAuth::getClientIp($context);
        AdminService::logAction(
            $adminId,
            'admin_logout',
            'admin',
            $adminId,
            ['ip' => $ip],
            $ip
        );

        return ['message' => '已登出'];
    }

    /**
     * 管理员列表（分页 10 条，支持 keyword 搜索）
     * GET /admin/list?page=1&keyword=xxx
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function list(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $get = $context['get'] ?? [];
        $page = (int)($get['page'] ?? 1);
        $keyword = (string)($get['keyword'] ?? '');

        return AdminService::getList($page, $keyword);
    }

    /**
     * 创建管理员
     * POST /admin/create
     * Body: { username, password, role }
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function create(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }
        $adminId = (int)$payload['admin_id'];

        // 仅 super_admin 可创建管理员
        if (($payload['role'] ?? '') !== 'super_admin') {
            Response::fail($context['response'], '无权限：仅超级管理员可创建管理员', Response::CODE_FORBIDDEN, 403);
            return false;
        }

        $body = self::parseJsonBody($context);
        $username = (string)($body['username'] ?? '');
        $password = (string)($body['password'] ?? '');
        $role     = (string)($body['role'] ?? (is_array($body['roles'] ?? null) ? ($body['roles'][0] ?? 'admin') : 'admin'));

        $result = AdminService::create($username, $password, $role);

        // 记录操作日志
        AdminLog::record(
            $context,
            $adminId,
            'admin_create',
            'admin',
            $result['id'] ?? 0,
            [
                'username' => $username,
                'role'     => $role,
                'status'   => $result['success'] ? 'success' : 'failed',
                'message'  => $result['message'],
            ]
        );

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }
        return ['id' => $result['id']];
    }

    /**
     * 更新管理员（可修改 username/password/role/status）
     * PUT /admin/update/{id}
     * Body: { username?, password?, role?, status? }
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function update(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }
        $adminId = (int)$payload['admin_id'];

        // 仅 super_admin 可修改管理员信息
        if (($payload['role'] ?? '') !== 'super_admin') {
            Response::fail($context['response'], '无权限：仅超级管理员可修改管理员', Response::CODE_FORBIDDEN, 403);
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '参数 id 无效', Response::CODE_BAD_REQUEST);
            return false;
        }

        $body = self::parseJsonBody($context);
        // 只保留允许更新的字段
        $data = [];
        foreach (['username', 'password', 'role', 'status'] as $field) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $body[$field];
            }
        }
        // 兼容前端 roles 数组
        if (!isset($data['role']) && isset($body['roles']) && is_array($body['roles']) && !empty($body['roles'])) {
            $data['role'] = (string)$body['roles'][0];
        }

        $result = AdminService::update($id, $data);

        // 记录操作日志（不记录 password 明文）
        $logData = $data;
        if (isset($logData['password'])) {
            $logData['password'] = '******';
        }
        AdminLog::record(
            $context,
            $adminId,
            'admin_update',
            'admin',
            $id,
            [
                'fields'  => array_keys($logData),
                'status'  => $result['success'] ? 'success' : 'failed',
                'message' => $result['message'],
            ]
        );

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }
        return null;
    }

    /**
     * 删除管理员
     * DELETE /admin/delete/{id}
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function delete(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }
        $adminId = (int)$payload['admin_id'];

        // 仅 super_admin 可删除管理员
        if (($payload['role'] ?? '') !== 'super_admin') {
            Response::fail($context['response'], '无权限：仅超级管理员可删除管理员', Response::CODE_FORBIDDEN, 403);
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '参数 id 无效', Response::CODE_BAD_REQUEST);
            return false;
        }

        // 不能删除自己
        if ($id === $adminId) {
            Response::fail($context['response'], '不能删除当前登录的管理员账号', Response::CODE_BAD_REQUEST);
            return false;
        }

        $result = AdminService::delete($id);

        AdminLog::record(
            $context,
            $adminId,
            'admin_delete',
            'admin',
            $id,
            [
                'status'  => $result['success'] ? 'success' : 'failed',
                'message' => $result['message'],
            ]
        );

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }
        return null;
    }

    /**
     * 修改自己的密码
     * PUT /admin/change-password
     * Body: { old_password, new_password }
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function changePassword(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }
        $adminId = (int)$payload['admin_id'];

        $body = self::parseJsonBody($context);
        $oldPassword = (string)($body['old_password'] ?? '');
        $newPassword = (string)($body['new_password'] ?? '');

        $result = AdminService::changePassword($adminId, $oldPassword, $newPassword);

        AdminLog::record(
            $context,
            $adminId,
            'admin_change_password',
            'admin',
            $adminId,
            [
                'status'  => $result['success'] ? 'success' : 'failed',
                'message' => $result['message'],
            ]
        );

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }
        return null;
    }

    /**
     * 操作日志列表（分页 10 条）
     * GET /admin/logs?page=1
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function logs(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $get = $context['get'] ?? [];
        $page = (int)($get['page'] ?? 1);

        return AdminService::getLogs($page);
    }

    /**
     * 管理员详情
     * GET /admin/admins/{id}
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function detail(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '参数 id 无效', Response::CODE_BAD_REQUEST);
            return false;
        }

        $admin = Database::fetch(
            'SELECT id, username, role, status, created_at, updated_at FROM admins WHERE id = ? LIMIT 1',
            [$id]
        );

        if ($admin === false) {
            Response::fail($context['response'], '管理员不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return $admin;
    }

    /**
     * 切换管理员状态
     * PUT /admin/admins/{id}/status  Body: { status: 0|1 }
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function toggleStatus(array $context, array $params = [])
    {
        $payload = AdminAuth::authenticate($context);
        if ($payload === null) {
            return false;
        }

        if (($payload['role'] ?? '') !== 'super_admin') {
            Response::fail($context['response'], '无权限：仅超级管理员可操作', Response::CODE_FORBIDDEN, 403);
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '参数 id 无效', Response::CODE_BAD_REQUEST);
            return false;
        }

        $body = self::parseJsonBody($context);
        $status = (int)($body['status'] ?? 0);

        Database::execute(
            'UPDATE admins SET status = ?, updated_at = NOW() WHERE id = ?',
            [$status, $id]
        );

        return ['id' => $id, 'status' => $status];
    }

    /**
     * GET /admin/login-logs
     * 管理员登录日志列表（分页10条，支持IP搜索）
     */
    public static function loginLogs(array $context, array $params = [])
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $page = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');
        $page = max(1, $page);
        $offset = ($page - 1) * 10;

        $where = '';
        $sqlParams = [];
        if ($keyword !== '') {
            $where = ' WHERE username LIKE ? OR ip LIKE ?';
            $sqlParams = ["%{$keyword}%", "%{$keyword}%"];
        }

        try {
            $list = Database::fetchAll(
                "SELECT id, admin_id, username, ip, user_agent, device, browser, os, status, message, created_at
                 FROM admin_login_logs{$where} ORDER BY id DESC LIMIT 10 OFFSET " . $offset,
                $sqlParams
            );

            $total = (int)(Database::fetch(
                "SELECT COUNT(*) AS total FROM admin_login_logs{$where}",
                $sqlParams
            )['total'] ?? 0);
        } catch (\Throwable $e) {
            // 表可能不存在
            $list = [];
            $total = 0;
        }

        return [
            'list'        => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => 10,
            'total_pages' => $total > 0 ? (int)ceil($total / 10) : 0,
        ];
    }

    /**
     * 从请求上下文中解析 JSON Body
     *
     * @param array $context
     * @return array
     */
    private static function parseJsonBody(array $context): array
    {
        $post = $context['post'] ?? [];
        if (!empty($post)) {
            return $post;
        }
        $raw = $context['raw'] ?? '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    /**
     * 解析 User-Agent，识别设备类型、浏览器与操作系统
     *
     * @param string $ua
     * @return array {device: string, browser: string, os: string}
     */
    private static function parseUserAgent(string $ua): array
    {
        $device = 'PC';
        $browser = 'Unknown';
        $os = 'Unknown';

        if ($ua === '') {
            return ['device' => $device, 'browser' => $browser, 'os' => $os];
        }

        // 设备类型
        if (preg_match('/iPad/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/Mobile|Android|iPhone/i', $ua)) {
            $device = 'Mobile';
        }

        // 浏览器
        if (preg_match('/Edge\/(\d+)/', $ua, $m)) {
            $browser = 'Edge ' . $m[1];
        } elseif (preg_match('/Chrome\/(\d+)/', $ua, $m)) {
            $browser = 'Chrome ' . $m[1];
        } elseif (preg_match('/Firefox\/(\d+)/', $ua, $m)) {
            $browser = 'Firefox ' . $m[1];
        } elseif (preg_match('/Safari\/(\d+)/', $ua, $m) && !preg_match('/Chrome/', $ua)) {
            $browser = 'Safari';
        }

        // 操作系统
        if (preg_match('/Windows NT 10/', $ua)) {
            $os = 'Windows 10/11';
        } elseif (preg_match('/Windows/', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Android/', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iPhone|iOS/', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Linux/', $ua)) {
            $os = 'Linux';
        }

        return ['device' => $device, 'browser' => $browser, 'os' => $os];
    }
}
