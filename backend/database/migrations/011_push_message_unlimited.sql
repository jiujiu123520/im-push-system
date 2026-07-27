-- 增加推送消息 title 和 content 字段长度，不再限制字数
ALTER TABLE `messages` MODIFY COLUMN `title` TEXT NOT NULL COMMENT '消息标题' AFTER `device_id`;
ALTER TABLE `messages` MODIFY COLUMN `content` MEDIUMTEXT NULL COMMENT '消息内容' AFTER `title`;

ALTER TABLE `push_logs` MODIFY COLUMN `title` TEXT NOT NULL COMMENT '推送标题' AFTER `target_value`;
ALTER TABLE `push_logs` MODIFY COLUMN `content` MEDIUMTEXT NULL COMMENT '推送内容' AFTER `title`;
