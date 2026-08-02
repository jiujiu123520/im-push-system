-- 019: 修复 users 表 phone/email 唯一键空值冲突
-- 问题：phone 字段 NOT NULL DEFAULT '' 且有 UNIQUE KEY，多个用户不填手机号时第二个起注册失败
-- 修复：将 phone 改为允许 NULL（MySQL 中多个 NULL 不冲突），删除原唯一键重建为仅非空值唯一

-- 1. 先将空字符串 phone 改为 NULL
UPDATE `users` SET `phone` = NULL WHERE `phone` = '';

-- 2. 修改 phone 字段允许 NULL
ALTER TABLE `users` MODIFY COLUMN `phone` VARCHAR(20) NULL DEFAULT NULL COMMENT '手机号';

-- 3. 删除原唯一键（uk_phone），重建为仅对非 NULL 值唯一
-- MySQL 唯一索引中 NULL 值不参与唯一性检查，多条 NULL 可共存
ALTER TABLE `users` DROP INDEX `uk_phone`;
ALTER TABLE `users` ADD UNIQUE KEY `uk_phone` (`phone`);

-- 4. email 同理处理（虽然原表无唯一键，但代码层做了唯一性校验，加上唯一键防止并发冲突）
UPDATE `users` SET `email` = NULL WHERE `email` = '';
ALTER TABLE `users` MODIFY COLUMN `email` VARCHAR(128) NULL DEFAULT NULL COMMENT '邮箱';
ALTER TABLE `users` ADD UNIQUE KEY `uk_email` (`email`);
