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
 *   GET    /admin/domains/environment           检查 SSL 环境
 *   POST   /admin/domains/install-acme         安装 acme.sh
 *   POST   /admin/domains                       添加域名
 *   GET    /admin/domains/{id}                  域名详情
 *   PUT    /admin/domains/{id}                  更新域名
 *   DELETE /admin/domains/{id}                  删除域名
 *   POST   /admin/domains/{id}/set-primary      设为主域名
 *   POST   /admin/domains/{id}/ssl-apply        申请 SSL 证书
 *   POST   /admin/domains/{id}/ssl-renew        续费 SSL 证书
 *   POST   /admin/domains/{id}/ssl-deploy       部署 Nginx
 *   POST   /admin/domains/{id}/toggle-auto-renew 切换自动续费
 *   POST   /admin/domains/sync-nginx            同步所有域名 Nginx
 *   POST   /admin/domains/renew-all             批量续费即将过期证书
 */
class DomainController
{
    /** 允许的目标类型 */
    private const ALLOWED_TARGET_TYPES = ['frontend', 'backend', 'ws', 'all'];

    /**
     * 域名列表
     */
    public function index(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $list = Database::fetchAll(
            'SELECT id, domain, listen_port, type, target_type, target_host,
                    ssl_enabled, ssl_status, ssl_expire_at, ssl_auto_renew, ssl_last_renew_at,
                    ssl_cert_path, ssl_key_path, ssl_error, nginx_deployed,
                    is_primary, status, remark, created_at, updated_at
             FROM domains ORDER BY is_primary DESC, id ASC'
        );

        // 实时检查每个域名的证书状态
        foreach ($list as &$row) {
            if ((int)$row['ssl_enabled'] === 1 && $row['ssl_status'] === 'issued') {
                $certInfo = SslService::checkCertificate($row['domain']);
                if (!$certInfo['valid']) {
                    $row['ssl_status'] = 'expired';
                    $row['ssl_error'] = $certInfo['reason'] ?? '';
                    Database::execute(
                        'UPDATE domains SET ssl_status = ?, ssl_error = ? WHERE id = ?',
                        ['expired', $row['ssl_error'], $row['id']]
                    );
                } else {
                    $row['ssl_expire_at'] = $certInfo['expire_at'];
                    $row['days_left'] = $certInfo['days_left'];
                    Database::execute(
                        'UPDATE domains SET ssl_expire_at = ? WHERE id = ?',
                        [$certInfo['expire_at'], $row['id']]
                    );
                }
            }
            // 类型转换
            $row['listen_port'] = (int)$row['listen_port'];
            $row['ssl_enabled'] = (int)$row['ssl_enabled'];
            $row['ssl_auto_renew'] = (int)$row['ssl_auto_renew'];
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
            'SELECT * FROM domains WHERE id = ? LIMIT 1',
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

        $domain     = strtolower(trim((string)($body['domain'] ?? '')));
        $targetType = (string)($body['target_type'] ?? $body['type'] ?? 'frontend');
        $listenPort = (int)($body['listen_port'] ?? 0);
        $targetHost = (string)($body['target_host'] ?? '127.0.0.1:9501');
        $remark     = (string)($body['remark'] ?? '');

        if ($domain === '') {
            Response::fail($response, '域名不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)+$/', $domain)) {
            Response::fail($response, '域名格式不正确', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        if (!in_array($targetType, self::ALLOWED_TARGET_TYPES, true)) {
            Response::fail($response, '目标类型非法，允许：' . implode(', ', self::ALLOWED_TARGET_TYPES), Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        if ($listenPort < 0 || $listenPort > 65535) {
            Response::fail($response, '监听端口非法', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $exists = Database::fetch('SELECT id FROM domains WHERE domain = ? LIMIT 1', [$domain]);
        if ($exists !== false) {
            Response::fail($response, '域名已存在', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 检查端口是否被占用（同端口+同SSL状态不能重复）
        if ($listenPort > 0) {
            $portConflict = Database::fetch(
                'SELECT id FROM domains WHERE listen_port = ? AND status = 1 LIMIT 1',
                [$listenPort]
            );
            if ($portConflict !== false) {
                Response::fail($response, "端口 {$listenPort} 已被其他启用域名占用", Response::CODE_BAD_REQUEST, 400);
                return false;
            }
        }

        $count = (int)(Database::fetch('SELECT COUNT(*) AS c FROM domains')['c'] ?? 0);
        $isPrimary = $count === 0 ? 1 : 0;

        // 兼容旧 type 字段
        $oldType = $targetType === 'frontend' ? 'admin' : $targetType;

        Database::execute(
            'INSERT INTO domains (domain, listen_port, type, target_type, target_host, is_primary, remark, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [$domain, $listenPort, $oldType, $targetType, $targetHost, $isPrimary, $remark]
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
        $updates = [];
        $args = [];

        if (isset($body['domain'])) {
            $domain = strtolower(trim($body['domain']));
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

        if (isset($body['target_type'])) {
            if (!in_array($body['target_type'], self::ALLOWED_TARGET_TYPES, true)) {
                Response::fail($response, '目标类型非法', Response::CODE_BAD_REQUEST, 400);
                return false;
            }
            $updates[] = 'target_type = ?';
            $args[] = $body['target_type'];
            // 同步旧 type 字段
            $updates[] = 'type = ?';
            $args[] = $body['target_type'] === 'frontend' ? 'admin' : $body['target_type'];
        }

        if (isset($body['listen_port'])) {
            $port = (int)$body['listen_port'];
            if ($port < 0 || $port > 65535) {
                Response::fail($response, '监听端口非法', Response::CODE_BAD_REQUEST, 400);
                return false;
            }
            if ($port > 0) {
                $portConflict = Database::fetch(
                    'SELECT id FROM domains WHERE listen_port = ? AND id != ? AND status = 1 LIMIT 1',
                    [$port, $id]
                );
                if ($portConflict !== false) {
                    Response::fail($response, "端口 {$port} 已被其他启用域名占用", Response::CODE_BAD_REQUEST, 400);
                    return false;
                }
            }
            $updates[] = 'listen_port = ?';
            $args[] = $port;
        }

        if (isset($body['target_host'])) {
            $updates[] = 'target_host = ?';
            $args[] = (string)$body['target_host'];
        }

        if (isset($body['remark'])) {
            $updates[] = 'remark = ?';
            $args[] = (string)$body['remark'];
        }

        if (isset($body['status'])) {
            $updates[] = 'status = ?';
            $args[] = (int)$body['status'];
        }

        if (isset($body['ssl_auto_renew'])) {
            $updates[] = 'ssl_auto_renew = ?';
            $args[] = (int)$body['ssl_auto_renew'] ? 1 : 0;
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
            Response::fail($context['response'], '域名不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        if ((int)$row['is_primary'] === 1) {
            Response::fail($response, '不能删除主域名，请先设置其他域名为主域名', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

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

        Database::execute('UPDATE domains SET is_primary = 0, updated_at = NOW()');
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

        $row = Database::fetch('SELECT id, domain FROM domains WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($response, '域名不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        Database::execute(
            'UPDATE domains SET ssl_enabled = 1, ssl_status = ?, ssl_error = "" WHERE id = ?',
            ['pending', $id]
        );

        $result = SslService::issueCertificate($row['domain']);

        if ($result['success']) {
            Database::execute(
                'UPDATE domains SET ssl_status = ?, ssl_expire_at = ?, ssl_cert_path = ?, ssl_key_path = ?, ssl_error = "", ssl_last_renew_at = NOW() WHERE id = ?',
                ['issued', $result['expire_at'], $result['cert_path'], $result['key_path'], $id]
            );
            return [
                'message'     => 'SSL 证书申请成功',
                'expire_at'   => $result['expire_at'],
                'cert_path'   => $result['cert_path'],
                'key_path'    => $result['key_path'],
            ];
        }

        Database::execute(
            'UPDATE domains SET ssl_status = ?, ssl_error = ? WHERE id = ?',
            ['failed', mb_substr($result['message'] . ': ' . ($result['output'] ?? ''), 0, 500), $id]
        );
        Response::fail($response, 'SSL 证书申请失败：' . $result['message'], Response::CODE_ERROR, 500);
        return false;
    }

    /**
     * 续费 SSL 证书
     */
    public function renewSsl(array $context, array $params)
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

        $row = Database::fetch('SELECT id, domain FROM domains WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            Response::fail($response, '域名不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $result = SslService::renewCertificate($row['domain']);

        if ($result['success']) {
            Database::execute(
                'UPDATE domains SET ssl_status = "issued", ssl_expire_at = ?, ssl_last_renew_at = NOW(), ssl_error = "" WHERE id = ?',
                [$result['expire_at'], $id]
            );
            return [
                'message'     => '证书续费成功',
                'expire_at'   => $result['expire_at'],
            ];
        }

        Database::execute(
            'UPDATE domains SET ssl_error = ? WHERE id = ?',
            [mb_substr($result['message'], 0, 500), $id]
        );
        Response::fail($response, '证书续费失败：' . $result['message'], Response::CODE_ERROR, 500);
        return false;
    }

    /**
     * 批量续费所有即将过期证书
     */
    public function renewAll(array $context, array $params)
    {
        if (AdminAuth::authenticate($context) === null) {
            return false;
        }

        $result = SslService::renewAllExpiring();
        return $result;
    }

    /**
     * 切换自动续费
     */
    public function toggleAutoRenew(array $context, array $params)
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
        $autoRenew = (int)($body['ssl_auto_renew'] ?? 0) ? 1 : 0;

        Database::execute('UPDATE domains SET ssl_auto_renew = ?, updated_at = NOW() WHERE id = ?', [$autoRenew, $id]);

        return [
            'message' => $autoRenew ? '已开启自动续费' : '已关闭自动续费',
            'ssl_auto_renew' => $autoRenew,
        ];
    }

    /**
     * 部署 Nginx
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

        Database::execute('UPDATE domains SET nginx_deployed = 1, updated_at = NOW() WHERE status = 1');

        return [
            'message' => 'Nginx 配置已部署并重载',
            'output'  => $reloadResult['output'],
        ];
    }

    /**
     * 同步所有域名 Nginx
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
