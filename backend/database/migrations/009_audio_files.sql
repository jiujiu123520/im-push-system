-- ============================================================
-- 音频文件管理表
-- 用于后台上传音频，APP 端播放
-- ============================================================

CREATE TABLE IF NOT EXISTS `audio_files` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`       VARCHAR(200)  NOT NULL DEFAULT '' COMMENT '音频标题',
    `artist`      VARCHAR(100)  NOT NULL DEFAULT '' COMMENT '艺术家/歌手',
    `filename`    VARCHAR(255)  NOT NULL DEFAULT '' COMMENT '服务器文件名',
    `file_path`   VARCHAR(500)  NOT NULL DEFAULT '' COMMENT '服务器文件路径',
    `file_size`   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '文件大小（字节）',
    `duration`    INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '时长（秒）',
    `mime_type`   VARCHAR(100)  NOT NULL DEFAULT '' COMMENT 'MIME 类型',
    `is_default`  TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '是否默认播放 1=是 0=否',
    `sort_order`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '排序（从小到大）',
    `status`      TINYINT(1)    NOT NULL DEFAULT 1 COMMENT '状态 1=启用 0=禁用',
    `play_count`  INT UNSIGNED  NOT NULL DEFAULT 0 COMMENT '播放次数',
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_is_default` (`is_default`),
    INDEX `idx_sort_order` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='音频文件管理';

-- 插入一条示例记录（可选，注释掉，按需手动插入）
-- INSERT INTO `audio_files` (`title`, `artist`, `filename`, `file_path`, `file_size`, `mime_type`, `is_default`, `sort_order`)
-- VALUES ('示例音频', '未知', 'demo.mp3', '/www/push-system/storage/audio/demo.mp3', 0, 'audio/mpeg', 1, 0);
