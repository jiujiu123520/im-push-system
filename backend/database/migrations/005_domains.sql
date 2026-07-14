-- ============================================================
-- 域名绑定与 SSL 证书管理
-- 版本：005
-- 说明：记录系统绑定的域名、SSL 证书申请状态、Nginx 部署状态
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- 域名绑定表
-- ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `domains` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `domain`         VARCHAR(255)     NOT NULL                COMMENT '域名（不含端口）',
  `type`           VARCHAR(20)      NOT NULL DEFAULT 'admin' COMMENT '用途：admin=管理后台, api=开放API, ws=WebSocket',
  `ssl_enabled`    TINYINT          NOT NULL DEFAULT 0      COMMENT 'SSL是否启用：0=否 1=是',
  `ssl_status`     VARCHAR(20)      NOT NULL DEFAULT 'none' COMMENT 'SSL状态：none/pending/issued/failed/expired',
  `ssl_expire_at`  DATETIME         NULL DEFAULT NULL       COMMENT 'SSL证书过期时间',
  `ssl_cert_path`  VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT 'SSL证书文件路径',
  `ssl_key_path`   VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT 'SSL私钥文件路径',
  `ssl_error`      VARCHAR(500)     NOT NULL DEFAULT ''     COMMENT 'SSL申请错误信息',
  `nginx_deployed` TINYINT          NOT NULL DEFAULT 0      COMMENT 'Nginx配置是否已部署：0=否 1=是',
  `is_primary`     TINYINT          NOT NULL DEFAULT 0      COMMENT '是否主域名：0=否 1=是',
  `status`         TINYINT          NOT NULL DEFAULT 1      COMMENT '状态：0=禁用 1=启用',
  `remark`         VARCHAR(255)     NOT NULL DEFAULT ''     COMMENT '备注',
  `created_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at`     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain` (`domain`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_primary` (`is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='域名绑定与SSL证书管理';

SET FOREIGN_KEY_CHECKS = 1;
