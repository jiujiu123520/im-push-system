<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 用户服务
 *
 * 处理前台用户注册、登录、信息查询、改密等业务。
 * 密码使用 bcrypt（PHP password_hash），验证码使用 AES 加密存储。
 * 注册时自动生成 8 位数字安全码（不可修改），用于忘记密码时重置。
 */
class UserService
{
    /**
     * 用户注册
     *
     * 注册成功后自动签发 JWT Token（实现自动登录），
     * 并返回 8 位数字安全码明文（仅此一次，后续不再展示）。
     *
     * @param string $username   用户名
     * @param string $phone      手机号
     * @param string $email      邮箱
     * @param string $password   明文密码
     * @param string $codeType    验证码类型：sms/email
     * @param string $codeTarget  验证码目标（手机号或邮箱）
     * @param string $codeInput   用户输入的验证码
     * @return array ["success" => bool, "message" => string, "user_id" => int|null,
     *               "token" => string|null, "user" => array|null, "security_code" => string|null]
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
        $fail = ['success' => false, 'message' => '', 'user_id' => null, 'token' => null, 'user' => null, 'security_code' => null];

        // 1. 校验验证码（受系统设置 captcha.enabled 控制，默认开启）
        // 支持两种验证码方式：
        //   a) 图形验证码（codeType='captcha'）：codeTarget=验证码token，codeInput=图形验证码
        //   b) 短信/邮箱验证码（codeType='sms'/'email'）：codeTarget=手机号/邮箱，codeInput=收到的验证码
        if (self::isCaptchaEnabled()) {
            if (strtolower($codeType) === 'captcha') {
                // 图形验证码模式：codeTarget 是验证码 token，codeInput 是图形码
                if (!CaptchaService::verifyImageCaptcha($codeTarget, $codeInput)) {
                    $fail['message'] = '验证码错误或已过期';
                    return $fail;
                }
            } else {
                // 短信/邮箱验证码模式
                if (!CaptchaService::verifyCode($codeType, $codeTarget, $codeInput)) {
                    $fail['message'] = '验证码错误或已过期';
                    return $fail;
                }

                // 2. 校验验证码目标与注册信息一致（防绕过）
                $expectedTarget = $codeType === 'sms' ? $phone : $email;
                if ($codeTarget !== $expectedTarget) {
                    $fail['message'] = '验证码目标与注册信息不匹配';
                    return $fail;
                }
            }
        }

        // 3. 参数基础校验
        if (trim($username) === '' || strlen($username) < 3 || strlen($username) > 64) {
            $fail['message'] = '用户名长度需在 3-64 之间';
            return $fail;
        }
        if (strlen($password) < 6 || strlen($password) > 64) {
            $fail['message'] = '密码长度需在 6-64 之间';
            return $fail;
        }
        // 手机号和邮箱至少填写一项，格式校验仅对已填写的字段生效
        if ($phone === '' && $email === '') {
            $fail['message'] = '手机号与邮箱至少填写一项';
            return $fail;
        }
        if ($phone !== '' && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $fail['message'] = '手机号格式不正确';
            return $fail;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fail['message'] = '邮箱格式不正确';
            return $fail;
        }

        // 4. 校验唯一性
        if (self::findByUsername($username) !== null) {
            $fail['message'] = '用户名已被占用';
            return $fail;
        }
        if ($phone !== '' && self::findByPhone($phone) !== null) {
            $fail['message'] = '手机号已注册';
            return $fail;
        }
        if ($email !== '' && self::findByEmail($email) !== null) {
            $fail['message'] = '邮箱已注册';
            return $fail;
        }

        // 5. 密码 bcrypt 哈希
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($hash === false) {
            $fail['message'] = '密码加密失败';
            return $fail;
        }

        // 6. 生成 8 位安全码（非简单数字），并哈希存储
        $securityCode = self::generateSecurityCode();
        $securityCodeHash = password_hash($securityCode, PASSWORD_BCRYPT);
        if ($securityCodeHash === false) {
            $fail['message'] = '安全码加密失败';
            return $fail;
        }

        // 7. 写入数据库
        $now = date('Y-m-d H:i:s');
        try {
            $userId = Database::insert(
                'INSERT INTO users (username, phone, email, password_hash, security_code_hash, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
                [$username, $phone, $email, $hash, $securityCodeHash, $now, $now]
            );
        } catch (\Throwable $e) {
            $fail['message'] = '注册失败：' . $e->getMessage();
            return $fail;
        }

        // 8. 自动登录：签发 JWT Token
        $token = Jwt::issue([
            'user_id'  => (int)$userId,
            'username' => $username,
            'type'     => 'user',
        ]);

        $userInfo = [
            'id'         => (int)$userId,
            'username'   => $username,
            'phone'      => $phone,
            'email'      => $email,
            'status'     => 1,
            'created_at' => $now,
        ];

        return [
            'success'       => true,
            'message'       => '注册成功',
            'user_id'       => (int)$userId,
            'token'         => $token,
            'user'          => $userInfo,
            'security_code' => $securityCode,
        ];
    }

    /**
     * 用户登录
     *
     * 支持用户名/手机号/邮箱登录，登录前需校验图形验证码。
     * 账号不存在时返回明确的"账号未注册"提示，便于前端引导注册。
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
        $fail = ['success' => false, 'message' => '', 'token' => null, 'user' => null];

        // 1. 校验图形验证码（受系统设置 captcha.enabled 控制，默认开启）
        if (self::isCaptchaEnabled() && !CaptchaService::verifyImageCaptcha($captchaToken, $captchaInput)) {
            $fail['message'] = '图形验证码错误或已过期';
            return $fail;
        }

        if ($account === '' || $password === '') {
            $fail['message'] = '账号或密码不能为空';
            return $fail;
        }

        // 2. 按用户名/手机号/邮箱查找用户
        $user = self::findByUsername($account)
            ?? self::findByPhone($account)
            ?? self::findByEmail($account);

        if ($user === null) {
            // 明确提示账号未注册，引导用户去注册
            $fail['message'] = '该账号未注册，请注册后使用';
            return $fail;
        }

        // 3. 校验账号状态
        if ((int)$user['status'] !== 1) {
            $fail['message'] = '账号已被禁用，请联系管理员';
            return $fail;
        }

        // 4. 校验密码
        if (!password_verify($password, $user['password_hash'])) {
            $fail['message'] = '密码错误，请重新输入';
            return $fail;
        }

        // 5. 签发 JWT Token
        $token = Jwt::issue([
            'user_id'  => (int)$user['id'],
            'username' => $user['username'],
            'type'     => 'user',
        ]);

        return [
            'success' => true,
            'message' => '登录成功',
            'token'   => $token,
            'user'    => self::formatUserInfo($user),
        ];
    }

    /**
     * 通过安全码重置密码
     *
     * 用户忘记密码时，输入账号 + 安全码 + 新密码即可重置。
     * 安全码不可修改，仅用于重置密码。
     *
     * @param string $account       用户名/手机号/邮箱
     * @param string $securityCode  8位数字安全码
     * @param string $newPassword    新密码
     * @return array ["success" => bool, "message" => string]
     */
    public static function resetPasswordBySecurityCode(
        string $account,
        string $securityCode,
        string $newPassword
    ): array {
        if ($account === '' || $securityCode === '' || $newPassword === '') {
            return ['success' => false, 'message' => '账号、安全码和新密码不能为空'];
        }
        if (strlen($securityCode) !== 8 || !ctype_digit($securityCode)) {
            return ['success' => false, 'message' => '安全码格式不正确'];
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 64) {
            return ['success' => false, 'message' => '密码长度需在 6-64 之间'];
        }

        $user = self::findByUsername($account)
            ?? self::findByPhone($account)
            ?? self::findByEmail($account);

        if ($user === null) {
            return ['success' => false, 'message' => '该账号未注册，请注册后使用'];
        }

        if ((int)$user['status'] !== 1) {
            return ['success' => false, 'message' => '账号已被禁用'];
        }

        // 校验安全码
        if (empty($user['security_code_hash'])) {
            return ['success' => false, 'message' => '该账号未设置安全码，请联系管理员'];
        }
        if (!password_verify($securityCode, $user['security_code_hash'])) {
            return ['success' => false, 'message' => '安全码错误'];
        }

        // 更新密码
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        if ($hash === false) {
            return ['success' => false, 'message' => '密码加密失败'];
        }

        try {
            Database::execute(
                'UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?',
                [$hash, date('Y-m-d H:i:s'), (int)$user['id']]
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '重置失败：' . $e->getMessage()];
        }

        return ['success' => true, 'message' => '密码重置成功，请使用新密码登录'];
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

    /**
     * 读取验证码开关（admin_settings.settings_captcha.enabled，默认开启）
     *
     * 公共方法，供 AuthController、AdminService 等复用，避免重复读取数据库。
     *
     * @return bool
     */
    public static function isCaptchaEnabled(): bool
    {
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_captcha']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg) && array_key_exists('enabled', $cfg)) {
                    return (bool)$cfg['enabled'];
                }
            }
        } catch (\Throwable $e) {
        }
        return true;
    }

    /**
     * 生成 8 位数字安全码
     *
     * 规则：
     *   - 8 位纯数字
     *   - 排除简单数字：全相同（如 11111111）、顺序递增（如 12345678）、
     *     顺序递减（如 87654321）、常见重复模式（如 11223344）
     *
     * @return string 8 位数字安全码
     */
    private static function generateSecurityCode(): string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= (string)random_int(0, 9);
            }
            if (!self::isSimpleSecurityCode($code)) {
                return $code;
            }
        }
        // 兜底：生成一个非简单码
        return str_pad((string)random_int(10234567, 98765432), 8, '0', STR_PAD_LEFT);
    }

    /**
     * 判断安全码是否过于简单
     *
     * @param string $code 8 位数字
     * @return bool true=简单（不可用）
     */
    private static function isSimpleSecurityCode(string $code): bool
    {
        // 全相同：11111111
        $allSame = true;
        for ($i = 1; $i < 8; $i++) {
            if ($code[$i] !== $code[0]) {
                $allSame = false;
                break;
            }
        }
        if ($allSame) {
            return true;
        }

        // 顺序递增：12345678
        $ascending = true;
        for ($i = 1; $i < 8; $i++) {
            if ((int)$code[$i] !== ((int)$code[$i - 1] + 1) % 10) {
                $ascending = false;
                break;
            }
        }
        if ($ascending) {
            return true;
        }

        // 顺序递减：87654321
        $descending = true;
        for ($i = 1; $i < 8; $i++) {
            if ((int)$code[$i] !== ((int)$code[$i - 1] - 1 + 10) % 10) {
                $descending = false;
                break;
            }
        }
        if ($descending) {
            return true;
        }

        // 两两重复：11223344
        $pairRepeat = true;
        for ($i = 0; $i < 8; $i += 2) {
            if ($code[$i] !== $code[$i + 1]) {
                $pairRepeat = false;
                break;
            }
        }
        if ($pairRepeat) {
            return true;
        }

        // 四四重复：12341234
        if (substr($code, 0, 4) === substr($code, 4, 4)) {
            return true;
        }

        return false;
    }
}
