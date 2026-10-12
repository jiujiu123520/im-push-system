<?php
declare(strict_types=1);

namespace App\Service;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\Encryption;

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
     * 单条通知 payload 的字节上限
     *
     * 直接取 minishlink/web-push 的常量，避免两边数值漂移：超过该值
     * queueNotification() 会抛「Size of payload must not be greater than 4078 octets」。
     */
    private const MAX_PAYLOAD_BYTES = Encryption::MAX_PAYLOAD_LENGTH;

    /** 正文/标题被截断后追加的省略号 */
    private const TRUNCATED_MARK = '…';

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

        // Web Push 对单条 payload 有 4078 字节硬上限，超出时 queueNotification() 直接抛异常，
        // 整条通知发不出去；且这种失败不属于「订阅失效」（expired=false），订阅不会被标记异常，
        // 用户端表现为静默丢消息——这正是长正文消息（4KB+）一直收不到的原因。
        // 这里把正文/标题裁到上限内，保证通知一定发得出去：手机上看摘要，
        // 全文仍可在 PWA 消息列表里查看。data 不参与裁剪，它带着 message_id，
        // 丢了会导致点通知跳不到对应消息。
        $notification = self::fitPayload($notification);

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

            // 网关明确回 404/410 = 订阅已过期/被撤销：立刻置为无效。
            // 这里（而非各调用方）统一处理，是因为本方法就是所有 Web Push 的唯一出口；
            // 以前只有 PWA 页的「测试推送」会标记失效，正常投递链路把 expired 丢掉，
            // 结果订阅早就死了、status 却一直是 1，服务端持续向死 endpoint 推且无人察觉。
            if ($expired) {
                self::markSubscriptionInvalid($subscription);
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
     * 把通知内容裁剪到 Web Push 单条上限之内
     *
     * 优先保留 data（携带 message_id，丢失会导致点击通知跳转失败），先压正文，正文压到
     * 空仍超限时再压标题。
     *
     * @param array $notification ['title' => string, 'body' => string, 'data' => array]
     * @return array 裁剪后的通知内容
     */
    private static function fitPayload(array $notification): array
    {
        if (self::payloadBytes($notification) <= self::MAX_PAYLOAD_BYTES) {
            return $notification;
        }

        $overflow = self::payloadBytes($notification) - self::MAX_PAYLOAD_BYTES;

        // 正文是最占空间的部分，先裁它；多裁一个省略号的宽度
        $notification['body'] = self::cutTail(
            (string)($notification['body'] ?? ''),
            $overflow + strlen(self::TRUNCATED_MARK)
        );

        if (self::payloadBytes($notification) <= self::MAX_PAYLOAD_BYTES) {
            return $notification;
        }

        // 正文已清空仍超限：只能再裁标题
        $notification['title'] = self::cutTail(
            (string)($notification['title'] ?? ''),
            self::payloadBytes($notification) - self::MAX_PAYLOAD_BYTES + strlen(self::TRUNCATED_MARK)
        );

        return $notification;
    }

    /**
     * 计算通知内容序列化后的字节数
     *
     * @param array $notification
     * @return int
     */
    private static function payloadBytes(array $notification): int
    {
        $json = json_encode($notification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? 0 : strlen($json);
    }

    /**
     * 从尾部裁掉若干字节，并补上省略号
     *
     * 按字节裁切但不切断 UTF-8 字符（中文一个字 3 字节，切在字符中间会得到乱码）。
     *
     * @param string $text     原文本
     * @param int    $cutBytes 需要裁掉的字节数
     * @return string
     */
    private static function cutTail(string $text, int $cutBytes): string
    {
        $keep = strlen($text) - $cutBytes;
        if ($keep <= 0) {
            return '';
        }
        if ($keep >= strlen($text)) {
            return $text;
        }

        $cut = substr($text, 0, $keep);

        // 去掉末尾残留的 UTF-8 续字节（10xxxxxx）
        while ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0xC0) === 0x80) {
            $cut = substr($cut, 0, -1);
        }
        // 末尾若是多字节字符的首字节（11xxxxxx），说明该字符被切断了，一并去掉
        if ($cut !== '' && ord($cut[strlen($cut) - 1]) >= 0xC0) {
            $cut = substr($cut, 0, -1);
        }

        return $cut . self::TRUNCATED_MARK;
    }

    /**
     * 标记订阅失效（网关返回 404/410 表示订阅已过期/取消）
     *
     * 定位顺序：主键 id → endpoint → device_id。
     * 优先用 id 是因为唯一键是 (device_id, push_key_id)，浏览器轮换 endpoint 时是
     * 原地更新同一行，拿旧 endpoint 反查会定位不到目标行。
     *
     * @param array $subscription 订阅信息，可含 id / endpoint / device_id
     * @return void
     */
    public static function markSubscriptionInvalid(array $subscription): void
    {
        $id       = (int)($subscription['id'] ?? 0);
        $endpoint = trim((string)($subscription['endpoint'] ?? ''));
        $deviceId = trim((string)($subscription['device_id'] ?? ''));

        if ($id <= 0 && $endpoint === '' && $deviceId === '') {
            return;
        }

        try {
            if ($id > 0) {
                Database::execute('UPDATE web_push_subscriptions SET status = 0 WHERE id = ?', [$id]);
            } elseif ($endpoint !== '') {
                Database::execute('UPDATE web_push_subscriptions SET status = 0 WHERE endpoint = ?', [$endpoint]);
            } else {
                Database::execute('UPDATE web_push_subscriptions SET status = 0 WHERE device_id = ?', [$deviceId]);
            }

            // 必须留痕：死订阅被静默跳过时，后台看不出「哪台设备什么时候失联」，
            // 现场只能靠翻 nginx 日志，这也是之前排查「PWA 疑似被取消订阅」最费时的地方。
            error_log('[WebPushService] 订阅已失效并置为无效: id=' . $id
                . ' device_id=' . $deviceId
                . ' endpoint=' . substr($endpoint, 0, 60));
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
