-- ============================================================
-- 域名表扩展：HTTP/HTTPS 访问开关
-- 版本：010
-- 说明：
--   增加 force_https 字段控制 HTTP→HTTPS 跳转行为：
--     1 = 强制跳转 HTTPS（默认，原有行为）
--     0 = 同时支持 HTTP 和 HTTPS 访问（不跳转）
--   这样可以支持 IP 访问、HTTP 调试等场景
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `domains`
  ADD COLUMN `force_https` TINYINT NOT NULL DEFAULT 1 COMMENT '是否强制HTTPS跳转：0=同时支持HTTP+HTTPS 1=强制跳转HTTPS' AFTER `ssl_auto_renew`;

ALTER TABLE `domains`
  ADD KEY `idx_force_https` (`force_https`);

SET FOREIGN_KEY_CHECKS = 1;
