<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\CaptchaService;
use App\Service\Response;
use App\Service\UserService;

/**
 * 认证控制器
 *
 * 处理前台用户的注册、登录、验证码相关接口。
 *
 * 返回值约定（与 HttpServer 配合）：
 *  - 成功：返回原始数据数组（HttpServer 会用 Response::ok 自动包装）
 *  - 失败：直接调用 Response::fail 写入响应，并返回 false 跳过自动包装
 *
 * 路由：
 *   GET  /captcha/image   获取图形验证码
 *   POST /auth/send-code  发送短信/邮箱验证码
 *   POST /auth/register   用户注册（返回 token + security_code）
 *   POST /auth/login      用户登录
 *   POST /auth/reset-password  通过安全码重置密码
 */
class AuthController
{
    /**
     * 获取图形验证码
     * GET /captcha/image
     *
     * @param array $context 请求上下文
     * @param array $params   路径参数
     * @return array|false
     */
    public static function captchaImage(array $context, array $params = [])
    {
        try {
            $data = CaptchaService::generateImageCaptcha();
            return [
                'token' => $data['token'],
                'image' => $data['image'],
            ];
        } catch (\Throwable $e) {
            Response::fail(
                $context['response'],
                '图形验证码生成失败：' . $e->getMessage(),
                Response::CODE_INTERNAL,
                500
            );
            return false;
        }
    }

    /**
     * 发送验证码（短信或邮箱）
     * POST /auth/send-code
     * Body: { "type": "sms"|"email", "target": "手机号或邮箱" }
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function sendCode(array $context, array $params = [])
    {
        $body = self::parseJsonBody($context);
        $type = (string)($body['type'] ?? '');
        $target = (string)($body['target'] ?? '');

        if ($type === '' || $target === '') {
            Response::fail($context['response'], '参数 type 和 target 不能为空', Response::CODE_BAD_REQUEST);
            return false;
        }

        switch (strtolower($type)) {
            case 'sms':
                $result = CaptchaService::sendSmsCode($target);
                break;
            case 'email':
                $result = CaptchaService::sendEmailCode($target);
                break;
            default:
                Response::fail($context['response'], 'type 仅支持 sms 或 email', Response::CODE_BAD_REQUEST);
                return false;
        }

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }

        return ['sent' => true, 'message' => $result['message']];
    }

    /**
     * 用户注册
     * POST /auth/register
     * Body: { username, phone, email, password, code_type, code_target, code_input }
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function register(array $context, array $params = [])
    {
        $body = self::parseJsonBody($context);

        $username   = (string)($body['username'] ?? '');
        $phone      = (string)($body['phone'] ?? '');
        $email      = (string)($body['email'] ?? '');
        $password   = (string)($body['password'] ?? '');
        $codeType   = (string)($body['code_type'] ?? '');
        $codeTarget = (string)($body['code_target'] ?? '');
        $codeInput  = (string)($body['code_input'] ?? '');

        $result = UserService::register(
            $username,
            $phone,
            $email,
            $password,
            $codeType,
            $codeTarget,
            $codeInput
        );

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }

        // 注册成功后返回 token（自动登录）+ security_code（仅此一次展示）
        return [
            'user_id'       => $result['user_id'],
            'token'         => $result['token'],
            'user'          => $result['user'],
            'security_code' => $result['security_code'],
        ];
    }

    /**
     * 通过安全码重置密码
     * POST /auth/reset-password
     * Body: { account, security_code, new_password }
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function resetPassword(array $context, array $params = [])
    {
        $body = self::parseJsonBody($context);

        $account      = (string)($body['account'] ?? '');
        $securityCode = (string)($body['security_code'] ?? '');
        $newPassword  = (string)($body['new_password'] ?? '');

        $result = UserService::resetPasswordBySecurityCode($account, $securityCode, $newPassword);

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }

        return ['message' => $result['message']];
    }

    /**
     * 用户登录
     * POST /auth/login
     * Body: { account, password, captcha_token, captcha_input }
     *
     * @param array $context
     * @param array $params
     * @return array|false
     */
    public static function login(array $context, array $params = [])
    {
        $body = self::parseJsonBody($context);

        $account      = (string)($body['account'] ?? '');
        $password     = (string)($body['password'] ?? '');
        $captchaToken = (string)($body['captcha_token'] ?? '');
        $captchaInput = (string)($body['captcha_input'] ?? '');

        $result = UserService::login($account, $password, $captchaToken, $captchaInput);

        if (!$result['success']) {
            Response::fail($context['response'], $result['message'], Response::CODE_ERROR);
            return false;
        }

        return [
            'token' => $result['token'],
            'user'  => $result['user'],
        ];
    }

    /**
     * 从请求上下文中解析 JSON Body
     *
     * Swoole 对 application/json 请求不会自动填充 $request->post，
     * 需要从 rawContent 中解析。同时对表单请求做兼容。
     *
     * @param array $context
     * @return array
     */
    private static function parseJsonBody(array $context): array
    {
        // 优先使用 Swoole 解析好的 post 字段（表单请求）
        $post = $context['post'] ?? [];
        if (!empty($post)) {
            return $post;
        }
        // 否则尝试解析 raw body（JSON 请求）
        $raw = $context['raw'] ?? '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }
}
