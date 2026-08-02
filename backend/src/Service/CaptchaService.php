<?php
declare(strict_types=1);

namespace App\Service;

use Intervention\Image\ImageManager;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * 验证码服务
 *
 * 提供：
 *  - 图形验证码生成（GD 库 + intervention/image）与校验
 *  - 短信验证码发送与校验
 *  - 邮箱验证码发送与校验
 *
 * 验证码明文均通过 AES 加密后存入 Redis，避免明文落盘。
 */
class CaptchaService
{
    /** 图形验证码有效期（秒） */
    private const IMAGE_TTL = 300;

    /** 短信/邮箱验证码有效期（秒） */
    private const CODE_TTL = 300;

    /** 图形验证码长度 */
    private const IMAGE_LEN = 4;

    /** 短信/邮箱验证码长度 */
    private const CODE_LEN = 6;

    /** 图形验证码字符集（去除易混淆字符） */
    private const IMAGE_CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Redis Key 前缀 */
    private const KEY_IMAGE = 'captcha:image:';
    private const KEY_SMS   = 'captcha:sms:';
    private const KEY_EMAIL = 'captcha:email:';

    /**
     * 从数据库读取验证码服务配置（admin_settings.settings_captcha）
     *
     * 后台"验证码服务配置"卡片保存的 SMTP/短信参数存于此 key。
     * CaptchaService 优先使用后台配置，回退到 .env 环境变量。
     *
     * @return array 后台配置数组（未找到或读取失败返回空数组）
     */
    private static function readCaptchaSettings(): array
    {
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_captcha']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg)) {
                    return $cfg;
                }
            }
        } catch (\Throwable $e) {
        }
        return [];
    }

    /**
     * 生成图形验证码
     *
     * 返回：
     *   [
     *     "token" => "AES加密token（含验证码与过期时间）",
     *     "image" => "data:image/png;base64,...." 或 "data:image/svg+xml;base64,..."
     *   ]
     *
     * @return array
     * @throws \RuntimeException 当 GD 和 SVG fallback 都失败时抛出
     */
    public static function generateImageCaptcha(): array
    {
        // 1. 生成 4 位字母数字验证码
        $code = '';
        $len = strlen(self::IMAGE_CHARS);
        for ($i = 0; $i < self::IMAGE_LEN; $i++) {
            $code .= self::IMAGE_CHARS[random_int(0, $len - 1)];
        }

        // 2. 构造明文 payload（验证码 + 过期时间）
        $expire = time() + self::IMAGE_TTL;
        $plain = json_encode([
            'code'   => $code,
            'expire' => $expire,
        ]);

        // 3. AES 加密生成 token
        $token = Aes::encryptString($plain);

        // 4. token 存 Redis（key: captcha:image:{token}），5 分钟过期
        Redis::setex(self::KEY_IMAGE . $token, self::IMAGE_TTL, $token);

        // 5. 绘制图片：优先 GD(PNG)，失败时降级为 SVG
        $dataUri = '';
        $gdLastError = '';

        if (extension_loaded('gd') && function_exists('imagecreatetruecolor')) {
            try {
                $pngBin = self::drawImage($code);
                if ($pngBin !== '' && strlen($pngBin) > 100) {
                    $dataUri = 'data:image/png;base64,' . base64_encode($pngBin);
                } else {
                    $gdLastError = 'GD 绘制返回空数据';
                    self::log('captcha', "[CAPTCHA] drawImage 返回空PNG，长度=" . strlen($pngBin) . "，降级 SVG");
                }
            } catch (\Throwable $e) {
                $gdLastError = 'GD 绘制异常：' . $e->getMessage();
                self::log('captcha', "[CAPTCHA] drawImage 异常，降级 SVG：" . $e->getMessage());
            }
        } else {
            $gdLastError = 'GD 扩展未安装或不可用';
        }

        // SVG fallback
        if ($dataUri === '') {
            try {
                $svg = self::drawSvgCaptcha($code);
                if ($svg !== '') {
                    $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);
                }
            } catch (\Throwable $e) {
                self::log('captcha', "[CAPTCHA] SVG fallback 异常：" . $e->getMessage());
            }
        }

        if ($dataUri === '') {
            throw new \RuntimeException('图形验证码生成失败（GD：' . $gdLastError . '，SVG fallback 也失败）');
        }

        return [
            'token' => $token,
            'image' => $dataUri,
        ];
    }

    /**
     * 校验图形验证码
     *
     * 从 Redis 读取加密 token，AES 解密后比对用户输入。
     * 校验后立即删除（一次性使用）。
     *
     * @param string $token  前端传入的 token
     * @param string $input  用户输入的验证码
     * @return bool
     */
    public static function verifyImageCaptcha(string $token, string $input): bool
    {
        if ($token === '' || $input === '') {
            return false;
        }

        $key = self::KEY_IMAGE . $token;
        $stored = Redis::get($key);
        // 校验后立即删除（无论成功失败都删除，防止暴力枚举）
        Redis::del($key);

        if ($stored === null) {
            return false;
        }

        // AES 解密
        $plain = Aes::decryptString($stored);
        if ($plain === null) {
            return false;
        }

        $data = json_decode($plain, true);
        if (!is_array($data) || empty($data['code']) || empty($data['expire'])) {
            return false;
        }

        // 过期校验
        if (time() > (int)$data['expire']) {
            return false;
        }

        // 大小写不敏感比对
        return strcasecmp((string)$data['code'], $input) === 0;
    }

    /**
     * 发送短信验证码
     *
     * @param string $phone 手机号
     * @return array ["success" => bool, "message" => string]
     */
    public static function sendSmsCode(string $phone): array
    {
        if (!self::isValidPhone($phone)) {
            return ['success' => false, 'message' => '手机号格式不正确'];
        }

        $code = self::generateNumericCode(self::CODE_LEN);

        // AES 加密后存 Redis（key: captcha:sms:{phone}）
        $plain = json_encode([
            'code'   => $code,
            'expire' => time() + self::CODE_TTL,
        ]);
        $encrypted = Aes::encryptString($plain);
        Redis::setex(self::KEY_SMS . $phone, self::CODE_TTL, $encrypted);

        // 读取短信 API 配置：优先后台管理配置，回退到 .env
        $settings = self::readCaptchaSettings();
        $apiKey = (string)($settings['smsApiKey'] ?? '') ?: (string)Config::env('SMS_API_KEY', '');
        $apiUrl = (string)($settings['smsApiUrl'] ?? '') ?: (string)Config::env('SMS_API_URL', '');

        if ($apiKey === '' || $apiUrl === '') {
            // 未配置短信服务：返回失败，避免前端误以为已发送
            self::log('sms', "[SMS] 未配置短信服务参数，无法发送验证码：phone={$phone}, code={$code}");
            return ['success' => false, 'message' => '短信服务未配置，请联系管理员在后台设置短信 API 参数'];
        }

        $result = self::callSmsApi($apiUrl, $apiKey, $phone, $code);
        if ($result) {
            return ['success' => true, 'message' => '验证码已发送'];
        }
        return ['success' => false, 'message' => '短信发送失败，请稍后重试'];
    }

    /**
     * 发送邮箱验证码
     *
     * @param string $email 邮箱
     * @return array ["success" => bool, "message" => string]
     */
    public static function sendEmailCode(string $email): array
    {
        if (!self::isValidEmail($email)) {
            return ['success' => false, 'message' => '邮箱格式不正确'];
        }

        $code = self::generateNumericCode(self::CODE_LEN);

        // AES 加密后存 Redis（key: captcha:email:{email}）
        $plain = json_encode([
            'code'   => $code,
            'expire' => time() + self::CODE_TTL,
        ]);
        $encrypted = Aes::encryptString($plain);
        Redis::setex(self::KEY_EMAIL . $email, self::CODE_TTL, $encrypted);

        // 读取邮件 SMTP 配置：优先后台管理配置，回退到 .env
        $settings = self::readCaptchaSettings();
        $mailHost = (string)($settings['mailHost'] ?? '') ?: (string)Config::env('MAIL_HOST', '');
        $mailUser = (string)($settings['mailUsername'] ?? '') ?: (string)Config::env('MAIL_USERNAME', '');
        $mailPass = (string)($settings['mailPassword'] ?? '') ?: (string)Config::env('MAIL_PASSWORD', '');
        $mailPort = (int)(($settings['mailPort'] ?? '') ?: Config::env('MAIL_PORT', 587));
        // 发件人显示名称：优先 mailSenderName（新字段），回退 mailFrom，最后回退 .env MAIL_SENDER_NAME
        $mailName = (string)($settings['mailSenderName'] ?? '')
            ?: ((string)($settings['mailFrom'] ?? '') ?: (string)Config::env('MAIL_SENDER_NAME', 'IM Push System'));
        // 加密方式：后台显式配置 > .env > 根据端口自动推断（465=ssl, 587=tls）
        $mailEnc  = strtolower((string)($settings['mailEncryption'] ?? ''));
        if ($mailEnc === '') {
            $mailEnc = strtolower((string)Config::env('MAIL_ENCRYPTION', ''));
        }
        if ($mailEnc === '' || $mailEnc === 'auto') {
            $mailEnc = $mailPort == 465 ? 'ssl' : 'tls';
        }

        if ($mailHost === '' || $mailUser === '') {
            // 未配置邮件服务：返回失败，避免前端误以为已发送
            self::log('email', "[EMAIL] 未配置 SMTP 参数，无法发送验证码：email={$email}, code={$code}");
            return ['success' => false, 'message' => '邮件服务未配置，请联系管理员在后台设置 SMTP 参数'];
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $mailHost;
            $mail->Port = $mailPort;
            $mail->SMTPAuth = true;
            $mail->Username = $mailUser;
            $mail->Password = $mailPass;
            $mail->CharSet = 'UTF-8';
            // 加密方式：tls / ssl / none
            if ($mailEnc === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($mailEnc === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
            // QQ/163 等邮箱要求 setFrom 地址与 SMTP 登录账号一致，
            // 否则会报 "SMTP Error: Could not authenticate" 或发件被拒。
            // 因此发件人地址强制使用 SMTP 登录账号（mailUser），mailFrom 仅作为显示名称。
            $mail->setFrom($mailUser, $mailName);
            $mail->addAddress($email);
            $mail->Subject = '邮箱验证码';
            $mail->Body = "您的邮箱验证码是：{$code}，5 分钟内有效。";
            $mail->send();
            return ['success' => true, 'message' => '验证码已发送'];
        } catch (PHPMailerException $e) {
            self::log('email', "[EMAIL] 发送失败：email={$email}, error=" . $e->getMessage());
            return ['success' => false, 'message' => '邮件发送失败：' . $e->getMessage()];
        }
    }

    /**
     * 通用验证码校验（短信/邮箱）
     *
     * @param string $type   类型：sms 或 email
     * @param string $target  手机号或邮箱
     * @param string $input   用户输入的验证码
     * @return bool
     */
    public static function verifyCode(string $type, string $target, string $input): bool
    {
        $type = strtolower($type);
        if ($type === 'sms') {
            $key = self::KEY_SMS . $target;
        } elseif ($type === 'email') {
            $key = self::KEY_EMAIL . $target;
        } else {
            return false;
        }

        $encrypted = Redis::get($key);
        // 校验后立即删除
        Redis::del($key);

        if ($encrypted === null) {
            return false;
        }

        $plain = Aes::decryptString($encrypted);
        if ($plain === null) {
            return false;
        }

        $data = json_decode($plain, true);
        if (!is_array($data) || empty($data['code']) || empty($data['expire'])) {
            return false;
        }
        if (time() > (int)$data['expire']) {
            return false;
        }

        return hash_equals((string)$data['code'], $input);
    }

    /**
     * 使用 intervention/image 绘制图形验证码
     *
     * @param string $code 验证码
     * @return string PNG 二进制数据
     * @throws \RuntimeException 当 GD 资源获取失败或编码为空时抛出
     */
    private static function drawImage(string $code): string
    {
        $width = 120;
        $height = 40;

        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('GD 扩展不可用');
        }

        $manager = new ImageManager(['driver' => 'gd']);
        $img = $manager->canvas($width, $height, '#f5f7fa');

        // 通过 intervention 暴露的 GD 资源直接绘制（保证无字体文件依赖）
        $core = $img->getCore();
        if ($core === false || $core === null) {
            throw new \RuntimeException('无法获取 GD 图像资源');
        }

        // 绘制干扰线
        for ($i = 0; $i < 4; $i++) {
            $lineColor = imagecolorallocate(
                $core,
                random_int(120, 200),
                random_int(120, 200),
                random_int(120, 200)
            );
            imageline(
                $core,
                random_int(0, $width),
                random_int(0, $height),
                random_int(0, $width),
                random_int(0, $height),
                $lineColor
            );
        }

        // 绘制验证码字符（使用 GD 内置字体 5，居中分布）
        $charCount = strlen($code);
        $charSpacing = 25;
        $startX = (int)(($width - $charCount * $charSpacing) / 2) + 5;
        for ($i = 0; $i < $charCount; $i++) {
            $textColor = imagecolorallocate(
                $core,
                random_int(0, 80),
                random_int(0, 80),
                random_int(0, 80)
            );
            imagestring(
                $core,
                5,
                $startX + $i * $charSpacing,
                random_int(8, 14),
                $code[$i],
                $textColor
            );
        }

        // 绘制噪点
        for ($i = 0; $i < 80; $i++) {
            $pixelColor = imagecolorallocate(
                $core,
                random_int(60, 220),
                random_int(60, 220),
                random_int(60, 220)
            );
            imagesetpixel(
                $core,
                random_int(0, $width - 1),
                random_int(0, $height - 1),
                $pixelColor
            );
        }

        // 边框
        $borderColor = imagecolorallocate($core, 200, 200, 200);
        imagerectangle($core, 0, 0, $width - 1, $height - 1, $borderColor);

        $png = (string)$img->encode('png');
        if ($png === '' || strlen($png) < 100) {
            throw new \RuntimeException('PNG 编码失败，返回数据长度=' . strlen($png));
        }

        return $png;
    }

    /**
     * SVG 验证码 fallback（零外部依赖，纯字符串拼接）
     *
     * 当 GD 不可用 / intervention 异常 / PNG 编码为空时，
     * 退化为生成一个带干扰线与噪点的 SVG 图形验证码。
     *
     * @param string $code 验证码
     * @return string SVG 字符串
     */
    private static function drawSvgCaptcha(string $code): string
    {
        $width = 120;
        $height = 40;

        $colors = [
            ['r' => 120, 'g' => 100, 'b' => 240],
            ['r' => 80,  'g' => 150, 'b' => 240],
            ['r' => 220, 'g' => 100, 'b' => 180],
            ['r' => 60,  'g' => 60,  'b' => 80],
            ['r' => 30,  'g' => 130, 'b' => 160],
        ];

        $lines = '';
        for ($i = 0; $i < 4; $i++) {
            $x1 = random_int(0, $width);
            $y1 = random_int(0, $height);
            $x2 = random_int(0, $width);
            $y2 = random_int(0, $height);
            $r = random_int(150, 210);
            $g = random_int(150, 210);
            $b = random_int(150, 210);
            $lines .= "<line x1=\"{$x1}\" y1=\"{$y1}\" x2=\"{$x2}\" y2=\"{$y2}\" stroke=\"rgb({$r},{$g},{$b})\" stroke-width=\"1\" opacity=\"0.8\"/>\n";
        }

        $dots = '';
        for ($i = 0; $i < 80; $i++) {
            $x = random_int(0, $width);
            $y = random_int(0, $height);
            $r = random_int(60, 220);
            $g = random_int(60, 220);
            $b = random_int(60, 220);
            $dots .= "<circle cx=\"{$x}\" cy=\"{$y}\" r=\"1\" fill=\"rgb({$r},{$g},{$b})\" opacity=\"0.7\"/>\n";
        }

        $chars = '';
        $charCount = strlen($code);
        $charSpacing = 25;
        $startX = (int)(($width - $charCount * $charSpacing) / 2) + 5;
        for ($i = 0; $i < $charCount; $i++) {
            $colorIdx = random_int(0, count($colors) - 1);
            $c = $colors[$colorIdx];
            $x = $startX + $i * $charSpacing + random_int(-1, 1);
            $y = random_int(22, 30);
            $rot = random_int(-18, 18);
            $ch = htmlspecialchars($code[$i], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $chars .= "<text x=\"{$x}\" y=\"{$y}\" font-family=\"Arial, Helvetica, sans-serif\" font-size=\"20\" font-weight=\"700\" fill=\"rgb({$c['r']},{$c['g']},{$c['b']})\" transform=\"rotate({$rot} {$x} {$y})\">{$ch}</text>\n";
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
  <rect width="100%" height="100%" fill="#f5f7fa" rx="4"/>
  <rect x="0.5" y="0.5" width="99%" height="99%" fill="none" stroke="#c8c8d0" stroke-width="1" rx="4"/>
  {$lines}{$dots}{$chars}</svg>
SVG;
    }

    /**
     * 生成数字验证码
     *
     * @param int $length 长度
     * @return string
     */
    private static function generateNumericCode(int $length): string
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= (string)random_int(0, 9);
        }
        return $code;
    }

    /**
     * 简单手机号校验（中国大陆）
     *
     * @param string $phone
     * @return bool
     */
    private static function isValidPhone(string $phone): bool
    {
        return (bool)preg_match('/^1[3-9]\d{9}$/', $phone);
    }

    /**
     * 简单邮箱校验
     *
     * @param string $email
     * @return bool
     */
    private static function isValidEmail(string $email): bool
    {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * 调用短信 API（HTTP POST，JSON）
     *
     * @param string $apiUrl
     * @param string $apiKey
     * @param string $phone
     * @param string $code
     * @return bool
     */
    private static function callSmsApi(string $apiUrl, string $apiKey, string $phone, string $code): bool
    {
        $payload = json_encode([
            'phone' => $phone,
            'code'  => $code,
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            self::log('sms', "[SMS] cURL 错误：{$err}");
            return false;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            self::log('sms', "[SMS] HTTP {$httpCode} 响应：{$resp}");
            return false;
        }
        return true;
    }

    /**
     * 记录日志到 runtime/logs/captcha.log
     *
     * @param string $channel 通道
     * @param string $message 日志内容
     * @return void
     */
    private static function log(string $channel, string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/runtime/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $channel, $message);
        @file_put_contents($dir . '/captcha.log', $line, FILE_APPEND);
    }
}
