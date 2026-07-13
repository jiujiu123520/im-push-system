<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 用户服务
 *
 * 处理前台用户注册、登录、信息查询等业务。
 * 密码使用 bcrypt（PHP password_hash），验证码使用 AES 加密存储。
 */
class UserService
{
    /**
     * 用户注册
     *
     * @param string $username   用户名
     * @param string $phone      手机号
     * @param string $email      邮箱
     * @param string $password   明文密码
     * @param string $codeType    验证码类型：sms/email
     * @param string $codeTarget  验证码目标（手机号或邮箱）
     * @param string $codeInput   用户输入的验证码
     * @return array ["success" => bool, "message" => string, "user_id" => int|null]
     */
    public static function register(
        string $username,
        string $phone,
        string $email,
        string $password,
        string $codeType,
        string $codeTarget,
        string $codeInput
    ): array {
        // 1. 校验验证码
        if (!CaptchaService::verifyCode($codeType, $codeTarget, $codeInput)) {
            return ['success' => false, 'message' => '验证码错误或已过期', 'user_id' => null];
        }

        // 2. 校验验证码目标与注册信息一致（防绕过）
        $expectedTarget = $codeType === 'sms' ? $phone : $email;
        if ($codeTarget !== $expectedTarget) {
            return ['success' => false, 'message' => '验证码目标与注册信息不匹配', 'user_id' => null];
        }

        // 3. 参数基础校验
        if (trim($username) === '' || strlen($username) < 3 || strlen($username) > 64) {
            return ['success' => false, 'message' => '用户名长度需在 3-64 之间', 'user_id' => null];
        }
        if (strlen($password) < 6 || strlen($password) > 64) {
            return ['success' => false, 'message' => '密码长度需在 6-64 之间', 'user_id' => null];
        }
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return ['success' => false, 'message' => '手机号格式不正确', 'user_id' => null];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => '邮箱格式不正确', 'user_id' => null];
        }

        // 4. 校验唯一性
        if (self::findByUsername($username) !== null) {
            return ['success' => false, 'message' => '用户名已被占用', 'user_id' => null];
        }
        if (self::findByPhone($phone) !== null) {
            return ['success' => false, 'message' => '手机号已注册', 'user_id' => null];
        }
        if (self::findByEmail($email) !== null) {
            return ['success' => false, 'message' => '邮箱已注册', 'user_id' => null];
        }

        // 5. 密码 bcrypt 哈希
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($hash === false) {
            return ['success' => false, 'message' => '密码加密失败', 'user_id' => null];
        }

        // 6. 写入数据库
        $now = date('Y-m-d H:i:s');
        try {
            $userId = Database::insert(
                'INSERT INTO users (username, phone, email, password_hash, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?)',
                [$username, $phone, $email, $hash, $now, $now]
            );
        } catch (\Throwable $e) {
            // 唯一索引冲突等数据库异常
            return ['success' => false, 'message' => '注册失败：' . $e->getMessage(), 'user_id' => null];
        }

        return ['success' => true, 'message' => '注册成功', 'user_id' => (int)$userId];
    }

    /**
     * 用户登录
     *
     * 支持用户名/手机号/邮箱登录，登录前需校验图形验证码。
     *
     * @param string $account       用户名/手机号/邮箱
     * @param string $password      明文密码
     * @param string $captchaToken  图形验证码 token
     * @param string $captchaInput  用户输入的图形验证码
     * @return array ["success" => bool, "message" => string, "token" => string|null, "user" => array|null]
     */
    public static function login(
        string $account,
        string $password,
        string $captchaToken,
        string $captchaInput
    ): array {
        // 1. 校验图形验证码（受系统设置 captcha.enabled 控制，默认开启）
        $captchaEnabled = true;
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_captcha']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg) && array_key_exists('enabled', $cfg)) {
                    $captchaEnabled = (bool)$cfg['enabled'];
                }
            }
        } catch (\Throwable $e) {
            // 表可能不存在或读取失败，保持默认开启
        }

        if ($captchaEnabled && !CaptchaService::verifyImageCaptcha($captchaToken, $captchaInput)) {
            return ['success' => false, 'message' => '图形验证码错误或已过期', 'token' => null, 'user' => null];
        }

        if ($account === '' || $password === '') {
            return ['success' => false, 'message' => '账号或密码不能为空', 'token' => null, 'user' => null];
        }

        // 2. 按用户名/手机号/邮箱查找用户
        $user = self::findByUsername($account)
            ?? self::findByPhone($account)
            ?? self::findByEmail($account);

        if ($user === null) {
            return ['success' => false, 'message' => '账号或密码错误', 'token' => null, 'user' => null];
        }

        // 3. 校验账号状态
        if ((int)$user['status'] !== 1) {
            return ['success' => false, 'message' => '账号已被禁用', 'token' => null, 'user' => null];
        }

        // 4. 校验密码
        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'message' => '账号或密码错误', 'token' => null, 'user' => null];
        }

        // 5. 签发 JWT Token
        $token = Jwt::issue([
            'user_id'  => (int)$user['id'],
            'username' => $user['username'],
            'type'     => 'user',
        ]);

        $userInfo = self::formatUserInfo($user);

        return [
            'success' => true,
            'message' => '登录成功',
            'token'   => $token,
            'user'    => $userInfo,
        ];
    }

    /**
     * 获取用户信息
     *
     * @param int $userId 用户ID
     * @return array|null 不含密码哈希的用户信息
     */
    public static function getUserInfo(int $userId): ?array
    {
        $user = Database::fetch(
            'SELECT id, username, phone, email, status, created_at, updated_at FROM users WHERE id = ?',
            [$userId]
        );
        if ($user === false || $user === null) {
            return null;
        }
        return $user;
    }

    /**
     * 根据用户名查询用户
     *
     * @param string $username
     * @return array|null
     */
    public static function findByUsername(string $username): ?array
    {
        $row = Database::fetch('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
        return $row === false ? null : $row;
    }

    /**
     * 根据手机号查询用户
     *
     * @param string $phone
     * @return array|null
     */
    public static function findByPhone(string $phone): ?array
    {
        $row = Database::fetch('SELECT * FROM users WHERE phone = ? LIMIT 1', [$phone]);
        return $row === false ? null : $row;
    }

    /**
     * 根据邮箱查询用户
     *
     * @param string $email
     * @return array|null
     */
    public static function findByEmail(string $email): ?array
    {
        $row = Database::fetch('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        return $row === false ? null : $row;
    }

    /**
     * 格式化用户信息（去掉敏感字段）
     *
     * @param array $user
     * @return array
     */
    private static function formatUserInfo(array $user): array
    {
        return [
            'id'         => (int)$user['id'],
            'username'   => $user['username'],
            'phone'      => $user['phone'],
            'email'      => $user['email'],
            'status'     => (int)$user['status'],
            'created_at' => $user['created_at'] ?? '',
        ];
    }
}
