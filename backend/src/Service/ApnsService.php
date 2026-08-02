<?php
declare(strict_types=1);

namespace App\Service;

/**
 * APNS (Apple Push Notification Service) 推送服务
 *
 * 使用 .p8 Auth Key（Token-Based Authentication）通过 HTTP/2 调用 APNS。
 *
 * 配置存储在 admin_settings 表：
 *   settings_apns: JSON {
 *     enabled: bool,           是否启用 APNS
 *     team_id: string,         Apple Developer Team ID（10位）
 *     key_id: string,          APNS Auth Key ID（10位）
 *     auth_key: string,        .p8 文件内容（PEM 格式，含 BEGIN/END PRIVATE KEY）
 *     bundle_id: string,       iOS APP Bundle ID（如 com.push.app）
 *     environment: string,     "production" 或 "development"
 *   }
 *
 * 使用场景：
 *   iOS 设备 WebSocket 离线时（后台/被杀），通过 APNS 投递推送，
 *   确保用户在 App 未运行时也能收到通知。
 */
class ApnsService
{
    /** APNS 生产环境 API 地址 */
    private const APNS_PRODUCTION_URL = 'https://api.push.apple.com';

    /** APNS 开发环境 API 地址 */
    private const APNS_DEVELOPMENT_URL = 'https://api.sandbox.push.apple.com';

    /** JWT Token 缓存 Key（Redis），避免每次推送都重新签名 */
    private const JWT_CACHE_KEY = 'apns:jwt_token';

    /** JWT Token 有效期（秒），APNS 最长允许 1 小时 */
    private const JWT_TTL = 3000; // 50 分钟，留 10 分钟余量

    /** APNS payload 最大字节数（RFC 8291 限制 4KB） */
    private const MAX_PAYLOAD_SIZE = 4096;

    /**
     * 同一设备两次推送的最小间隔（秒）
     * 苹果警告：大量重复推送同一设备会被视为 DoS 攻击
     * 60 秒间隔可避免被判定为攻击行为
     */
    private const MIN_PUSH_INTERVAL = 60;

    /**
     * 单设备每日推送上限
     * 苹果建议：避免对单用户高频推送，否则触发用户投诉→封号
     * 20 条/天是保守值，正常业务足够用
     */
    private const DAILY_PUSH_LIMIT_PER_DEVICE = 20;

    /** 连续失败次数达到该值时触发熔断（暂停 APNS 通道） */
    private const CIRCUIT_BREAK_THRESHOLD = 10;

    /** 熔断持续时间（秒），期间所有 APNS 推送直接拒绝 */
    private const CIRCUIT_BREAK_DURATION = 300;

    /**
     * 全局速率限制：每秒最多发送 N 条推送
     * 防止服务器 IP 被苹果临时封禁（中度封禁后果）
     */
    private const GLOBAL_RATE_LIMIT_PER_SECOND = 20;

    /** 全局速率统计窗口（秒） */
    private const GLOBAL_RATE_WINDOW = 1;

    /**
     * 重复内容去重窗口（秒）
     * 同一 token + 相同内容在 5 分钟内只推送一次
     * 苹果会监控重复推送模式
     */
    private const DEDUP_WINDOW = 300;

    /**
     * Token 失败拉黑机制
     * 同一 token 失败达到该次数后拉黑一段时间
     */
    private const TOKEN_FAIL_THRESHOLD = 3;
    private const TOKEN_BLACKLIST_DURATION = 3600; // 拉黑 1 小时

    /**
     * 新证书慢启动：配置启用后前 N 小时限制总推送量
     * 苹果对新证书有冷启动期，突然大量推送会被风控
     */
    private const SLOW_START_HOURS = 24;
    private const SLOW_START_DAILY_LIMIT = 100; // 前 24 小时最多 100 条

    /** Redis Key 前缀 */
    private const RATE_LIMIT_KEY     = 'apns:rate:';          // 限流：apns:rate:{token} = 上次推送时间戳
    private const DAILY_COUNT_KEY    = 'apns:daily:';         // 每日计数：apns:daily:{token}:{YYYYMMDD} = 次数
    private const FAIL_COUNT_KEY     = 'apns:fail_count';     // 连续失败计数
    private const CIRCUIT_BREAK_KEY  = 'apns:circuit_break';  // 熔断标记（存在=熔断中）
    private const STATS_KEY          = 'apns:stats';          // 健康度统计（hash）
    private const GLOBAL_RATE_KEY    = 'apns:global_rate';    // 全局速率统计
    private const DEDUP_KEY          = 'apns:dedup:';         // 内容去重：apns:dedup:{token_hash}:{content_hash}
    private const TOKEN_FAIL_KEY     = 'apns:token_fail:';    // token 失败计数
    private const TOKEN_BLACKLIST_KEY = 'apns:blacklist:';    // token 黑名单
    private const SLOW_START_KEY     = 'apns:slow_start';     // 新证书慢启动标记

    /**
     * 向单个 iOS 设备发送 APNS 推送
     *
     * @param string $deviceToken APNS device token（由 iOS APP 上报）
     * @param string $title       通知标题
     * @param string $body        通知内容
     * @param array  $payload     自定义数据（放入 aps.payload）
     * @param int    $badge       角标数字（0=不显示）
     * @param string $collapseId  apns-collapse-id（同 id 的推送会替换锁屏同一条通知，防刷屏）
     * @return array ['success'=>bool, 'message'=>string, 'apns_id'=>string]
     */
    public static function send(string $deviceToken, string $title, string $body, array $payload = [], int $badge = 0, string $collapseId = ''): array
    {
        $deviceToken = trim($deviceToken);
        if ($deviceToken === '') {
            return ['success' => false, 'message' => 'device token 为空', 'apns_id' => ''];
        }

        // 1. Token 格式预校验（APNS token 为 64 位十六进制字符串）
        //    避免向 APNS 发送格式错误的 token，减少无效请求（防封号关键）
        if (!self::isValidTokenFormat($deviceToken)) {
            self::invalidateToken($deviceToken);
            return ['success' => false, 'message' => 'device token 格式无效（应为 64 位 hex）', 'apns_id' => ''];
        }

        $config = self::getConfig();
        if (empty($config['enabled'])) {
            return ['success' => false, 'message' => 'APNS 未启用', 'apns_id' => ''];
        }

        $bundleId = trim($config['bundle_id'] ?? '');
        if ($bundleId === '') {
            return ['success' => false, 'message' => '未配置 Bundle ID', 'apns_id' => ''];
        }

        // 2. 熔断检查：连续失败次数过多时暂停 APNS 通道（防封号核心）
        //    苹果会监控异常推送模式，连续失败说明证书/token 有问题，继续发会被风控
        if (self::isCircuitBroken()) {
            return ['success' => false, 'message' => 'APNS 通道熔断中（连续失败过多，已暂停 5 分钟）', 'apns_id' => ''];
        }

        // 3. Token 黑名单检查：失败次数过多的 token 暂时拉黑
        //    继续给已知有问题的 token 发推送，会被苹果监控为异常行为
        if (self::isTokenBlacklisted($deviceToken)) {
            return ['success' => false, 'message' => '设备 token 已被拉黑（近期失败过多，1 小时后自动解除）', 'apns_id' => ''];
        }

        // 4. 新证书慢启动检查：配置启用后前 24 小时限制总推送量
        //    苹果对新证书有冷启动期，突然大量推送会被风控
        $slowStartCheck = self::checkSlowStart();
        if ($slowStartCheck !== null) {
            return ['success' => false, 'message' => $slowStartCheck, 'apns_id' => ''];
        }

        // 5. 全局速率限制：每秒最多 N 条推送
        //    防止服务器 IP 被苹果临时封禁（中度封禁：几小时～几天全部设备推送失败）
        $globalRateCheck = self::checkGlobalRate();
        if ($globalRateCheck !== null) {
            return ['success' => false, 'message' => $globalRateCheck, 'apns_id' => ''];
        }

        // 6. 重复内容去重：同一 token + 相同内容在 5 分钟内只推送一次
        //    苹果会监控重复推送模式，大量重复会被判定为垃圾推送
        $dedupCheck = self::checkDedup($deviceToken, $title, $body);
        if ($dedupCheck !== null) {
            return ['success' => false, 'message' => $dedupCheck, 'apns_id' => ''];
        }

        // 7. 单设备限流：最小推送间隔 + 每日上限
        $rateLimit = self::checkRateLimit($deviceToken);
        if ($rateLimit !== null) {
            return ['success' => false, 'message' => $rateLimit, 'apns_id' => ''];
        }

        // 构造 APNS payload（RFC 8291）
        $apsPayload = [
            'aps' => [
                'alert' => [
                    'title' => $title,
                    'body'  => $body,
                ],
                'sound'    => 'default',
                'mutable-content' => 1,  // 允许 iOS 通知服务扩展处理
            ],
        ];
        if ($badge > 0) {
            $apsPayload['aps']['badge'] = $badge;
        }
        // 合并自定义数据（放在 aps 同级，客户端可通过 userInfo 访问）
        if (!empty($payload)) {
            $apsPayload = array_merge($apsPayload, $payload);
        }

        $bodyJson = json_encode($apsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 4. Payload 大小预检（RFC 8291 限制 4KB）
        //    超大的 payload 会被 APNS 拒绝，频繁超限会被监控
        $payloadSize = strlen($bodyJson);
        if ($payloadSize > self::MAX_PAYLOAD_SIZE) {
            // 自动截断 body 内容，保留 title（截断策略：砍 payload 自定义数据，再砍 body）
            $body = self::truncateForPayload($title, $body, $payload, $badge);
            $apsPayload['aps']['alert']['body'] = $body;
            $bodyJson = json_encode($apsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // 仍然超限则直接拒绝（不发送，避免浪费配额）
            if (strlen($bodyJson) > self::MAX_PAYLOAD_SIZE) {
                self::recordFailure();
                return ['success' => false, 'message' => 'payload 超过 4KB 限制（' . $payloadSize . ' 字节），已拒绝发送', 'apns_id' => ''];
            }
        }

        // 获取 APNS API 地址
        $apiBase = ($config['environment'] ?? 'production') === 'development'
            ? self::APNS_DEVELOPMENT_URL
            : self::APNS_PRODUCTION_URL;
        $url = $apiBase . '/3/device/' . $deviceToken;

        // 获取 JWT Token
        $jwt = self::getJwtToken($config);
        if ($jwt === '') {
            return ['success' => false, 'message' => '生成 APNS JWT 失败，请检查 .p8 密钥配置', 'apns_id' => ''];
        }

        // 发送 HTTP/2 请求
        $ch = curl_init();
        $headers = [
            'Content-Type: application/json',
            'Authorization: bearer ' . $jwt,
            'apns-topic: ' . $bundleId,
            'apns-push-type: alert',     // alert=普通通知 background=静默推送
            'apns-priority: 10',         // 10=立即投递 5=省电模式
        ];

        // apns-collapse-id：相同 id 的推送会替换锁屏上的同一条通知
        // 用于告警合并场景，避免锁屏刷屏（苹果防 DoS 的官方推荐机制）
        // collapse-id 最多 64 字节
        if ($collapseId !== '') {
            $collapseId = substr($collapseId, 0, 64);
            $headers[] = 'apns-collapse-id: ' . $collapseId;
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bodyJson,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,  // 强制 HTTP/2
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER         => true,  // 返回 header 用于提取 apns-id
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            return ['success' => false, 'message' => 'APNS 请求失败: ' . $error, 'apns_id' => ''];
        }

        // 提取 apns-id（用于排查问题）
        $apnsId = '';
        if (is_string($response)) {
            if (preg_match('/apns-id:\s*([^\r\n]+)/i', $response, $m)) {
                $apnsId = trim($m[1]);
            }
        }

        // HTTP/2 APNS 成功状态码是 200
        if ($httpCode === 200) {
            // 成功：重置连续失败计数 + 记录限流时间戳 + 更新统计
            self::recordSuccess($deviceToken);
            return ['success' => true, 'message' => 'APNS 推送成功', 'apns_id' => $apnsId];
        }

        // 解析错误响应 body（header 之后的内容）
        $bodyStart = is_string($response) ? strpos($response, "\r\n\r\n") : false;
        $errorBody = $bodyStart !== false ? substr($response, $bodyStart + 4) : '';
        $errorInfo = json_decode($errorBody, true);
        $reason    = $errorInfo['reason'] ?? 'unknown';
        $errMsg    = self::explainError($reason);

        // 记录失败（用于熔断判断）
        self::recordFailure();

        // 失效 token 自动清理（防封号关键）
        // 以下错误表示 token 已不可用，继续发送会被苹果监控为异常推送
        if (in_array($reason, ['Unregistered', 'BadDeviceToken', 'DeviceTokenNotForTopic', 'BadTopic'], true)) {
            self::invalidateToken($deviceToken);
        }

        // Token 失败计数（达到阈值拉黑，避免持续给问题 token 发推送）
        self::recordTokenFailure($deviceToken);

        // 429 限流：主动触发熔断，避免继续发送被风控
        if ($httpCode === 429 || $reason === 'TooManyRequests') {
            self::triggerCircuitBreak();
        }

        return [
            'success' => false,
            'message' => "APNS 返回 {$httpCode}: {$reason}（{$errMsg}）",
            'apns_id' => $apnsId,
        ];
    }

    /**
     * 批量发送 APNS 推送（逐个发送，APNS 不支持真正的批量）
     *
     * @param array  $deviceTokens APNS device token 数组
     * @param string $title
     * @param string $body
     * @param array  $payload
     * @return array ['success_count'=>int, 'fail_count'=>int, 'detail'=>array]
     */
    public static function sendBatch(array $deviceTokens, string $title, string $body, array $payload = []): array
    {
        $success = 0;
        $fail    = 0;
        $detail  = [];

        foreach ($deviceTokens as $token) {
            $token = trim((string)$token);
            if ($token === '') continue;

            $r = self::send($token, $title, $body, $payload);
            if ($r['success']) {
                $success++;
            } else {
                $fail++;
            }
            $detail[] = [
                'token'   => substr($token, 0, 16) . '...',
                'success' => $r['success'],
                'message' => $r['message'],
            ];
        }

        return [
            'success_count' => $success,
            'fail_count'    => $fail,
            'detail'        => $detail,
        ];
    }

    /**
     * 生成 APNS JWT Token（带 Redis 缓存）
     *
     * 使用 .p8 私钥按 ES256 算法签名，JWT header 含 kid（Key ID），
     * payload 含 iss（Team ID）+ iat（签发时间）。
     *
     * @param array $config APNS 配置
     * @return string JWT token，失败返回 ''
     */
    private static function getJwtToken(array $config): string
    {
        $redis = Redis::getInstance();

        // 先尝试从缓存读取
        try {
            $cached = $redis->get(self::JWT_CACHE_KEY);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (\Throwable $e) {}

        $teamId  = trim($config['team_id'] ?? '');
        $keyId   = trim($config['key_id'] ?? '');
        $authKey = trim($config['auth_key'] ?? '');

        if ($teamId === '' || $keyId === '' || $authKey === '') {
            return '';
        }

        // 确保 .p8 私钥格式正确
        if (!str_contains($authKey, 'BEGIN PRIVATE KEY')) {
            $authKey = "-----BEGIN PRIVATE KEY-----\n" .
                       wordwrap($authKey, 64, "\n", true) .
                       "\n-----END PRIVATE KEY-----";
        }

        // 构造 JWT
        $header  = ['alg' => 'ES256', 'kid' => $keyId, 'typ' => 'JWT'];
        $payload = ['iss' => $teamId, 'iat' => time()];

        $headerJson  = json_encode($header, JSON_UNESCAPED_SLASHES);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headerB64   = self::base64UrlEncode($headerJson);
        $payloadB64  = self::base64UrlEncode($payloadJson);
        $signingInput = $headerB64 . '.' . $payloadB64;

        // ES256 签名
        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $authKey, 'sha256');
        if (!$ok) {
            return '';
        }

        // ES256 签名是 ASN.1 DER 格式，需要转换为原始 r||s 拼接（64字节）
        $rawSig = self::derToRaw($signature);
        if ($rawSig === '') {
            return '';
        }

        $jwt = $signingInput . '.' . self::base64UrlEncode($rawSig);

        // 缓存到 Redis
        try {
            $redis->setex(self::JWT_CACHE_KEY, self::JWT_TTL, $jwt);
        } catch (\Throwable $e) {}

        return $jwt;
    }

    /**
     * Base64 URL Safe 编码（RFC 7515）
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * 将 ASN.1 DER 签名转换为 ES256 原始 r||s 格式（64字节）
     *
     * DER 格式: 0x30 <len> 0x02 <r_len> <r> 0x02 <s_len> <s>
     * APNS 要求: <r(32字节)><s(32字节)>
     */
    private static function derToRaw(string $der): string
    {
        if (strlen($der) < 8) return '';
        $offset = 0;
        // SEQUENCE tag
        if (ord($der[$offset++]) !== 0x30) return '';
        // SEQUENCE length（跳过）
        $seqLen = ord($der[$offset++]);
        if ($seqLen & 0x80) {
            $lenBytes = $seqLen & 0x7f;
            $offset += $lenBytes;
        }
        // INTEGER r
        if (ord($der[$offset++]) !== 0x02) return '';
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;
        // 去除前导零（DER 编码规则）
        while (strlen($r) > 1 && $r[0] === "\x00") {
            $r = substr($r, 1);
        }
        // INTEGER s
        if (ord($der[$offset++]) !== 0x02) return '';
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);
        // 去除前导零
        while (strlen($s) > 1 && $s[0] === "\x00") {
            $s = substr($s, 1);
        }
        // 补齐到 32 字节
        $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }

    /**
     * 解释 APNS 错误码
     */
    private static function explainError(string $reason): string
    {
        $map = [
            'BadDeviceToken'        => 'device token 无效或与证书环境不匹配（开发/生产证书弄混）',
            'DeviceTokenNotForTopic'=> 'device token 与 Bundle ID 不匹配',
            'Unregistered'          => 'device token 已失效（用户卸载APP或系统更新），应从数据库清除',
            'PayloadEmpty'          => '推送内容为空',
            'TopicDisallowed'       => 'Bundle ID 未被允许',
            'BadExpirationDate'     => '过期时间格式错误',
            'BadMessageId'          => 'apns-id 格式错误',
            'BadPriority'           => '优先级参数错误',
            'BadTopic'              => 'Bundle ID 格式错误',
            'Forbidden'             => '认证被拒绝，检查 Auth Key 是否正确',
            'InvalidProviderToken'  => 'JWT Token 无效，检查 Team ID / Key ID / .p8 私钥',
            'MissingProviderToken'  => '缺少 Authorization header',
            'ExpiredProviderToken'  => 'JWT Token 已过期',
            'TooManyRequests'       => '请求频率过高，APNS 限流',
            'InternalServerError'   => 'APNS 服务端错误，可重试',
            'ServiceUnavailable'    => 'APNS 服务不可用，可重试',
            'Shutdown'              => 'APNS 服务正在关闭维护',
        ];
        return $map[$reason] ?? '未知错误';
    }

    /**
     * 获取 APNS 配置
     *
     * @return array
     */
    public static function getConfig(): array
    {
        $row = Database::fetch(
            'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
            ['settings_apns']
        );
        if ($row === false) {
            return [
                'enabled'     => false,
                'team_id'     => '',
                'key_id'      => '',
                'auth_key'    => '',
                'bundle_id'   => '',
                'environment' => 'production',
            ];
        }
        $config = json_decode((string)$row['config_value'], true);
        if (!is_array($config)) {
            $config = [];
        }
        return array_merge([
            'enabled'     => false,
            'team_id'     => '',
            'key_id'      => '',
            'auth_key'    => '',
            'bundle_id'   => '',
            'environment' => 'production',
        ], $config);
    }

    /**
     * 保存 APNS 配置
     *
     * @param array $config
     * @return void
     */
    public static function saveConfig(array $config): void
    {
        $json = json_encode($config, JSON_UNESCAPED_UNICODE);

        $existing = Database::fetch(
            'SELECT id FROM admin_settings WHERE config_key = ? LIMIT 1',
            ['settings_apns']
        );

        if ($existing !== false) {
            Database::execute(
                'UPDATE admin_settings SET config_value = ?, updated_at = NOW() WHERE config_key = ?',
                [$json, 'settings_apns']
            );
        } else {
            Database::execute(
                'INSERT INTO admin_settings (config_key, config_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                ['settings_apns', $json]
            );
        }

        // 配置变更后清除 JWT 缓存
        try {
            Redis::getInstance()->del(self::JWT_CACHE_KEY);
        } catch (\Throwable $e) {}
    }

    /**
     * 验证 APNS 配置是否有效（不实际发送推送，仅检查参数完整性）
     *
     * @return array ['valid'=>bool, 'message'=>string]
     */
    public static function validateConfig(): array
    {
        $config = self::getConfig();

        if (empty($config['enabled'])) {
            return ['valid' => false, 'message' => 'APNS 未启用'];
        }
        if (trim($config['team_id'] ?? '') === '') {
            return ['valid' => false, 'message' => 'Team ID 不能为空'];
        }
        if (trim($config['key_id'] ?? '') === '') {
            return ['valid' => false, 'message' => 'Key ID 不能为空'];
        }
        if (trim($config['auth_key'] ?? '') === '') {
            return ['valid' => false, 'message' => '.p8 Auth Key 不能为空'];
        }
        if (trim($config['bundle_id'] ?? '') === '') {
            return ['valid' => false, 'message' => 'Bundle ID 不能为空'];
        }

        // 尝试生成 JWT 验证私钥格式
        $jwt = self::getJwtToken($config);
        if ($jwt === '') {
            return ['valid' => false, 'message' => '.p8 私钥格式错误或已损坏，无法生成 JWT'];
        }

        return ['valid' => true, 'message' => 'APNS 配置有效'];
    }

    /**
     * 标记设备的 APNS token 失效（收到 Unregistered 错误时调用）
     *
     * @param string $deviceToken
     * @return void
     */
    public static function invalidateToken(string $deviceToken): void
    {
        if ($deviceToken === '') return;
        try {
            Database::execute(
                'UPDATE devices SET apns_active = 0 WHERE apns_token = ?',
                [$deviceToken]
            );
        } catch (\Throwable $e) {}
    }

    // ============================================================
    //  防封号优化：Token 格式校验 / 限流 / 熔断 / 健康度统计
    // ============================================================

    /**
     * 校验 APNS device token 格式
     *
     * APNS token 是 64 位十六进制字符串（32 字节）。
     * 格式错误的 token 不应发送给 APNS，否则会增加无效请求被风控。
     *
     * @param string $token
     * @return bool
     */
    private static function isValidTokenFormat(string $token): bool
    {
        // 长度 64，纯十六进制
        return strlen($token) === 64 && ctype_xdigit($token);
    }

    /**
     * 检查限流：同一设备推送间隔 + 每日上限
     *
     * 返回 null 表示通过，返回字符串表示被限流的原因
     *
     * @param string $deviceToken
     * @return string|null
     */
    private static function checkRateLimit(string $deviceToken): ?string
    {
        try {
            $redis = Redis::getInstance();
            $tokenHash = md5($deviceToken);

            // 1. 最小间隔检查
            $rateKey = self::RATE_LIMIT_KEY . $tokenHash;
            $lastPush = $redis->get($rateKey);
            if (is_string($lastPush) && $lastPush !== '') {
                $elapsed = time() - (int)$lastPush;
                if ($elapsed < self::MIN_PUSH_INTERVAL) {
                    $wait = self::MIN_PUSH_INTERVAL - $elapsed;
                    return "推送过于频繁，距上次推送仅 {$elapsed} 秒（最小间隔 " . self::MIN_PUSH_INTERVAL . " 秒，请 {$wait} 秒后重试）";
                }
            }

            // 2. 每日上限检查
            $today = date('Ymd');
            $dailyKey = self::DAILY_COUNT_KEY . $tokenHash . ':' . $today;
            $dailyCount = (int)$redis->get($dailyKey);
            if ($dailyCount >= self::DAILY_PUSH_LIMIT_PER_DEVICE) {
                return "设备今日推送已达上限（" . self::DAILY_PUSH_LIMIT_PER_DEVICE . " 条/天）";
            }
        } catch (\Throwable $e) {
            // Redis 异常时不阻断推送（降级策略）
        }
        return null;
    }

    /**
     * 记录推送成功：重置失败计数 + 记录限流时间戳 + 更新统计
     *
     * @param string $deviceToken
     */
    private static function recordSuccess(string $deviceToken): void
    {
        try {
            $redis = Redis::getInstance();
            $tokenHash = md5($deviceToken);

            // 重置连续失败计数
            $redis->del(self::FAIL_COUNT_KEY);

            // 记录本次推送时间戳（用于最小间隔判断）
            $rateKey = self::RATE_LIMIT_KEY . $tokenHash;
            $redis->setex($rateKey, self::MIN_PUSH_INTERVAL * 2, (string)time());

            // 每日计数 +1
            $today = date('Ymd');
            $dailyKey = self::DAILY_COUNT_KEY . $tokenHash . ':' . $today;
            $redis->incr($dailyKey);
            $redis->expire($dailyKey, 86400 * 2); // 2 天过期

            // 统计：成功数 +1
            $redis->hIncrBy(self::STATS_KEY, 'success_total', 1);
            $redis->hIncrBy(self::STATS_KEY, 'success_today:' . date('Ymd'), 1);
            $redis->hSet(self::STATS_KEY, 'last_success_at', date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {}
    }

    /**
     * 记录推送失败：连续失败计数 +1，达到阈值触发熔断
     */
    private static function recordFailure(): void
    {
        try {
            $redis = Redis::getInstance();

            // 连续失败计数 +1
            $count = (int)$redis->incr(self::FAIL_COUNT_KEY);
            $redis->expire(self::FAIL_COUNT_KEY, 600); // 10 分钟无失败则自动清零

            // 达到阈值触发熔断
            if ($count >= self::CIRCUIT_BREAK_THRESHOLD) {
                self::triggerCircuitBreak();
            }

            // 统计：失败数 +1
            $redis->hIncrBy(self::STATS_KEY, 'fail_total', 1);
            $redis->hIncrBy(self::STATS_KEY, 'fail_today:' . date('Ymd'), 1);
            $redis->hSet(self::STATS_KEY, 'last_fail_at', date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {}
    }

    /**
     * 触发熔断：设置熔断标记，暂停 APNS 通道
     */
    private static function triggerCircuitBreak(): void
    {
        try {
            $redis = Redis::getInstance();
            $redis->setex(self::CIRCUIT_BREAK_KEY, self::CIRCUIT_BREAK_DURATION, '1');
            $redis->hSet(self::STATS_KEY, 'last_circuit_break', date('Y-m-d H:i:s'));
            error_log('[APNS] 触发熔断！连续失败达 ' . self::CIRCUIT_BREAK_THRESHOLD . ' 次，暂停 APNS 通道 ' . self::CIRCUIT_BREAK_DURATION . ' 秒');
        } catch (\Throwable $e) {}
    }

    /**
     * 检查是否处于熔断状态
     *
     * @return bool true=熔断中（拒绝所有推送）
     */
    private static function isCircuitBroken(): bool
    {
        try {
            $flag = Redis::getInstance()->get(self::CIRCUIT_BREAK_KEY);
            return $flag !== false && $flag !== null;
        } catch (\Throwable $e) {
            return false; // Redis 异常时不阻断（降级）
        }
    }

    /**
     * 手动重置熔断状态（管理员后台可调用）
     */
    public static function resetCircuitBreaker(): void
    {
        try {
            $redis = Redis::getInstance();
            $redis->del(self::CIRCUIT_BREAK_KEY);
            $redis->del(self::FAIL_COUNT_KEY);
            $redis->hSet(self::STATS_KEY, 'last_reset', date('Y-m-d H:i:s'));
        } catch (\Throwable $e) {}
    }

    /**
     * 获取 APNS 健康度统计（供后台展示）
     *
     * @return array
     */
    public static function getHealthStats(): array
    {
        $defaults = [
            'success_total'      => 0,
            'fail_total'         => 0,
            'last_success_at'    => '',
            'last_fail_at'       => '',
            'last_circuit_break' => '',
            'last_reset'         => '',
            'circuit_broken'     => false,
            'fail_count'         => 0,
        ];

        try {
            $redis = Redis::getInstance();
            $stats = $redis->hGetAll(self::STATS_KEY);
            if (!is_array($stats)) $stats = [];

            $defaults['circuit_broken'] = self::isCircuitBroken();
            $defaults['fail_count']     = (int)$redis->get(self::FAIL_COUNT_KEY);

            // 合并并转换今日统计
            $today = date('Ymd');
            $defaults['success_today'] = (int)($stats['success_today:' . $today] ?? 0);
            $defaults['fail_today']    = (int)($stats['fail_today:' . $today] ?? 0);
            $defaults['success_total'] = (int)($stats['success_total'] ?? 0);
            $defaults['fail_total']    = (int)($stats['fail_total'] ?? 0);
            $defaults['last_success_at']    = (string)($stats['last_success_at'] ?? '');
            $defaults['last_fail_at']       = (string)($stats['last_fail_at'] ?? '');
            $defaults['last_circuit_break'] = (string)($stats['last_circuit_break'] ?? '');
            $defaults['last_reset']         = (string)($stats['last_reset'] ?? '');

            // 成功率
            $total = $defaults['success_total'] + $defaults['fail_total'];
            $defaults['success_rate'] = $total > 0
                ? round($defaults['success_total'] / $total * 100, 2)
                : 0;
        } catch (\Throwable $e) {}

        return $defaults;
    }

    /**
     * 截断 payload 以满足 4KB 限制
     *
     * 策略：优先丢弃自定义 payload 数据，再截断 body 内容
     *
     * @param string $title
     * @param string $body
     * @param array  $payload
     * @param int    $badge
     * @return string 截断后的 body
     */
    private static function truncateForPayload(string $title, string $body, array $payload, int $badge): string
    {
        // 如果有自定义 payload，丢弃它（自定义数据可由 App 打开后通过 HTTP 拉取）
        if (!empty($payload)) {
            // 重新计算不带 payload 的大小
            $testPayload = [
                'aps' => [
                    'alert' => ['title' => $title, 'body' => $body],
                    'sound' => 'default',
                    'mutable-content' => 1,
                ],
            ];
            if ($badge > 0) $testPayload['aps']['badge'] = $badge;
            $testJson = json_encode($testPayload, JSON_UNESCAPED_UNICODE);
            if (strlen($testJson) <= self::MAX_PAYLOAD_SIZE) {
                return $body; // 丢弃 payload 后已满足
            }
        }

        // 仍超限：截断 body（预留 200 字节给 JSON 结构和 title）
        $maxBodyBytes = self::MAX_PAYLOAD_SIZE - 200 - strlen($title);
        if ($maxBodyBytes < 100) $maxBodyBytes = 100;

        // 按字节截断（避免中文截断乱码）
        $truncated = '';
        $currentLen = 0;
        for ($i = 0; $i < mb_strlen($body, 'UTF-8'); $i++) {
            $char = mb_substr($body, $i, 1, 'UTF-8');
            $charLen = strlen($char);
            if ($currentLen + $charLen > $maxBodyBytes - 10) break; // 预留 "..." 空间
            $truncated .= $char;
            $currentLen += $charLen;
        }
        return $truncated . '...';
    }

    // ============================================================
    //  高级防封号：全局速率限制 / 重复内容去重 / Token 黑名单 / 慢启动
    // ============================================================

    /**
     * 全局速率限制检查
     *
     * 使用滑动窗口统计每秒推送数，超过阈值则拒绝
     * 防止服务器 IP 被苹果临时封禁（中度封禁：几小时～几天全部设备推送失败）
     *
     * @return string|null null=通过，字符串=被限流原因
     */
    private static function checkGlobalRate(): ?string
    {
        try {
            $redis = Redis::getInstance();
            $now = time();
            $key = self::GLOBAL_RATE_KEY . $now;

            $count = (int)$redis->incr($key);
            if ($count === 1) {
                $redis->expire($key, self::GLOBAL_RATE_WINDOW + 1);
            }

            if ($count > self::GLOBAL_RATE_LIMIT_PER_SECOND) {
                return '全局推送速率超限（每秒最多 ' . self::GLOBAL_RATE_LIMIT_PER_SECOND . ' 条，当前 ' . $count . ' 条），已拒绝以防 IP 被封';
            }
        } catch (\Throwable $e) {
            // Redis 异常时不阻断（降级）
        }
        return null;
    }

    /**
     * 重复内容去重检查
     *
     * 同一 token + 相同内容在 DEDUP_WINDOW 秒内只推送一次
     * 苹果会监控重复推送模式，大量重复会被判定为垃圾推送
     *
     * @param string $deviceToken
     * @param string $title
     * @param string $body
     * @return string|null null=通过，字符串=被去重
     */
    private static function checkDedup(string $deviceToken, string $title, string $body): ?string
    {
        try {
            $redis = Redis::getInstance();
            $tokenHash = md5($deviceToken);
            $contentHash = md5($title . '|' . $body);
            $dedupKey = self::DEDUP_KEY . $tokenHash . ':' . $contentHash;

            $exists = $redis->get($dedupKey);
            if ($exists) {
                return '内容重复（5 分钟内已推送过相同内容，已去重）';
            }

            // 标记为已推送
            $redis->setex($dedupKey, self::DEDUP_WINDOW, '1');
        } catch (\Throwable $e) {
            // Redis 异常时不阻断（降级）
        }
        return null;
    }

    /**
     * Token 黑名单检查
     *
     * @param string $deviceToken
     * @return bool true=在黑名单中
     */
    private static function isTokenBlacklisted(string $deviceToken): bool
    {
        try {
            $key = self::TOKEN_BLACKLIST_KEY . md5($deviceToken);
            $flag = Redis::getInstance()->get($key);
            return $flag !== false && $flag !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 记录 token 失败，达到阈值拉黑
     *
     * @param string $deviceToken
     */
    private static function recordTokenFailure(string $deviceToken): void
    {
        try {
            $redis = Redis::getInstance();
            $failKey = self::TOKEN_FAIL_KEY . md5($deviceToken);
            $count = (int)$redis->incr($failKey);
            $redis->expire($failKey, self::TOKEN_BLACKLIST_DURATION);

            if ($count >= self::TOKEN_FAIL_THRESHOLD) {
                // 拉黑该 token
                $blacklistKey = self::TOKEN_BLACKLIST_KEY . md5($deviceToken);
                $redis->setex($blacklistKey, self::TOKEN_BLACKLIST_DURATION, (string)$count);
                error_log('[APNS] Token 拉黑：失败 ' . $count . ' 次，拉黑 1 小时 token=' . substr($deviceToken, 0, 16) . '...');
            }
        } catch (\Throwable $e) {}
    }

    /**
     * 新证书慢启动检查
     *
     * 配置首次启用后前 SLOW_START_HOURS 小时限制总推送量
     * 苹果对新证书有冷启动期，突然大量推送会被风控
     *
     * @return string|null null=通过，字符串=被限制
     */
    private static function checkSlowStart(): ?string
    {
        try {
            $redis = Redis::getInstance();
            $startTs = $redis->get(self::SLOW_START_KEY);

            if (!$startTs) {
                // 首次调用，记录慢启动开始时间
                $redis->setex(self::SLOW_START_KEY, self::SLOW_START_HOURS * 3600 + 86400, (string)time());
                return null;
            }

            $elapsed = time() - (int)$startTs;
            if ($elapsed < self::SLOW_START_HOURS * 3600) {
                // 仍在慢启动期，检查今日总推送量
                $today = date('Ymd');
                $dailyKey = 'apns:slow_start_daily:' . $today;
                $dailyCount = (int)$redis->get($dailyKey);
                if ($dailyCount >= self::SLOW_START_DAILY_LIMIT) {
                    $remainingHours = ceil((self::SLOW_START_HOURS * 3600 - $elapsed) / 3600);
                    return '新证书慢启动期（剩 ' . $remainingHours . ' 小时），今日推送已达上限 ' . self::SLOW_START_DAILY_LIMIT . ' 条';
                }
                // 计数 +1
                $redis->incr($dailyKey);
                $redis->expire($dailyKey, 86400 * 2);
            }
        } catch (\Throwable $e) {
            // Redis 异常时不阻断（降级）
        }
        return null;
    }
}
