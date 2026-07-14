<?php
declare(strict_types=1);

namespace App\Service;

/**
 * SSL 证书管理与 Nginx 配置生成服务
 *
 * 设计要点：
 *   - 每个域名独立一个 Nginx server 块，可独立监听端口（前端/后端分离）
 *   - ACME challenge 验证路径在每个 server 块中独立配置（80 端口不冲突）
 *   - 支持 frontend / backend / ws / all 四种目标类型
 *   - 支持 IP+端口 直连（target_host 配置项）
 *   - 自动续费基于 acme.sh 的 cronjob + 后台触发 API
 */
class SslService
{
    public const ACME_SH = '/root/.acme.sh/acme.sh';
    public const SSL_DIR = '/etc/nginx/ssl';
    public const ACME_WEBROOT = '/www/push-system/acme';
    public const NGINX_AVAILABLE = '/etc/nginx/sites-available';
    public const NGINX_ENABLED = '/etc/nginx/sites-enabled';
    public const ADMIN_DIST = '/www/push-system/admin/dist';
    public const PROJECT_ROOT = '/www/push-system';

    /** 默认后端地址 */
    public const DEFAULT_BACKEND = '127.0.0.1:9501';
    /** 默认 WebSocket 地址 */
    public const DEFAULT_WS = '127.0.0.1:9502';

    /**
     * 检查 acme.sh 是否已安装
     */
    public static function isAcmeInstalled(): bool
    {
        return file_exists(self::ACME_SH);
    }

    /**
     * 检查依赖环境
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
     * 安装 acme.sh
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
     * 申请 SSL 证书（webroot 模式）
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

        if (!is_dir(self::ACME_WEBROOT)) {
            shell_exec(sprintf('sudo mkdir -p %s 2>&1', escapeshellarg(self::ACME_WEBROOT)));
            shell_exec(sprintf('sudo chown www-data:www-data %s 2>&1', escapeshellarg(self::ACME_WEBROOT)));
        }

        $certFile = self::SSL_DIR . '/' . $domain . '.crt';
        $keyFile  = self::SSL_DIR . '/' . $domain . '.key';

        shell_exec(sprintf('sudo mkdir -p %s 2>&1', escapeshellarg(self::SSL_DIR)));

        $cmd = sprintf(
            'sudo %s --issue -d %s -w %s --keylength ec-256 2>&1',
            escapeshellarg(self::ACME_SH),
            escapeshellarg($domain),
            escapeshellarg(self::ACME_WEBROOT)
        );
        $output = shell_exec($cmd);

        if (strpos($output, 'Cert success') === false
            && strpos($output, 'Domains change') === false
            && strpos($output, 'Skipping') === false
            && strpos($output, 'Skip') === false) {
            return ['success' => false, 'message' => '证书申请失败', 'output' => $output];
        }

        $installCmd = sprintf(
            'sudo %s --install-cert -d %s --ecc --key-file %s --fullchain-file %s --reloadcmd "sudo nginx -s reload" 2>&1',
            escapeshellarg(self::ACME_SH),
            escapeshellarg($domain),
            escapeshellarg($keyFile),
            escapeshellarg($certFile)
        );
        $installOutput = shell_exec($installCmd);

        if (!file_exists($certFile) || !file_exists($keyFile)) {
            return ['success' => false, 'message' => '证书文件生成失败', 'output' => $installOutput];
        }

        shell_exec(sprintf('sudo chmod 644 %s 2>&1', escapeshellarg($certFile)));
        shell_exec(sprintf('sudo chmod 600 %s 2>&1', escapeshellarg($keyFile)));

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
     * 续费 SSL 证书（acme.sh --renew）
     */
    public static function renewCertificate(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return ['success' => false, 'message' => '域名为空'];
        }

        if (!self::isAcmeInstalled()) {
            return ['success' => false, 'message' => 'acme.sh 未安装'];
        }

        // 强制续费（即使未到期也会重新签发）
        $cmd = sprintf(
            'sudo %s --renew -d %s --ecc --force 2>&1',
            escapeshellarg(self::ACME_SH),
            escapeshellarg($domain)
        );
        $output = shell_exec($cmd);

        // 重新安装证书
        $certFile = self::SSL_DIR . '/' . $domain . '.crt';
        $keyFile  = self::SSL_DIR . '/' . $domain . '.key';

        if (file_exists($certFile)) {
            $installCmd = sprintf(
                'sudo %s --install-cert -d %s --ecc --key-file %s --fullchain-file %s --reloadcmd "sudo nginx -s reload" 2>&1',
                escapeshellarg(self::ACME_SH),
                escapeshellarg($domain),
                escapeshellarg($keyFile),
                escapeshellarg($certFile)
            );
            $installOutput = shell_exec($installCmd);
            $output .= "\n" . $installOutput;
        }

        $expireAt = self::getCertExpire($certFile);

        if (strpos($output, 'Renew success') !== false || strpos($output, 'Skip') !== false || $expireAt !== null) {
            return [
                'success'   => true,
                'message'   => '证书续费成功',
                'expire_at' => $expireAt,
                'output'    => $output,
            ];
        }

        return ['success' => false, 'message' => '证书续费失败', 'output' => $output];
    }

    /**
     * 批量续费所有即将过期的证书（30天内）
     */
    public static function renewAllExpiring(): array
    {
        $pdo = \App\Service\Database::pdo();
        $stmt = $pdo->query(
            "SELECT id, domain FROM domains
             WHERE ssl_enabled = 1 AND ssl_status = 'issued' AND ssl_auto_renew = 1 AND status = 1"
        );
        $domains = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $renewed = [];
        $failed = [];

        foreach ($domains as $row) {
            $certInfo = self::checkCertificate($row['domain']);
            // 30天内到期或无法验证则续费
            $shouldRenew = false;
            if (!$certInfo['valid']) {
                $shouldRenew = true;
            } elseif (isset($certInfo['days_left']) && $certInfo['days_left'] < 30) {
                $shouldRenew = true;
            }

            if ($shouldRenew) {
                $result = self::renewCertificate($row['domain']);
                if ($result['success']) {
                    $renewed[] = $row['domain'];
                    // 更新数据库
                    Database::execute(
                        'UPDATE domains SET ssl_expire_at = ?, ssl_last_renew_at = NOW(), ssl_status = "issued", ssl_error = "" WHERE id = ?',
                        [$result['expire_at'], $row['id']]
                    );
                } else {
                    $failed[] = ['domain' => $row['domain'], 'error' => $result['message']];
                }
            }
        }

        return [
            'success'     => true,
            'message'     => sprintf('续费完成：成功 %d 个，失败 %d 个', count($renewed), count($failed)),
            'renewed'     => $renewed,
            'failed'      => $failed,
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
     * 检查证书有效性
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
     * 生成 Nginx 配置（多 server 块，每个域名独立）
     */
    public static function generateNginxConfig(array $domains): array
    {
        if (empty($domains)) {
            return ['success' => false, 'message' => '没有绑定的域名'];
        }

        $config = self::buildNginxConfig($domains);
        $confPath = self::NGINX_AVAILABLE . '/push-system.conf';

        $cmd = sprintf("sudo tee %s > /dev/null 2>&1 <<'PUSH_NGINX_EOF'\n%s\nPUSH_NGINX_EOF", escapeshellarg($confPath), $config);
        shell_exec($cmd);

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
     * 构建 Nginx 配置内容（多 server 块）
     *
     * 设计：
     *   - 每个域名独立一个 server 块
     *   - listen_port=0 时：SSL 用 443，非 SSL 用 80
     *   - listen_port>0 时：直接 listen 该端口
     *   - 每个块都配置 ACME challenge 路径，互不冲突
     *   - target_type 决定 location 配置：
     *     - frontend: 仅静态文件 + /api/admin/ 代理
     *     - backend:  仅 API 代理（/admin /api /auth /captcha /health）
     *     - ws:       仅 WebSocket 代理
     *     - all:      全部（前端+后端+ws）
     */
    public static function buildNginxConfig(array $domains): string
    {
        $lines = [];
        $lines[] = '# ============================================================';
        $lines[] = '# Push System Nginx Config (Auto-generated)';
        $lines[] = '# 生成时间：' . date('Y-m-d H:i:s');
        $lines[] = '# 设计：每域名独立 server 块，支持独立端口与目标类型';
        $lines[] = '# ============================================================';
        $lines[] = '';

        // upstream 定义（仅一次）
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

        // 为每个域名生成独立 server 块
        foreach ($domains as $row) {
            if ((int)$row['status'] !== 1) {
                continue;
            }
            $lines = array_merge($lines, self::buildDomainServerBlock($row));
            $lines[] = '';
        }

        // 默认 ACME challenge server（80 端口，兜底所有未匹配域名）
        $lines[] = '# ----------------------------------------------------------';
        $lines[] = '# 默认 ACME challenge 兜底（确保 80 端口可验证证书）';
        $lines[] = '# ----------------------------------------------------------';
        $lines[] = 'server {';
        $lines[] = '    listen 80 default_server;';
        $lines[] = '    listen [::]:80 default_server;';
        $lines[] = '    server_name _;';
        $lines[] = '    location /.well-known/acme-challenge/ {';
        $lines[] = '        root ' . self::ACME_WEBROOT . ';';
        $lines[] = '    }';
        $lines[] = '    location / {';
        $lines[] = '        return 444;';
        $lines[] = '    }';
        $lines[] = '}';

        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * 构建单个域名的 server 块
     */
    private static function buildDomainServerBlock(array $row): array
    {
        $domain = $row['domain'];
        $listenPort = (int)($row['listen_port'] ?? 0);
        $targetType = $row['target_type'] ?? 'all';
        $sslEnabled = (int)$row['ssl_enabled'] === 1 && ($row['ssl_status'] ?? '') === 'issued';
        $targetHost = $row['target_host'] ?? self::DEFAULT_BACKEND;

        $lines = [];
        $lines[] = '# ----------------------------------------------------------';
        $lines[] = '# ' . $domain . ' (' . $targetType . ')' . ($sslEnabled ? ' [SSL]' : '');
        $lines[] = '# ----------------------------------------------------------';

        // 计算监听端口
        if ($listenPort > 0) {
            // 指定端口模式：只 listen 指定端口
            $httpPort = $listenPort;
            $httpsPort = $listenPort;
        } else {
            // 默认端口模式：HTTP=80，HTTPS=443
            $httpPort = 80;
            $httpsPort = 443;
        }

        if ($sslEnabled) {
            // HTTPS server
            $lines[] = 'server {';
            $lines[] = '    listen ' . $httpsPort . ' ssl http2;';
            $lines[] = '    listen [::]:' . $httpsPort . ' ssl http2;';
            $lines[] = '    server_name ' . $domain . ';';
            $lines[] = '    client_max_body_size 50m;';
            $lines[] = '';

            $certPath = $row['ssl_cert_path'] ?? (self::SSL_DIR . '/' . $domain . '.crt');
            $keyPath  = $row['ssl_key_path'] ?? (self::SSL_DIR . '/' . $domain . '.key');
            $lines[] = '    ssl_certificate     ' . $certPath . ';';
            $lines[] = '    ssl_certificate_key ' . $keyPath . ';';
            $lines[] = '    ssl_protocols TLSv1.2 TLSv1.3;';
            $lines[] = '    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;';
            $lines[] = '    ssl_prefer_server_ciphers on;';
            $lines[] = '    ssl_session_cache shared:SSL:10m;';
            $lines[] = '    ssl_session_timeout 10m;';
            $lines[] = '';
            $lines[] = '    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;';
            $lines[] = '';
            $lines[] = '    access_log /var/log/nginx/push_' . $domain . '_access.log;';
            $lines[] = '    error_log  /var/log/nginx/push_' . $domain . '_error.log;';
            $lines[] = '';

            // ACME challenge（续费用）
            $lines[] = '    location /.well-known/acme-challenge/ {';
            $lines[] = '        root ' . self::ACME_WEBROOT . ';';
            $lines[] = '    }';
            $lines[] = '';

            // 根据 target_type 添加 location
            $lines = array_merge($lines, self::buildLocationsByType($targetType, $targetHost));
            $lines[] = '}';

            // HTTP 80 → HTTPS 跳转（仅当未指定独立端口时）
            if ($listenPort === 0) {
                $lines[] = '';
                $lines[] = 'server {';
                $lines[] = '    listen 80;';
                $lines[] = '    listen [::]:80;';
                $lines[] = '    server_name ' . $domain . ';';
                $lines[] = '    location /.well-known/acme-challenge/ {';
                $lines[] = '        root ' . self::ACME_WEBROOT . ';';
                $lines[] = '    }';
                $lines[] = '    location / {';
                $lines[] = '        return 301 https://$host$request_uri;';
                $lines[] = '    }';
                $lines[] = '}';
            }
        } else {
            // 仅 HTTP server
            $lines[] = 'server {';
            $lines[] = '    listen ' . $httpPort . ';';
            $lines[] = '    listen [::]:' . $httpPort . ';';
            $lines[] = '    server_name ' . $domain . ';';
            $lines[] = '    client_max_body_size 50m;';
            $lines[] = '';
            $lines[] = '    access_log /var/log/nginx/push_' . $domain . '_access.log;';
            $lines[] = '    error_log  /var/log/nginx/push_' . $domain . '_error.log;';
            $lines[] = '';

            // ACME challenge（首次申请用）
            $lines[] = '    location /.well-known/acme-challenge/ {';
            $lines[] = '        root ' . self::ACME_WEBROOT . ';';
            $lines[] = '    }';
            $lines[] = '';

            $lines = array_merge($lines, self::buildLocationsByType($targetType, $targetHost));
            $lines[] = '}';
        }

        return $lines;
    }

    /**
     * 根据 target_type 构建 location 块
     */
    private static function buildLocationsByType(string $targetType, string $targetHost): array
    {
        $lines = [];
        $targetType = $targetType ?: 'all';

        // 自定义后端目标（支持 IP+端口）
        $backendUpstream = ($targetHost && $targetHost !== self::DEFAULT_BACKEND)
            ? $targetHost
            : 'http://push_http';
        $wsUpstream = ($targetHost && $targetHost !== self::DEFAULT_BACKEND)
            ? str_replace('9501', '9502', $targetHost)
            : 'http://push_websocket';

        if ($targetType === 'frontend' || $targetType === 'all') {
            $lines[] = '    # ===== 管理后台静态文件 =====';
            $lines[] = '    location / {';
            $lines[] = '        root ' . self::ADMIN_DIST . ';';
            $lines[] = '        index index.html;';
            $lines[] = '        try_files $uri $uri/ /index.html;';
            $lines[] = '        location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {';
            $lines[] = '            expires 30d;';
            $lines[] = '            add_header Cache-Control "public, immutable";';
            $lines[] = '        }';
            $lines[] = '    }';
            $lines[] = '';
        }

        if ($targetType === 'backend' || $targetType === 'frontend' || $targetType === 'all') {
            $lines[] = '    # ===== /api/admin/ 前端管理后台请求 =====';
            $lines[] = '    location /api/admin/ {';
            $lines[] = '        rewrite ^/api/(.*)$ /$1 break;';
            $lines[] = '        proxy_pass ' . $backendUpstream . ';';
            $lines[] = '        proxy_http_version 1.1;';
            $lines[] = '        proxy_set_header Host $host;';
            $lines[] = '        proxy_set_header X-Real-IP $remote_addr;';
            $lines[] = '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
            $lines[] = '        proxy_set_header X-Forwarded-Proto $scheme;';
            $lines[] = '        proxy_read_timeout 60s;';
            $lines[] = '        proxy_send_timeout 60s;';
            $lines[] = '        proxy_buffering on;';
            $lines[] = '        proxy_buffer_size 128k;';
            $lines[] = '        proxy_buffers 4 256k;';
            $lines[] = '    }';
            $lines[] = '';
        }

        if ($targetType === 'backend' || $targetType === 'all') {
            $lines[] = '    # ===== /api/ 开放接口 =====';
            $lines[] = '    location /api/ {';
            $lines[] = '        proxy_pass ' . $backendUpstream . ';';
            $lines[] = '        proxy_http_version 1.1;';
            $lines[] = '        proxy_set_header Host $host;';
            $lines[] = '        proxy_set_header X-Real-IP $remote_addr;';
            $lines[] = '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
            $lines[] = '        proxy_set_header X-Forwarded-Proto $scheme;';
            $lines[] = '        proxy_read_timeout 60s;';
            $lines[] = '        proxy_send_timeout 60s;';
            $lines[] = '        proxy_buffering on;';
            $lines[] = '        proxy_buffer_size 128k;';
            $lines[] = '        proxy_buffers 4 256k;';
            $lines[] = '    }';
            $lines[] = '';
            $lines[] = '    # ===== /admin /auth /captcha /health =====';
            $lines[] = '    location ~ ^/(admin|auth|captcha|health) {';
            $lines[] = '        proxy_pass ' . $backendUpstream . ';';
            $lines[] = '        proxy_http_version 1.1;';
            $lines[] = '        proxy_set_header Host $host;';
            $lines[] = '        proxy_set_header X-Real-IP $remote_addr;';
            $lines[] = '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
            $lines[] = '        proxy_set_header X-Forwarded-Proto $scheme;';
            $lines[] = '        proxy_read_timeout 60s;';
            $lines[] = '        proxy_send_timeout 60s;';
            $lines[] = '        proxy_buffering on;';
            $lines[] = '        proxy_buffer_size 128k;';
            $lines[] = '        proxy_buffers 4 256k;';
            $lines[] = '    }';
            $lines[] = '';
            $lines[] = '    # ===== APK 下载 =====';
            $lines[] = '    location /admin/app-build/download/ {';
            $lines[] = '        proxy_pass ' . $backendUpstream . ';';
            $lines[] = '        proxy_http_version 1.1;';
            $lines[] = '        proxy_set_header Host $host;';
            $lines[] = '        proxy_set_header X-Real-IP $remote_addr;';
            $lines[] = '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
            $lines[] = '        proxy_buffering off;';
            $lines[] = '        proxy_read_timeout 300s;';
            $lines[] = '        proxy_send_timeout 300s;';
            $lines[] = '    }';
            $lines[] = '';
        }

        if ($targetType === 'ws' || $targetType === 'all') {
            $lines[] = '    # ===== WebSocket 推送服务 =====';
            $lines[] = '    location /ws {';
            $lines[] = '        proxy_pass ' . $wsUpstream . ';';
            $lines[] = '        proxy_http_version 1.1;';
            $lines[] = '        proxy_set_header Upgrade $http_upgrade;';
            $lines[] = '        proxy_set_header Connection "upgrade";';
            $lines[] = '        proxy_set_header Host $host;';
            $lines[] = '        proxy_set_header X-Real-IP $remote_addr;';
            $lines[] = '        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;';
            $lines[] = '        proxy_set_header X-Forwarded-Proto $scheme;';
            $lines[] = '        proxy_read_timeout 3600s;';
            $lines[] = '        proxy_send_timeout 3600s;';
            $lines[] = '        proxy_buffering off;';
            $lines[] = '    }';
            $lines[] = '';
        }

        // 安全
        $lines[] = '    # ===== 隐藏敏感文件 =====';
        $lines[] = '    location ~ /\.(git|env|htaccess) {';
        $lines[] = '        deny all;';
        $lines[] = '        return 404;';
        $lines[] = '}';

        return $lines;
    }

    /**
     * 测试并重载 Nginx
     */
    public static function reloadNginx(): array
    {
        $test = shell_exec('sudo nginx -t 2>&1');
        if (strpos($test, 'successful') === false) {
            return ['success' => false, 'message' => 'Nginx 配置测试失败', 'output' => $test];
        }
        $output = shell_exec('sudo nginx -s reload 2>&1');
        return ['success' => true, 'message' => 'Nginx 已重载', 'output' => $test . "\n" . $output];
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

        if (self::isAcmeInstalled()) {
            shell_exec(sprintf('sudo %s --remove -d %s --ecc 2>&1', escapeshellarg(self::ACME_SH), escapeshellarg($domain)));
        }

        if (file_exists($certFile)) {
            shell_exec(sprintf('sudo rm -f %s 2>&1', escapeshellarg($certFile)));
        }
        if (file_exists($keyFile)) {
            shell_exec(sprintf('sudo rm -f %s 2>&1', escapeshellarg($keyFile)));
        }

        return ['success' => true, 'message' => '证书已删除'];
    }

    private static function checkSudoers(): bool
    {
        $output = shell_exec('sudo -n nginx -t 2>&1');
        return $output !== null && strpos($output, 'not allowed to run') === false;
    }

    private static function commandExists(string $cmd): bool
    {
        $check = shell_exec(sprintf('which %s 2>/dev/null', escapeshellarg($cmd)));
        return $check !== null && trim($check) !== '';
    }
}
