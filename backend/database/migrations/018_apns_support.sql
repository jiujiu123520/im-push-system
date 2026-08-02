-- ============================================================
-- 018_apns_support.sql
-- iOS APNS 推送支持
--
-- 用途：
--   - apns_token        iOS 设备的 APNS device token（由 iOS APP 上报）
--   - apns_active       APNS 是否可用（0=未注册/失效 1=正常）
--   - apns_bundle_id    iOS APP 的 Bundle ID（如 com.push.app）
--   - apns_updated_at   APNS token 最近更新时间
--
-- 说明：
--   - Android 设备这些字段为空，不影响现有逻辑
--   - iOS 设备在前台时仍走 WebSocket，后台/被杀时走 APNS
--   - PushDispatcher 在设备 WebSocket 离线时，检查 apns_token 是否存在，
--     存在则走 APNS 通道，不存在则存离线消息
-- ============================================================

ALTER TABLE `devices`
  ADD COLUMN `apns_token`      VARCHAR(256) NOT NULL DEFAULT '' COMMENT 'iOS APNS device token'    AFTER `fingerprint`,
  ADD COLUMN `apns_active`     TINYINT(1)   NOT NULL DEFAULT 0  COMMENT 'APNS 是否可用 0=否 1=是' AFTER `apns_token`,
  ADD COLUMN `apns_bundle_id`  VARCHAR(128) NOT NULL DEFAULT '' COMMENT 'iOS Bundle ID'           AFTER `apns_active`,
  ADD COLUMN `apns_updated_at` DATETIME     NULL DEFAULT NULL    COMMENT 'APNS token 更新时间'     AFTER `apns_bundle_id`,
  ADD INDEX `idx_apns_token`  (`apns_token`(64)),
  ADD INDEX `idx_apns_active` (`apns_active`);
