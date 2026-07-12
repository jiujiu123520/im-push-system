<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Database;
use App\Service\MailService;
use App\Service\Response;

/**
 * 系统设置控制器（管理员鉴权）
 *
 * 路由：
 *   GET  /admin/settings/mail           获取邮件配置
 *   POST /admin/settings/mail           保存邮件配置
 *   POST /admin/settings/mail/test      测试邮件配置
 */
class SettingsController
{
    /**
     * 获取邮件配置
     * 路由：GET /admin/settings/mail
     */
    public function getMailConfig(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $config = MailService::getConfig();

        return [
            'enabled'     => $config['enabled'],
            'host'        => $config['host'],
            'port'        => $config['port'],
            'username'    => $config['username'],
            'password'    => $config['password'] !== '' ? '******' : '',
            'encryption'  => $config['encryption'],
            'sender_name' => $config['sender_name'],
        ];
    }

    /**
     * 保存邮件配置
     * 路由：POST /admin/settings/mail
     *
     * 请求体：
     *   {
     *     "enabled": bool,
     *     "host": string,
     *     "port": int|string,
     *     "username": string,
     *     "password": string,
     *     "encryption": "tls" | "ssl" | "",
     *     "sender_name": string
     *   }
     */
    public function saveMailConfig(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $body = $this->parseBody($context);

        $enabled     = (bool)($body['enabled'] ?? false);
        $host        = (string)($body['host'] ?? 'smtp.qq.com');
        $port        = (string)($body['port'] ?? '587');
        $username    = (string)($body['username'] ?? '');
        $password    = (string)($body['password'] ?? '');
        $encryption  = (string)($body['encryption'] ?? 'tls');
        $sender_name = (string)($body['sender_name'] ?? '');

        // 如果密码是 ******，说明用户不想修改密码，从当前配置读取
        if ($password === '******') {
            $currentConfig = MailService::getConfig();
            $password = $currentConfig['password'];
        }

        // 验证
        if ($enabled && ($username === '' || $password === '')) {
            Response::fail($response, '启用邮件服务时，发件人邮箱和授权码不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $configData = [
            'enabled'     => $enabled,
            'host'        => $host,
            'port'        => $port,
            'username'    => $username,
            'password'    => $password,
            'encryption'  => $encryption,
            'sender_name' => $sender_name,
        ];

        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO admin_settings (config_key, config_value)
                 VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = ?'
            );
            $stmt->execute(['mail_config', json_encode($configData, JSON_UNESCAPED_UNICODE), json_encode($configData, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            Response::fail($response, '保存失败：' . $e->getMessage(), Response::CODE_INTERNAL, 500);
            return false;
        }

        return ['message' => '邮件配置已保存'];
    }

    /**
     * 测试邮件配置
     * 路由：POST /admin/settings/mail/test
     *
     * 请求体：
     *   {
     *     "to": string,
     *     "host": string,
     *     "port": int|string,
     *     "username": string,
     *     "password": string,
     *     "encryption": string,
     *     "sender_name": string
     *   }
     */
    public function testMailConfig(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $body = $this->parseBody($context);

        $to          = (string)($body['to'] ?? '');
        $host        = (string)($body['host'] ?? 'smtp.qq.com');
        $port        = (string)($body['port'] ?? '587');
        $username    = (string)($body['username'] ?? '');
        $password    = (string)($body['password'] ?? '');
        $encryption  = (string)($body['encryption'] ?? 'tls');
        $sender_name = (string)($body['sender_name'] ?? '');

        if ($to === '') {
            Response::fail($response, '请输入测试收件人邮箱', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Response::fail($response, '收件人邮箱格式不正确', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $config = [
            'host'        => $host,
            'port'        => $port,
            'username'    => $username,
            'password'    => $password,
            'encryption'  => $encryption,
            'sender_name' => $sender_name,
        ];

        $success = MailService::testConfig($config, $to);

        if ($success) {
            return ['message' => '测试邮件发送成功，请检查收件箱'];
        } else {
            Response::fail($response, '测试邮件发送失败，请检查配置是否正确', Response::CODE_ERROR, 500);
            return false;
        }
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
