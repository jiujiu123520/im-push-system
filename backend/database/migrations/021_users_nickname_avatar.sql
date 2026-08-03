-- 021_users_nickname_avatar.sql
-- 为 users 表添加 nickname 和 avatar 字段，支持用户端个人中心

-- 1. 添加 nickname 字段
SET @col_nick_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'nickname');
SET @sql = IF(@col_nick_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `nickname` VARCHAR(64) NULL DEFAULT NULL COMMENT ''昵称'' AFTER `username`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. 添加 avatar 字段
SET @col_avatar_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'avatar');
SET @sql = IF(@col_avatar_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `avatar` VARCHAR(512) NULL DEFAULT NULL COMMENT ''头像URL'' AFTER `nickname`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
