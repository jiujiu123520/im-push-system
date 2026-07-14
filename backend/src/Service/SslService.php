<?php
declare(strict_types=1);

namespace App\Service;

/**
 * SSL 证书管理服务
 *
 * 基于 acme.sh 自动申请 Let's Encrypt 免费证书，并生成/部署 Nginx 配置。
 *
 * 工作流：
 *   1. 检查/安装 acme.sh
 *   2. 通过 webroot 模式申请证书（不中断现有服务）
 *   3. 安装证书到 /etc/nginx/ssl/{domain}.{crt,key}
 *   4. 根据 domains 表生成 Nginx server 块
 *   5. 重载 Nginx 生效
 *
 * 所需 sudoers（见 deploy/sudoers-push-system-ssl）：
 *   www-data ALL=(ALL) NOPASSWD: /usr/bin/install -d /etc/nginx/ssl
 *   www-data ALL=(ALL) NOPASSWD: /bin/cp /tmp/*.crt /etc/nginx/ssl/*
 *   www-data ALL=(ALL) NOPASSWD: /bin/cp /tmp/*.key /etc/nginx/ssl/*
 *   www-data ALL=(ALL) NOPASSWD: /bin/chmod 644 /etc/nginx/ssl/*
 *   www-data ALL=(ALL) NOPASSWD: /bin/chown root:root /etc/nginx/ssl/*
 *   www-data ALL=(ALL) NOPASSWD: /usr/bin/tee /etc/nginx/sites-available/*
 *   www-data ALL=(ALL) NOPASSWD: /bin/ln -sf /etc/nginx/sites-available/* /etc/nginx/sites-enabled/*
 *   www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t
 *   www-data ALL=(ALL) NOPASSWD: /usr/sbin/nginx -s reload
 *   www-data ALL=(ALL) NOPASSWD: /root/.acme.sh/acme.sh *
 */
class SslService
{
    /** acme.sh 脚本路径（默认 root 安装） */
    public const ACME_SH = '/root/.acme.sh/acme.sh';

    /** 证书目录 */
    public const SSL_DIR = '/etc/nginx/ssl';

    /** ACME webroot 验证目录 */
    public const ACME_WEBROOT = '/www/push-system/acme';

    /** Nginx 站点可用配置目录 */
    public const NGINX_AVAILABLE = '/etc/nginx/sites-available';

    /** Nginx 站点启用配置目录 */
    public const NGINX_ENABLED = '/etc/nginx/sites-enabled';

    /** 管理后台静态资源目录 */
    public const ADMIN_DIST = '/www/push-system/admin/dist';

    /** 项目根目录 */
    public const PROJECT_ROOT = '/www/push-system';

    /**
     * 检查 acme.sh 是否已安装
     */
    public static function isAcmeInstalled(): bool
    {
        return file_exists(self::ACME_SH) && is_executable(self::ACME_SH);
    }

    /**
     * 检查依赖环境（acme.sh、nginx、curl）
     */
    public static function checkEnvironment(): array
    {
        $result = [
            'acme_sh'      => self::isAcmeInstalled(),
            'nginx'        => self::commandExists('nginx'),
            'curl'         => self::commandExists('curl'),
            'openssl'      => self::commandExists('openssl'),
            'ssl_dir'      => is_dir(self::SSL_DIR),
            'webroot_dir'  => is_dir(self::ACME_WEBROOT),
            'sudoers'      => self::checkSudoers(),
        ];
        $result['ready'] = $result['acme_sh'] && $result['nginx'] && $result['curl'] && $result['openssl'];
        return $result;
    }

    /**
     * 安装 acme.sh（通过部署脚本）
     */
    public static function installAcme(): array
    {
        $script = dirname(__DIR__, 2) . '/deploy/ssl/setup-acme.sh';
        if (!file_exists($script)) {
            return ['success' => false, 'message' => '安装脚本不存在：' . $script];
        }
        $cmd = sprintf('sudo bash %s 2>&1', escapeshellarg($script));
        $output = shell_exec($cmd);
        return [
            'success' => self::isAcmeInstalled(),
            'output'  => $output,
            'message' => self::isAcmeInstalled() ? 'acme.sh 安装成功' : 'acme.sh 安装失败',
        ];
    }

    /**
     * 申请 SSL 证书（webroot 模式，不中断服务）
     *
     * @param string $domain 域名
     * @return array {success, message, expire_at, cert_path, key_path}
     */
    public static function issueCertificate(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return ['success' => false, 'message' => '域名为空'];
        }

        if (!self::isAcmeInstalled()) {
            return ['success' => false, 'message' => 'acme.sh 未安装，请先执行环境初始化'];
        }

        // 确保 webroot 目录存在
        if (!is_dir(self::ACME_WEBROOT)) {
            shell_exec(sprintf('sudo mkdir -p %s 2>&1', escapeshellarg(self::ACME_WEBROOT)));
            shell_exec(sprintf('sudo chown www-data:www-data %s 2>&1', escapeshellarg(self::ACME_WEBROOT)));
        }

        // 证书路径
        $certFile = self::SSL_DIR . '/' . $domain . '.crt';
        $keyFile  = self::SSL_DIR . '/' . $domain . '.key';

        // 确保证书目录存在
        shell_exec(sprintf('sudo mkdir -p %s 2>&1', escapeshellarg(self::SSL_DIR)));

        // 使用 acme.sh 申请证书（webroot 模式）
        $cmd = sprintf(
            'sudo %s --issue -d %s -w %s --keylength ec-256 2>&1',
            escapeshellarg(self::ACME_SH),
            escapeshellarg($domain),
            escapeshellarg(self::ACME_WEBROOT)
        );
        $output = shell_exec($cmd);

        // 检查是否申请成功（acme.sh 成功后会输出 "Cert success!"）
        if (strpos($output, 'Cert success') === false && strpos($output, 'Domains change') === false && strpos($output, 'Skipping') === false) {
            return [
                'success' => false,
                'message' => '证书申请失败',
                'output'  => $output,
            ];
        }

        // 安装证书到 Nginx 目录
        $installCmd = sprintf(
            'sudo %s --install-cert -d %s --ecc --key-file %s --fullchain-file %s --reloadcmd "sudo nginx -s reload" 2>&1',
            escapeshellarg(self::ACME_SH),
            escapeshellarg($domain),
            escapeshellarg($keyFile),
            escapeshellarg($certFile)
        );
        $installOutput = shell_exec($installCmd);

        // 验证证书文件存在
        if (!file_exists($certFile) || !file_exists($keyFile)) {
            return [
                'success' => false,
                'message' => '证书文件生成失败',
                'output'  => $installOutput,
            ];
        }

        // 设置权限
        shell_exec(sprintf('sudo chmod 644 %s 2>&1', escapeshellarg($certFile)));
        shell_exec(sprintf('sudo chmod 600 %s 2>&1', escapeshellarg($keyFile)));

        // 获取过期时间
        $expireAt = self::getCertExpire($certFile);

        return [
            'success'     => true,
            'message'     => '证书申请成功',
            'expire_at'   => $expireAt,
            'cert_path'   => $certFile,
            'key_path'    => $keyFile,
            'output'      => $output . "\n" . $installOutput,
        ];
    }

    /**
     * 获取证书过期时间
     */
    public static function getCertExpire(string $certPath): ?string
    {
        if (!file_exists($certPath)) {
            return null;
        }
        $cmd = sprintf('sudo openssl x509 -in %s -noout -enddate 2>/dev/null', escapeshellarg($certPath));
        $output = shell_exec($cmd);
        if ($output && preg_match('/notAfter=(.+)/', $output, $m)) {
            $dateStr = trim($m[1]);
            $ts = strtotime($dateStr);
            if ($ts !== false) {
                return date('Y-m-d H:i:s', $ts);
            }
        }
        return null;
    }

    /**
     * 检查证书是否有效（存在且未过期）
     */
    public static function checkCertificate(string $domain): array
    {
        $certFile = self::SSL_DIR . '/' . $domain . '.crt';
        $keyFile  = self::SSL_DIR . '/' . $domain . '.key';

        if (!file_exists($certFile) || !file_exists($keyFile)) {
            return ['valid' => false, 'reason' => '证书文件不存在'];
        }

        $expireAt = self::getCertExpire($certFile);
        if ($expireAt === null) {
            return ['valid' => false, 'reason' => '无法读取证书过期时间'];
        }

        $expireTs = strtotime($expireAt);
        if ($expireTs === false) {
            return ['valid' => false, 'reason' => '过期时间格式错误'];
        }

        if ($expireTs < time()) {
            return ['valid' => false, 'reason' => '证书已过期', 'expire_at' => $expireAt];
        }

        // 30天内到期标记为即将过期
        $daysLeft = (int)(($expireTs - time()) / 86400);
        return [
            'valid'      => true,
            'expire_at'  => $expireAt,
            'days_left'  => $daysLeft,
            'cert_path'  => $certFile,
            'key_path'   => $keyFile,
        ];
    }

    /**
     * 生成 Nginx 配置文件
     *
     * @param array $domains domains 表记录数组
     * @return array {success, message, config, conf_path}
     */
    public static function generateNginxConfig(array $domains): array
    {
        if (empty($domains)) {
            return ['success' => false, 'message' => '没有绑定的域名'];
        }

        $config = self::buildNginxConfig($domains);
        $confPath = self::NGINX_AVAILABLE . '/push-system.conf';

        // 通过 sudo tee 写入文件
        $cmd = sprintf("sudo tee %s > /dev/null 2>&1 <<'PUSH_NGINX_EOF'\n%s\nPUSH_NGINX_EOF", escapeshellarg($confPath), $config);
        shell_exec($cmd);

        // 创建软链到 sites-enabled
        $link = self::NGINX_ENABLED . '/push-system.conf';
        shell_exec(sprintf('sudo ln -sf %s %s 2>&1', escapeshellarg($confPath), escapeshellarg($link)));

        return [
            'success'   => true,
            'message'   => 'Nginx 配置生成成功',
            'config'    => $config,
            'conf_path' => $confPath,
        ];
    }

    /**
     * 构建 Nginx 配置内容
     */
    public static function buildNginxConfig(array $domains): string
    {
        $lines = [];
        $lines[] = '# ============================================================';
        $lines[] = '# Push System Nginx Config (Auto-generated by SslService)';
        $lines[] = '# 生成时间：' . date('Y-m-d H:i:s');
        $lines[] = '# ============================================================';
        $lines[] = '';
        $lines[] = 'upstream push_http {';
        $lines[] = '    server 127.0.0.1:9501;';
        $lines[] = '    keepalive 32;';
        $lines[] = '}';
        $lines[] = '';
        $lines[] = 'upstream push_websocket {';
        $lines[] = '    server 127.0.0.1:9502;';
        $lines[] = '    keepalive 64;';
        $lines[] = '}';
        $lines[] = '';

        // 收集所有域名
        $serverNames = [];
        $sslDomains = [];
        foreach ($domains as $row) {
            if ((int)$row['status'] !== 1) {
                continue;
            }
            $serverNames[] = $row['domain'];
            // ssl_status 字符串：none/pending/issued/failed/expired
            if ((int)$row['ssl_enabled'] === 1 && ($row['ssl_status'] ?? '') === 'issued') {
                $sslDomains[] = $row;
            }
        }

        $allDomains = implode(' ', $serverNames);

        // 是否启用 SSL
        $hasSsl = !empty($sslDomains);

        if ($hasSsl) {
            // HTTPS server (443)
            $lines[] = '# ----------------------------------------------------------';
            $lines[] = '# HTTPS (443)';
            $lines[] = '# ----------------------------------------------------------';
            $lines[] = 'server {';
            $lines[] = '    listen 443 ssl http2;';
            $lines[] = '    listen [::]:443 ssl http2;';
            $lines[] = '    server_name ' . $allDomains . ';';
            $lines[] = '    client_max_body_size 50m;';
            $lines[] = '';
            // 使用第一个 SSL 域名的证书
            $primarySsl = $sslDomains[0];
            $lines[] = '    # SSL 证书';
            $lines[] = '    ssl_certificate     ' . $primarySsl['ssl_cert_path'] . ';';
            $lines[] = '    ssl_certificate_key ' . $primarySsl['ssl_key_path'] . ';';
            $lines[] = '    ssl_protocols TLSv1.2 TLSv1.3;';
            $lines[] = '    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;';
            $lines[] = '    ssl_prefer_server_ciphers on;';
            $lines[] = '    ssl_session_cache shared:SSL:10m;';
            $lines[] = '    ssl_session_timeout 10m;';
            $lines[] = '';
            $lines[] = '    # HSTS';
            $lines[] = '    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;';
            $lines[] = '';
            $lines[] = '    access_log /var/log/nginx/push_access.log;';
            $lines[] = '    error_log  /var/log/nginx/push_error.log;';
            $lines[] = '';

            // ACME 验证路径（webroot）
            $lines[] = '    # ACME challenge 验证路径';
            $lines[] = '    location /.well-known/acme-challenge/ {';
            $lines[] = '        root ' . self::ACME_WEBROOT . ';';
            $lines[] = '    }';
            $lines[] = '';

            // 公共 location 块
            $lines = array_merge($lines, self::buildCommonLocations());
            $lines[] = '}';
            $lines[] = '';

            // HTTP (80) → 301 跳转到 HTTPS
            $lines[] = '# ----------------------------------------------------------';
            $lines[] = '# HTTP (80) → HTTPS 跳转（保留 ACME challenge 直通）';
            $lines[] = '# ----------------------------------------------------------';
            $lines[] = 'server {';
            $lines[] = '    listen 80;';
            $lines[] = '    listen [::]:80;';
            $lines[] = '    server_name ' . $allDomains . ';';
            $lines[] = '';
            $lines[] = '    # ACME challenge 不跳转，直通 webroot';
            $lines[] = '    location /.well-known/acme-challenge/ {';
            $lines[] = '        root ' . self::ACME_WEBROOT . ';';
            $lines[] = '    }';
            $lines[] = '';
            $lines[] = '    location / {';
            $lines[] = '        return 301 https://$host$request_uri;';
            $lines[] = '    }';
            $lines[] = '}';
        } else {
            // 仅 HTTP (80)
            $lines[] = '# ----------------------------------------------------------';
            $lines[] = '# HTTP (80) - 未启用 SSL';
            $lines[] = '# ----------------------------------------------------------';
            $lines[] = 'server {';
            $lines[] = '    listen 80;';
            $lines[] = '    listen [::]:80;';
            $lines[] = '    server_name ' . $allDomains . ';';
            $lines[] = '    client_max_body_size 50m;';
            $lines[] = '';
            $lines[] = '    access_log /var/log/nginx/push_access.log;';
            $lines[] = '    error_log  /var/log/nginx/push_error.log;';
            $lines[] = '';
            // ACME 验证路径
            $lines[] = '    # ACME challenge 验证路径';
            $lines[] = '    location /.well-known/acme-challenge/ {';
            $lines[] = '        root ' . self::ACME_WEBROOT . ';';
            $lines[] = '    }';
            $lines[] = '';
            $lines = array_merge($lines, self::buildCommonLocations());
            $lines[] = '}';
        }

        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * 公共 location 块（HTTP API、WebSocket、静态文件等）
     */
    private static function buildCommonLocations(): array
    {
        return [
            '',
            '    # ----------------------------------------------------------',
            '    # WebSocket 推送服务代理',
            '    # ----------------------------------------------------------',
            '    location /ws {',
            '        proxy_pass http://push_websocket;',
            '        proxy_http_version 1.1;',
            '        proxy_set_header Upgrade $http_upgrade;',
            '        proxy_set_header Connection "upgrade";',
            '        proxy_set_header Host $host;',
            '        proxy_set_header X-Real-IP $remote_addr;',
            '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            '        proxy_set_header X-Forwarded-Proto $scheme;',
            '        proxy_read_timeout 3600s;',
            '        proxy_send_timeout 3600s;',
            '        proxy_buffering off;',
            '    }',
            '',
            '    # ----------------------------------------------------------',
            '    # 管理后台静态文件',
            '    # ----------------------------------------------------------',
            '    location / {',
            '        root ' . self::ADMIN_DIST . ';',
            '        index index.html;',
            '        try_files $uri $uri/ /index.html;',
            '        location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {',
            '            expires 30d;',
            '            add_header Cache-Control "public, immutable";',
            '        }',
            '    }',
            '',
            '    # ----------------------------------------------------------',
            '    # /api/admin/ 前端管理后台请求（rewrite 去掉 /api 前缀）',
            '    # ----------------------------------------------------------',
            '    location /api/admin/ {',
            '        rewrite ^/api/(.*)$ /$1 break;',
            '        proxy_pass http://push_http;',
            '        proxy_http_version 1.1;',
            '        proxy_set_header Host $host;',
            '        proxy_set_header X-Real-IP $remote_addr;',
            '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            '        proxy_set_header X-Forwarded-Proto $scheme;',
            '        proxy_read_timeout 60s;',
            '        proxy_send_timeout 60s;',
            '        proxy_buffering on;',
            '        proxy_buffer_size 128k;',
            '        proxy_buffers 4 256k;',
            '    }',
            '',
            '    # ----------------------------------------------------------',
            '    # /api/ 开放接口（不 rewrite）',
            '    # ----------------------------------------------------------',
            '    location /api/ {',
            '        proxy_pass http://push_http;',
            '        proxy_http_version 1.1;',
            '        proxy_set_header Host $host;',
            '        proxy_set_header X-Real-IP $remote_addr;',
            '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            '        proxy_set_header X-Forwarded-Proto $scheme;',
            '        proxy_read_timeout 60s;',
            '        proxy_send_timeout 60s;',
            '        proxy_buffering on;',
            '        proxy_buffer_size 128k;',
            '        proxy_buffers 4 256k;',
            '    }',
            '',
            '    # ----------------------------------------------------------',
            '    # /admin /auth /captcha /health 直接代理',
            '    # ----------------------------------------------------------',
            '    location ~ ^/(admin|auth|captcha|health) {',
            '        proxy_pass http://push_http;',
            '        proxy_http_version 1.1;',
            '        proxy_set_header Host $host;',
            '        proxy_set_header X-Real-IP $remote_addr;',
            '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            '        proxy_set_header X-Forwarded-Proto $scheme;',
            '        proxy_read_timeout 60s;',
            '        proxy_send_timeout 60s;',
            '        proxy_buffering on;',
            '        proxy_buffer_size 128k;',
            '        proxy_buffers 4 256k;',
            '    }',
            '',
            '    # ----------------------------------------------------------',
            '    # APK 大文件下载',
            '    # ----------------------------------------------------------',
            '    location /admin/app-build/download/ {',
            '        proxy_pass http://push_http;',
            '        proxy_http_version 1.1;',
            '        proxy_set_header Host $host;',
            '        proxy_set_header X-Real-IP $remote_addr;',
            '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;',
            '        proxy_buffering off;',
            '        proxy_read_timeout 300s;',
            '        proxy_send_timeout 300s;',
            '    }',
            '',
            '    # ----------------------------------------------------------',
            '    # 安全：隐藏敏感文件',
            '    # ----------------------------------------------------------',
            '    location ~ /\.(git|env|htaccess) {',
            '        deny all;',
            '        return 404;',
            '    }',
        ];
    }

    /**
     * 测试并重载 Nginx 配置
     */
    public static function reloadNginx(): array
    {
        // 先测试配置
        $test = shell_exec('sudo nginx -t 2>&1');
        if (strpos($test, 'successful') === false) {
            return [
                'success' => false,
                'message' => 'Nginx 配置测试失败',
                'output'  => $test,
            ];
        }
        // 重载
        $output = shell_exec('sudo nginx -s reload 2>&1');
        return [
            'success' => true,
            'message' => 'Nginx 已重载',
            'output'  => $test . "\n" . $output,
        ];
    }

    /**
     * 删除域名证书
     */
    public static function removeCertificate(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return ['success' => false, 'message' => '域名为空'];
        }

        $certFile = self::SSL_DIR . '/' . $domain . '.crt';
        $keyFile  = self::SSL_DIR . '/' . $domain . '.key';

        // 使用 acme.sh 移除
        if (self::isAcmeInstalled()) {
            shell_exec(sprintf('sudo %s --remove -d %s --ecc 2>&1', escapeshellarg(self::ACME_SH), escapeshellarg($domain)));
        }

        // 删除证书文件
        if (file_exists($certFile)) {
            shell_exec(sprintf('sudo rm -f %s 2>&1', escapeshellarg($certFile)));
        }
        if (file_exists($keyFile)) {
            shell_exec(sprintf('sudo rm -f %s 2>&1', escapeshellarg($keyFile)));
        }

        return ['success' => true, 'message' => '证书已删除'];
    }

    /**
     * 检查 sudoers 是否配置
     */
    private static function checkSudoers(): bool
    {
        $output = shell_exec('sudo -n nginx -t 2>&1');
        return $output !== null && strpos($output, 'not allowed to run') === false;
    }

    /**
     * 命令是否存在
     */
    private static function commandExists(string $cmd): bool
    {
        $check = shell_exec(sprintf('which %s 2>/dev/null', escapeshellarg($cmd)));
        return $check !== null && trim($check) !== '';
    }
}
