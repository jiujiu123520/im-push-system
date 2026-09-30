-- ============================================================
-- 024_web_push_subscriptions_meta.sql
-- 扩展 web_push_subscriptions 表：补充 PWA 设备元信息字段
--
-- 用途：让「订阅设备明细」能展示 PWA（iOS Safari 主屏幕）设备的
--   平台 / 设备名 / 型号 / APP版本 / IP / 最后活跃，而不再全是「未知」。
--
-- 字段：
--   device_name     设备名（如 "iPhone PWA"）
--   device_model    型号（iPhone / iPad / Edge / Chrome）
--   os_version      系统/浏览器版本（如 "iOS 17.5"）
--   app_version     PWA 应用版本号
--   ip              客户端 IP（订阅时记录，心跳更新）
--   last_active_at  最后活跃时间（订阅/心跳更新）
-- ============================================================

ALTER TABLE `web_push_subscriptions`
  ADD COLUMN `device_name`    VARCHAR(128) NOT NULL DEFAULT ''     COMMENT '设备名（PWA 显示名）'             AFTER `platform`,
  ADD COLUMN `device_model`   VARCHAR(128) NOT NULL DEFAULT ''     COMMENT '型号（iPhone/iPad/Edge/Chrome）' AFTER `device_name`,
  ADD COLUMN `os_version`     VARCHAR(64)  NOT NULL DEFAULT ''     COMMENT '系统/浏览器版本'                   AFTER `device_model`,
  ADD COLUMN `app_version`    VARCHAR(32)  NOT NULL DEFAULT ''     COMMENT 'PWA 应用版本号'                   AFTER `os_version`,
  ADD COLUMN `ip`             VARCHAR(64)  NOT NULL DEFAULT ''     COMMENT '客户端 IP'                        AFTER `app_version`,
  ADD COLUMN `last_active_at` DATETIME     NULL DEFAULT NULL       COMMENT '最后活跃时间'                     AFTER `ip`;
