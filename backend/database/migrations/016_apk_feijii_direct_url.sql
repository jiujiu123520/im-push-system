-- ============================================================
-- 016_apk_feijii_direct_url.sql
--
-- 小飞机分享链接 -> 真实 CDN 直链 懒解析 + 缓存
--   - feijipan_direct_url:   解析到的真实 CDN 直链（可能包含 sign/expire 参数）
--   - feijipan_direct_expires: 直链过期时间（保守缓存 2 小时）
--   - feijipan_fetch_count: 解析次数（监控小飞机分享页结构变更）
--
-- 变更对象：apk_distributions 表
-- ============================================================

ALTER TABLE `apk_distributions`
  ADD COLUMN `feijipan_direct_url` TEXT NULL COMMENT '缓存的小飞机直链（解析分享页得到）' AFTER `feijipan_share_id`,
  ADD COLUMN `feijipan_direct_expires` DATETIME NULL COMMENT '直链过期时间（NULL=不强制过期）' AFTER `feijipan_direct_url`,
  ADD COLUMN `feijipan_fetch_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '解析小飞机分享页次数（用于监控告警）' AFTER `feijipan_direct_expires`;
