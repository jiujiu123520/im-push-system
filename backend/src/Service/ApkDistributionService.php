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
            $item['download_count'] = (int)($item['download_count'] ?? 0);
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
        $row['download_count'] = (int)($row['download_count'] ?? 0);
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
     * 递增下载计数并记录下载日志
     *
     * @param string $token   下载令牌
     * @param string $ip      下载者 IP
     * @param string $ua      User-Agent
     * @param string $referer 来源页面
     * @return void
     */
    public static function incrementDownloadCount(string $token, string $ip = '', string $ua = '', string $referer = ''): void
    {
        try {
            $record = self::getByToken($token);
            if ($record === null) {
                return;
            }
            $distributionId = (int)$record['id'];

            // 递增 download_count
            Database::execute(
                'UPDATE apk_distributions SET download_count = download_count + 1 WHERE id = ?',
                [$distributionId]
            );

            // 写入下载日志
            Database::insert(
                'INSERT INTO apk_download_logs (distribution_id, download_token, ip_address, user_agent, referer, downloaded_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$distributionId, $token, mb_substr($ip, 0, 45), mb_substr($ua, 0, 512), mb_substr($referer, 0, 512)]
            );
        } catch (\Throwable $e) {
            // 下载计数失败不影响下载本身
        }
    }

    /**
     * 获取下载统计数据
     *
     * @param int $id 分发记录ID
     * @return array
     */
    public static function getDownloadStats(int $id): array
    {
        $record = self::getDetail($id);
        if ($record === null) {
            return ['total' => 0, 'recent' => []];
        }

        $total = (int)($record['download_count'] ?? 0);

        // 最近 50 条下载日志
        $recent = Database::fetchAll(
            'SELECT ip_address, user_agent, referer, downloaded_at
             FROM apk_download_logs WHERE distribution_id = ?
             ORDER BY id DESC LIMIT 50',
            [$id]
        );

        // 简化 UA（只保留浏览器/客户端名称）
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
     * 验证蓝奏云 Cookie 是否有效
     *
     * 通过 Cookie 请求蓝奏云的个人网盘页面，检查返回内容是否包含登录态标识。
     *
     * @param string $cookie 蓝奏云 Cookie 字符串
     * @return array ["valid" => bool, "message" => string]
     */
    public static function validateLanzouCookie(string $cookie): array
    {
        $cookie = trim($cookie);
        if ($cookie === '') {
            return ['valid' => false, 'message' => 'Cookie 不能为空'];
        }

        // 请求蓝奏云个人网盘页面（与本地上传/文件列表同一路径），
        // 已登录会包含反 CSRF 字段 ve、folder_id_f 或 退出/userinfo/文件管理 等关键词
        $url = 'https://up.woozooo.com/mydisk.php?item=files&action=index';
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIE => $cookie,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_REFERER => 'https://up.woozooo.com/mydisk.php',
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            return ['valid' => false, 'message' => '请求蓝奏云失败: ' . $error];
        }

        // 301/302 到登录页 = Cookie 完全失效
        if ($httpCode === 302 || $httpCode === 301) {
            return ['valid' => false, 'message' => 'Cookie 已失效（被重定向到登录页），请重新获取'];
        }

        if ($httpCode !== 200) {
            return ['valid' => false, 'message' => '蓝奏云返回异常 HTTP ' . $httpCode];
        }

        // HTTP 200 情况下的登录态判断：
        //   强特征（登录成功一定有）：name="ve" / name="folder_id_f" / 上传表单 html5up
        //   普通特征：退出 / userinfo / 文件管理 / 用户名 / 全部文件 / 新建文件夹
        $hasVe          = stripos($body, 'name="ve"') !== false || preg_match('/ve\s*[:=]\s*["\'][A-Za-z0-9_-]{10,}/', $body) === 1;
        $hasFolderId    = stripos($body, 'folder_id_f') !== false;
        $hasUploadForm  = stripos($body, 'html5up.php') !== false || stripos($body, 'fileup.php') !== false;
        $hasLogout      = mb_strpos($body, '退出') !== false || stripos($body, 'userinfo') !== false
                        || mb_strpos($body, '文件管理') !== false || mb_strpos($body, '全部文件') !== false
                        || mb_strpos($body, '新建文件夹') !== false || mb_strpos($body, '用户名') !== false;

        if ($hasVe && ($hasFolderId || $hasUploadForm || $hasLogout)) {
            return ['valid' => true, 'message' => 'Cookie 有效，蓝奏云登录状态正常'];
        }

        if ($hasVe || $hasLogout) {
            // 弱匹配，可能是接口结构变了，给出半确认提示
            return ['valid' => true, 'message' => 'Cookie 疑似有效（匹配到登录特征，但未取到完整表单结构，建议用本地上传测试实际是否能传）'];
        }

        // 未登录强特征：登录表单里出现 password + action=login
        $isLoginPage = (stripos($body, 'password') !== false && preg_match('/action=["\']?[^"\']*login/i', $body) === 1)
                    || mb_strpos($body, '请登录') !== false
                    || mb_strpos($body, '立即登录') !== false;
        if ($isLoginPage) {
            return ['valid' => false, 'message' => 'Cookie 已失效（返回登录页），请在浏览器重新登录 up.woozooo.com 后复制完整 Cookie'];
        }

        return ['valid' => false, 'message' => '无法确认登录状态，请检查 Cookie 是否为 up.woozooo.com 下完整 Cookie（至少需要 ylogin 和 phpdisk_info 两项）'];
    }

    /**
     * 从上传的文件创建分发记录（本地上传 APK）
     *
     * 接收类似 $_FILES['file'] 的文件数组，兼容 Swoole 运行环境。
     *
     * @param array  $file        上传文件数组（含 tmp_name/name/size/error）
     * @param string $appName     应用名称
     * @param string $packageName 包名
     * @param string $versionName 版本名
     * @param int    $adminId     管理员ID
     * @return array ["success" => bool, "message" => string, "id" => int|null]
     */
    public static function createFromFile(
        array $file,
        string $appName,
        string $packageName,
        string $versionName,
        int $adminId
    ): array {
        // 检查上传错误码
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => '上传错误码：' . $file['error'], 'id' => null];
        }

        // Swoole 环境下 is_uploaded_file 可能失效，改用 is_file 校验
        $tmpPath = (string)($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            return ['success' => false, 'message' => '无效的上传文件', 'id' => null];
        }

        $originalName = (string)($file['name'] ?? '');

        // 验证文件扩展名
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'apk') {
            return ['success' => false, 'message' => '只支持 .apk 文件', 'id' => null];
        }

        // 文件大小限制 200MB
        $fileSize = (int)($file['size'] ?? 0);
        if ($fileSize <= 0) {
            $fileSize = filesize($tmpPath);
        }
        if ($fileSize > 200 * 1024 * 1024) {
            return ['success' => false, 'message' => '文件超过 200MB 限制', 'id' => null];
        }

        // 应用名/版本名兜底
        $appName = trim($appName);
        if ($appName === '') {
            $appName = pathinfo($originalName, PATHINFO_FILENAME);
        }
        $versionName = trim($versionName);
        if ($versionName === '') {
            $versionName = '1.0.0';
        }

        // 生成 build_id（用 upload- 前缀 + 时间戳）
        $buildId = 'upload-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

        // 创建存储目录
        $storageDir = dirname(__DIR__, 2) . '/storage/apk_uploads';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        // 移动文件到持久化目录（Swoole 下 move_uploaded_file 可能失效，改用 rename）
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $appName);
        $fileName = $safeName . '-' . $versionName . '-' . $buildId . '.apk';
        $destPath = $storageDir . '/' . $fileName;
        if (!rename($tmpPath, $destPath)) {
            return ['success' => false, 'message' => '文件保存失败', 'id' => null];
        }

        // 计算文件信息
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
