-- ============================================================
-- 020_user_console.sql
-- 用户端独立系统相关变更：
--   1. users 扩展 qq 字段
--   2. api_keys.user_id 关联
--   3. user_notices 公告表
--   4. user_notice_reads 公告已读表
--   5. admin_settings 新增 settings_paths / settings_security / settings_user_app
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- 1. users 扩展 qq 字段
-- ----------------------------------------------------------
SET @col_qq_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'qq');
SET @sql = IF(@col_qq_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `qq` VARCHAR(32) NULL DEFAULT NULL COMMENT ''绑定QQ号（NULL=未绑定）'' AFTER `email`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_qq_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_qq');
SET @sql = IF(@idx_qq_exists = 0,
  'ALTER TABLE `users` ADD KEY `idx_qq` (`qq`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------
-- 2. api_keys 增加 user_id 关联（NULL=管理员全局 Key，非空=用户专属 Key）
-- ----------------------------------------------------------
SET @col_uid_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'api_keys' AND column_name = 'user_id');
SET @sql = IF(@col_uid_exists = 0,
  'ALTER TABLE `api_keys` ADD COLUMN `user_id` BIGINT UNSIGNED NULL DEFAULT NULL COMMENT ''所属用户ID（NULL=全局管理员Key）'' AFTER `id`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_desc_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE table_schema = DATABASE() AND table_name = 'api_keys' AND column_name = 'description');
SET @sql = IF(@col_desc_exists = 0,
  'ALTER TABLE `api_keys` ADD COLUMN `description` VARCHAR(500) NOT NULL DEFAULT '''' COMMENT ''使用场景说明（可选）'' AFTER `status`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_uid_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = 'api_keys' AND index_name = 'idx_user_id');
SET @sql = IF(@idx_uid_exists = 0,
  'ALTER TABLE `api_keys` ADD KEY `idx_user_id` (`user_id`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ----------------------------------------------------------
-- 3. user_notices 用户公告表
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_notices` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `title`       VARCHAR(200)     NOT NULL DEFAULT ''     COMMENT '公告标题',
  `content`     MEDIUMTEXT       NULL                   COMMENT '公告内容（富文本/Markdown/HTML）',
  `type`        TINYINT          NOT NULL DEFAULT 1      COMMENT '类型：1=普通公告 2=紧急公告 3=维护公告 4=新功能',
  `level`       TINYINT          NOT NULL DEFAULT 1      COMMENT '等级：1=普通 2=重要 3=紧急',
  `show_dialog` TINYINT          NOT NULL DEFAULT 1      COMMENT '是否登录弹窗：0=否 1=是',
  `show_home`   TINYINT          NOT NULL DEFAULT 1      COMMENT '是否首页展示：0=否 1=是',
  `is_sticky`   TINYINT          NOT NULL DEFAULT 0      COMMENT '是否置顶：0=否 1=是',
  `sort`        INT              NOT NULL DEFAULT 0      COMMENT '排序（大的靠前）',
  `status`      TINYINT          NOT NULL DEFAULT 1      COMMENT '状态：0=草稿 1=已发布',
  `start_at`    DATETIME         NULL DEFAULT NULL       COMMENT '展示开始时间（NULL=立即）',
  `end_at`      DATETIME         NULL DEFAULT NULL       COMMENT '展示结束时间（NULL=永久）',
  `publish_at`  DATETIME         NULL DEFAULT NULL       COMMENT '发布时间',
  `created_by`  BIGINT UNSIGNED  NULL DEFAULT NULL       COMMENT '创建管理员ID',
  `updated_by`  BIGINT UNSIGNED  NULL DEFAULT NULL       COMMENT '更新管理员ID',
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_status_sticky_sort` (`status`, `is_sticky`, `sort` DESC),
  KEY `idx_publish_at` (`publish_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户公告表';

-- ----------------------------------------------------------
-- 4. user_notice_reads 公告已读表
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_notice_reads` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `user_id`    BIGINT UNSIGNED NOT NULL COMMENT '用户ID',
  `notice_id`  BIGINT UNSIGNED NOT NULL COMMENT '公告ID',
  `read_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '已读时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_notice` (`user_id`, `notice_id`),
  KEY `idx_notice_id` (`notice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公告已读表';

-- ----------------------------------------------------------
-- 5. admin_settings 新增配置项（INSERT IGNORE 防止重复）
-- ----------------------------------------------------------

-- settings_paths：管理端/用户端路径与 API 前缀
INSERT IGNORE INTO `admin_settings` (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES (
  'settings_paths',
  JSON_OBJECT(
    'admin_path',      '/admin/',
    'admin_api_prefix','/api/',
    'user_path',       '/user/',
    'user_api_prefix', '/user-api/'
  ),
  '管理端/用户端 访问路径与API前缀配置（修改后立即生效，无需重启）',
  NOW(), NOW()
);

-- settings_security：用户端安全配置
INSERT IGNORE INTO `admin_settings` (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES (
  'settings_security',
  JSON_OBJECT(
    'allow_register',            1,
    'password_reset_mode',       'both',
    'require_email_for_reset',   1,
    'qq_bind_enabled',           1,
    'user_self_unbind_qq',       0,
    'rate_limit_push_per_min',   20,
    'rate_limit_push_per_hour',  500,
    'rate_limit_push_per_day',   3000
  ),
  '用户端安全配置（注册开关、改密方式、QQ绑定开关、推送频率限制）',
  NOW(), NOW()
);

-- settings_user_app：用户端 APP 分发配置
INSERT IGNORE INTO `admin_settings` (`config_key`, `config_value`, `description`, `created_at`, `updated_at`)
VALUES (
  'settings_user_app',
  JSON_OBJECT(
    'apk_download_url', '',
    'ipa_download_url', '',
    'apk_version',      '',
    'ipa_version',      '',
    'update_log',       '',
    'force_update',     0,
    'user_hbx_enabled', 1
  ),
  '用户端APP下载/更新配置（含用户自建HBuilderX打包开关）',
  NOW(), NOW()
);

SET FOREIGN_KEY_CHECKS = 1;
