-- ============================================================
-- 迁移脚本：为 push_keys 表添加设备掉线邮箱通知配置字段
-- 版本：002
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 为 push_keys 表添加通知配置字段
ALTER TABLE `push_keys`
  ADD COLUMN `notify_email`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '设备掉线通知邮箱（多个用逗号分隔）' AFTER `max_devices`,
  ADD COLUMN `notify_enabled`    TINYINT      NOT NULL DEFAULT 0 COMMENT '是否启用掉线通知：0=禁用 1=启用' AFTER `notify_email`,
  ADD COLUMN `notify_interval`   INT          NOT NULL DEFAULT 300 COMMENT '通知间隔（秒），避免频繁通知' AFTER `notify_enabled`;

SET FOREIGN_KEY_CHECKS = 1;

-- 更新已有记录的默认值（保持兼容）
UPDATE `push_keys` SET `notify_enabled` = 0 WHERE `notify_enabled` IS NULL;
