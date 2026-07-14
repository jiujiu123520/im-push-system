-- ============================================================
-- 域名表扩展：独立端口、独立绑定、SSL 自动续费
-- 版本：006
-- 说明：
--   1. 增加 listen_port：每个域名可独立监听端口（默认 80/443）
--   2. 增加 target_type：域名指向的目标类型（frontend=管理后台/backend=后端API/ws=WebSocket）
--   3. 增加 target_host：后端目标主机（默认 127.0.0.1:9501，支持 IP+端口）
--   4. 增加 ssl_auto_renew：是否自动续费（默认 1）
--   5. 增加 ssl_last_renew_at：最后续费时间
--   6. 增加前端/后端独立绑定能力
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------
-- 域名表增加字段
-- ----------------------------------------------------------
ALTER TABLE `domains`
  ADD COLUMN `listen_port`      INT          NOT NULL DEFAULT 0       COMMENT '监听端口：0=默认(80/443)，>0=指定端口' AFTER `domain`,
  ADD COLUMN `target_type`      VARCHAR(20)  NOT NULL DEFAULT 'frontend' COMMENT '目标类型：frontend=管理后台, backend=后端API, ws=WebSocket, all=全部' AFTER `listen_port`,
  ADD COLUMN `target_host`      VARCHAR(100) NOT NULL DEFAULT '127.0.0.1:9501' COMMENT '后端目标主机（仅 backend/ws 类型）' AFTER `target_type`,
  ADD COLUMN `ssl_auto_renew`   TINYINT      NOT NULL DEFAULT 1      COMMENT '是否自动续费：0=否 1=是' AFTER `ssl_expire_at`,
  ADD COLUMN `ssl_last_renew_at` DATETIME    NULL DEFAULT NULL       COMMENT '最后续费时间' AFTER `ssl_auto_renew`;

-- 更新 type 字段默认值兼容性（保留旧字段，新增 target_type）
UPDATE `domains` SET `target_type` = `type` WHERE `target_type` = 'frontend' AND `type` != 'admin';

-- 增加索引
ALTER TABLE `domains`
  ADD KEY `idx_listen_port` (`listen_port`),
  ADD KEY `idx_target_type` (`target_type`),
  ADD KEY `idx_ssl_auto_renew` (`ssl_auto_renew`);

SET FOREIGN_KEY_CHECKS = 1;
