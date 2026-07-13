-- 管理员登录日志表
CREATE TABLE IF NOT EXISTS `admin_login_logs` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT COMMENT '日志ID',
  `admin_id`    BIGINT UNSIGNED  NOT NULL DEFAULT 0     COMMENT '管理员ID',
  `username`   VARCHAR(64)      NOT NULL DEFAULT ''    COMMENT '登录用户名',
  `ip`         VARCHAR(45)      NOT NULL DEFAULT ''    COMMENT '登录IP',
  `user_agent` VARCHAR(512)    NOT NULL DEFAULT ''    COMMENT 'User-Agent',
  `device`     VARCHAR(128)    NOT NULL DEFAULT ''    COMMENT '设备类型（PC/Mobile/Tablet）',
  `browser`    VARCHAR(128)    NOT NULL DEFAULT ''    COMMENT '浏览器',
  `os`         VARCHAR(128)    NOT NULL DEFAULT ''    COMMENT '操作系统',
  `status`     TINYINT          NOT NULL DEFAULT 1    COMMENT '登录状态：1=成功 0=失败',
  `message`    VARCHAR(255)    NOT NULL DEFAULT ''    COMMENT '登录消息',
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '登录时间',
  PRIMARY KEY (`id`),
  KEY `idx_admin_id` (`admin_id`),
  KEY `idx_ip` (`ip`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='管理员登录日志表';
