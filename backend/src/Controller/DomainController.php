<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Database;
use App\Service\Response;
use App\Service\SslService;

/**
 * 域名绑定与 SSL 证书管理控制器（管理员鉴权）
 *
 * 路由：
 *   GET    /admin/domains                       域名列表
 *   GET    /admin/domains/{id}                   域名详情
 *   POST   /admin/domains                        添加域名
 *   PUT    /admin/domains/{id}                   更新域名
 *   DELETE /admin/domains/{id}                   删除域名
 *   POST   /admin/domains/{id}/set-primary       设为主域名
 *   POST   /admin/domains/{id}/ssl-apply         申请 SSL 证书
 *   POST   /admin/domains/{id}/ssl-deploy        部署 Nginx（生成配置+reload）
 *   POST   /admin/domains/sync-nginx             同步所有域名 Nginx 配置
 *   GET    /admin/domains/environment            检查 SSL 环境
 *   POST   /admin/domains/install-acme           安装 acme.sh
 */
class DomainController
{
    /** 允许的域名用途 */
    private const ALLOWED_TYPES = ['admin', 'api', 'ws'];

    /**
     * 域名列表
     */
    public function index(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $list = Database::fetchAll(
            'SELECT id, domain, type, ssl_enabled, ssl_status, ssl_expire_at,
                    ssl_cert_path, ssl_key_path, ssl_error, nginx_deployed,
                    is_primary, status, remark, created_at, updated_at
             FROM domains ORDER BY is_primary DESC, id ASC'
        );

        // 检查每个域名的证书实际状态
        foreach ($list as &$row) {
            if ((int)$row['ssl_enabled'] === 1 && $row['ssl_status'] === 'issued') {
                $certInfo = SslService::checkCertificate($row['domain']);
                if (!$certInfo['valid']) {
                    // 证书已过期或文件丢失，更新状态
                    $row['ssl_status'] = 'expired';
                    $row['ssl_error'] = $certInfo['reason'] ?? '';
                    Database::execute(
                        'UPDATE domains SET ssl_status = ?, ssl_error = ? WHERE id = ?',
                        ['expired', $row['ssl_error'], $row['id']]
                    );
                } else {
                    $row['ssl_expire_at'] = $certInfo['expire_at'];
                    $row['days_left'] = $certInfo['days_left'];
                    // 同步过期时间
                    Database::execute(
                        'UPDATE domains SET ssl_expire_at = ? WHERE id = ?',
                        [$certInfo['expire_at'], $row['id']]
                    );
                }
            }
            $row['ssl_enabled'] = (int)$row['ssl_enabled'];
            $row['nginx_deployed'] = (int)$row['nginx_deployed'];
            $row['is_primary'] = (int)$row['is_primary'];
            $row['status'] = (int)$row['status'];
        }
        unset($row);

        return ['list' => $list, 'total' => count($list)];
    }

    /**
     * 域名详情
     */
    public function show(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $row = Database::fetch(
            'SELECT id, domain, type, ssl_enabled, ssl_status, ssl_expire_at,
                    ssl_cert_path, ssl_key_path, ssl_error, nginx_deployed,
                    is_primary, status, remark, created_at, updated_at
             FROM domains WHERE id = ? LIMIT 1',
            [$id]
        );

        if ($row === false) {
            Response::fail($context['response'], '域名不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return $row;
    }

    /**
     * 添加域名
     */
    public function create(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $response = $context['response'];
        $body = $this->parseBody($context);

        $domain = strtolower(trim((string)($body['domain'] ?? '')));
        $type   = (string)($body['type'] ?? 'admin');
        $remark = (string)($body['remark'] ?? '');

        if ($domain === '') {
            Response::fail($response, '域名不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 简单域名校验
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/', $domain)) {
            Response::fail($response, '域名格式不正确', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            Response::fail($response, '域名用途非法，允许：' . implode(', ', self::ALLOWED_TYPES), Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 检查是否已存在
        $exists = Database::fetch('SELECT id FROM domains WHERE domain = ? LIMIT 1', [$domain]);
        if ($exists !== false) {
            Response::fail($response, '域名已存在', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 如果是第一个域名，自动设为主域名
        $count = (int)(Database::fetch('SELECT COUNT(*) AS c FROM domains')['c'] ?? 0);
        $isPrimary = $count === 0 ? 1 : 0;

        Database::execute(
            'INSERT INTO domains (domain, type, is_primary, remark, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$domain, $type, $isPrimary, $remark]
        );

        $newId = (int)Database::pdo()->lastInsertId();

        return [
            'message' => '域名添加成功',
            'id'      => $newId,
            'is_primary' => $isPrimary,
        ];
    }

    /**
     * 更新域名
     */
    public function update(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $response = $context['response'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($response, '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $body = $this->parseBody($context);
        $domain = strtolower(trim((string)($body['domain'] ?? '')));
        $type   = (string)($body['type'] ?? '');
        $remark = (string)($body['remark'] ?? '');
        $status = $body['status'] ?? null;

        $row = Database::fetch('SELECT id FROM domains WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($response, '域名不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $updates = [];
        $args = [];

        if ($domain !== '') {
            if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/', $domain)) {
                Response::fail($response, '域名格式不正确', Response::CODE_BAD_REQUEST, 400);
                return false;
            }
            $dup = Database::fetch('SELECT id FROM domains WHERE domain = ? AND id != ? LIMIT 1', [$domain, $id]);
            if ($dup !== false) {
                Response::fail($response, '域名已存在', Response::CODE_BAD_REQUEST, 400);
                return false;
            }
            $updates[] = 'domain = ?';
            $args[] = $domain;
        }

        if ($type !== '' && in_array($type, self::ALLOWED_TYPES, true)) {
            $updates[] = 'type = ?';
            $args[] = $type;
        }

        if ($remark !== '') {
            $updates[] = 'remark = ?';
            $args[] = $remark;
        }

        if ($status !== null) {
            $updates[] = 'status = ?';
            $args[] = (int)$status;
        }

        if (empty($updates)) {
            Response::fail($response, '未提供需要更新的字段', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $updates[] = 'updated_at = NOW()';
        $args[] = $id;

        Database::execute(
            'UPDATE domains SET ' . implode(', ', $updates) . ' WHERE id = ?',
            $args
        );

        return ['message' => '更新成功'];
    }

    /**
     * 删除域名
     */
    public function delete(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $response = $context['response'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($response, '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $row = Database::fetch('SELECT id, domain, is_primary, ssl_enabled FROM domains WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($response, '域名不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        // 如果是主域名，阻止删除（需先取消主域名）
        if ((int)$row['is_primary'] === 1) {
            Response::fail($response, '不能删除主域名，请先设置其他域名为主域名', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 删除证书文件
        if ((int)$row['ssl_enabled'] === 1) {
            SslService::removeCertificate($row['domain']);
        }

        Database::execute('DELETE FROM domains WHERE id = ?', [$id]);

        return ['message' => '域名已删除'];
    }

    /**
     * 设为主域名
     */
    public function setPrimary(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $response = $context['response'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($response, '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $row = Database::fetch('SELECT id, status FROM domains WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($response, '域名不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        if ((int)$row['status'] !== 1) {
            Response::fail($response, '禁用的域名不能设为主域名', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 取消其他主域名
        Database::execute('UPDATE domains SET is_primary = 0, updated_at = NOW()');
        // 设置新的主域名
        Database::execute('UPDATE domains SET is_primary = 1, updated_at = NOW() WHERE id = ?', [$id]);

        return ['message' => '已设为主域名'];
    }

    /**
     * 申请 SSL 证书
     */
    public function applySsl(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $response = $context['response'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($response, '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $row = Database::fetch('SELECT id, domain, ssl_status FROM domains WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($response, '域名不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        // 标记为申请中
        Database::execute(
            'UPDATE domains SET ssl_enabled = 1, ssl_status = ?, ssl_error = "" WHERE id = ?',
            ['pending', $id]
        );

        // 执行证书申请
        $result = SslService::issueCertificate($row['domain']);

        if ($result['success']) {
            Database::execute(
                'UPDATE domains SET ssl_status = ?, ssl_expire_at = ?, ssl_cert_path = ?, ssl_key_path = ?, ssl_error = "" WHERE id = ?',
                ['issued', $result['expire_at'], $result['cert_path'], $result['key_path'], $id]
            );
            return [
                'message'     => 'SSL 证书申请成功',
                'expire_at'   => $result['expire_at'],
                'cert_path'   => $result['cert_path'],
                'key_path'    => $result['key_path'],
            ];
        }

        // 失败
        Database::execute(
            'UPDATE domains SET ssl_status = ?, ssl_error = ? WHERE id = ?',
            ['failed', mb_substr($result['message'] . ': ' . ($result['output'] ?? ''), 0, 500), $id]
        );
        Response::fail($response, 'SSL 证书申请失败：' . $result['message'], Response::CODE_ERROR, 500);
        return false;
    }

    /**
     * 部署 Nginx 配置（生成配置文件 + reload）
     */
    public function deployNginx(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $response = $context['response'];
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($response, '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $row = Database::fetch('SELECT id FROM domains WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($response, '域名不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        // 生成并部署所有域名的 Nginx 配置（一个 server 块共用）
        $domains = Database::fetchAll(
            'SELECT * FROM domains WHERE status = 1 ORDER BY is_primary DESC, id ASC'
        );

        if (empty($domains)) {
            Response::fail($response, '没有启用的域名', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $genResult = SslService::generateNginxConfig($domains);
        if (!$genResult['success']) {
            Response::fail($response, $genResult['message'], Response::CODE_ERROR, 500);
            return false;
        }

        $reloadResult = SslService::reloadNginx();
        if (!$reloadResult['success']) {
            Response::fail($response, $reloadResult['message'] . "\n" . $reloadResult['output'], Response::CODE_ERROR, 500);
            return false;
        }

        // 更新所有域名的 nginx_deployed 状态
        Database::execute('UPDATE domains SET nginx_deployed = 1, updated_at = NOW() WHERE status = 1');

        return [
            'message' => 'Nginx 配置已部署并重载',
            'output'  => $reloadResult['output'],
        ];
    }

    /**
     * 同步所有域名 Nginx 配置
     */
    public function syncNginx(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $response = $context['response'];

        $domains = Database::fetchAll(
            'SELECT * FROM domains WHERE status = 1 ORDER BY is_primary DESC, id ASC'
        );

        if (empty($domains)) {
            Response::fail($response, '没有启用的域名，请先添加域名', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $genResult = SslService::generateNginxConfig($domains);
        if (!$genResult['success']) {
            Response::fail($response, $genResult['message'], Response::CODE_ERROR, 500);
            return false;
        }

        $reloadResult = SslService::reloadNginx();
        if (!$reloadResult['success']) {
            Response::fail($response, $reloadResult['message'] . "\n" . $reloadResult['output'], Response::CODE_ERROR, 500);
            return false;
        }

        Database::execute('UPDATE domains SET nginx_deployed = 1, updated_at = NOW() WHERE status = 1');

        return [
            'message' => 'Nginx 配置同步成功',
            'output'  => $reloadResult['output'],
        ];
    }

    /**
     * 检查 SSL 环境
     */
    public function environment(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        return SslService::checkEnvironment();
    }

    /**
     * 安装 acme.sh
     */
    public function installAcme(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $response = $context['response'];
        $result = SslService::installAcme();

        if (!$result['success']) {
            Response::fail($response, $result['message'], Response::CODE_ERROR, 500);
            return false;
        }

        return ['message' => $result['message'], 'output' => $result['output']];
    }

    /**
     * 解析请求体
     */
    private function parseBody(array $context): array
    {
        $body = $context['post'] ?? [];
        if (!empty($body)) {
            return $body;
        }

        $raw = $context['raw'] ?? '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
