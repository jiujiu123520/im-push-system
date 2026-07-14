<?php
declare(strict_types=1);

namespace App\Service;

/**
 * APK 分发服务
 *
 * 管理构建完成后的 APK 分发记录，支持三种分发方式：
 *  1. 自托管下载（服务器直接提供下载）
 *  2. 蓝奏云上传（通过 Cookie 模拟登录上传）
 *  3. 自定义脚本上传（用户自行配置上传命令）
 *
 * 配置存储在 admin_settings 表：
 *  - settings_apk_distribution: JSON { enabled, lanzou_cookie, custom_script, base_url }
 */
class ApkDistributionService
{
    /** 分页每页条数 */
    private const PAGE_SIZE = 10;

    /**
     * 构建成功后自动创建分发记录
     *
     * @param string $buildId    构建ID
     * @param string $apkPath    APK 文件绝对路径
     * @param string $appName    应用名称
     * @param string $packageName 包名
     * @param string $versionName 版本名
     * @param int    $adminId    管理员ID
     * @return array ["success" => bool, "message" => string, "id" => int|null]
     */
    public static function createFromBuild(
        string $buildId,
        string $apkPath,
        string $appName,
        string $packageName,
        string $versionName,
        int $adminId
    ): array {
        // 检查是否已存在该 build_id 的分发记录
        $exist = Database::fetch(
            'SELECT id FROM apk_distributions WHERE build_id = ? LIMIT 1',
            [$buildId]
        );
        if ($exist !== false) {
            return ['success' => false, 'message' => '该构建的分发记录已存在', 'id' => null];
        }

        // 检查 APK 文件是否存在
        if (!file_exists($apkPath)) {
            return ['success' => false, 'message' => 'APK 文件不存在: ' . $apkPath, 'id' => null];
        }

        $apkSize = filesize($apkPath);
        $md5 = md5_file($apkPath);
        $downloadToken = self::generateDownloadToken();

        // 自托管下载 URL（相对路径，由前端拼接完整 URL）
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

    /**
     * 获取分发记录列表（分页）
     *
     * @param int    $page   页码
     * @param string $keyword 搜索关键字（匹配 app_name 或 build_id）
     * @return array
     */
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
            // 转换为可读大小
            $item['apk_size_text'] = self::formatFileSize((int)$item['apk_size']);
        }
        unset($item);

        return [
            'list'      => $list,
            'total'     => $total,
            'page'      => $page,
            'page_size' => self::PAGE_SIZE,
        ];
    }

    /**
     * 获取单条分发记录详情
     *
     * @param int $id
     * @return array|null
     */
    public static function getDetail(int $id): ?array
    {
        $row = Database::fetch('SELECT * FROM apk_distributions WHERE id = ? LIMIT 1', [$id]);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int)$row['id'];
        $row['apk_size'] = (int)$row['apk_size'];
        $row['admin_id'] = (int)$row['admin_id'];
        $row['apk_size_text'] = self::formatFileSize((int)$row['apk_size']);
        return $row;
    }

    /**
     * 根据 download_token 获取分发记录（公开下载用）
     *
     * @param string $token
     * @return array|null
     */
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

    /**
     * 获取 APK 文件路径用于下载
     *
     * @param string $token 下载令牌
     * @return array ["found" => bool, "path" => string, "filename" => string, "record" => array|null]
     */
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
     * 更新蓝奏云上传结果
     *
     * @param int    $id        分发记录ID
     * @param string $url       蓝奏云分享链接
     * @param string $password  分享密码
     * @param string $status    上传状态
     * @param string $message   消息
     * @return bool
     */
    public static function updateLanzouResult(int $id, string $url, string $password, string $status, string $message): bool
    {
        try {
            Database::execute(
                'UPDATE apk_distributions SET lanzou_url = ?, lanzou_password = ?, upload_status = ?, upload_message = ?, updated_at = NOW() WHERE id = ?',
                [$url, $password, $status, $message, $id]
            );
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 更新自定义上传结果
     *
     * @param int    $id      分发记录ID
     * @param string $url     上传后的 URL
     * @param string $status  上传状态
     * @param string $message 消息
     * @return bool
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

    /**
     * 删除分发记录
     *
     * @param int $id
     * @return bool
     */
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
     *
     * @return array { enabled: bool, lanzou_cookie: string, custom_script: string, base_url: string }
     */
    public static function getConfig(): array
    {
        $defaults = [
            'enabled'        => true,
            'lanzou_cookie'  => '',
            'custom_script'  => '',
            'base_url'       => '',
        ];

        try {
            $row = Database::fetch(
                'SELECT config_value FROM admin_settings WHERE config_key = ? LIMIT 1',
                ['settings_apk_distribution']
            );
            if ($row !== false) {
                $cfg = json_decode((string)$row['config_value'], true);
                if (is_array($cfg)) {
                    return array_merge($defaults, $cfg);
                }
            }
        } catch (\Throwable $e) {
        }
        return $defaults;
    }

    /**
     * 保存分发配置
     *
     * @param array $config
     * @return bool
     */
    public static function saveConfig(array $config): bool
    {
        $cfg = [
            'enabled'        => (bool)($config['enabled'] ?? true),
            'lanzou_cookie'  => (string)($config['lanzou_cookie'] ?? ''),
            'custom_script'  => (string)($config['custom_script'] ?? ''),
            'base_url'       => (string)($config['base_url'] ?? ''),
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
     * 上传 APK 到蓝奏云（通过 Cookie 模拟登录）
     *
     * 蓝奏云没有官方 API，这里通过模拟浏览器 Cookie 实现上传。
     * 需要用户提供登录后的 Cookie（从浏览器开发者工具获取）。
     *
     * @param int $id 分发记录ID
     * @return array ["success" => bool, "message" => string, "url" => string, "password" => string]
     */
    public static function uploadToLanzou(int $id): array
    {
        $record = self::getDetail($id);
        if ($record === null) {
            return ['success' => false, 'message' => '分发记录不存在', 'url' => '', 'password' => ''];
        }

        $apkPath = $record['apk_path'];
        if (!file_exists($apkPath)) {
            return ['success' => false, 'message' => 'APK 文件不存在', 'url' => '', 'password' => ''];
        }

        // 蓝奏云免费版限制 100MB
        $fileSize = filesize($apkPath);
        if ($fileSize > 100 * 1024 * 1024) {
            self::updateLanzouResult($id, '', '', 'failed', '文件超过 100MB，蓝奏云免费版不支持');
            return ['success' => false, 'message' => '文件超过 100MB（' . self::formatFileSize($fileSize) . '），蓝奏云免费版不支持。请使用自托管下载或自定义上传', 'url' => '', 'password' => ''];
        }

        $config = self::getConfig();
        $cookie = $config['lanzou_cookie'];
        if ($cookie === '') {
            self::updateLanzouResult($id, '', '', 'failed', '未配置蓝奏云 Cookie');
            return ['success' => false, 'message' => '未配置蓝奏云 Cookie，请在分发设置中填写', 'url' => '', 'password' => ''];
        }

        self::updateLanzouResult($id, '', '', 'uploading', '正在上传到蓝奏云...');

        // 调用上传脚本
        $scriptPath = dirname(__DIR__, 2) . '/deploy/apk/upload-to-lanzou.sh';
        $appName = escapeshellarg($record['app_name']);
        $apkPathArg = escapeshellarg($apkPath);
        $cookieArg = escapeshellarg($cookie);

        $cmd = "bash {$scriptPath} {$apkPathArg} {$appName} {$cookieArg} 2>&1";
        $output = shell_exec($cmd);
        $output = is_string($output) ? trim($output) : '';

        // 解析脚本输出（JSON 格式：{"success":true,"url":"...","password":"...","message":"..."}）
        $result = json_decode($output, true);
        if (is_array($result) && ($result['success'] ?? false)) {
            $url = (string)($result['url'] ?? '');
            $password = (string)($result['password'] ?? '');
            self::updateLanzouResult($id, $url, $password, 'success', '上传成功');
            return ['success' => true, 'message' => '上传蓝奏云成功', 'url' => $url, 'password' => $password];
        }

        $errorMsg = is_array($result) ? ($result['message'] ?? $output) : $output;
        self::updateLanzouResult($id, '', '', 'failed', $errorMsg);
        return ['success' => false, 'message' => '蓝奏云上传失败: ' . $errorMsg, 'url' => '', 'password' => ''];
    }

    /**
     * 执行自定义上传脚本
     *
     * 用户在配置中填写的脚本路径，脚本接收 APK 文件路径作为参数，
     * 输出上传后的 URL（第一行）。
     *
     * @param int $id 分发记录ID
     * @return array ["success" => bool, "message" => string, "url" => string]
     */
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

        // 脚本第一行输出 URL
        $lines = explode("\n", $output);
        $url = trim($lines[0] ?? '');
        $message = count($lines) > 1 ? trim(implode("\n", array_slice($lines, 1))) : '上传完成';

        // 简单验证 URL 格式
        if ($url !== '' && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0)) {
            self::updateCustomResult($id, $url, 'success', $message);
            return ['success' => true, 'message' => '自定义上传成功', 'url' => $url];
        }

        self::updateCustomResult($id, '', 'failed', $output);
        return ['success' => false, 'message' => '自定义上传失败: ' . $output, 'url' => ''];
    }

    /**
     * 生成下载令牌（32位随机字符串）
     *
     * @return string
     */
    private static function generateDownloadToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 格式化文件大小为可读字符串
     *
     * @param int $bytes
     * @return string
     */
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
