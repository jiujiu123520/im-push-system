-- ============================================================
-- 迁移脚本：为 push_keys 表添加掉线提醒阈值字段（per-Key 可配）
-- 版本：022
-- 说明：不同 Key 对掉线敏感度不同（关键设备想 5 分钟就知道，
--       普通设备 30 分钟即可），阈值从全局常量改为 Key 级配置
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 掉线提醒阈值（分钟）：0=使用系统默认 30 分钟，有效范围 5~1440
ALTER TABLE `push_keys`
  ADD COLUMN `notify_offline_minutes` INT NOT NULL DEFAULT 0 COMMENT '掉线提醒阈值（分钟）：0=系统默认30，范围5~1440' AFTER `notify_interval`;

SET FOREIGN_KEY_CHECKS = 1;
