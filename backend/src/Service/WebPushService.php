<?php
declare(strict_types=1);

namespace App\Service;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;

/**
 * Web Push 推送服务（PWA + iOS Safari 主屏幕）
 *
 * 使用 minishlink/web-push 库处理：
 *   - VAPID（RFC 8292）密钥生成与签名
 *   - 消息加密（RFC 8291，ECDH + HKDF + AES-128-GCM）
 *   - HTTP Web Push（RFC 8030）投递
 *
 * VAPID 配置存储在 admin_settings 表：
 *   settings_web_push: JSON {
 *     enabled: bool,         是否启用（默认 true）
 *     subject: string,       VAPID subject（mailto: 或 https:）
 *     public_key: string,    VAPID 公钥（base64url，无 padding）
 *     private_key: string,   VAPID 私钥（ENC: 前缀 AES 加密存储）
 *   }
 *
 * 密钥首次使用时自动生成，无需手动配置。
 */
class WebPushService
{
    /** admin_settings 配置键 */
    private const CONFIG_KEY = 'settings_web_push';

    /** VAPID subject 默认值 */
    private const DEFAULT_SUBJECT = 'mailto:admin@example.com';

    /**
     * 获取（必要时自动生成）VAPID 公钥
     *
     * 供前端 PushManager.subscribe 使用（applicationServerKey）。
     *
     * @return string base64url 编码的 VAPID 公钥
     */
    public static function getPublicKey(): string
    {
        $config = self::ensureVapid();
        return (string)($config['public_key'] ?? '');
    }

    /**
     * 向单个订阅发送 Web Push 通知
     *
     * @param array  $subscription 订阅信息 ['endpoint', 'p256dh', 'auth']
     * @param string $title        通知标题
     * @param string $body         通知内容
     * @param array  $payload      自定义数据（放入 notification.data）
     * @return array ['success'=>bool, 'message'=>string, 'expired'=>bool]
     */
    public static function send(array $subscription, string $title, string $body, array $payload = []): array
    {
        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $p256dh   = trim((string)($subscription['p256dh'] ?? ''));
        $auth     = trim((string)($subscription['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return ['success' => false, 'message' => '订阅信息不完整（endpoint/p256dh/auth 缺失）', 'expired' => false];
        }

        $config = self::ensureVapid();

        // 构造通知 payload
        $notification = [
            'title' => $title,
            'body'  => $body,
        ];
        if (!empty($payload)) {
            $notification['data'] = $payload;
        }
        $bodyJson = json_encode($notification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($bodyJson === false) {
            return ['success' => false, 'message' => '通知内容 JSON 编码失败', 'expired' => false];
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject'    => (string)($config['subject'] ?? self::DEFAULT_SUBJECT),
                    'publicKey'  => (string)($config['public_key'] ?? ''),
                    'privateKey' => (string)($config['private_key'] ?? ''),
                ],
            ]);

            $sub = Subscription::create([
                'endpoint' => $endpoint,
                'keys'     => [
                    'p256dh' => $p256dh,
                    'auth'   => $auth,
                ],
            ]);

            $webPush->queueNotification($sub, $bodyJson);

            $success = false;
            $reason  = '';
            $expired = false;
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $success = true;
                } else {
                    $reason  = $report->getReason();
                    $expired = $report->isSubscriptionExpired();
                }
            }
        } catch (\Throwable $e) {
            error_log('[WebPushService] 发送异常: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Web Push 发送异常: ' . $e->getMessage(), 'expired' => false];
        }

        if ($success) {
            return ['success' => true, 'message' => 'ok', 'expired' => false];
        }

        return [
            'success' => false,
            'message' => $reason !== '' ? $reason : 'Web Push 推送失败',
            'expired' => $expired,
        ];
    }

    /**
     * 标记订阅失效（网关返回 404/410 表示订阅已过期/取消）
     *
     * @param string $endpoint
     * @return void
     */
    public static function markSubscriptionInvalid(string $endpoint): void
    {
        if ($endpoint === '') {
            return;
        }
        try {
            Database::execute(
                'UPDATE web_push_subscriptions SET status = 0 WHERE endpoint = ?',
                [$endpoint]
            );
        } catch (\Throwable $e) {
            error_log('[WebPushService] 标记订阅失效失败: ' . $e->getMessage());
        }
    }

    /**
     * 读取 VAPID 配置
     *
     * @return array
     */
    public static function getConfig(): array
    {
        $row = false;
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                [self::CONFIG_KEY]
            );
        } catch (\Throwable $e) {
            error_log('[WebPushService] 读取配置失败: ' . $e->getMessage());
        }

        if ($row === false) {
            return [
                'enabled'     => true,
                'subject'     => self::DEFAULT_SUBJECT,
                'public_key'  => '',
                'private_key' => '',
            ];
        }

        $config = json_decode((string)$row['config_value'], true);
        if (!is_array($config)) {
            $config = [];
        }

        $config = array_merge([
            'enabled'     => true,
            'subject'     => self::DEFAULT_SUBJECT,
            'public_key'  => '',
            'private_key' => '',
        ], $config);

        // 解密 ENC: 前缀的 private_key
        if (!empty($config['private_key']) && strpos((string)$config['private_key'], 'ENC:') === 0) {
            try {
                $decrypted = Aes::decryptString(substr((string)$config['private_key'], 4));
                if ($decrypted !== null) {
                    $config['private_key'] = $decrypted;
                }
            } catch (\Throwable $e) {
                error_log('[WebPushService] private_key 解密失败: ' . $e->getMessage());
            }
        }

        return $config;
    }

    /**
     * 确保 VAPID 密钥已生成（未生成则自动生成并保存）
     *
     * @return array 完整配置（含明文 private_key）
     */
    public static function ensureVapid(): array
    {
        $config = self::getConfig();

        if (empty($config['public_key']) || empty($config['private_key'])) {
            try {
                $keys = VAPID::createVapidKeys();
                $config['public_key']  = $keys['publicKey'];
                $config['private_key'] = $keys['privateKey'];
                self::saveConfig($config);
                error_log('[WebPushService] 已自动生成 VAPID 密钥对');
            } catch (\Throwable $e) {
                error_log('[WebPushService] 生成 VAPID 密钥失败: ' . $e->getMessage());
            }
        }

        return $config;
    }

    /**
     * 保存 VAPID 配置（private_key 用 AES 加密存储）
     *
     * @param array $config
     * @return void
     * @throws \RuntimeException
     */
    public static function saveConfig(array $config): void
    {
        // private_key AES 加密存储（防止数据库泄露后私钥直接暴露）
        // AES_KEY 未配置时降级为明文（保证功能可用），并记录日志
        if (!empty($config['private_key']) && strpos((string)$config['private_key'], 'ENC:') !== 0) {
            try {
                $config['private_key'] = 'ENC:' . Aes::encryptString((string)$config['private_key']);
            } catch (\Throwable $e) {
                error_log('[WebPushService] private_key 加密失败（AES_KEY 未配置？降级明文）: ' . $e->getMessage());
            }
        }

        $json = json_encode($config, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Web Push 配置 JSON 编码失败');
        }

        try {
            $existing = Database::fetch(
                'SELECT id FROM admin_settings WHERE config_key = ? LIMIT 1',
                [self::CONFIG_KEY]
            );

            if ($existing !== false) {
                Database::execute(
                    'UPDATE admin_settings SET config_value = ?, updated_at = NOW() WHERE config_key = ?',
                    [$json, self::CONFIG_KEY]
                );
            } else {
                Database::execute(
                    'INSERT INTO admin_settings (config_key, config_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                    [self::CONFIG_KEY, $json]
                );
            }
        } catch (\Throwable $e) {
            error_log('[WebPushService] 保存配置失败: ' . $e->getMessage());
            throw new \RuntimeException('保存 Web Push 配置失败: ' . $e->getMessage(), 0, $e);
        }
    }
}
