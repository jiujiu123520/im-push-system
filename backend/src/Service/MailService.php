<?php
declare(strict_types=1);

namespace App\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * 邮件服务
 *
 * 封装 PHPMailer，支持：
 *   - QQ 邮箱（smtp.qq.com:587，使用授权码）
 *   - 普通 SMTP 服务器
 *   - HTML 邮件模板
 *
 * 配置来源：
 *   - 优先从数据库系统设置读取（admin_settings 表）
 *   - 回退到 .env 环境变量
 */
class MailService
{
    /**
     * 发送邮件
     *
     * @param string|array $to       收件人邮箱（支持数组多个）
     * @param string       $subject  邮件主题
     * @param string       $body     邮件内容（HTML 或纯文本）
     * @param bool         $isHtml   是否为 HTML 格式
     * @return bool 是否发送成功
     */
    public static function send($to, string $subject, string $body, bool $isHtml = true): bool
    {
        $config = self::getConfig();

        if (!$config['enabled']) {
            error_log('[MailService] 邮件服务未启用');
            return false;
        }

        try {
            $mail = new PHPMailer(true);

            // SMTP 配置
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['encryption'];
            $mail->Port = (int)$config['port'];

            // 发件人
            $mail->setFrom($config['username'], $config['sender_name'] ?: 'Push Notification');

            // 收件人
            if (is_array($to)) {
                foreach ($to as $email) {
                    $mail->addAddress(trim($email));
                }
            } else {
                $mail->addAddress(trim($to));
            }

            // 回复地址
            $mail->addReplyTo($config['username'], $config['sender_name'] ?: 'No Reply');

            // 内容
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->CharSet = 'UTF-8';

            $mail->send();
            error_log("[MailService] 邮件发送成功: {$subject} -> " . (is_array($to) ? implode(',', $to) : $to));
            return true;
        } catch (PHPMailerException $e) {
            error_log("[MailService] 邮件发送失败: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * 发送设备掉线通知邮件
     *
     * @param array  $deviceInfo 设备信息
     * @param string $keyName    Key 名称
     * @param string $email      收件人邮箱（多个用逗号分隔）
     * @return bool
     */
    public static function sendOfflineNotification(array $deviceInfo, string $keyName, string $email): bool
    {
        $emails = array_filter(array_map('trim', explode(',', $email)));
        if (empty($emails)) {
            return false;
        }

        $deviceId   = $deviceInfo['device_id'] ?? '';
        $deviceName = $deviceInfo['device_name'] ?? $deviceId;
        $ip         = $deviceInfo['ip'] ?? '未知';
        $model      = $deviceInfo['device_model'] ?? '未知';
        $osVersion  = $deviceInfo['os_version'] ?? '未知';
        $offlineAt  = date('Y-m-d H:i:s');

        $subject = "[设备掉线通知] {$deviceName} 已离线";

        $body = <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>设备掉线通知</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; margin: 0; padding: 0; background: #f5f7fa; }
        .container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); overflow: hidden; }
        .header { background: linear-gradient(135deg, #6d5cff 0%, #8b5cf6 100%); padding: 24px; text-align: center; }
        .header h1 { color: #fff; margin: 0; font-size: 20px; font-weight: 600; }
        .header .icon { font-size: 36px; margin-bottom: 8px; }
        .content { padding: 24px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .info-item { background: #f8fafc; padding: 12px; border-radius: 8px; }
        .info-label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
        .info-value { font-size: 14px; color: #1e293b; font-weight: 500; word-break: break-all; }
        .footer { padding: 16px 24px; background: #f8fafc; border-top: 1px solid #e2e8f0; }
        .footer p { margin: 0; font-size: 12px; color: #94a3b8; text-align: center; }
        .warning { color: #ef4444; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">⚠️</div>
            <h1>设备掉线通知</h1>
        </div>
        <div class="content">
            <p style="color: #64748b; font-size: 14px; margin-bottom: 20px;">
                您的设备已断开连接，请及时检查设备状态。
            </p>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">设备名称</div>
                    <div class="info-value">{$deviceName}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">设备 ID</div>
                    <div class="info-value">{$deviceId}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">所属 Key</div>
                    <div class="info-value">{$keyName}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">掉线时间</div>
                    <div class="info-value warning">{$offlineAt}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">IP 地址</div>
                    <div class="info-value">{$ip}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">设备型号</div>
                    <div class="info-value">{$model}</div>
                </div>
                <div class="info-item" style="grid-column: span 2;">
                    <div class="info-label">操作系统</div>
                    <div class="info-value">{$osVersion}</div>
                </div>
            </div>
        </div>
        <div class="footer">
            <p>此邮件由推送服务系统自动发送，请勿回复。</p>
        </div>
    </div>
</body>
</html>
HTML;

        return self::send($emails, $subject, $body);
    }

    /**
     * 获取邮件配置
     *
     * 优先从数据库读取，回退到 .env
     *
     * @return array
     */
    public static function getConfig(): array
    {
        // 优先从数据库系统设置读取
        $dbConfig = self::readFromDatabase();
        if ($dbConfig['enabled']) {
            return $dbConfig;
        }

        // 回退到 .env（使用 Config::env 读取环境变量，非 Config::get）
        $config = [
            'enabled'     => false,
            'host'        => Config::env('MAIL_HOST', 'smtp.qq.com'),
            'port'        => Config::env('MAIL_PORT', '587'),
            'username'    => Config::env('MAIL_USERNAME', ''),
            'password'    => Config::env('MAIL_PASSWORD', ''),
            'encryption'  => Config::env('MAIL_ENCRYPTION', 'tls'),
            'sender_name' => Config::env('MAIL_SENDER_NAME', ''),
        ];

        // 判断是否启用（有用户名和密码就算启用）
        $config['enabled'] = $config['username'] !== '' && $config['password'] !== '';

        return $config;
    }

    /**
     * 从数据库读取邮件配置
     *
     * @return array
     */
    private static function readFromDatabase(): array
    {
        try {
            $stmt = Database::pdo()->prepare(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1'
            );
            $stmt->execute(['mail_config']);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row && $row['config_value']) {
                $data = json_decode($row['config_value'], true);
                if (is_array($data)) {
                    return [
                        'enabled'     => (bool)($data['enabled'] ?? false),
                        'host'        => $data['host'] ?? 'smtp.qq.com',
                        'port'        => $data['port'] ?? '587',
                        'username'    => $data['username'] ?? '',
                        'password'    => $data['password'] ?? '',
                        'encryption'  => $data['encryption'] ?? 'tls',
                        'sender_name' => $data['sender_name'] ?? '',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // 数据库读取失败，回退到 .env
        }

        return ['enabled' => false];
    }

    /**
     * 测试邮件配置
     *
     * @param array  $config 邮件配置
     * @param string $to     测试收件人
     * @return bool
     */
    public static function testConfig(array $config, string $to): bool
    {
        try {
            $mail = new PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['encryption'];
            $mail->Port = (int)$config['port'];
            $mail->Timeout = 10;
            $mail->ConnectionTimeout = 10;

            $mail->setFrom($config['username'], $config['sender_name'] ?: 'Push Notification');
            $mail->addAddress(trim($to));
            $mail->isHTML(true);
            $mail->Subject = '【推送系统】邮件配置测试';
            $mail->Body    = '<h3>邮件配置测试成功！</h3><p>您的邮件通知功能已正常工作。</p>';
            $mail->CharSet = 'UTF-8';

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log("[MailService] 测试邮件失败: {$e->getMessage()}");
            return false;
        }
    }
}
