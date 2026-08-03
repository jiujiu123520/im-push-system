<?php
declare(strict_types=1);

namespace App\Controller\UserConsole;

use App\Service\CaptchaService;
use App\Service\Database;
use App\Service\Jwt;
use App\Service\UserService;

/**
 * 用户端个人中心
 *
 * 路由前缀：/user-api/profile
 */
class ProfileController extends BaseUserController
{
    public function info(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $row = Database::fetch(
            'SELECT id, username, nickname, phone, email, qq, avatar, status, created_at, updated_at
             FROM users WHERE id = ? LIMIT 1',
            [$userId]
        );
        if ($row === false) return $this->fail($context, '用户不存在', 404, 404);

        // QQ 绑定状态
        $row['qq_bound'] = !empty($row['qq']);
        // 手机号/邮箱脱敏
        $row['phone_masked'] = UserService::maskPhone((string)($row['phone'] ?? ''));
        $row['email_masked'] = UserService::maskEmail((string)($row['email'] ?? ''));
        $row['qq_masked']    = UserService::maskQq((string)($row['qq'] ?? ''));
        return $row;
    }

    public function update(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $body = $this->parseBody($context);
        $nickname  = trim((string)($body['nickname'] ?? ''));
        $email     = trim((string)($body['email'] ?? ''));
        $emailCode = trim((string)($body['email_code'] ?? ''));
        $avatar    = trim((string)($body['avatar'] ?? ''));

        $sets = [];
        $bind = [];

        // 昵称
        if ($nickname !== '') {
            if (mb_strlen($nickname) < 1 || mb_strlen($nickname) > 32) {
                return $this->fail($context, '昵称长度需 1-32 字符');
            }
            $sets[] = 'nickname = ?';
            $bind[] = $nickname;
        }

        // 邮箱（需验证码）
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->fail($context, '邮箱格式不正确');
            }
            if ($emailCode === '') {
                return $this->fail($context, '修改邮箱需要输入验证码');
            }
            // 校验验证码
            if (!CaptchaService::verifyCode('email', $email, $emailCode)) {
                return $this->fail($context, '邮箱验证码错误或已过期');
            }
            // 唯一性校验
            $dup = Database::fetch('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1', [$email, $userId]);
            if ($dup) return $this->fail($context, '该邮箱已被其他账号使用');
            $sets[] = 'email = ?';
            $bind[] = $email;
        }

        // 头像
        if ($avatar !== '') {
            if (strlen($avatar) > 512) return $this->fail($context, '头像 URL 过长');
            $sets[] = 'avatar = ?';
            $bind[] = $avatar;
        }

        if (empty($sets)) return $this->fail($context, '没有需要更新的字段');

        $sets[] = 'updated_at = NOW()';
        $bind[] = $userId;
        Database::execute('UPDATE users SET ' . implode(',', $sets) . ' WHERE id = ?', $bind);
        return $this->info($context, $params);
    }

    public function changePassword(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $body = $this->parseBody($context);
        $oldPwd    = (string)($body['old_password'] ?? '');
        $newPwd    = (string)($body['new_password'] ?? '');
        $confirmPwd = (string)($body['confirm_password'] ?? '');

        if ($oldPwd === '' || $newPwd === '') return $this->fail($context, '原密码与新密码不能为空');
        if ($newPwd !== $confirmPwd) return $this->fail($context, '两次输入的新密码不一致');
        if (strlen($newPwd) < 6 || strlen($newPwd) > 64) return $this->fail($context, '密码长度需 6-64 位');

        $row = Database::fetch('SELECT id, password_hash FROM users WHERE id = ? LIMIT 1', [$userId]);
        if ($row === false) return $this->fail($context, '用户不存在', 404, 404);
        if (!password_verify($oldPwd, (string)($row['password_hash'] ?? ''))) {
            return $this->fail($context, '原密码错误', 403, 403);
        }
        if (password_verify($newPwd, (string)($row['password_hash'] ?? ''))) {
            return $this->fail($context, '新密码不能与原密码相同');
        }

        $hash = password_hash($newPwd, PASSWORD_DEFAULT);
        Database::execute('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?', [$hash, $userId]);

        // 登出旧 Token：如果 JWT 支持吊销列表，当前 token 也一起吊销
        try {
            $server = isset($context['header']) ? array_change_key_case($context['header']) : [];
            $auth = $server['authorization'] ?? '';
            if (is_string($auth) && str_starts_with(strtolower($auth), 'bearer ')) {
                $tok = trim(substr($auth, 7));
                if ($tok !== '') {
                    Jwt::revoke($tok);
                }
            }
        } catch (\Throwable $e) {
        }

        return ['changed' => true, 'need_relogin' => true];
    }

    public function bindQq(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $row = Database::fetch('SELECT qq FROM users WHERE id = ? LIMIT 1', [$userId]);
        if ($row === false) return $this->fail($context, '用户不存在', 404, 404);

        // 后台开关
        $secCfg = UserService::getSecurityConfig();
        if (empty($secCfg['qq_bind_enabled'])) {
            return $this->fail($context, '管理员未开启 QQ 绑定功能');
        }
        // 绑定后用户不能自行解绑；但没绑定时可以绑
        if (!empty($row['qq'])) {
            return $this->fail($context, '已绑定 QQ，如需改绑请联系管理员', 403, 403);
        }

        $body = $this->parseBody($context);
        $qq = trim((string)($body['qq'] ?? ''));
        if ($qq === '' || !ctype_digit($qq) || strlen($qq) < 5 || strlen($qq) > 11) {
            return $this->fail($context, '请输入正确的 QQ 号（纯数字 5-11 位）');
        }

        // 唯一性校验（NULL 值的唯一键不会冲突，但如果其它用户填了同样值会冲突）
        $dup = Database::fetch('SELECT id FROM users WHERE qq = ? AND id <> ? LIMIT 1', [$qq, $userId]);
        if ($dup) return $this->fail($context, '该 QQ 号已被其他账号绑定');

        Database::execute('UPDATE users SET qq = ?, updated_at = NOW() WHERE id = ?', [$qq, $userId]);
        return ['qq' => $qq, 'bound' => true];
    }

    public function unbindQq(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        $secCfg = UserService::getSecurityConfig();
        if (empty($secCfg['user_self_unbind_qq'])) {
            return $this->fail($context, '不允许用户自行解绑 QQ，请联系管理员', 403, 403);
        }
        Database::execute('UPDATE users SET qq = NULL, updated_at = NOW() WHERE id = ?', [$userId]);
        return ['unbound' => true];
    }

    /**
     * 退出所有登录（吊销当前 token 并标记用户需重新登录）
     */
    public function logoutAll(array $context, array $params)
    {
        $payload = $this->auth($context);
        if ($payload === null) return false;
        $userId = (int)$context['user_id'];

        // 吊销当前 token
        try {
            $server = isset($context['header']) ? array_change_key_case($context['header']) : [];
            $auth = $server['authorization'] ?? '';
            if (is_string($auth) && str_starts_with(strtolower($auth), 'bearer ')) {
                $tok = trim(substr($auth, 7));
                if ($tok !== '') {
                    Jwt::revoke($tok);
                }
            }
        } catch (\Throwable $e) {
        }

        return ['logged_out' => true];
    }
}
