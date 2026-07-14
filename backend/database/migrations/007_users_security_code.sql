-- ============================================================
-- 007: users 表增加安全码字段
--
-- security_code: 8位数字安全码，注册时自动生成，不可修改
--   用于忘记密码时通过安全码重置密码
-- security_code_hash: 安全码哈希（bcrypt），不存储明文
-- ============================================================

ALTER TABLE `users`
  ADD COLUMN `security_code_hash` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '安全码哈希（8位数字，bcrypt）' AFTER `password_hash`;
