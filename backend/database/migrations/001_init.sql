-- ============================================================
-- 即时消息推送系统 - 数据库初始化脚本
-- 版本：001
-- 说明：创建系统所需的全部数据表
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- 1. 用户表（前台用户）
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `username`       VARCHAR(64)      NOT NULL DEFAULT ''     COMMENT '用户名',
  `phone`          VARCHAR(20)      NOT NULL DEFAULT ''     COMMENT '手机号',
  `email`          VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT '邮箱',
  `password_hash`  VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT '密码哈希（password_hash）',
  `status`         TINYINT          NOT NULL DEFAULT 1      COMMENT '状态：0=禁用 1=正常',
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_phone` (`phone`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='前台用户表';

-- ----------------------------------------------------------
-- 2. 管理员表
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '管理员ID',
  `username`       VARCHAR(64)      NOT NULL DEFAULT ''     COMMENT '用户名',
  `password_hash`  VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT '密码哈希',
  `role`           VARCHAR(32)      NOT NULL DEFAULT 'admin' COMMENT '角色：super/admin/operator',
  `status`         TINYINT          NOT NULL DEFAULT 1      COMMENT '状态：0=禁用 1=正常',
  `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员表';

-- ----------------------------------------------------------
-- 3. 推送 Key 表（用户的推送通道密钥）
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_keys` (
  `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `key_value`    VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT '推送 Key 值',
  `name`         VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT 'Key 名称（备注）',
  `user_id`      BIGINT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '所属用户ID',
  `status`       TINYINT          NOT NULL DEFAULT 1      COMMENT '状态：0=禁用 1=正常',
  `max_devices`  INT              NOT NULL DEFAULT 10     COMMENT '允许最大设备数',
  `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key_value` (`key_value`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推送 Key 表';

-- ----------------------------------------------------------
-- 4. 设备表（绑定的客户端设备）
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `devices` (
  `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `device_id`       VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT '设备唯一标识',
  `push_key_id`     BIGINT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '关联推送 Key ID',
  `user_id`         BIGINT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '关联用户ID',
  `device_name`     VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT '设备名称',
  `device_model`    VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT '设备型号',
  `os_version`      VARCHAR(64)      NOT NULL DEFAULT ''     COMMENT '操作系统版本',
  `ip`              VARCHAR(45)      NOT NULL DEFAULT ''     COMMENT '最近IP地址',
  `ua`              VARCHAR(512)     NOT NULL DEFAULT ''     COMMENT 'User-Agent',
  `fingerprint`     VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT '设备指纹',
  `status`          TINYINT          NOT NULL DEFAULT 1      COMMENT '状态：0=离线 1=在线 2=禁用',
  `last_connect_at` DATETIME         NULL DEFAULT NULL       COMMENT '最近连接时间',
  `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device` (`device_id`, `push_key_id`),
  KEY `idx_push_key_id` (`push_key_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设备表';

-- ----------------------------------------------------------
-- 5. 消息表（推送给设备的消息）
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `message_id`  VARCHAR(64)      NOT NULL DEFAULT ''     COMMENT '消息唯一标识（由 PushDispatcher 生成）',
  `push_key_id` BIGINT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '推送 Key ID',
  `device_id`   VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT '目标设备ID',
  `title`       VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT '消息标题',
  `content`     TEXT             NULL                    COMMENT '消息内容',
  `payload`     TEXT             NULL                    COMMENT '附加数据（JSON）',
  `is_read`     TINYINT          NOT NULL DEFAULT 0      COMMENT '是否已读：0=未读 1=已读',
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_message_device` (`message_id`, `device_id`),
  KEY `idx_push_key_device` (`push_key_id`, `device_id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_is_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='消息表';

-- ----------------------------------------------------------
-- 6. 黑名单表
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blacklists` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `type`       VARCHAR(32)      NOT NULL DEFAULT ''     COMMENT '类型：ip/device/user',
  `value`      VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT '黑名单值',
  `reason`     VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT '原因',
  `admin_id`   BIGINT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '操作管理员ID',
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_value` (`type`, `value`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='黑名单表';

-- ----------------------------------------------------------
-- 7. 推送日志表
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `push_logs` (
  `id`            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `api_key_id`    BIGINT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '使用的 API Key ID',
  `target_type`   VARCHAR(32)      NOT NULL DEFAULT ''     COMMENT '目标类型：device/user/broadcast',
  `target_value`  VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT '目标值',
  `title`         VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT '推送标题',
  `content`       TEXT             NULL                    COMMENT '推送内容',
  `success_count` INT              NOT NULL DEFAULT 0      COMMENT '成功数',
  `fail_count`    INT              NOT NULL DEFAULT 0      COMMENT '失败数',
  `detail`        TEXT             NULL                    COMMENT '详细结果（JSON）',
  `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_api_key_id` (`api_key_id`),
  KEY `idx_target` (`target_type`, `target_value`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='推送日志表';

-- ----------------------------------------------------------
-- 8. 管理员操作日志表
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `admin_id`    BIGINT UNSIGNED  NOT NULL DEFAULT 0      COMMENT '操作管理员ID',
  `action`      VARCHAR(64)      NOT NULL DEFAULT ''     COMMENT '操作动作',
  `target_type` VARCHAR(32)      NOT NULL DEFAULT ''     COMMENT '目标类型',
  `target_id`   VARCHAR(64)      NOT NULL DEFAULT '0'    COMMENT '目标ID',
  `detail`      TEXT             NULL                    COMMENT '操作详情（JSON）',
  `ip`          VARCHAR(45)      NOT NULL DEFAULT ''     COMMENT '操作IP',
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员操作日志表';

-- ----------------------------------------------------------
-- 9. API Key 表（用于外部 HTTP API 调用鉴权）
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `key_value`  VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT 'API Key 值',
  `name`       VARCHAR(128)     NOT NULL DEFAULT ''     COMMENT 'Key 名称（备注）',
  `status`     TINYINT          NOT NULL DEFAULT 1      COMMENT '状态：0=禁用 1=正常',
  `expire_at`  DATETIME         NULL DEFAULT NULL       COMMENT '过期时间（NULL 永不过期）',
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key_value` (`key_value`),
  KEY `idx_status` (`status`),
  KEY `idx_expire_at` (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='API Key 表';

SET FOREIGN_KEY_CHECKS = 1;
