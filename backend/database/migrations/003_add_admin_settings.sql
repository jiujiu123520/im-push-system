-- 管理后台系统设置表
-- 存储邮件配置等系统级设置（JSON 格式）
CREATE TABLE IF NOT EXISTS `admin_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `config_key` VARCHAR(128) NOT NULL UNIQUE COMMENT '配置键名',
    `config_value` TEXT COMMENT '配置值（JSON 格式）',
    `description` VARCHAR(255) DEFAULT '' COMMENT '配置描述',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理后台系统设置';

-- 插入默认邮件配置
INSERT INTO `admin_settings` (`config_key`, `config_value`, `description`)
VALUES (
    'mail_config',
    '{"enabled":false,"host":"smtp.qq.com","port":"587","username":"","password":"","encryption":"tls","sender_name":""}',
    '邮件通知配置'
) ON DUPLICATE KEY UPDATE `config_key` = `config_key`;
