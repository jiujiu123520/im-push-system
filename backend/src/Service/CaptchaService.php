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
     * 生成图形验证码
     *
     * 返回：
     *   [
     *     "token" => "AES加密token（含验证码与过期时间）",
     *     "image" => "data:image/png;base64,...."
     *   ]
     *
     * @return array
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
        //    Redis 值存加密后的 token，校验时解密比对
        Redis::setex(self::KEY_IMAGE . $token, self::IMAGE_TTL, $token);

        // 5. 使用 intervention/image + GD 绘制图片
        $image = self::drawImage($code);

        return [
            'token' => $token,
            'image' => 'data:image/png;base64,' . base64_encode($image),
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

        // 调用短信 API
        $apiKey = (string)Config::env('SMS_API_KEY', '');
        $apiUrl = (string)Config::env('SMS_API_URL', '');

        if ($apiKey === '' || $apiUrl === '') {
            // 未配置短信服务，仅记录日志不实际发送
            self::log('sms', "[SMS] 未配置 SMS_API_KEY/SMS_API_URL，验证码模拟发送：phone={$phone}, code={$code}");
            return ['success' => true, 'message' => '验证码已发送（开发环境未实际发送）'];
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

        // 使用 PHPMailer 发送
        $mailHost = (string)Config::env('MAIL_HOST', '');
        $mailUser = (string)Config::env('MAIL_USERNAME', '');
        $mailPass = (string)Config::env('MAIL_PASSWORD', '');
        $mailPort = (int)Config::env('MAIL_PORT', 587);

        if ($mailHost === '' || $mailUser === '') {
            // 未配置邮件服务，仅记录日志
            self::log('email', "[EMAIL] 未配置 MAIL_HOST/MAIL_USERNAME，验证码模拟发送：email={$email}, code={$code}");
            return ['success' => true, 'message' => '验证码已发送（开发环境未实际发送）'];
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
            $mail->setFrom($mailUser, 'IM Push System');
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
     */
    private static function drawImage(string $code): string
    {
        $width = 120;
        $height = 40;

        $manager = new ImageManager(['driver' => 'gd']);
        $img = $manager->canvas($width, $height, '#f5f7fa');

        // 通过 intervention 暴露的 GD 资源直接绘制（保证无字体文件依赖）
        /** @var \GdImage $core */
        $core = $img->getCore();

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

        return (string)$img->encode('png');
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
