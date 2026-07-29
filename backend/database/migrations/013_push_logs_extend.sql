-- ============================================================
-- 013_push_logs_extend.sql
-- 扩展 push_logs 表：新增失败原因、推送状态、推送耗时字段
-- 用于支撑"推送消息失败原因与日志"功能
-- ============================================================

ALTER TABLE `push_logs`
    ADD COLUMN `fail_reason` VARCHAR(500) NULL DEFAULT NULL COMMENT '失败原因摘要（人类可读）' AFTER `fail_count`,
    ADD COLUMN `status`      TINYINT       NOT NULL DEFAULT 0 COMMENT '推送状态：0=失败 1=成功 2=部分成功 3=进行中' AFTER `fail_reason`,
    ADD COLUMN `elapsed_ms`  INT           NOT NULL DEFAULT 0 COMMENT '推送耗时（毫秒）' AFTER `status`;

-- 为 status 字段添加索引，便于按状态筛选
ALTER TABLE `push_logs`
    ADD INDEX `idx_status` (`status`);

-- 回填历史数据的 status 字段（根据 success_count/fail_count 派生）
UPDATE `push_logs`
SET `status` = CASE
    WHEN `success_count` > 0 AND `fail_count` = 0 THEN 1
    WHEN `success_count` > 0 AND `fail_count` > 0 THEN 2
    WHEN `success_count` = 0 AND `fail_count` > 0 THEN 0
    ELSE 0
END;
