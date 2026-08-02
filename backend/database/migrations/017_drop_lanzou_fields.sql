-- ============================================================
-- 017_drop_lanzou_fields.sql
-- 清理蓝奏云残留字段：apk_distributions.lanzou_url / lanzou_password
--
-- 背景：015 迁移已新增 feijipan_* 字段并完成业务切换，老字段
--       lanzou_url / lanzou_password 保留至今仅用于兼容映射。
--       现映射逻辑已移除，彻底 DROP 这两个字段。
--
-- 注意：MySQL 5.7 不支持 DROP COLUMN IF EXISTS，使用存储过程兼容。
--       DELIMITER 是 mysql 客户端命令，`mysql < file` 可正常执行。
-- ============================================================

DROP PROCEDURE IF EXISTS `drop_lanzou_columns`;
DELIMITER //
CREATE PROCEDURE `drop_lanzou_columns`()
BEGIN
    IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'apk_distributions' AND COLUMN_NAME = 'lanzou_url') THEN
        ALTER TABLE `apk_distributions` DROP COLUMN `lanzou_url`;
    END IF;
    IF EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'apk_distributions' AND COLUMN_NAME = 'lanzou_password') THEN
        ALTER TABLE `apk_distributions` DROP COLUMN `lanzou_password`;
    END IF;
END //
DELIMITER ;
CALL `drop_lanzou_columns`();
DROP PROCEDURE IF EXISTS `drop_lanzou_columns`;
