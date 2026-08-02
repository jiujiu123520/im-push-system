-- 019: 修复 users 表 phone/email 唯一键空值冲突
-- 问题：phone 字段 NOT NULL DEFAULT '' 且有 UNIQUE KEY，多个用户不填手机号时第二个起注册失败
-- 修复：将 phone/email 改为允许 NULL（MySQL 中多个 NULL 不冲突），唯一键中 NULL 不参与唯一性检查

-- 1. 先将空字符串 phone/email 改为 NULL
UPDATE `users` SET `phone` = NULL WHERE `phone` = '';
UPDATE `users` SET `email` = NULL WHERE `email` = '';

-- 2. 修改字段允许 NULL
ALTER TABLE `users` MODIFY COLUMN `phone` VARCHAR(20) NULL DEFAULT NULL COMMENT '手机号';
ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(128) NULL DEFAULT NULL COMMENT '邮箱';

-- 3. 删除 uk_phone（如果存在）并重建
SET @phone_index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uk_phone');
SET @sql = IF(@phone_index_exists > 0, 'ALTER TABLE `users` DROP INDEX `uk_phone`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
ALTER TABLE `users` ADD UNIQUE KEY `uk_phone` (`phone`);

-- 4. uk_email 唯一键（如果不存在才创建）
SET @email_index_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'uk_email');
SET @sql = IF(@email_index_exists = 0, 'ALTER TABLE `users` ADD UNIQUE KEY `uk_email` (`email`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
