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
        self::logRegister("START  username={$username} phone={$phone} email={$email} codeType={$codeType} codeTarget={$codeTarget} codeInputLen=" . strlen($codeInput));

        // 1. 校验验证码（受系统设置 captcha.enabled 控制，默认开启）
        // 支持两种验证码方式：
        //   a) 图形验证码（codeType='captcha'）：codeTarget=验证码token，codeInput=图形验证码
        //   b) 短信/邮箱验证码（codeType='sms'/'email'）：codeTarget=手机号/邮箱，codeInput=收到的验证码
        // 短信和邮箱验证码分别受 smsEnabled 和 emailEnabled 独立开关控制
        $codeTypeLower = strtolower($codeType);
        $captchaEnabled = $codeTypeLower !== '' && self::isCaptchaTypeEnabled($codeTypeLower);
        self::logRegister("STEP1  codeTypeLower={$codeTypeLower} captchaEnabled=" . ($captchaEnabled ? 'yes' : 'no') . " captcha.settings.enabled=" . (self::isCaptchaEnabled() ? 'yes' : 'no'));
        if ($captchaEnabled) {
            if ($codeTypeLower === 'captcha') {
                // 图形验证码模式：codeTarget 是验证码 token，codeInput 是图形码
                if (!CaptchaService::verifyImageCaptcha($codeTarget, $codeInput)) {
                    $fail['message'] = '图形验证码错误或已过期';
                    self::logRegister("FAIL1a imageCaptchaVerifyFailed codeTarget={$codeTarget} codeInput={$codeInput}");
                    return $fail;
                }
                self::logRegister("STEP1a imageCaptchaVerifyOK");
            } else {
                // 短信/邮箱验证码模式
                $verifyResult = CaptchaService::verifyCode($codeType, $codeTarget, $codeInput);
                if (!$verifyResult) {
                    $fail['message'] = '验证码错误或已过期';
                    self::logRegister("FAIL1b sms/emailCaptchaVerifyFailed codeType={$codeType} codeTarget={$codeTarget} codeInput={$codeInput}");
                    return $fail;
                }
                self::logRegister("STEP1b sms/emailCaptchaVerifyOK codeType={$codeType}");

                // 2. 校验验证码目标与注册信息一致（防绕过）
                $expectedTarget = $codeTypeLower === 'sms' ? $phone : $email;
                if ($codeTarget !== $expectedTarget) {
                    $fail['message'] = '验证码目标与注册信息不匹配';
                    self::logRegister("FAIL2  targetMismatch codeTarget={$codeTarget} expected={$expectedTarget}");
                    return $fail;
                }
                self::logRegister("STEP2  targetMatchOK");
            }
        } else {
            self::logRegister("STEP1  captchaSkipped");
        }

        // 3. 参数基础校验
        if (trim($username) === '' || strlen($username) < 3 || strlen($username) > 64) {
            $fail['message'] = '用户名长度需在 3-64 之间';
            self::logRegister("FAIL3  usernameInvalid len=" . strlen($username));
            return $fail;
        }
        $pwdCheck = AdminService::validatePasswordStrength($password);
        if (!$pwdCheck['valid']) {
            $fail['message'] = $pwdCheck['message'];
            self::logRegister("FAIL3  passwordWeak: " . $pwdCheck['message']);
            return $fail;
        }
        // 手机号和邮箱至少填写一项，格式校验仅对已填写的字段生效
        if ($phone === '' && $email === '') {
            $fail['message'] = '手机号与邮箱至少填写一项';
            self::logRegister("FAIL3  bothPhoneEmailEmpty");
            return $fail;
        }
        if ($phone !== '' && !preg_match('/^1[3-9]\d{9}$/', $phone)) {
            $fail['message'] = '手机号格式不正确';
            self::logRegister("FAIL3  phoneFormatInvalid phone={$phone}");
            return $fail;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $fail['message'] = '邮箱格式不正确';
            self::logRegister("FAIL3  emailFormatInvalid email={$email}");
            return $fail;
        }
        self::logRegister("STEP3  paramsOK");

        // 4. 校验唯一性
        if (self::findByUsername($username) !== null) {
            $fail['message'] = '用户名已被占用';
            self::logRegister("FAIL4  usernameDuplicate {$username}");
            return $fail;
        }
        if ($phone !== '' && self::findByPhone($phone) !== null) {
            $fail['message'] = '手机号已注册';
            self::logRegister("FAIL4  phoneDuplicate {$phone}");
            return $fail;
        }
        if ($email !== '' && self::findByEmail($email) !== null) {
            $fail['message'] = '邮箱已注册';
            self::logRegister("FAIL4  emailDuplicate {$email}");
            return $fail;
        }
        self::logRegister("STEP4  uniquenessOK");

        // 5. 密码 bcrypt 哈希
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($hash === false) {
            $fail['message'] = '密码加密失败';
            self::logRegister("FAIL5  passwordHashFailed");
            return $fail;
        }
        self::logRegister("STEP5  passwordHashOK");

        // 6. 生成 8 位安全码（非简单数字），并哈希存储
        $securityCode = self::generateSecurityCode();
        $securityCodeHash = password_hash($securityCode, PASSWORD_BCRYPT);
        if ($securityCodeHash === false) {
            $fail['message'] = '安全码加密失败';
            self::logRegister("FAIL6  securityCodeHashFailed");
            return $fail;
        }
        self::logRegister("STEP6  securityCodeHashOK");

        // 7. 写入数据库
        $now = date('Y-m-d H:i:s');
        try {
            $phoneForDb = $phone !== '' ? $phone : null;
            $emailForDb = $email !== '' ? $email : null;
            $userId = Database::insert(
                'INSERT INTO users (username, phone, email, password_hash, security_code_hash, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)',
                [$username, $phoneForDb, $emailForDb, $hash, $securityCodeHash, $now, $now]
            );
        } catch (\Throwable $e) {
            $fail['message'] = '注册失败：' . $e->getMessage();
            self::logRegister("FAIL7  dbInsertError: " . $e->getMessage());
            return $fail;
        }
        self::logRegister("STEP7  dbInsertOK userId={$userId}");

        // 8. 自动登录：签发 JWT Token
        try {
            $token = Jwt::issue([
                'user_id'  => (int)$userId,
                'username' => $username,
                'type'     => 'user',
            ]);
        } catch (\Throwable $e) {
            $fail['message'] = 'JWT签发失败：' . $e->getMessage();
            self::logRegister("FAIL8  jwtIssueError: " . $e->getMessage());
            return $fail;
        }
        self::logRegister("STEP8  jwtIssueOK tokenLen=" . strlen($token));

        $userInfo = [
            'id'         => (int)$userId,
            'username'   => $username,
            'phone'      => $phone,
            'email'      => $email,
            'status'     => 1,
            'created_at' => $now,
        ];

        $result = [
            'success'       => true,
            'message'       => '注册成功',
            'user_id'       => (int)$userId,
            'token'         => $token,
            'user'          => $userInfo,
            'security_code' => $securityCode,
        ];
        self::logRegister("SUCCESS  user_id={$userId} username={$username} securityCodeLen=8");
        return $result;
    }

    /**
     * 记录注册日志到 runtime/logs/register.log（便于排查注册失败原因）
     */
    private static function logRegister(string $message): void
    {
        $dir = dirname(__DIR__, 2) . '/runtime/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . "] [REGISTER] {$message}" . PHP_EOL;
        @file_put_contents($dir . '/register.log', $line, FILE_APPEND);
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

        // 1. 校验图形验证码（受登录验证码开关 loginCaptchaEnabled 控制，默认开启）
        if (self::isLoginCaptchaEnabled() && !CaptchaService::verifyImageCaptcha($captchaToken, $captchaInput)) {
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
     * 读取登录图形验证码开关（admin_settings.settings_captcha.loginCaptchaEnabled，默认开启）
     *
     * 独立于注册验证码总开关，仅控制登录时是否需要图形验证码。
     * 总开关关闭时，登录验证码也视为关闭。
     *
     * @return bool
     */
    public static function isLoginCaptchaEnabled(): bool
    {
        if (!self::isCaptchaEnabled()) {
            return false;
        }
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_captcha']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg) && array_key_exists('loginCaptchaEnabled', $cfg)) {
                    return (bool)$cfg['loginCaptchaEnabled'];
                }
            }
        } catch (\Throwable $e) {
        }
        return true;
    }

    /**
     * 读取短信验证码开关（admin_settings.settings_captcha.smsEnabled，默认开启）
     *
     * 总开关关闭时，短信验证码也视为关闭。
     *
     * @return bool
     */
    public static function isSmsCaptchaEnabled(): bool
    {
        if (!self::isCaptchaEnabled()) {
            return false;
        }
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_captcha']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg) && array_key_exists('smsEnabled', $cfg)) {
                    return (bool)$cfg['smsEnabled'];
                }
            }
        } catch (\Throwable $e) {
        }
        return true;
    }

    /**
     * 读取邮箱验证码开关（admin_settings.settings_captcha.emailEnabled，默认开启）
     *
     * 总开关关闭时，邮箱验证码也视为关闭。
     *
     * @return bool
     */
    public static function isEmailCaptchaEnabled(): bool
    {
        if (!self::isCaptchaEnabled()) {
            return false;
        }
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_captcha']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg) && array_key_exists('emailEnabled', $cfg)) {
                    return (bool)$cfg['emailEnabled'];
                }
            }
        } catch (\Throwable $e) {
        }
        return true;
    }

    /**
     * 检查指定类型的验证码是否启用
     *
     * @param string $type captcha|sms|email
     * @return bool
     */
    public static function isCaptchaTypeEnabled(string $type): bool
    {
        $type = strtolower($type);
        if ($type === 'captcha') {
            return self::isCaptchaEnabled();
        }
        if ($type === 'sms') {
            return self::isSmsCaptchaEnabled();
        }
        if ($type === 'email') {
            return self::isEmailCaptchaEnabled();
        }
        return self::isCaptchaEnabled();
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

    /**
     * 读取 settings_security 安全配置
     *
     * @return array
     */
    public static function getSecurityConfig(): array
    {
        $defaults = [
            'allow_register'          => 1,
            'password_reset_mode'     => 'both',   // qq_only | email_only | both
            'require_email_for_reset' => 1,
            'qq_bind_enabled'         => 1,
            'user_self_unbind_qq'     => 0,
            'rate_limit_push_per_min'  => 20,
            'rate_limit_push_per_hour' => 500,
            'rate_limit_push_per_day'  => 3000,
        ];
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_security']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg)) {
                    return array_merge($defaults, $cfg);
                }
            }
        } catch (\Throwable $e) {
        }
        return $defaults;
    }

    /**
     * 脱敏手机号
     */
    public static function maskPhone(string $phone): string
    {
        if ($phone === '' || strlen($phone) < 7) return $phone;
        return substr($phone, 0, 3) . str_repeat('*', max(0, strlen($phone) - 7)) . substr($phone, -4);
    }

    /**
     * 脱敏邮箱
     */
    public static function maskEmail(string $email): string
    {
        if ($email === '' || strpos($email, '@') === false) return $email;
        [$local, $domain] = explode('@', $email, 2);
        $len = strlen($local);
        if ($len <= 2) {
            $local = $local . str_repeat('*', 2);
        } else {
            $local = $local[0] . str_repeat('*', max(1, $len - 2)) . ($len > 1 ? $local[$len - 1] : '');
        }
        return $local . '@' . $domain;
    }

    /**
     * 脱敏 QQ 号
     */
    public static function maskQq(string $qq): string
    {
        if ($qq === '' || strlen($qq) < 5) return $qq;
        return substr($qq, 0, 2) . str_repeat('*', max(1, strlen($qq) - 4)) . substr($qq, -2);
    }

    /**
     * 通过 QQ 号重置密码
     *
     * @param string $qq            QQ号
     * @param string $account       绑定的账号名（username/phone/email 任一，可选取决于 settings_security）
     * @param string $email         邮箱（可选，若 require_email_for_reset=1 则需要填注册邮箱）
     * @param string $emailCode     邮箱验证码（若传了 email，则验证邮箱验证码）
     * @param string $newPassword   新密码
     * @return array [success, message]
     */
    public static function resetPasswordByQq(
        string $qq,
        string $account,
        string $email,
        string $emailCode,
        string $newPassword
    ): array {
        $sec = self::getSecurityConfig();
        if (empty($sec['qq_bind_enabled'])) {
            return ['success' => false, 'message' => '系统未开启 QQ 绑定功能'];
        }
        $mode = (string)($sec['password_reset_mode'] ?? 'both');
        if ($mode === 'email_only') {
            return ['success' => false, 'message' => '管理员关闭了通过 QQ 号改密，请使用邮箱找回密码'];
        }
        if ($qq === '' || !ctype_digit($qq)) {
            return ['success' => false, 'message' => '请输入正确的 QQ 号'];
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 64) {
            return ['success' => false, 'message' => '密码长度需 6-64 位'];
        }

        // 查找用户
        $row = Database::fetch('SELECT id, username, phone, email, qq FROM users WHERE qq = ? LIMIT 1', [$qq]);
        if ($row === false) {
            return ['success' => false, 'message' => '该 QQ 号未绑定任何账号'];
        }

        // 要求匹配绑定的账号名（防止知道别人QQ号就改密码）
        $account = trim($account);
        $accountOk = true;
        if ($account !== '') {
            $accountLower = mb_strtolower($account);
            $hit = false;
            foreach (['username', 'phone', 'email'] as $f) {
                $v = (string)($row[$f] ?? '');
                if ($v !== '' && mb_strtolower($v) === $accountLower) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) $accountOk = false;
        }
        if (!$accountOk) {
            return ['success' => false, 'message' => '绑定的账号名不匹配，请输入绑定 QQ 的账号（用户名/手机号/邮箱）'];
        }

        // 邮箱验证码双重校验（可选，require_email_for_reset=1 必须校验注册邮箱）
        $email = trim($email);
        if (!empty($sec['require_email_for_reset'])) {
            $regEmail = (string)($row['email'] ?? '');
            if ($regEmail === '') {
                return ['success' => false, 'message' => '该账号未绑定邮箱，无法通过邮箱验证码校验，请联系管理员'];
            }
            if ($email === '' || strtolower($email) !== strtolower($regEmail)) {
                return ['success' => false, 'message' => '请填写该账号绑定的邮箱'];
            }
            if ($emailCode === '') {
                return ['success' => false, 'message' => '请输入邮箱验证码'];
            }
            $verify = CaptchaService::verify($regEmail, $emailCode, 'email');
            if (!$verify['success']) {
                return ['success' => false, 'message' => $verify['message']];
            }
        } elseif ($email !== '' && $emailCode !== '') {
            // 用户主动提供邮箱和验证码，走校验
            $verify = CaptchaService::verify($email, $emailCode, 'email');
            if (!$verify['success']) {
                return ['success' => false, 'message' => $verify['message']];
            }
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        Database::execute(
            'UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
            [$hash, (int)$row['id']]
        );
        self::invalidateAllUserTokens((int)$row['id']);
        return ['success' => true, 'message' => '密码已重置，请使用新密码登录'];
    }

    /**
     * 让用户所有 token 失效（通过 Redis 置一个"最早有效签发时间"）
     */
    private static function invalidateAllUserTokens(int $userId): void
    {
        if ($userId <= 0) return;
        try {
            $r = Redis::getInstance();
            // 当前时间之后签发的 token 才算有效
            $r->set('user_jwt_nbf:' . $userId, (string)time(), 'nx', 'ex', 86400 * 30);
        } catch (\Throwable $e) {
        }
    }
}
