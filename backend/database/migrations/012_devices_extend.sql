-- ============================================================
-- 012_devices_extend.sql
-- 扩展 devices 表：新增 platform、app_version、last_active_at 字段
--
-- 用途：
--   - platform      设备平台（android/ios/web/harmony），由 APP 鉴权时上报
--   - app_version   APP 版本号（如 1.0.0），用于设备管理展示与版本分布统计
--   - last_active_at 最后活跃时间，由 WebSocket 心跳周期性更新
-- ============================================================

ALTER TABLE `devices`
  ADD COLUMN `platform`       VARCHAR(32)  NOT NULL DEFAULT ''     COMMENT '设备平台：android/ios/web/harmony' AFTER `device_model`,
  ADD COLUMN `app_version`    VARCHAR(32)  NOT NULL DEFAULT ''     COMMENT 'APP 版本号'                       AFTER `os_version`,
  ADD COLUMN `last_active_at` DATETIME     NULL DEFAULT NULL       COMMENT '最后活跃时间（心跳更新）'         AFTER `last_connect_at`,
  ADD INDEX `idx_platform`       (`platform`),
  ADD INDEX `idx_last_active_at` (`last_active_at`);
