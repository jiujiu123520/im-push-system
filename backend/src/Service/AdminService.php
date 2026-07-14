<?php
declare(strict_types=1);

namespace App\Service;

/**
 * 管理员服务
 *
 * 处理管理员登录、列表查询、增删改、改密、操作日志等业务。
 * 管理员 JWT 中携带 role 字段，可由 super_admin/admin 区分权限。
 */
class AdminService
{
    /** 列表分页每页条数 */
    private const PAGE_SIZE = 10;

    /**
     * 管理员登录（独立接口，签发带 role 的 JWT）
     *
     * 校验顺序（避免验证码被无谓消费、避免账号状态信息泄露）：
     *   1. IP 黑名单检查
     *   2. 登录失败次数限制（Redis 计数）
     *   3. 用户名/密码非空
     *   4. 查询管理员（用户名不存在与密码错误返回相同提示，防止枚举）
     *   5. 账号状态检查
     *   6. 密码校验
     *   7. 图形验证码校验（最后消费，密码错误时不浪费验证码）
     *   8. 清除失败计数并签发 JWT
     *
     * @param string $username      管理员用户名
     * @param string $password      明文密码
     * @param string $captchaToken  图形验证码 token
     * @param string $captchaInput  用户输入的图形验证码
     * @param string $ip            登录 IP（用于黑名单与失败计数）
     * @return array ["success" => bool, "message" => string, "token" => string|null, "admin" => array|null]
     */
    public static function login(
        string $username,
        string $password,
        string $captchaToken,
        string $captchaInput,
        string $ip = ''
    ): array {
        $empty = ['success' => false, 'message' => '', 'token' => null, 'admin' => null];

        // 1. IP 黑名单检查
        if ($ip !== '') {
            try {
                $blacklist = new BlacklistService();
                if ($blacklist->check('ip', $ip)) {
                    $empty['message'] = '您的 IP 已被加入黑名单，请联系管理员';
                    return $empty;
                }
            } catch (\Throwable $e) {
                // 黑名单服务异常时不阻断登录，避免影响正常用户
            }
        }

        // 2. 登录失败次数限制（Redis 计数）
        $failLimit = self::getLoginFailLimit();
        $failKey = 'admin:login_fail:' . ($ip !== '' ? $ip : $username);
        $failCount = 0;
        if ($failLimit > 0) {
            try {
                $failCount = (int)Redis::get($failKey);
            } catch (\Throwable $e) {
                // Redis 不可用时降级为不限制
            }
            if ($failCount >= $failLimit) {
                $mins = 30;
                try {
                    $ttl = (int)Redis::ttl($failKey);
                    if ($ttl > 0) {
                        $mins = (int)max(1, ceil($ttl / 60));
                    }
                } catch (\Throwable $e) {
                }
                $empty['message'] = "登录失败次数过多，请 {$mins} 分钟后再试";
                return $empty;
            }
        }

        if ($username === '' || $password === '') {
            $empty['message'] = '用户名或密码不能为空';
            return $empty;
        }

        // 4. 查询管理员
        $admin = self::findByUsername($username);
        if ($admin === null) {
            self::recordLoginFailure($failKey);
            $empty['message'] = '用户名或密码错误';
            return $empty;
        }

        // 5. 账号状态检查
        if ((int)$admin['status'] !== 1) {
            // 不区分"账号不存在"与"账号禁用"过于严格，这里保留禁用提示以便用户联系管理员
            $empty['message'] = '账号已被禁用，请联系超级管理员';
            return $empty;
        }

        // 6. 密码校验
        if (!password_verify($password, $admin['password_hash'])) {
            self::recordLoginFailure($failKey);
            $empty['message'] = '用户名或密码错误';
            return $empty;
        }

        // 7. 图形验证码校验（最后消费，密码错误时验证码仍可用）
        if (self::isCaptchaEnabled() && !CaptchaService::verifyImageCaptcha($captchaToken, $captchaInput)) {
            $empty['message'] = '图形验证码错误或已过期';
            return $empty;
        }

        // 8. 登录成功，清除失败计数
        self::clearLoginFailure($failKey);

        // 9. 签发带 role 的 JWT Token
        $token = Jwt::issue([
            'admin_id' => (int)$admin['id'],
            'username' => $admin['username'],
            'role'     => $admin['role'],
            'type'     => 'admin',
        ]);

        return [
            'success' => true,
            'message' => '登录成功',
            'token'   => $token,
            'admin'   => self::formatAdminInfo($admin),
        ];
    }

    /**
     * 读取验证码开关（admin_settings.settings_captcha.enabled，默认开启）
     *
     * @return bool
     */
    private static function isCaptchaEnabled(): bool
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
            // 表可能不存在或读取失败，保持默认开启
        }
        return true;
    }

    /**
     * 读取登录失败次数上限（admin_settings.settings_security.loginFailLimit，默认 5，0=不限制）
     *
     * @return int
     */
    private static function getLoginFailLimit(): int
    {
        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_security']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg) && isset($cfg['loginFailLimit'])) {
                    $val = (int)$cfg['loginFailLimit'];
                    return $val >= 0 ? $val : 5;
                }
            }
        } catch (\Throwable $e) {
        }
        return 5;
    }

    /**
     * 记录一次登录失败（Redis INCR + 30 分钟过期）
     *
     * @param string $failKey
     * @return void
     */
    private static function recordLoginFailure(string $failKey): void
    {
        try {
            $count = (int)Redis::incr($failKey);
            if ($count === 1) {
                // 首次失败设置 30 分钟过期
                Redis::expire($failKey, 1800);
            }
        } catch (\Throwable $e) {
            // Redis 不可用时忽略
        }
    }

    /**
     * 清除登录失败计数
     *
     * @param string $failKey
     * @return void
     */
    private static function clearLoginFailure(string $failKey): void
    {
        try {
            Redis::del($failKey);
        } catch (\Throwable $e) {
        }
    }

    /**
     * 获取当前登录管理员信息
     *
     * @param int $adminId
     * @return array|null
     */
    public static function getInfo(int $adminId): ?array
    {
        $admin = self::findById($adminId);
        if ($admin === null) {
            return null;
        }
        return self::formatAdminInfo($admin);
    }

    /**
     * 分页查询管理员列表（每页 10 条）
     *
     * @param int    $page    页码（从 1 开始）
     * @param string $keyword 搜索关键字（匹配用户名）
     * @return array ["list" => array, "total" => int, "page" => int, "page_size" => int]
     */
    public static function getList(int $page, string $keyword): array
    {
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $keyword = trim($keyword);

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $countRow = Database::fetch(
                'SELECT COUNT(*) AS cnt FROM admins WHERE username LIKE ?',
                [$like]
            );
            $total = $countRow === false ? 0 : (int)($countRow['cnt'] ?? 0);

            $list = Database::fetchAll(
                'SELECT id, username, role, status, created_at, updated_at
                 FROM admins
                 WHERE username LIKE ?
                 ORDER BY id DESC
                 LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
                [$like]
            );
        } else {
            $countRow = Database::fetch('SELECT COUNT(*) AS cnt FROM admins');
            $total = $countRow === false ? 0 : (int)($countRow['cnt'] ?? 0);

            $list = Database::fetchAll(
                'SELECT id, username, role, status, created_at, updated_at
                 FROM admins
                 ORDER BY id DESC
                 LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
                []
            );
        }

        // 类型规范化
        foreach ($list as &$item) {
            $item['id'] = (int)$item['id'];
            $item['status'] = (int)$item['status'];
        }
        unset($item);

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => self::PAGE_SIZE,
        ];
    }

    /**
     * 创建管理员
     *
     * @param string $username  用户名
     * @param string $password  明文密码
     * @param string $role      角色：super_admin/admin
     * @return array ["success" => bool, "message" => string, "id" => int|null]
     */
    public static function create(string $username, string $password, string $role): array
    {
        // 校验用户名
        if (trim($username) === '' || strlen($username) < 3 || strlen($username) > 64) {
            return ['success' => false, 'message' => '用户名长度需在 3-64 之间', 'id' => null];
        }
        if (strlen($password) < 6 || strlen($password) > 64) {
            return ['success' => false, 'message' => '密码长度需在 6-64 之间', 'id' => null];
        }
        if (!in_array($role, ['super_admin', 'admin'], true)) {
            return ['success' => false, 'message' => '角色无效，仅支持 super_admin/admin', 'id' => null];
        }

        // 唯一性校验
        if (self::findByUsername($username) !== null) {
            return ['success' => false, 'message' => '用户名已存在', 'id' => null];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        if ($hash === false) {
            return ['success' => false, 'message' => '密码加密失败', 'id' => null];
        }

        $now = date('Y-m-d H:i:s');
        try {
            $id = Database::insert(
                'INSERT INTO admins (username, password_hash, role, status, created_at, updated_at)
                 VALUES (?, ?, ?, 1, ?, ?)',
                [$username, $hash, $role, $now, $now]
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '创建失败：' . $e->getMessage(), 'id' => null];
        }

        return ['success' => true, 'message' => '创建成功', 'id' => (int)$id];
    }

    /**
     * 更新管理员
     *
     * 可修改 username、password、role、status。
     * 修改密码时旧密码立即失效（更新 password_hash 后旧 hash 不再匹配）。
     *
     * @param int   $id   管理员ID
     * @param array $data 待更新字段：username/password/role/status
     * @return array ["success" => bool, "message" => string]
     */
    public static function update(int $id, array $data): array
    {
        $admin = self::findById($id);
        if ($admin === null) {
            return ['success' => false, 'message' => '管理员不存在'];
        }

        $fields = [];
        $params = [];

        // username
        if (isset($data['username']) && $data['username'] !== '') {
            $newUsername = (string)$data['username'];
            if (strlen($newUsername) < 3 || strlen($newUsername) > 64) {
                return ['success' => false, 'message' => '用户名长度需在 3-64 之间'];
            }
            if ($newUsername !== $admin['username'] && self::findByUsername($newUsername) !== null) {
                return ['success' => false, 'message' => '用户名已存在'];
            }
            $fields[] = 'username = ?';
            $params[] = $newUsername;
        }

        // password
        if (isset($data['password']) && $data['password'] !== '') {
            $newPwd = (string)$data['password'];
            if (strlen($newPwd) < 6 || strlen($newPwd) > 64) {
                return ['success' => false, 'message' => '密码长度需在 6-64 之间'];
            }
            $hash = password_hash($newPwd, PASSWORD_BCRYPT);
            if ($hash === false) {
                return ['success' => false, 'message' => '密码加密失败'];
            }
            // 修改密码后旧密码立即失效：更新 password_hash 即可，旧 hash 不再匹配
            $fields[] = 'password_hash = ?';
            $params[] = $hash;
        }

        // role
        if (isset($data['role']) && $data['role'] !== '') {
            if (!in_array($data['role'], ['super_admin', 'admin'], true)) {
                return ['success' => false, 'message' => '角色无效'];
            }
            // 防止把最后一个 super_admin 降级
            if ($admin['role'] === 'super_admin' && $data['role'] !== 'super_admin') {
                $superCount = self::countSuperAdmins();
                if ($superCount <= 1) {
                    return ['success' => false, 'message' => '不能降级最后一个超级管理员'];
                }
            }
            $fields[] = 'role = ?';
            $params[] = $data['role'];
        }

        // status
        if (isset($data['status'])) {
            $status = (int)$data['status'];
            if (!in_array($status, [0, 1], true)) {
                return ['success' => false, 'message' => '状态无效'];
            }
            // 防止禁用最后一个 super_admin
            if ($admin['role'] === 'super_admin' && $status === 0 && (int)$admin['status'] === 1) {
                $superCount = self::countSuperAdmins();
                if ($superCount <= 1) {
                    return ['success' => false, 'message' => '不能禁用最后一个超级管理员'];
                }
            }
            $fields[] = 'status = ?';
            $params[] = $status;
        }

        if (empty($fields)) {
            return ['success' => false, 'message' => '没有需要更新的字段'];
        }

        $fields[] = 'updated_at = ?';
        $params[] = date('Y-m-d H:i:s');
        $params[] = $id;

        try {
            Database::execute(
                'UPDATE admins SET ' . implode(', ', $fields) . ' WHERE id = ?',
                $params
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '更新失败：' . $e->getMessage()];
        }

        return ['success' => true, 'message' => '更新成功'];
    }

    /**
     * 删除管理员
     *
     * 不能删除最后一个 super_admin。
     *
     * @param int $id 管理员ID
     * @return array ["success" => bool, "message" => string]
     */
    public static function delete(int $id): array
    {
        $admin = self::findById($id);
        if ($admin === null) {
            return ['success' => false, 'message' => '管理员不存在'];
        }

        if ($admin['role'] === 'super_admin') {
            $superCount = self::countSuperAdmins();
            if ($superCount <= 1) {
                return ['success' => false, 'message' => '不能删除最后一个超级管理员'];
            }
        }

        try {
            $affected = Database::execute('DELETE FROM admins WHERE id = ?', [$id]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '删除失败：' . $e->getMessage()];
        }
        if ($affected === 0) {
            return ['success' => false, 'message' => '删除失败，记录可能已不存在'];
        }
        return ['success' => true, 'message' => '删除成功'];
    }

    /**
     * 修改密码
     *
     * @param int    $id           管理员ID
     * @param string $oldPassword  旧密码
     * @param string $newPassword  新密码
     * @return array ["success" => bool, "message" => string]
     */
    public static function changePassword(int $id, string $oldPassword, string $newPassword): array
    {
        $admin = self::findById($id);
        if ($admin === null) {
            return ['success' => false, 'message' => '管理员不存在'];
        }
        if (!password_verify($oldPassword, $admin['password_hash'])) {
            return ['success' => false, 'message' => '旧密码错误'];
        }
        if (strlen($newPassword) < 6 || strlen($newPassword) > 64) {
            return ['success' => false, 'message' => '新密码长度需在 6-64 之间'];
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        if ($hash === false) {
            return ['success' => false, 'message' => '密码加密失败'];
        }
        // 更新 password_hash 后旧密码立即失效
        try {
            Database::execute(
                'UPDATE admins SET password_hash = ?, updated_at = ? WHERE id = ?',
                [$hash, date('Y-m-d H:i:s'), $id]
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '修改失败：' . $e->getMessage()];
        }
        return ['success' => true, 'message' => '密码修改成功'];
    }

    /**
     * 记录管理员操作日志
     *
     * @param int    $adminId     操作管理员ID
     * @param string $action      操作动作
     * @param string $targetType  目标类型
     * @param mixed  $targetId    目标ID
     * @param mixed  $detail      操作详情（数组会被 JSON 序列化）
     * @param string $ip          操作 IP
     * @return int 日志ID
     */
    public static function logAction(
        int $adminId,
        string $action,
        string $targetType,
        $targetId,
        $detail,
        string $ip
    ): int {
        $targetId = (string)$targetId;
        if (is_array($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE);
        }
        try {
            $id = Database::insert(
                'INSERT INTO admin_logs (admin_id, action, target_type, target_id, detail, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$adminId, $action, $targetType, $targetId, (string)$detail, $ip, date('Y-m-d H:i:s')]
            );
            return (int)$id;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * 操作日志列表（分页 10 条）
     *
     * @param int $page 页码
     * @return array ["list" => array, "total" => int, "page" => int, "page_size" => int]
     */
    public static function getLogs(int $page): array
    {
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $countRow = Database::fetch('SELECT COUNT(*) AS cnt FROM admin_logs');
        $total = $countRow === false ? 0 : (int)($countRow['cnt'] ?? 0);
        $list = Database::fetchAll(
            'SELECT l.id, l.admin_id, l.action, l.target_type, l.target_id, l.detail, l.ip, l.created_at,
                    a.username AS admin_username
             FROM admin_logs l
             LEFT JOIN admins a ON a.id = l.admin_id
             ORDER BY l.id DESC
             LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset
        );
        foreach ($list as &$item) {
            $item['id'] = (int)$item['id'];
            $item['admin_id'] = (int)$item['admin_id'];
        }
        unset($item);
        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => self::PAGE_SIZE,
        ];
    }

    // ============================================================
    // 以下为内部辅助方法
    // ============================================================

    /**
     * 根据ID查询管理员
     *
     * @param int $id
     * @return array|null
     */
    public static function findById(int $id): ?array
    {
        $row = Database::fetch('SELECT * FROM admins WHERE id = ? LIMIT 1', [$id]);
        return $row === false ? null : $row;
    }

    /**
     * 根据用户名查询管理员
     *
     * @param string $username
     * @return array|null
     */
    public static function findByUsername(string $username): ?array
    {
        $row = Database::fetch('SELECT * FROM admins WHERE username = ? LIMIT 1', [$username]);
        return $row === false ? null : $row;
    }

    /**
     * 统计超级管理员数量
     *
     * @return int
     */
    private static function countSuperAdmins(): int
    {
        $row = Database::fetch(
            "SELECT COUNT(*) AS cnt FROM admins WHERE role = 'super_admin' AND status = 1"
        );
        if ($row === false) {
            return 0;
        }
        return (int)($row['cnt'] ?? 0);
    }

    /**
     * 格式化管理员信息（去掉敏感字段）
     *
     * @param array $admin
     * @return array
     */
    private static function formatAdminInfo(array $admin): array
    {
        return [
            'id'         => (int)$admin['id'],
            'username'   => $admin['username'],
            'role'       => $admin['role'],
            'status'     => (int)$admin['status'],
            'created_at' => $admin['created_at'] ?? '',
            'updated_at' => $admin['updated_at'] ?? '',
        ];
    }
}
