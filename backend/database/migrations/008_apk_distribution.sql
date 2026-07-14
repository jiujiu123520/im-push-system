-- ============================================================
-- 008: APK 分发记录表
--
-- 用于记录每次构建完成后 APK 的分发信息：
-- - 自托管下载（服务器直接提供下载）
-- - 蓝奏云上传
-- - 自定义脚本上传
-- ============================================================

CREATE TABLE IF NOT EXISTS `apk_distributions` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '分发记录ID',
  `build_id`        VARCHAR(64)      NOT NULL DEFAULT ''     COMMENT '构建ID（关联 Redis build:task:{build_id}）',
  `app_name`        VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT '应用名称',
  `package_name`    VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT '包名',
  `version_name`    VARCHAR(32)      NOT NULL DEFAULT ''     COMMENT '版本名（如 1.0.0）',
  `apk_path`        VARCHAR(512)     NOT NULL DEFAULT ''     COMMENT 'APK 文件服务器相对路径（build/output/{build_id}/app-release.apk）',
  `apk_size`        BIGINT UNSIGNED  NOT NULL DEFAULT 0      COMMENT 'APK 文件大小（字节）',
  `md5`             VARCHAR(32)      NOT NULL DEFAULT ''     COMMENT 'APK 文件 MD5',
  `download_token`  VARCHAR(64)      NOT NULL DEFAULT ''     COMMENT '下载令牌（用于公开下载链接鉴权）',
  `self_hosted_url` VARCHAR(512)     NOT NULL DEFAULT ''     COMMENT '自托管下载 URL',
  `lanzou_url`      VARCHAR(512)     NOT NULL DEFAULT ''     COMMENT '蓝奏云分享链接',
  `lanzou_password` VARCHAR(32)      NOT NULL DEFAULT ''     COMMENT '蓝奏云分享密码',
  `custom_url`      VARCHAR(512)     NOT NULL DEFAULT ''     COMMENT '自定义上传后的 URL',
  `upload_status`   VARCHAR(20)     NOT NULL DEFAULT 'pending' COMMENT '上传状态：pending/uploading/success/failed/disabled',
  `upload_message`  TEXT            NULL                     COMMENT '上传结果消息（成功或失败原因）',
  `admin_id`        INT UNSIGNED     NOT NULL DEFAULT 0      COMMENT '提交构建的管理员ID',
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_build_id` (`build_id`),
  UNIQUE KEY `uk_download_token` (`download_token`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='APK 分发记录表';
