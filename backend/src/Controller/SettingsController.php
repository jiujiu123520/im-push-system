<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Database;
use App\Service\MailService;
use App\Service\Response;

/**
 * 系统设置控制器（管理员鉴权）
 *
 * 路由：
 *   GET  /admin/settings                    获取系统设置
 *   PUT  /admin/settings                    更新系统设置
 *   GET  /admin/settings/mail               获取邮件配置
 *   POST /admin/settings/mail               保存邮件配置
 *   POST /admin/settings/mail/test          测试邮件配置
 *   GET  /admin/settings/system-info        获取系统信息
 *   GET  /admin/settings/check-version      版本检测
 *   POST /admin/settings/system-update       一键更新
 *   GET  /admin/settings/update-progress/{taskId}  查询更新进度
 */
class SettingsController
{
    /**
     * 获取系统设置
     * 路由：GET /admin/settings
     */
    public function getSettings(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $pdo = Database::pdo();

        // 读取各分组的配置
        $sections = ['server', 'push', 'captcha', 'security'];
        $result = [];

        foreach ($sections as $section) {
            $stmt = $pdo->prepare('SELECT config_value FROM admin_settings WHERE config_key = ?');
            $stmt->execute(["settings_{$section}"]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $decoded = json_decode($row['config_value'], true);
                if (is_array($decoded)) {
                    $result[$section] = $decoded;
                }
            }
        }

        return $result;
    }

    /**
     * 更新系统设置
     * 路由：PUT /admin/settings
     */
    public function updateSettings(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $body = $this->parseBody($context);

        $pdo = Database::pdo();
        $updatedSections = [];

        // 支持按分组更新：server / push / captcha / security
        $sections = ['server', 'push', 'captcha', 'security'];

        foreach ($sections as $section) {
            if (isset($body[$section]) && is_array($body[$section])) {
                $configData = $body[$section];

                // 安全配置中密码字段脱敏处理
                if ($section === 'security') {
                    if (isset($configData['jwtSecret']) && $configData['jwtSecret'] === '******') {
                        $stmt = $pdo->prepare('SELECT config_value FROM admin_settings WHERE config_key = ?');
                        $stmt->execute(['settings_security']);
                        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                        if ($row) {
                            $existing = json_decode($row['config_value'], true);
                            $configData['jwtSecret'] = $existing['jwtSecret'] ?? '';
                        }
                    }
                    if (isset($configData['aesKey']) && $configData['aesKey'] === '******') {
                        $stmt = $pdo->prepare('SELECT config_value FROM admin_settings WHERE config_key = ?');
                        $stmt->execute(['settings_security']);
                        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                        if ($row) {
                            $existing = json_decode($row['config_value'], true);
                            $configData['aesKey'] = $existing['aesKey'] ?? '';
                        }
                    }
                }

                // 验证码配置中密码脱敏
                if ($section === 'captcha') {
                    if (isset($configData['mailPassword']) && $configData['mailPassword'] === '******') {
                        $stmt = $pdo->prepare('SELECT config_value FROM admin_settings WHERE config_key = ?');
                        $stmt->execute(['settings_captcha']);
                        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                        if ($row) {
                            $existing = json_decode($row['config_value'], true);
                            $configData['mailPassword'] = $existing['mailPassword'] ?? '';
                        }
                    }
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO admin_settings (config_key, config_value)
                     VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = ?'
                );
                $json = json_encode($configData, JSON_UNESCAPED_UNICODE);
                $stmt->execute(["settings_{$section}", $json, $json]);
                $updatedSections[] = $section;
            }
        }

        if (empty($updatedSections)) {
            Response::fail($response, '未提供需要更新的配置项', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        return ['message' => '配置已保存', 'updated' => $updatedSections];
    }

    /**
     * 获取邮件配置
     * 路由：GET /admin/settings/mail
     */
    public function getMailConfig(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $config = MailService::getConfig();

        return [
            'enabled'     => $config['enabled'],
            'host'        => $config['host'],
            'port'        => $config['port'],
            'username'    => $config['username'],
            'password'    => $config['password'] !== '' ? '******' : '',
            'encryption'  => $config['encryption'],
            'sender_name' => $config['sender_name'],
        ];
    }

    /**
     * 保存邮件配置
     * 路由：POST /admin/settings/mail
     */
    public function saveMailConfig(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $body = $this->parseBody($context);

        $enabled     = (bool)($body['enabled'] ?? false);
        $host        = (string)($body['host'] ?? 'smtp.qq.com');
        $port        = (string)($body['port'] ?? '587');
        $username    = (string)($body['username'] ?? '');
        $password    = (string)($body['password'] ?? '');
        $encryption  = (string)($body['encryption'] ?? 'tls');
        $sender_name = (string)($body['sender_name'] ?? '');

        // 如果密码是 ******，说明用户不想修改密码，从当前配置读取
        if ($password === '******') {
            $currentConfig = MailService::getConfig();
            $password = $currentConfig['password'];
        }

        // 验证
        if ($enabled && ($username === '' || $password === '')) {
            Response::fail($response, '启用邮件服务时，发件人邮箱和授权码不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $configData = [
            'enabled'     => $enabled,
            'host'        => $host,
            'port'        => $port,
            'username'    => $username,
            'password'    => $password,
            'encryption'  => $encryption,
            'sender_name' => $sender_name,
        ];

        try {
            $stmt = Database::pdo()->prepare(
                'INSERT INTO admin_settings (config_key, config_value)
                 VALUES (?, ?) ON DUPLICATE KEY UPDATE config_value = ?'
            );
            $stmt->execute(['mail_config', json_encode($configData, JSON_UNESCAPED_UNICODE), json_encode($configData, JSON_UNESCAPED_UNICODE)]);
        } catch (\Throwable $e) {
            Response::fail($response, '保存失败：' . $e->getMessage(), Response::CODE_INTERNAL, 500);
            return false;
        }

        return ['message' => '邮件配置已保存'];
    }

    /**
     * 测试邮件配置
     * 路由：POST /admin/settings/mail/test
     */
    public function testMailConfig(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $body = $this->parseBody($context);

        $to          = (string)($body['to'] ?? '');
        $host        = (string)($body['host'] ?? 'smtp.qq.com');
        $port        = (string)($body['port'] ?? '587');
        $username    = (string)($body['username'] ?? '');
        $password    = (string)($body['password'] ?? '');
        $encryption  = (string)($body['encryption'] ?? 'tls');
        $sender_name = (string)($body['sender_name'] ?? '');

        if ($to === '') {
            Response::fail($response, '请输入测试收件人邮箱', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Response::fail($response, '收件人邮箱格式不正确', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $config = [
            'host'        => $host,
            'port'        => $port,
            'username'    => $username,
            'password'    => $password,
            'encryption'  => $encryption,
            'sender_name' => $sender_name,
        ];

        $success = MailService::testConfig($config, $to);

        if ($success) {
            return ['message' => '测试邮件发送成功，请检查收件箱'];
        } else {
            Response::fail($response, '测试邮件发送失败，请检查配置是否正确', Response::CODE_ERROR, 500);
            return false;
        }
    }

    /**
     * 获取系统信息
     * 路由：GET /admin/settings/system-info
     *
     * 返回：版本、运行时间、CPU/内存/磁盘
     */
    public function getSystemInfo(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        // 获取版本号（从 git 描述）
        $version = 'unknown';
        $projectRoot = dirname(__DIR__, 3);
        $gitDescribe = @shell_exec('cd ' . escapeshellarg($projectRoot) . ' && git describe --tags --always 2>/dev/null');
        if ($gitDescribe) {
            $version = trim($gitDescribe);
        }

        // 系统运行时间（秒）
        $uptime = 0;
        if (file_exists('/proc/uptime')) {
            $uptimeContent = file_get_contents('/proc/uptime');
            if ($uptimeContent) {
                $parts = explode(' ', $uptimeContent);
                $uptime = (int)floatval($parts[0] ?? 0);
            }
        }

        // 内存信息
        $memUsed = 0;
        $memTotal = 0;
        if (file_exists('/proc/meminfo')) {
            $memInfo = file_get_contents('/proc/meminfo');
            if ($memInfo) {
                if (preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m)) {
                    $memTotal = (int)$m[1] * 1024;
                }
                if (preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m)) {
                    $memAvailable = (int)$m[1] * 1024;
                    $memUsed = $memTotal - $memAvailable;
                }
            }
        }

        // 磁盘信息（项目所在分区）
        $diskUsed = 0;
        $diskTotal = 0;
        $diskFree = disk_free_space($projectRoot);
        $diskTotalSpace = disk_total_space($projectRoot);
        if ($diskFree !== false && $diskTotalSpace !== false) {
            $diskTotal = (int)$diskTotalSpace;
            $diskUsed = (int)($diskTotalSpace - $diskFree);
        }

        // CPU 使用率（近似值）
        $cpu = 0.0;
        $loadAvg = sys_getloadavg();
        if ($loadAvg !== false && isset($loadAvg[0])) {
            $cpuCount = 1;
            if (file_exists('/proc/cpuinfo')) {
                $cpuInfo = file_get_contents('/proc/cpuinfo');
                if ($cpuInfo) {
                    $cpuCount = substr_count($cpuInfo, 'processor');
                    if ($cpuCount < 1) $cpuCount = 1;
                }
            }
            $cpu = round(($loadAvg[0] / $cpuCount) * 100, 1);
            if ($cpu > 100) $cpu = 100.0;
        }

        return [
            'version' => $version,
            'uptime'  => $uptime,
            'cpu'     => $cpu,
            'memory'  => [
                'used'  => $memUsed,
                'total' => $memTotal,
            ],
            'disk'    => [
                'used'  => $diskUsed,
                'total' => $diskTotal,
            ],
        ];
    }

    /**
     * 版本检测 - 对比本地与云端版本
     * 路由：GET /admin/settings/check-version
     */
    public function checkVersion(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];

        // 项目根目录（包含 admin/ 和 backend/ 的目录）
        $projectRoot = dirname(__DIR__, 3);
        $scriptPath = dirname(__DIR__, 2) . '/deploy/check-version.sh';

        // 如果脚本不存在，返回未检测状态
        if (!file_exists($scriptPath)) {
            return [
                'local'  => ['commit' => '', 'short' => '', 'date' => ''],
                'remote' => ['commit' => '', 'short' => '', 'branch' => '', 'date' => ''],
                'status' => 'unknown',
                'ahead_count'  => 0,
                'behind_count' => 0,
                'changelog'    => [],
            ];
        }

        // 执行版本检测脚本（使用 --json 获取结构化输出）
        $queryParams = $context['get'] ?? [];
        $ghProxy = (string)($queryParams['ghProxy'] ?? '');
        $proxy = (string)($queryParams['proxy'] ?? '');
        $cmdArgs = ['--json'];
        if ($ghProxy === '1' || $ghProxy === 'true') {
            $cmdArgs[] = '--gh-proxy';
        }
        if ($proxy !== '') {
            $cmdArgs[] = '--proxy=' . escapeshellarg($proxy);
        }

        $cmd = 'cd ' . escapeshellarg($projectRoot) . ' && PROJECT_DIR=' . escapeshellarg($projectRoot) . ' bash ' . escapeshellarg($scriptPath) . ' ' . implode(' ', $cmdArgs) . ' 2>&1';
        $output = @shell_exec($cmd);

        if (!$output) {
            Response::fail($response, '版本检测失败：无法执行检测脚本', Response::CODE_ERROR, 500);
            return false;
        }

        // 解析 JSON 输出（脚本可能输出了一些 warning 信息，需要提取 JSON 部分）
        $jsonStr = '';
        $lines = explode("\n", trim($output));
        $jsonStart = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '{') {
                $jsonStart = true;
            }
            if ($jsonStart) {
                $jsonStr .= $line . "\n";
            }
            if ($line === '}') {
                break;
            }
        }

        if (empty($jsonStr)) {
            // 尝试直接解析整段输出
            $jsonStr = $output;
        }

        $data = json_decode(trim($jsonStr), true);

        if (!is_array($data)) {
            Response::fail($response, '版本检测失败：无法解析检测结果', Response::CODE_ERROR, 500);
            return false;
        }

        // 如果是落后状态，获取变更日志
        $changelog = [];
        if (isset($data['status']) && $data['status'] === 'behind' && !empty($data['remote']['branch'])) {
            $branch = $data['remote']['branch'];
            $logCmd = 'cd ' . escapeshellarg($projectRoot) . ' && git log --oneline "HEAD..origin/' . escapeshellarg($branch) . '" 2>/dev/null | head -n 20';
            $logOutput = @shell_exec($logCmd);
            if ($logOutput) {
                $changelog = array_filter(array_map('trim', explode("\n", trim($logOutput))));
            }
        }

        $data['changelog'] = array_values($changelog);

        return $data;
    }

    /**
     * 一键更新 - 触发服务器端更新流程
     * 路由：POST /admin/settings/system-update
     */
    public function systemUpdate(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $body = $this->parseBody($context);

        // 项目根目录（包含 admin/ 和 backend/ 的目录）
        $projectRoot = dirname(__DIR__, 3);
        $scriptPath = dirname(__DIR__, 2) . '/deploy/update.sh';

        if (!file_exists($scriptPath)) {
            Response::fail($response, '更新脚本不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        // 生成任务 ID
        $taskId = 'upd_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8);

        // 构造更新命令参数
        $cmdArgs = ['--yes'];
        if (!empty($body['ghProxy'])) {
            $cmdArgs[] = '--gh-proxy';
        }
        if (!empty($body['proxy'])) {
            $cmdArgs[] = '--proxy=' . escapeshellarg($body['proxy']);
        }
        if (!empty($body['skipBuild'])) {
            $cmdArgs[] = '--skip-build';
        }
        if (!empty($body['skipMigration'])) {
            $cmdArgs[] = '--skip-migration';
        }

        // 确保 backend/runtime 目录存在（必须在 shell_exec 之前，否则日志文件无法写入）
        $runtimeDir = $projectRoot . '/backend/runtime';
        if (!is_dir($runtimeDir)) {
            @mkdir($runtimeDir, 0755, true);
        }

        // 构建完整命令
        $cmd = sprintf(
            'cd %s && PROJECT_DIR=%s bash %s %s > %s 2>&1 & echo $!',
            escapeshellarg($projectRoot),
            escapeshellarg($projectRoot),
            escapeshellarg($scriptPath),
            implode(' ', $cmdArgs),
            escapeshellarg($projectRoot . '/backend/runtime/update_' . $taskId . '.log')
        );

        $pid = @shell_exec($cmd);

        if (!$pid || trim($pid) === '') {
            Response::fail($response, '启动更新失败：无法创建后台进程', Response::CODE_ERROR, 500);
            return false;
        }

        // 写入初始进度文件
        $progressFile = $projectRoot . '/backend/runtime/update_' . $taskId . '.json';
        $progressData = [
            'task_id'  => $taskId,
            'status'   => 'running',
            'step'     => '启动中',
            'progress' => 0,
            'message'  => '更新已启动',
            'logs'     => ['更新任务已启动，PID: ' . trim($pid)],
            'pid'      => trim($pid),
            'started_at' => date('Y-m-d H:i:s'),
        ];

        @file_put_contents($progressFile, json_encode($progressData, JSON_UNESCAPED_UNICODE));

        return [
            'task_id' => $taskId,
            'message' => '更新已启动，请稍候',
        ];
    }

    /**
     * 查询更新进度
     * 路由：GET /admin/settings/update-progress/{taskId}
     */
    public function getUpdateProgress(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $response = $context['response'];
        $taskId = $params['taskId'] ?? '';

        if (empty($taskId)) {
            Response::fail($response, '缺少任务 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        // 项目根目录（包含 admin/ 和 backend/ 的目录）
        $projectRoot = dirname(__DIR__, 3);
        $progressFile = $projectRoot . '/backend/runtime/update_' . $taskId . '.json';
        $logFile = $projectRoot . '/backend/runtime/update_' . $taskId . '.log';

        // 读取进度文件
        if (!file_exists($progressFile)) {
            Response::fail($response, '更新任务不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $progressData = json_decode(file_get_contents($progressFile), true);
        if (!is_array($progressData)) {
            $progressData = [
                'task_id' => $taskId,
                'status'  => 'unknown',
                'step'    => '',
                'progress' => 0,
                'message' => '',
                'logs'    => [],
            ];
        }

        // 读取日志文件
        $logs = [];
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            if ($logContent) {
                $logLines = explode("\n", trim($logContent));
                $logs = array_slice($logLines, -50); // 最后50行
            }
        }

        // 根据日志内容分析进度
        $logText = implode("\n", $logs);
        $step = $progressData['step'] ?? '';
        $progressNum = $progressData['progress'] ?? 0;
        $status = $progressData['status'] ?? 'running';

        // 分析日志中的步骤标记
        if (strpos($logText, '[1/4]') !== false || strpos($logText, '拉取最新代码') !== false) {
            $step = '[1/4] 拉取最新代码';
            $progressNum = 25;
        }
        if (strpos($logText, '[2/4]') !== false || strpos($logText, '更新依赖') !== false) {
            $step = '[2/4] 更新依赖';
            $progressNum = 50;
        }
        if (strpos($logText, '[3/4]') !== false || strpos($logText, '数据库迁移') !== false) {
            $step = '[3/4] 数据库迁移';
            $progressNum = 75;
        }
        if (strpos($logText, '[4/4]') !== false || strpos($logText, '重启服务') !== false) {
            $step = '[4/4] 重启服务';
            $progressNum = 90;
        }

        // 检查是否完成
        if (strpos($logText, '✓ 更新完成') !== false || strpos($logText, '更新完成') !== false) {
            $status = 'success';
            $step = '完成';
            $progressNum = 100;
        }

        // 检查是否失败
        if (strpos($logText, '更新失败') !== false || strpos($logText, 'ERROR] 更新失败') !== false) {
            $status = 'failed';
            // 尝试提取失败原因
            if (preg_match('/失败发生在步骤:\s*(.+)/', $logText, $m)) {
                $step = '失败: ' . $m[1];
            }
        }

        // 检查进程是否仍在运行
        $pid = $progressData['pid'] ?? '';
        if ($status === 'running' && $pid !== '') {
            $isRunning = @shell_exec("ps -p " . escapeshellarg($pid) . " -o pid= 2>/dev/null");
            if (!$isRunning || trim($isRunning) === '') {
                // 进程已退出但未标记为成功，可能是失败
                if ($status !== 'success') {
                    $status = 'failed';
                    $step = $step ?: '进程已退出';
                    $progressNum = $progressNum ?: 0;
                }
            }
        }

        // 更新进度文件
        $progressData['status'] = $status;
        $progressData['step'] = $step;
        $progressData['progress'] = $progressNum;
        $progressData['logs'] = $logs;
        @file_put_contents($progressFile, json_encode($progressData, JSON_UNESCAPED_UNICODE));

        return [
            'task_id'  => $taskId,
            'status'   => $status,
            'step'     => $step,
            'progress' => $progressNum,
            'message'  => $progressData['message'] ?? '',
            'logs'     => $logs,
        ];
    }

    /**
     * 解析请求体
     */
    private function parseBody(array $context): array
    {
        $body = $context['post'] ?? [];
        if (!empty($body)) {
            return $body;
        }
        $raw = $context['raw'] ?? '';
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}
