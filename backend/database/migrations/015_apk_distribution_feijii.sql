-- ============================================================
-- 015_apk_distribution_feijii.sql
-- APK 分发表：从蓝奏云切换到小飞机网盘 (feejii.com)
--   - 新增 feijipan_url、feijipan_share_id 字段
--   - 保留原 lanzou_* 字段（兼容老数据，不立即删除）
-- ============================================================

ALTER TABLE `apk_distributions`
  ADD COLUMN `feijipan_url` VARCHAR(512) NOT NULL DEFAULT '' COMMENT '小飞机网盘下载链接' AFTER `custom_url`,
  ADD COLUMN `feijipan_share_id` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '小飞机网盘文件分享ID/密码' AFTER `feijipan_url`;

-- 为新字段创建索引（加速按链接查找）
CREATE INDEX `idx_feijipan_share_id` ON `apk_distributions` (`feijipan_share_id`);
