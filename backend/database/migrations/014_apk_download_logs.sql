-- ============================================================
-- 014: APK 下载统计
--
-- 1. apk_distributions 表增加 download_count 下载次数字段
-- 2. 新建 apk_download_logs 下载日志表（记录每次下载的 IP/UA/时间）
-- ============================================================

-- 1. 给 apk_distributions 增加 download_count 字段
ALTER TABLE `apk_distributions`
  ADD COLUMN `download_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下载次数' AFTER `upload_message`;

-- 2. 下载日志表
CREATE TABLE IF NOT EXISTS `apk_download_logs` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `distribution_id` BIGINT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '分发记录ID',
  `download_token`  VARCHAR(64)      NOT NULL DEFAULT ''     COMMENT '下载令牌',
  `ip_address`      VARCHAR(45)      NOT NULL DEFAULT ''     COMMENT '下载者IP',
  `user_agent`      VARCHAR(512)     NOT NULL DEFAULT ''     COMMENT 'User-Agent',
  `referer`         VARCHAR(512)     NOT NULL DEFAULT ''     COMMENT '来源页面',
  `downloaded_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '下载时间',
  PRIMARY KEY (`id`),
  KEY `idx_distribution_id` (`distribution_id`),
  KEY `idx_downloaded_at` (`downloaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='APK 下载日志表';
