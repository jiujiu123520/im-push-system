<?php
declare(strict_types=1);

namespace App\Service;

/**
 * APK 分发服务
 *
 * 管理构建完成后的 APK 分发记录，支持三种分发方式：
 *  1. 自托管下载（服务器直接提供下载）
 *  2. 小飞机网盘上传（feejii.com，通过 AppToken+UUID 鉴权）
 *  3. 自定义脚本上传（用户自行配置上传命令）
 *
 * 配置存储在 admin_settings 表：
 *  - settings_apk_distribution: JSON { enabled, feijii_app_token, feijii_uuid, feijii_dev_code, custom_script, base_url }
 */
class ApkDistributionService
{
    /** 分页每页条数 */
    private const PAGE_SIZE = 10;

    /** 小飞机直链默认缓存有效期（秒）：2 小时。sign 参数通常 24h 过期，保守一点留 2h。 */
    private const FEEJII_DIRECT_TTL = 7200;

    /**
     * 小飞机直链缓存命中判断 + 更新 DB
     * 返回 [ 'hit' => bool, 'url' => string ]   hit=true 时可以直接 302
     */
    public static function getCachedFeijiiDirectUrl(int $id): array
    {
        $row = Database::fetch(
            'SELECT id, feijipan_url, feijipan_direct_url, feijipan_direct_expires
             FROM apk_distributions WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($row === false || empty($row['feijipan_url'] ?? '')) {
            return ['hit' => false, 'url' => ''];
        }
        $directUrl = (string)($row['feijipan_direct_url'] ?? '');
        $expiresStr = $row['feijipan_direct_expires'] ?? null;
        if ($directUrl !== '' && $expiresStr !== null) {
            $expiresAt = strtotime((string)$expiresStr);
            if ($expiresAt !== false && $expiresAt > time() + 60) {
                return ['hit' => true, 'url' => $directUrl];
            }
        }
        return ['hit' => false, 'url' => ''];
    }

    /**
     * 保存解析到的直链到缓存
     */
    public static function saveCachedFeijiiDirectUrl(int $id, string $directUrl, int $ttl = self::FEEJII_DIRECT_TTL): void
    {
        if ($directUrl === '') return;
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        try {
            Database::execute(
                'UPDATE apk_distributions
                    SET feijipan_direct_url = ?,
                        feijipan_direct_expires = ?,
                        feijipan_fetch_count = feijipan_fetch_count + 1,
                        updated_at = NOW()
                  WHERE id = ?',
                [$directUrl, $expires, $id]
            );
        } catch (\Throwable $e) {}
    }

    /**
     * 请求小飞机分享页 HTML，用多层正则/meta 提取真实下载直链。
     * 兜底：解析失败返回 ''，由调用方决定是否回退到跳分享页。
     */
    public static function resolveFeijiiDirectUrl(string $shareUrl): string
    {
        $shareUrl = trim($shareUrl);
        if ($shareUrl === '' || !str_starts_with($shareUrl, 'http')) return '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $shareUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,  // 分享页可能 301/302 跳转
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            ],
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || $httpCode < 200 || $httpCode >= 400) return '';
        if (!is_string($html)) return '';

        $candidates = [];

        // ---------- Pattern 1: 页面直接声明下载按钮/链接 ----------
        if (preg_match_all('/<a[^>]+(?:id|class)="[^"]*(?:down|download|getfile)[^"]*"[^>]+href="(https?:\/\/[^"]+)"/i', $html, $m)) {
            foreach ($m[1] as $u) {
                $decoded = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
                if (preg_match('/\.(apk|zip|rar|7z|tar\.gz|tgz|exe|dmg|ipa|msi|pkg|deb)(?:$|\?|#)/i', $decoded)) {
                    $candidates[] = $decoded;
                }
            }
        }

        // ---------- Pattern 2: JS 内声明变量 ----------
        // const DOWNLOAD_URL = "https://..."; var fileUrl = 'https://...'; share_url = "https://..."
        if (preg_match_all('/(?:DOWNLOAD_URL|download[_\-]?url|file[_\-]?url|share[_\-]?url|real[_\-]?url|cdn[_\-]?url)\s*[:=]\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) $candidates[] = $u;
        }

        // ---------- Pattern 3: meta property (og:audio / og:video / og:file) ----------
        // <meta property="og:video:url" content="https://...">
        // <meta content="https://..." property="og:audio">
        if (preg_match_all('/<meta\s+(?:property|name)="og:(?:video|audio|file|download)(?::url)?"\s+content="(https?:\/\/[^"]+)"/i', $html, $m)) {
            foreach ($m[1] as $u) $candidates[] = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
        }
        if (preg_match_all('/<meta\s+content="(https?:\/\/[^"]+)"\s+(?:property|name)="og:(?:video|audio|file|download)(?::url)?"/i', $html, $m)) {
            foreach ($m[1] as $u) $candidates[] = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
        }

        // ---------- Pattern 4: 通用：任何带 sign/exp/token 参数的 https CDN URL（小飞机直链特征） ----------
        // 形如 https://cdnX.feejii.com/v1/file/xxx?sign=yyy&expire=zzz
        // 或     https://*.feejii.com/*?AWSAccessKeyId=...&Signature=...  (S3 签名)
        // 或     https://*.aliyuncs.com/*?Expires=...&OSSAccessKeyId=...&Signature=...  (OSS)
        if (preg_match_all('/https?:\/\/[a-zA-Z0-9.\-]+\/[^"\'<>?#\s]+\?(?:[^"\'<>#\s]*(?:sign|expir|expire|token|AWSAccessKeyId|OSSAccessKeyId|Signature|X-Amz-Credential|X-Amz-Signature)=[^"\'<>#\s]*)+/i', $html, $m)) {
            foreach ($m[0] as $u) $candidates[] = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
        }

        // ---------- Pattern 5: 小飞机常见的 JS 跳转 / window.location ----------
        // window.location.href = "https://..."; location.replace("https://...")
        // location.href = "https://cdn..."
        if (preg_match_all('/(?:window\.location(?:\.href)?|location\.(?:href|replace))\s*[=.]\s*\(?\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) $candidates[] = $u;
        }

        // ---------- 命中优先级：长度长的优先（直链通常更长、带签名参数） ----------
        $candidates = array_values(array_filter(array_unique($candidates)));
        if (empty($candidates)) return '';
        usort($candidates, fn($a, $b) => strlen($b) <=> strlen($a));
        return $candidates[0];
    }

    /**
     * 构建成功后自动创建分发记录
     */
    public static function createFromBuild(
        string $buildId,
        string $apkPath,
        string $appName,
        string $packageName,
        string $versionName,
        int $adminId
    ): array {
        $exist = Database::fetch(
            'SELECT id FROM apk_distributions WHERE build_id = ? LIMIT 1',
            [$buildId]
        );
        if ($exist !== false) {
            return ['success' => false, 'message' => '该构建的分发记录已存在', 'id' => null];
        }

        if (!file_exists($apkPath)) {
            return ['success' => false, 'message' => 'APK 文件不存在: ' . $apkPath, 'id' => null];
        }

        $apkSize = filesize($apkPath);
        $md5 = md5_file($apkPath);
        $downloadToken = self::generateDownloadToken();
        $selfHostedUrl = '/api/apk-distribution/download/' . $downloadToken;

        try {
            $id = Database::insert(
                'INSERT INTO apk_distributions
                 (build_id, app_name, package_name, version_name, apk_path, apk_size, md5, download_token, self_hosted_url, upload_status, admin_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$buildId, $appName, $packageName, $versionName, $apkPath, $apkSize, $md5, $downloadToken, $selfHostedUrl, 'pending', $adminId]
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => '创建分发记录失败: ' . $e->getMessage(), 'id' => null];
        }

        return ['success' => true, 'message' => '分发记录已创建', 'id' => (int)$id];
    }

    public static function getList(int $page, string $keyword = ''): array
    {
        if ($page < 1) {
            $page = 1;
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $keyword = trim($keyword);

        if ($keyword !== '') {
            $like = '%' . $keyword . '%';
            $countRow = Database::fetch(
                'SELECT COUNT(*) AS cnt FROM apk_distributions WHERE app_name LIKE ? OR build_id LIKE ?',
                [$like, $like]
            );
            $total = $countRow === false ? 0 : (int)($countRow['cnt'] ?? 0);

            $list = Database::fetchAll(
                'SELECT * FROM apk_distributions WHERE app_name LIKE ? OR build_id LIKE ?
                 ORDER BY id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
                [$like, $like]
            );
        } else {
            $countRow = Database::fetch('SELECT COUNT(*) AS cnt FROM apk_distributions');
            $total = $countRow === false ? 0 : (int)($countRow['cnt'] ?? 0);

            $list = Database::fetchAll(
                'SELECT * FROM apk_distributions ORDER BY id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset,
                []
            );
        }

        foreach ($list as &$item) {
            $item['id'] = (int)$item['id'];
            $item['apk_size'] = (int)$item['apk_size'];
            $item['admin_id'] = (int)$item['admin_id'];
            $item['download_count'] = (int)($item['download_count'] ?? 0);
            $item['apk_size_text'] = self::formatFileSize((int)$item['apk_size']);
            // 兼容老数据：若 feijipan_url 为空但 lanzou_url 有值，合并到 feijipan_url
            if (empty($item['feijipan_url'] ?? '') && !empty($item['lanzou_url'] ?? '')) {
                $item['feijipan_url'] = $item['lanzou_url'];
                $item['feijipan_share_id'] = $item['lanzou_password'] ?? '';
            }
        }
        unset($item);

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => self::PAGE_SIZE,
        ];
    }

    public static function getDetail(int $id): ?array
    {
        $row = Database::fetch('SELECT * FROM apk_distributions WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['apk_size'] = (int)$row['apk_size'];
        $row['admin_id'] = (int)$row['admin_id'];
        $row['download_count'] = (int)($row['download_count'] ?? 0);
        $row['apk_size_text'] = self::formatFileSize((int)$row['apk_size']);
        if (empty($row['feijipan_url'] ?? '') && !empty($row['lanzou_url'] ?? '')) {
            $row['feijipan_url'] = $row['lanzou_url'];
            $row['feijipan_share_id'] = $row['lanzou_password'] ?? '';
        }
        return $row;
    }

    public static function getByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $row = Database::fetch(
            'SELECT * FROM apk_distributions WHERE download_token = ? LIMIT 1',
            [$token]
        );
        if ($row === false) {
            return null;
        }
        return $row;
    }

    public static function getDownloadFile(string $token): array
    {
        $record = self::getByToken($token);
        if ($record === null) {
            return ['found' => false, 'path' => '', 'filename' => '', 'record' => null];
        }

        $apkPath = $record['apk_path'];
        if (!file_exists($apkPath)) {
            return ['found' => false, 'path' => '', 'filename' => '', 'record' => $record];
        }

        $appName = $record['app_name'] ?: 'app';
        $versionName = $record['version_name'] ?: '';
        $filename = $appName . ($versionName !== '' ? '-' . $versionName : '') . '.apk';

        return ['found' => true, 'path' => $apkPath, 'filename' => $filename, 'record' => $record];
    }

    /**
     * 更新小飞机网盘上传结果
     */
    public static function updateFeijiiResult(int $id, string $url, string $shareId, string $status, string $message): bool
    {
        try {
            Database::execute(
                'UPDATE apk_distributions SET feijipan_url = ?, feijipan_share_id = ?, upload_status = ?, upload_message = ?, updated_at = NOW() WHERE id = ?',
                [$url, $shareId, $status, $message, $id]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 更新自定义上传结果
     */
    public static function updateCustomResult(int $id, string $url, string $status, string $message): bool
    {
        try {
            Database::execute(
                'UPDATE apk_distributions SET custom_url = ?, upload_status = ?, upload_message = ?, updated_at = NOW() WHERE id = ?',
                [$url, $status, $message, $id]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function delete(int $id): bool
    {
        try {
            $affected = Database::execute('DELETE FROM apk_distributions WHERE id = ?', [$id]);
            return $affected > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取分发配置
     * @return array { enabled: bool, feijii_app_token: string, feijii_uuid: string, feijii_dev_code: string, custom_script: string, base_url: string }
     */
    public static function getConfig(): array
    {
        $defaults = [
            'enabled'           => true,
            'feijii_app_token'  => '',
            'feijii_uuid'       => '',
            'feijii_dev_code'   => '',
            'custom_script'     => '',
            'base_url'          => '',
        ];

        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_apk_distribution']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg)) {
                    // 兼容老配置：lanzou_cookie 迁移为提示，但仍读取老字段以便平滑过渡
                    $merged = array_merge($defaults, $cfg);
                    // 若老的 lanzou_cookie 还在且新字段为空，保留（前端会提示需要切换）
                    return $merged;
                }
            }
        } catch (\Throwable $e) {
        }
        return $defaults;
    }

    /**
     * 保存分发配置
     */
    public static function saveConfig(array $config): bool
    {
        $cfg = [
            'enabled'           => (bool)($config['enabled'] ?? true),
            'feijii_app_token'  => (string)($config['feijii_app_token'] ?? ''),
            'feijii_uuid'       => (string)($config['feijii_uuid'] ?? ''),
            'feijii_dev_code'   => (string)($config['feijii_dev_code'] ?? ''),
            'custom_script'     => (string)($config['custom_script'] ?? ''),
            'base_url'          => (string)($config['base_url'] ?? ''),
        ];

        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE);

        try {
            $exist = Database::fetch(
                'SELECT id FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_apk_distribution']
            );
            if ($exist !== false) {
                Database::execute(
                    'UPDATE admin_settings SET config_value = ?, updated_at = NOW() WHERE config_key = ?',
                    [$json, 'settings_apk_distribution']
                );
            } else {
                Database::execute(
                    'INSERT INTO admin_settings (config_key, config_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())',
                    ['settings_apk_distribution', $json]
                );
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 上传 APK 到小飞机网盘
     *
     * @param int $id 分发记录ID
     * @return array ["success" => bool, "message" => string, "url" => string, "share_id" => string]
     */
    public static function uploadToFeijii(int $id): array
    {
        $record = self::getDetail($id);
        if ($record === null) {
            return ['success' => false, 'message' => '分发记录不存在', 'url' => '', 'share_id' => ''];
        }

        $apkPath = $record['apk_path'];
        if (!file_exists($apkPath)) {
            return ['success' => false, 'message' => 'APK 文件不存在', 'url' => '', 'share_id' => ''];
        }

        $config = self::getConfig();
        $appToken = trim((string)($config['feijii_app_token'] ?? ''));
        $uuid     = trim((string)($config['feijii_uuid'] ?? ''));
        $devCode  = trim((string)($config['feijii_dev_code'] ?? ''));

        if ($appToken === '' || $uuid === '' || $devCode === '') {
            self::updateFeijiiResult($id, '', '', 'failed', '未配置小飞机网盘凭证（需要 AppToken、UUID、DevCode）');
            return ['success' => false, 'message' => '未配置小飞机网盘凭证，请在分发设置中填写 AppToken、UUID、DevCode', 'url' => '', 'share_id' => ''];
        }

        self::updateFeijiiResult($id, '', '', 'uploading', '正在上传到小飞机网盘...');

        $scriptPath = dirname(__DIR__, 2) . '/deploy/apk/upload-to-feijipan.sh';
        $appName = escapeshellarg($record['app_name']);
        $apkPathArg = escapeshellarg($apkPath);
        $appTokenArg = escapeshellarg($appToken);
        $uuidArg = escapeshellarg($uuid);
        $devCodeArg = escapeshellarg($devCode);

        $cmd = "bash {$scriptPath} {$apkPathArg} {$appName} {$appTokenArg} {$uuidArg} {$devCodeArg} 2>&1";
        $output = shell_exec($cmd);
        $output = is_string($output) ? trim($output) : '';

        $result = json_decode($output, true);
        if (is_array($result) && ($result['success'] ?? false)) {
            $url = (string)($result['url'] ?? '');
            $shareId = (string)($result['share_id'] ?? '');
            $message = (string)($result['message'] ?? '上传成功');
            $status = $url !== '' ? 'success' : 'success';
            self::updateFeijiiResult($id, $url, $shareId, $status, $message);
            return ['success' => true, 'message' => $message, 'url' => $url, 'share_id' => $shareId];
        }

        $errorMsg = is_array($result) ? ($result['message'] ?? $output) : $output;
        self::updateFeijiiResult($id, '', '', 'failed', $errorMsg);
        return ['success' => false, 'message' => '小飞机网盘上传失败: ' . $errorMsg, 'url' => '', 'share_id' => ''];
    }

    public static function uploadCustom(int $id): array
    {
        $record = self::getDetail($id);
        if ($record === null) {
            return ['success' => false, 'message' => '分发记录不存在', 'url' => ''];
        }

        $apkPath = $record['apk_path'];
        if (!file_exists($apkPath)) {
            return ['success' => false, 'message' => 'APK 文件不存在', 'url' => ''];
        }

        $config = self::getConfig();
        $script = $config['custom_script'];
        if ($script === '') {
            self::updateCustomResult($id, '', 'failed', '未配置自定义上传脚本');
            return ['success' => false, 'message' => '未配置自定义上传脚本', 'url' => ''];
        }

        if (!file_exists($script) || !is_executable($script)) {
            self::updateCustomResult($id, '', 'failed', '脚本不存在或不可执行');
            return ['success' => false, 'message' => '自定义上传脚本不存在或不可执行: ' . $script, 'url' => ''];
        }

        self::updateCustomResult($id, '', 'uploading', '正在执行自定义上传...');

        $apkPathArg = escapeshellarg($apkPath);
        $buildIdArg = escapeshellarg($record['build_id']);
        $appNameArg = escapeshellarg($record['app_name']);
        $cmd = "{$script} {$apkPathArg} {$buildIdArg} {$appNameArg} 2>&1";
        $output = shell_exec($cmd);
        $output = is_string($output) ? trim($output) : '';

        $lines = explode("\n", $output);
        $url = trim($lines[0] ?? '');
        $message = count($lines) > 1 ? trim(implode("\n", array_slice($lines, 1))) : '上传完成';

        if ($url !== '' && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)) {
            self::updateCustomResult($id, $url, 'success', $message);
            return ['success' => true, 'message' => '自定义上传成功', 'url' => $url];
        }

        self::updateCustomResult($id, '', 'failed', $output);
        return ['success' => false, 'message' => '自定义上传失败: ' . $output, 'url' => ''];
    }

    public static function incrementDownloadCount(string $token, string $ip = '', string $ua = '', string $referer = ''): void
    {
        try {
            $record = self::getByToken($token);
            if ($record === null) {
                return;
            }
            $distributionId = (int)$record['id'];

            Database::execute(
                'UPDATE apk_distributions SET download_count = download_count + 1 WHERE id = ?',
                [$distributionId]
            );

            Database::insert(
                'INSERT INTO apk_download_logs (distribution_id, download_token, ip_address, user_agent, referer, downloaded_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$distributionId, $token, mb_substr($ip, 0, 45), mb_substr($ua, 0, 512), mb_substr($referer, 0, 512)]
            );
        } catch (\Throwable $e) {
        }
    }

    public static function getDownloadStats(int $id): array
    {
        $record = self::getDetail($id);
        if ($record === null) {
            return ['total' => 0, 'recent' => []];
        }

        $total = (int)($record['download_count'] ?? 0);

        $recent = Database::fetchAll(
            'SELECT ip_address, user_agent, referer, downloaded_at
             FROM apk_download_logs WHERE distribution_id = ?
             ORDER BY id DESC LIMIT 50',
            [$id]
        );

        foreach ($recent as &$log) {
            $ua = (string)($log['user_agent'] ?? '');
            if (strlen($ua) > 100) {
                $log['user_agent_short'] = mb_substr($ua, 0, 100) . '...';
            } else {
                $log['user_agent_short'] = $ua;
            }
        }
        unset($log);

        return ['total' => $total, 'recent' => $recent];
    }

    /**
     * 验证小飞机网盘凭证是否有效
     *
     * 通过调用 /app/user/info 接口检查 AppToken/UUID/DevCode 是否有效。
     *
     * @param string $appToken
     * @param string $uuid
     * @param string $devCode
     * @return array ["valid" => bool, "message" => string, "user_info" => array|null]
     */
    public static function validateFeijiiCredentials(string $appToken, string $uuid, string $devCode): array
    {
        $appToken = trim($appToken);
        $uuid = trim($uuid);
        $devCode = trim($devCode);
        if ($appToken === '' || $uuid === '' || $devCode === '') {
            return ['valid' => false, 'message' => 'AppToken、UUID、DevCode 三项均不能为空', 'user_info' => null];
        }

        $url = 'https://api.feejii.com/app/user/info?appToken=' . urlencode($appToken)
            . '&uuid=' . urlencode($uuid)
            . '&devCode=' . urlencode($devCode)
            . '&devType=1&userId=';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
                'Origin: https://www.feejii.com',
                'Referer: https://www.feejii.com/',
            ],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            return ['valid' => false, 'message' => '请求小飞机网盘失败: ' . $error, 'user_info' => null];
        }
        if ($httpCode !== 200) {
            return ['valid' => false, 'message' => '小飞机网盘返回异常 HTTP ' . $httpCode, 'user_info' => null];
        }

        $resp = json_decode((string)$body, true);
        if (!is_array($resp)) {
            return ['valid' => false, 'message' => '小飞机网盘返回非 JSON 响应（可能接口变更/网络代理）', 'user_info' => null];
        }

        $code = $resp['code'] ?? null;
        $msg  = (string)($resp['msg'] ?? '');
        $data = is_array($resp['data'] ?? null) ? $resp['data'] : null;

        if ($code === 0 && $data !== null) {
            $nickName = (string)($data['nickName'] ?? $data['userName'] ?? $data['name'] ?? '');
            $userId   = (string)($data['userId'] ?? $data['id'] ?? '');
            $vip      = isset($data['isVip']) ? ($data['isVip'] ? 'VIP' : '普通用户') : '';
            $info = '';
            if ($nickName !== '') $info .= "用户：{$nickName}";
            if ($userId !== '')   $info .= ($info ? '，' : '') . "ID：{$userId}";
            if ($vip !== '')      $info .= ($info ? '，' : '') . $vip;
            return [
                'valid'     => true,
                'message'   => '凭证有效，小飞机网盘登录正常' . ($info !== '' ? "（{$info}）" : ''),
                'user_info' => $data,
            ];
        }

        $errMsg = $msg !== '' ? "小飞机返回错误：{$msg}" : '凭证无效，请检查 AppToken / UUID / DevCode 是否与登录会话一致';
        if ($code !== null) {
            $errMsg .= "（code={$code}）";
        }
        return ['valid' => false, 'message' => $errMsg, 'user_info' => null];
    }

    /**
     * 从上传的文件创建分发记录（本地上传 APK）
     */
    public static function createFromFile(
        array $file,
        string $appName,
        string $packageName,
        string $versionName,
        int $adminId
    ): array {
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => '上传错误码：' . $file['error'], 'id' => null];
        }

        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return ['success' => false, 'message' => '无效的上传文件', 'id' => null];
        }

        $originalName = (string)($file['name'] ?? '');

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'apk') {
            return ['success' => false, 'message' => '只支持 .apk 文件', 'id' => null];
        }

        $fileSize = (int)($file['size'] ?? 0);
        if ($fileSize <= 0) {
            $fileSize = filesize($tmpPath);
        }
        if ($fileSize > 200 * 1024 * 1024) {
            return ['success' => false, 'message' => '文件超过 200MB 限制', 'id' => null];
        }

        $appName = trim($appName);
        if ($appName === '') {
            $appName = pathinfo($originalName, PATHINFO_FILENAME);
        }
        $versionName = trim($versionName);
        if ($versionName === '') {
            $versionName = '1.0.0';
        }

        $buildId = 'upload-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

        $storageDir = dirname(__DIR__, 2) . '/storage/apk_uploads';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $appName);
        $fileName = $safeName . '-' . $versionName . '-' . $buildId . '.apk';
        $destPath = $storageDir . '/' . $fileName;
        if (!rename($tmpPath, $destPath)) {
            return ['success' => false, 'message' => '文件保存失败', 'id' => null];
        }

        $md5 = md5_file($destPath);
        $downloadToken = self::generateDownloadToken();
        $selfHostedUrl = '/api/apk-distribution/download/' . $downloadToken;

        try {
            $id = Database::insert(
                'INSERT INTO apk_distributions
                 (build_id, app_name, package_name, version_name, apk_path, apk_size, md5, download_token, self_hosted_url, upload_status, admin_id, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$buildId, $appName, $packageName, $versionName, $destPath, $fileSize, $md5, $downloadToken, $selfHostedUrl, 'pending', $adminId]
            );
        } catch (\Throwable $e) {
            @unlink($destPath);
            return ['success' => false, 'message' => '创建分发记录失败: ' . $e->getMessage(), 'id' => null];
        }

        return ['success' => true, 'message' => 'APK 上传成功，分发记录已创建', 'id' => (int)$id];
    }

    private static function generateDownloadToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }
        return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}
