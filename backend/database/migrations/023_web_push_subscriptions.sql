-- ============================================================
-- 023_web_push_subscriptions.sql
-- PWA + Web Push 订阅存储
--
-- 用途：
--   - 存储浏览器 Web Push 订阅信息，用于向 PWA（iOS Safari 主屏幕）推送通知
--   - device_id 为稳定设备标识（由 PWA 前端 localStorage 持久化，非随机）
--   - push_key_id 关联 push_keys，订阅归属某个推送 Key
--
-- 字段：
--   endpoint    PushSubscription.endpoint（推送网关地址，Apple/WNS/FCM）
--   p256dh      客户端加密公钥（RFC 8291 ECDH）
--   auth        客户端认证密钥（16 字节）
--   platform    浏览器平台：ios(webkit)/edge/chrome
--   status      1=有效 0=失效（订阅过期/取消时置 0）
-- ============================================================

CREATE TABLE IF NOT EXISTS `web_push_subscriptions` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `device_id`    VARCHAR(128)     NOT NULL DEFAULT '' COMMENT '稳定设备标识（localStorage 持久化）',
  `push_key_id`  BIGINT UNSIGNED  NOT NULL DEFAULT 0  COMMENT '关联 push_keys.id',
  `endpoint`     VARCHAR(512)     NOT NULL DEFAULT '' COMMENT 'PushSubscription.endpoint',
  `p256dh`       VARCHAR(256)     NOT NULL DEFAULT '' COMMENT '客户端加密公钥',
  `auth`         VARCHAR(256)     NOT NULL DEFAULT '' COMMENT '客户端认证密钥',
  `platform`     VARCHAR(32)      NOT NULL DEFAULT 'ios' COMMENT '平台：ios/edge/chrome',
  `user_agent`   VARCHAR(512)     NOT NULL DEFAULT '' COMMENT 'User-Agent',
  `status`       TINYINT          NOT NULL DEFAULT 1  COMMENT '状态：1=有效 0=失效',
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_key` (`device_id`, `push_key_id`),
  KEY `idx_push_key` (`push_key_id`),
  KEY `idx_endpoint` (`endpoint`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Web Push 订阅表';
