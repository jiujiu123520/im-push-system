<?php
declare(strict_types=1);

namespace Tests;

require_once __DIR__ . '/TestCase.php';

use App\Service\AdminService;
use App\Service\UserService;
use App\Service\Jwt;
use App\Service\Database;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use UnexpectedValueException;

/**
 * 鉴权测试
 *
 * 测试场景：
 *   1. 用户注册流程（验证码校验）
 *   2. 用户登录（JWT 签发）
 *   3. 管理员登录
 *   4. JWT 校验（正常 / 过期 / 无效签名 / 格式错误）
 */
class AuthTest extends TestCase
{
    // ================================================================
    // 用户注册流程测试
    // ================================================================

    /**
     * 测试用户注册 - 短信验证码校验通过
     */
    public function testUserRegisterWithSmsCode(): void
    {
        // 注入短信验证码
        $phone = '13800138000';
        $this->injectSmsCode($phone, '123456');

        $result = UserService::register(
            'testuser',
            $phone,
            'test@example.com',
            'password123',
            'sms',
            $phone,
            '123456'
        );

        $this->assertTrue($result['success'], '注册应成功：' . ($result['message'] ?? ''));
        $this->assertNotNull($result['user_id'], '应返回用户 ID');
        $this->assertGreaterThan(0, $result['user_id'], '用户 ID 应大于 0');

        // 验证用户已写入数据库
        $user = Database::fetch('SELECT * FROM users WHERE id = ?', [$result['user_id']]);
        $this->assertNotFalse($user, '用户记录应存在');
        $this->assertSame('testuser', $user['username']);
        $this->assertSame($phone, $user['phone']);
        $this->assertSame('test@example.com', $user['email']);
        $this->assertSame(1, (int)$user['status'], '状态应为 1（正常）');
        $this->assertTrue(password_verify('password123', $user['password_hash']), '密码哈希应可校验');
    }

    /**
     * 测试用户注册 - 验证码错误
     */
    public function testUserRegisterWithWrongCode(): void
    {
        $phone = '13800138001';
        $this->injectSmsCode($phone, '123456');

        $result = UserService::register(
            'testuser2',
            $phone,
            'test2@example.com',
            'password123',
            'sms',
            $phone,
            '000000'  // 错误验证码
        );

        $this->assertFalse($result['success'], '验证码错误应注册失败');
        $this->assertStringContainsString('验证码', $result['message']);
        $this->assertNull($result['user_id']);
    }

    /**
     * 测试用户注册 - 验证码目标与注册信息不匹配
     */
    public function testUserRegisterWithMismatchedCodeTarget(): void
    {
        $phone = '13800138002';
        $this->injectSmsCode($phone, '123456');

        // 验证码发送到 phone，但注册时 code_target 传入不同的手机号
        $result = UserService::register(
            'testuser3',
            $phone,
            'test3@example.com',
            'password123',
            'sms',
            '13900000000',  // 不匹配的目标
            '123456'
        );

        $this->assertFalse($result['success'], '验证码目标不匹配应注册失败');
        $this->assertStringContainsString('不匹配', $result['message']);
    }

    /**
     * 测试用户注册 - 邮箱验证码
     */
    public function testUserRegisterWithEmailCode(): void
    {
        $email = 'test4@example.com';
        $this->injectEmailCode($email, '654321');

        $result = UserService::register(
            'testuser4',
            '13800138003',
            $email,
            'password123',
            'email',
            $email,
            '654321'
        );

        $this->assertTrue($result['success'], '邮箱验证码注册应成功：' . ($result['message'] ?? ''));
        $this->assertGreaterThan(0, $result['user_id']);
    }

    /**
     * 测试用户注册 - 用户名已存在
     */
    public function testUserRegisterDuplicateUsername(): void
    {
        $phone1 = '13800138004';
        $this->injectSmsCode($phone1, '111111');
        UserService::register('dup_user', $phone1, 'dup1@example.com', 'password123', 'sms', $phone1, '111111');

        $phone2 = '13800138005';
        $this->injectSmsCode($phone2, '222222');
        $result = UserService::register('dup_user', $phone2, 'dup2@example.com', 'password123', 'sms', $phone2, '222222');

        $this->assertFalse($result['success'], '重复用户名应注册失败');
        $this->assertStringContainsString('用户名', $result['message']);
    }

    /**
     * 测试用户注册 - 参数校验（用户名过短）
     */
    public function testUserRegisterShortUsername(): void
    {
        $phone = '13800138006';
        $this->injectSmsCode($phone, '123456');

        $result = UserService::register(
            'ab',  // 用户名过短（< 3）
            $phone,
            'short@example.com',
            'password123',
            'sms',
            $phone,
            '123456'
        );

        $this->assertFalse($result['success'], '用户名过短应注册失败');
        $this->assertStringContainsString('用户名', $result['message']);
    }

    // ================================================================
    // 用户登录测试
    // ================================================================

    /**
     * 测试用户登录 - JWT 签发
     */
    public function testUserLoginIssuesJwt(): void
    {
        // 先注册用户
        $phone = '13800138010';
        $this->injectSmsCode($phone, '123456');
        UserService::register('loginuser', $phone, 'login@example.com', 'password123', 'sms', $phone, '123456');

        // 注入图形验证码
        $captcha = $this->injectImageCaptcha('LOGN');

        $result = UserService::login('loginuser', 'password123', $captcha['token'], $captcha['code']);

        $this->assertTrue($result['success'], '登录应成功：' . ($result['message'] ?? ''));
        $this->assertNotEmpty($result['token'], '应签发 JWT Token');
        $this->assertNotNull($result['user'], '应返回用户信息');
        $this->assertSame('loginuser', $result['user']['username']);

        // 验证 JWT 可解析
        $payload = Jwt::verify($result['token']);
        $this->assertSame('user', $payload['type'], 'JWT type 应为 user');
        $this->assertArrayHasKey('user_id', $payload);
    }

    /**
     * 测试用户登录 - 密码错误
     */
    public function testUserLoginWrongPassword(): void
    {
        $phone = '13800138011';
        $this->injectSmsCode($phone, '123456');
        UserService::register('loginuser2', $phone, 'login2@example.com', 'password123', 'sms', $phone, '123456');

        $captcha = $this->injectImageCaptcha('WRNG');
        $result = UserService::login('loginuser2', 'wrong_password', $captcha['token'], $captcha['code']);

        $this->assertFalse($result['success'], '密码错误应登录失败');
        $this->assertStringContainsString('密码', $result['message']);
        $this->assertNull($result['token']);
    }

    /**
     * 测试用户登录 - 图形验证码错误
     */
    public function testUserLoginWrongCaptcha(): void
    {
        $phone = '13800138012';
        $this->injectSmsCode($phone, '123456');
        UserService::register('loginuser3', $phone, 'login3@example.com', 'password123', 'sms', $phone, '123456');

        // 注入正确的验证码但传入错误的输入
        $captcha = $this->injectImageCaptcha('CRCT');
        $result = UserService::login('loginuser3', 'password123', $captcha['token'], 'WRONG_CODE');

        $this->assertFalse($result['success'], '图形验证码错误应登录失败');
        $this->assertStringContainsString('验证码', $result['message']);
    }

    /**
     * 测试用户登录 - 支持手机号登录
     */
    public function testUserLoginWithPhone(): void
    {
        $phone = '13800138013';
        $this->injectSmsCode($phone, '123456');
        UserService::register('phoneuser', $phone, 'phone@example.com', 'password123', 'sms', $phone, '123456');

        $captcha = $this->injectImageCaptcha('PHON');
        $result = UserService::login($phone, 'password123', $captcha['token'], $captcha['code']);

        $this->assertTrue($result['success'], '手机号登录应成功');
        $this->assertNotEmpty($result['token']);
    }

    // ================================================================
    // 管理员登录测试
    // ================================================================

    /**
     * 测试管理员登录 - 签发带 role 的 JWT
     */
    public function testAdminLoginIssuesJwtWithRole(): void
    {
        // 默认管理员 admin/admin123（setUp 中已创建）
        $captcha = $this->injectImageCaptcha('ADM1');

        $result = AdminService::login('admin', 'admin123', $captcha['token'], $captcha['code']);

        $this->assertTrue($result['success'], '管理员登录应成功：' . ($result['message'] ?? ''));
        $this->assertNotEmpty($result['token'], '应签发 JWT Token');
        $this->assertSame('super_admin', $result['admin']['role'], '角色应为 super_admin');
        $this->assertSame('admin', $result['admin']['username']);

        // 验证 JWT 包含 role 字段
        $payload = Jwt::verify($result['token']);
        $this->assertSame('admin', $payload['type'], 'JWT type 应为 admin');
        $this->assertSame('super_admin', $payload['role'], 'JWT role 应为 super_admin');
        $this->assertArrayHasKey('admin_id', $payload);
    }

    /**
     * 测试管理员登录 - 密码错误
     */
    public function testAdminLoginWrongPassword(): void
    {
        $captcha = $this->injectImageCaptcha('ADM2');
        $result = AdminService::login('admin', 'wrong_password', $captcha['token'], $captcha['code']);

        $this->assertFalse($result['success'], '密码错误应登录失败');
        $this->assertStringContainsString('密码', $result['message']);
        $this->assertNull($result['token']);
    }

    /**
     * 测试管理员登录 - 图形验证码错误
     */
    public function testAdminLoginWrongCaptcha(): void
    {
        $captcha = $this->injectImageCaptcha('ADM3');
        $result = AdminService::login('admin', 'admin123', $captcha['token'], 'WRONG');

        $this->assertFalse($result['success'], '验证码错误应登录失败');
        $this->assertStringContainsString('验证码', $result['message']);
    }

    /**
     * 测试管理员登录 - 管理员不存在
     */
    public function testAdminLoginNonExistent(): void
    {
        $captcha = $this->injectImageCaptcha('ADM4');
        $result = AdminService::login('nonexistent_admin', 'password', $captcha['token'], $captcha['code']);

        $this->assertFalse($result['success'], '不存在的管理员应登录失败');
        $this->assertStringContainsString('密码', $result['message']);
    }

    // ================================================================
    // JWT 校验测试
    // ================================================================

    /**
     * 测试 JWT 签发与校验 - 正常流程
     */
    public function testJwtIssueAndVerify(): void
    {
        $payload = [
            'user_id'  => 42,
            'username' => 'jwt_test_user',
            'type'     => 'user',
        ];

        $token = Jwt::issue($payload);

        $this->assertNotEmpty($token, '签发的 JWT 不应为空');

        $decoded = Jwt::verify($token);

        $this->assertSame(42, $decoded['user_id']);
        $this->assertSame('jwt_test_user', $decoded['username']);
        $this->assertSame('user', $decoded['type']);
        $this->assertSame('im-push', $decoded['iss'], '签发者应为 im-push');
        $this->assertArrayHasKey('iat', $decoded, '应包含 iat');
        $this->assertArrayHasKey('exp', $decoded, '应包含 exp');
        $this->assertArrayHasKey('nbf', $decoded, '应包含 nbf');
        $this->assertGreaterThan($decoded['iat'], $decoded['exp'], 'exp 应大于 iat');
    }

    /**
     * 测试 JWT 校验 - 过期 token
     */
    public function testJwtVerifyExpiredToken(): void
    {
        // 签发一个已过期的 token（有效期 -1 秒，即立即过期）
        $token = Jwt::issue(['user_id' => 1, 'type' => 'user'], -1);

        $this->expectException(ExpiredException::class);
        Jwt::verify($token);
    }

    /**
     * 测试 JWT 校验 - 无效签名
     */
    public function testJwtVerifyInvalidSignature(): void
    {
        // 用一个 secret 签发
        $token = Jwt::issue(['user_id' => 1, 'type' => 'user']);

        // 篡改 token 的签名部分
        $parts = explode('.', $token);
        $this->assertCount(3, $parts, 'JWT 应有 3 段');
        $tamperedToken = $parts[0] . '.' . $parts[1] . '.invalid_signature_part';

        $this->expectException(SignatureInvalidException::class);
        Jwt::verify($tamperedToken);
    }

    /**
     * 测试 JWT 校验 - 格式错误
     */
    public function testJwtVerifyMalformedToken(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Jwt::verify('this.is.not.a.valid.jwt');
    }

    /**
     * 测试 JWT 校验 - 空字符串
     */
    public function testJwtVerifyEmptyToken(): void
    {
        $this->expectException(UnexpectedValueException::class);
        Jwt::verify('');
    }

    /**
     * 测试 JWT 刷新 - refresh 方法重新签发
     */
    public function testJwtRefresh(): void
    {
        $token = Jwt::issue(['user_id' => 99, 'username' => 'refresh_user', 'type' => 'user']);

        // 等待 1 秒确保 iat 不同
        sleep(1);

        $newToken = Jwt::refresh($token);

        $this->assertNotSame($token, $newToken, '刷新后的 token 应不同');

        $decoded = Jwt::verify($newToken);
        $this->assertSame(99, $decoded['user_id'], '刷新后 user_id 应保持不变');
        $this->assertSame('refresh_user', $decoded['username']);
    }

    /**
     * 测试 JWT 从请求头提取 token
     */
    public function testJwtExtractTokenFromHeader(): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer abc123.token.xyz'];
        $token = Jwt::extractToken($server, []);

        $this->assertSame('abc123.token.xyz', $token, '应从 Authorization Bearer 头提取 token');
    }

    /**
     * 测试 JWT 从查询参数提取 token
     */
    public function testJwtExtractTokenFromQuery(): void
    {
        $server = [];
        $get = ['token' => 'query_token_123'];

        $token = Jwt::extractToken($server, $get);
        $this->assertSame('query_token_123', $token, '应从查询参数 token 提取');
    }

    /**
     * 测试 JWT 提取 - 无 token 时返回 null
     */
    public function testJwtExtractTokenNone(): void
    {
        $token = Jwt::extractToken([], []);
        $this->assertNull($token, '无 token 时应返回 null');
    }
}
