-- ============================================================
-- 即时消息推送系统 - 初始管理员数据
-- 版本：001
-- 说明：插入默认超级管理员账号
-- ============================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------
-- 默认超级管理员
--   username : admin
--   password : admin123
--   role     : super_admin
--   status   : 1
-- ----------------------------------------------------------
-- password_hash 是 "admin123" 的 bcrypt 哈希（cost=10）。
-- 注意：$2b$ 前缀的哈希可被 PHP password_verify() 完全校验通过
-- （PHP 兼容 $2a$ / $2b$ / $2y$ 三种前缀，算法完全一致）。
-- 如需重新生成哈希：
--   php -r "echo password_hash('admin123', PASSWORD_BCRYPT);"
-- ----------------------------------------------------------

INSERT INTO `admins` (`username`, `password_hash`, `role`, `status`, `created_at`, `updated_at`)
VALUES (
    'admin',
    '$2b$10$kMUTUgd0jCOYp04Jn4sbEu2mdq1CwGfdviL1/xMslAV0lsUDA06au',
    'super_admin',
    1,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    `password_hash` = VALUES(`password_hash`),
    `role`          = VALUES(`role`),
    `status`        = VALUES(`status`),
    `updated_at`    = NOW();
