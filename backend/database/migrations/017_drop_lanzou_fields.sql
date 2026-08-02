-- ============================================================
-- 017_drop_lanzou_fields.sql
-- 清理蓝奏云残留字段：apk_distributions.lanzou_url / lanzou_password
--
-- 背景：015 迁移已新增 feijipan_* 字段并完成业务切换，老字段
--       lanzou_url / lanzou_password 保留至今仅用于兼容映射。
--       现映射逻辑已移除，彻底 DROP 这两个字段。
-- ============================================================

ALTER TABLE `apk_distributions`
  DROP COLUMN IF EXISTS `lanzou_url`,
  DROP COLUMN IF EXISTS `lanzou_password`;
