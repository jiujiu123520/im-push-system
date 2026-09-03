<?php
/**
 * APNS (iOS 推送) 端到端测试脚本
 *
 * 用途：
 *   1) 环境自检：PHP curl HTTP/2、APNS 配置完整性、JWT 签发
 *   2) 真机测试：传 --token 向真实 iOS 设备发送一条测试推送
 *
 * 使用方法（在项目根目录 backend/ 下执行；.env 仅 www-data 可读，需 sudo）：
 *   sudo php bin/apns_test.php                          # 仅做环境 + 配置自检（不发送推送）
 *   sudo php bin/apns_test.php --self-check             # 同上
 *   sudo php bin/apns_test.php --token=<64字节HEX设备TOKEN>                 # 用默认 title/body 发送
 *   sudo php bin/apns_test.php --token=xxx --title="测试" --body="这是iOS推送"  # 自定义内容
 *   sudo php bin/apns_test.php --token=xxx --bundle-id=com.your.app        # 覆盖配置里的 bundle-id
 *   sudo php bin/apns_test.php --token=xxx --env=production|development    # 强制切换环境（不保存）
 *
 * 退出码：
 *   0  全部检查通过 / 推送发送成功
 *   1  配置缺失或环境异常
 *   2  APNS 实际推送请求失败
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "[FATAL] 请先执行 composer install 安装依赖（缺失 {$autoload}）\n");
    exit(1);
}
require $autoload;

try {
    \App\Service\Config::loadEnv();
} catch (Throwable $e) {
    fwrite(STDERR, "[FATAL] 加载 .env 失败: " . $e->getMessage() . "\n");
    // .env 属主 www-data 且权限 600，普通用户读不到（部署脚本的安全约束）
    $envFile = BASE_PATH . '/.env';
    if (!is_readable($envFile) && function_exists('posix_getuid') && posix_getuid() !== 0) {
        fwrite(STDERR, "[HINT]  .env 仅 www-data 可读（600），请用 sudo 运行：sudo php bin/apns_test.php ...\n");
    }
    exit(1);
}

// ---------- CLI 颜色 ----------
$C = PHP_SAPI === 'cli' && function_exists('posix_isatty') && @posix_isatty(STDOUT)
    ? ['R'=>"\033[31m",'G'=>"\033[32m",'Y'=>"\033[33m",'B'=>"\033[34m",'C'=>"\033[36m",'W'=>"\033[1;37m",'0'=>"\033[0m"]
    : ['R'=>'','G'=>'','Y'=>'','B'=>'','C'=>'','W'=>'','0'=>''];
function println(string $msg, string $color = ''): void {
    global $C;
    echo ($color ? $C[$color] : '') . $msg . $C['0'] . PHP_EOL;
}
function section(string $title): void {
    global $C;
    println("\n{$C['W']}========== {$title} =========={$C['0']}");
}

// ---------- 解析参数 ----------
$options = getopt('', ['token::', 'title::', 'body::', 'bundle-id::', 'env::', 'self-check', 'help', 'verbose']);
$showHelp = isset($options['help']);
if ($showHelp) {
    echo <<<HELP
USAGE:
  php bin/apns_test.php [OPTIONS]

OPTIONS:
  --self-check             (默认) 仅做环境 + 配置 + JWT 签发自检，不发真实推送
  --token=<HEX>            目标 iOS 设备 APNS device token（64 字节 HEX）；提供则发送真实推送
  --title=<STRING>         推送标题（默认：APNS 测试推送）
  --body=<STRING>          推送内容（默认：时间 Y-m-d H:i:s + 成功提示）
  --bundle-id=<STRING>     临时覆盖配置中的 Bundle ID（不写入数据库）
  --env=<production|dev>   临时覆盖 environment：production=api.push.apple.com / development=api.development.push.apple.com
  --verbose                打印完整 HTTP 响应原文和 JWT Header.Payload（仅前 80 字符）
  --help                   显示本帮助

EXAMPLES:
  # 配置环境自检（每次改完 APNS 配置后运行）
  php bin/apns_test.php --self-check

  # 向真机发一条测试推送
  php bin/apns_test.php --token=a1b2c3d4... --title="告警推送测试" --body="服务器 10.0.0.1 CPU 100%"

HELP;
    exit(0);
}

$wantSendPush = isset($options['token']) && $options['token'] !== '';
$token     = (string)($options['token'] ?? '');
$title     = (string)($options['title'] ?? 'APNS 测试推送');
$body      = (string)($options['body']  ?? 'Push System APNS 通道测试成功 ✅ 时间：' . date('Y-m-d H:i:s'));
$overrideBundle = (string)($options['bundle-id'] ?? '');
$overrideEnv    = (string)($options['env'] ?? '');
$verbose        = isset($options['verbose']);

$globalPass = true;
$pushExitCode = 0;

// ================================================================
// STEP 1 : PHP curl HTTP/2 支持检查（APNS 硬性要求）
// ================================================================
section('STEP 1/5 : PHP curl HTTP/2 环境检查');
$curlVer = function_exists('curl_version') ? curl_version() : false;
if (!$curlVer) {
    println('[FAIL] php-curl 扩展未安装或未启用', 'R');
    $globalPass = false;
} else {
    $hasHttp2Const = defined('CURL_VERSION_HTTP2');
    $hasHttp2Feat  = $hasHttp2Const && (($curlVer['features'] & CURL_VERSION_HTTP2) !== 0);
    println('curl 版本       : ' . ($curlVer['version'] ?? 'n/a'));
    println('libcurl 版本    : ' . ($curlVer['version_number'] ?? 'n/a'));
    println('HTTP/2 常量     : ' . ($hasHttp2Const ? '✓ CURL_VERSION_HTTP2 已定义' : '✗ 未定义（PHP < 7.3 或编译 curl 时未带 nghttp2）'));
    println('HTTP/2 支持     : ' . ($hasHttp2Feat ? "{$GLOBALS['C']['G']}✓ 已启用（APNS 可用）{$GLOBALS['C']['0']}" : "{$GLOBALS['C']['R']}✗ 未启用，APNS 会被苹果直接拒绝{$GLOBALS['C']['0']}"));
    if (!$hasHttp2Feat) {
        $globalPass = false;
        println('  → 修复建议：安装 libnghttp2(-dev) 后重装 php-curl；或运行 deploy/setup.sh 选择 2 安装 / 1 系统优化', 'Y');
    }
}

// ================================================================
// STEP 2 : 数据库 & APNS 配置完整性检查
// ================================================================
section('STEP 2/5 : 数据库 & APNS 配置检查');
try {
    $cfg = \App\Service\ApnsService::getConfig();
} catch (Throwable $e) {
    println('[FAIL] 读取 APNS 配置异常: ' . $e->getMessage(), 'R');
    $globalPass = false;
    $cfg = false;
}

if (is_array($cfg)) {
    $enabled = !empty($cfg['enabled']);
    $teamId = (string)($cfg['team_id'] ?? '');
    $keyId  = (string)($cfg['key_id'] ?? '');
    $authKey = (string)($cfg['auth_key'] ?? '');
    $bundleId = (string)($cfg['bundle_id'] ?? '');
    $environment = (string)($cfg['environment'] ?? 'production');

    // 覆盖参数
    if ($overrideBundle !== '') $bundleId = $overrideBundle;
    if ($overrideEnv !== '') {
        if (stripos($overrideEnv, 'dev') === 0) $environment = 'development';
        else $environment = 'production';
    }

    $baseHost = $environment === 'development'
        ? 'api.development.push.apple.com'
        : 'api.push.apple.com';

    $checks = [
        '启用开关'        => [$enabled ? '已启用' : '未启用（ApnsService 发送时仍会推送，但建议显式开启）', true],
        'Team ID (10位)'  => [$teamId,    (bool)preg_match('/^[A-Z0-9]{10}$/', $teamId)],
        'Key ID  (10位)'  => [$keyId,     (bool)preg_match('/^[A-Z0-9]{10}$/', $keyId)],
        '.p8 私钥'        => [$authKey !== '' ? '已配置 (' . strlen($authKey) . '字节)' : '缺失',
                              (bool)preg_match('/-----BEGIN (?:EC |RSA |)PRIVATE KEY-----/', $authKey)],
        'Bundle ID'       => [$bundleId,  (bool)preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z0-9.-]+/', $bundleId)],
        '环境 (endpoint)' => ["{$environment} → {$baseHost}", in_array($environment, ['production','development'], true)],
    ];
    $allOk = true;
    foreach ($checks as $label => [$show, $ok]) {
        $status = $ok ? '✓' : '✗';
        $clr    = $ok ? 'G' : 'R';
        if (!$ok) $allOk = false;
        println(sprintf('  %-16s %s [%-6s] %s', $label, $status, $show, $ok ? '' : ' ← 缺失或格式不对'), $clr);
    }
    if (!$allOk) {
        $globalPass = false;
        println('  → 请在管理后台 → 系统设置 → "iOS APNS 推送配置"卡片中完成配置后再测试', 'Y');
    }
}

// ================================================================
// STEP 3 : JWT 签发验证（不发请求，只验证 .p8 可用 + kid/iss + ES256）
// ================================================================
section('STEP 3/5 : APNS JWT (ES256) 签发验证');
if (!$globalPass || !is_array($cfg) || empty($authKey) || empty($teamId) || empty($keyId)) {
    println('[SKIP] 前置环境/配置未通过，跳过 JWT 签发', 'Y');
    $jwtGenerated = '';
} else {
    // 临时构造给 getJwtToken 用的 config 数组（字段名和 ApnsService 内部一致）
    $testConfig = [
        'team_id'  => $teamId,
        'key_id'   => $keyId,
        'auth_key' => $authKey,
    ];
    try {
        // 清掉可能的 JWT 缓存（Redis Key 与 ApnsService 内部常量一致），保证测试真实生成
        try {
            $redis = \App\Service\Redis::getInstance();
            $redis->del('apns:jwt_token');
        } catch (Throwable $e) { /* Redis 不可用忽略 */ }

        $meth = new ReflectionMethod(\App\Service\ApnsService::class, 'getJwtToken');
        $meth->setAccessible(true);
        $jwtGenerated = $meth->invoke(null, $testConfig);
    } catch (Throwable $e) {
        // 反射失败或 Redis 包装异常，直接手动走 ApnsService::getConfig 的正常流程
        // （ApnsService JWT 缓存在 Redis，即使失败也会重新生成，send 内部会处理）
        $jwtGenerated = '';
        println('[WARN] getJwtToken 反射调用失败: ' . $e->getMessage() . '；改为间接验证', 'Y');
    }

    if ($jwtGenerated === '') {
        // 兜底：直接调用 ApnsService::send 时会生成，这里额外尝试静态构造再次校验
        // 如果反射失败，先让脚本继续，到 STEP 5 真实请求时 ApnsService 会再签发
        println('[WARN] JWT 返回为空（可能 .p8 格式错误 / OpenSSL 未加载 / PHP 缺少 openssl 扩展）', 'Y');
        $globalPass = false;
    } else {
        $parts = explode('.', $jwtGenerated);
        $validFormat = count($parts) === 3
            && self_base64_valid($parts[0])
            && self_base64_valid($parts[1])
            && self_base64_valid($parts[2]);
        $header = @json_decode(self_base64url_decode($parts[0] ?? ''), true);
        $payload = @json_decode(self_base64url_decode($parts[1] ?? ''), true);
        $kidOk = ($header['kid'] ?? null) === $keyId;
        $issOk = ($payload['iss'] ?? null) === $teamId;
        $algOk = ($header['alg'] ?? null) === 'ES256';
        $iatOk = isset($payload['iat']) && is_int($payload['iat']) && abs($payload['iat'] - time()) < 3600;

        println('JWT 长度      : ' . strlen($jwtGenerated) . ' 字符');
        println('三段式格式     : ' . ($validFormat ? "{$GLOBALS['C']['G']}✓ header.payload.signature{$GLOBALS['C']['0']}" : "{$GLOBALS['C']['R']}✗ 非 JWT 结构{$GLOBALS['C']['0']}"));
        println('Header.alg     : ' . ($algOk    ? "✓ ES256" : "✗ 缺失或不是 ES256 → " . ($header['alg'] ?? 'null')) . ($algOk    ? '' : '  ← 苹果强制 ES256'));
        println('Header.kid     : ' . ($kidOk    ? "✓ {$keyId}" : "✗ 与配置 Key ID 不符 → " . ($header['kid'] ?? 'null')));
        println('Payload.iss    : ' . ($issOk    ? "✓ {$teamId}" : "✗ 与配置 Team ID 不符 → " . ($payload['iss'] ?? 'null')));
        println('Payload.iat    : ' . ($iatOk    ? '✓ 签发时间正常 (' . date('Y-m-d H:i:s', $payload['iat']) . ')' : '✗ 签发时间异常（时钟可能漂移）→ ' . ($payload['iat'] ?? 'null')));
        if ($verbose) {
            println('JWT 样本前80   : ' . substr($jwtGenerated, 0, 80) . '...');
        }
        if (!($validFormat && $algOk && $kidOk && $issOk && $iatOk)) {
            $globalPass = false;
            println('[FAIL] JWT 字段不合法，APNS 会返回 403 InvalidProviderToken', 'R');
        } else {
            println('[OK] JWT 签发通过（ES256 / kid / iss / iat 均符合苹果要求）', 'G');
        }
    }
}

// ================================================================
// STEP 4 : 真实设备推送（可选）
// ================================================================
section('STEP 4/5 : 真实 APNS 推送发送（可选）');
if (!$wantSendPush) {
    println('[INFO] 未指定 --token，跳过真实推送。本机校验到此为止 ✔', 'C');
    println('       使用示例: php bin/apns_test.php --token=a1b2c3d4e5f6...', 'C');
} else {
    if (!$globalPass) {
        println('[SKIP] 前面环境 / 配置 / JWT 任一项失败，为避免产生误导结果，不发送真实推送', 'Y');
        println('       可加 --help 查看用法，或先到后台补全 APNS 配置', 'Y');
        $pushExitCode = 1;
    } else {
        // Token 基本形态校验：64 HEX 字符（新 APNs 8 字节 → 64 hex；旧 32 字节 → 64 hex，统一按非空+无空格）
        $tokenClean = preg_replace('/[^0-9a-fA-F]/', '', $token);
        if ($tokenClean === '' || strlen($tokenClean) % 2 !== 0) {
            println('[FAIL] --token 参数不是合法的 HEX 字符串，请粘贴 AppDelegate:didRegisterForRemoteNotifications 里的原始 deviceToken', 'R');
            $pushExitCode = 2;
        } else {
            println("目标 token     : {$tokenClean}");
            println("推送标题        : {$title}");
            println("推送内容        : {$body}");
            println("Bundle ID       : {$bundleId}");
            println("推送环境        : {$environment} ({$baseHost})");
            println();

            // 调用 ApnsService::send（它会自己重新拿配置 + 读 JWT；上面已验证缓存清理过）
            $startMs = microtime(true);
            try {
                $payload = [
                    'source'      => 'apns_test.php (CLI自检)',
                    'run_at'      => date('Y-m-d H:i:s'),
                    'verify_only' => false,
                ];
                $result = \App\Service\ApnsService::send($tokenClean, $title, $body, $payload, 0, 'apns_test:' . substr(md5(uniqid('', true)), 0, 12));
            } catch (Throwable $e) {
                $result = ['success' => false, 'message' => 'ApnsService 异常: ' . $e->getMessage(), 'apns_id' => '', 'http_code' => 0, 'response_body' => ''];
            }
            $elapsed = round((microtime(true) - $startMs) * 1000, 1);

            $success = !empty($result['success']);
            $apnsId = $result['apns_id'] ?? '';
            $msg    = $result['message'] ?? '';
            $httpCode = (int)($result['http_code'] ?? 0);
            $respBody = (string)($result['response_body'] ?? '');
            $statusClr = $success ? 'G' : 'R';
            println("耗时            : {$elapsed} ms");
            println("HTTP 状态码     : " . ($httpCode ? $httpCode : 'N/A'));
            println("apns-id         : " . ($apnsId ?: 'N/A'));
            println("最终结果        : " . ($success ? '✓ 发送成功' : '✗ 发送失败'), $statusClr);
            println("消息原文        : {$msg}");
            if ($verbose && $respBody !== '') {
                println("响应正文        : {$respBody}");
            }

            if (!$success) {
                $pushExitCode = 2;
                // 给出苹果常见错误码对应的修复建议
                $hint = self_apns_hint($httpCode, $respBody . ' ' . $msg);
                if ($hint) println("→ 诊断建议       : {$hint}", 'Y');
            } else {
                println('→ iOS 设备锁屏/通知中心应已出现此推送条；如无任何显示：', 'C');
                println('   ① 检查 Bundle ID 是否与 Xcode 配置一致；', 'C');
                println('   ② 检查设备是否开启了该 APP 的通知权限（设置→通知→对应APP→允许通知）；', 'C');
                println('   ③ 若用了 dev 证书/development 环境，请传 --env=development。', 'C');
            }
        }
    }
}

// ================================================================
// STEP 5 : 汇总
// ================================================================
section('STEP 5/5 : 汇总');
if ($globalPass && $pushExitCode === 0) {
    $hasWarnStep1  = false;
    println("{$C['G']}全部校验通过 ✔{$C['0']}");
    println('  • 若还未在真机运行 iOS APP，请先完成：1) Xcode 填入 Team + Bundle ID；2) App 首次启动允许通知；3) 在 App 中输入 Push Key 连接 WebSocket；4) didRegisterForRemoteNotifications 拿到 token 再用本脚本测试。');
    exit(0);
} else {
    println("{$C['R']}存在未通过项，请按上面 STEP* 提示修复后重试。{$C['0']}");
    exit(max(1, $pushExitCode));
}

// ---------- helpers ----------
function self_base64_valid(string $s): bool {
    return $s !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $s) === 1;
}
function self_base64url_decode(string $s): string {
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $raw = base64_decode(strtr($s, '-_', '+/'), true);
    return $raw === false ? '' : $raw;
}
function self_apns_hint(int $httpCode, string $fullMsg): string {
    $m = strtolower($fullMsg);
    if ($httpCode === 0) return '';
    if ($httpCode === 400) {
        if (strpos($m, 'badcollapseid')  !== false) return 'collapse-id 超过 64 字节或非法字符';
        if (strpos($m, 'badmessageid')   !== false) return 'apns-id 格式非法';
        if (strpos($m, 'badpriority')    !== false) return 'apns-priority 非法（此脚本固定 10）';
        if (strpos($m, 'badtopic')       !== false) return 'apns-topic（Bundle ID）与 .p8 / provisioning 不匹配，请检查后台 Bundle ID 配置或加 --bundle-id 覆盖';
        if (strpos($m, 'missingtopic')   !== false) return '缺少 apns-topic：后台 Bundle ID 为空';
        if (strpos($m, 'invalidpushsize')!== false) return '推送 JSON 超过 4KB 限制';
        if (strpos($m, 'invalid')        !== false && strpos($m, 'payload') !== false) return 'payload JSON 非法，检查 title/body 是否含控制字符';
        return '通用 400：检查 payload JSON / 字段合法性（加 --verbose 打印完整响应）';
    }
    if ($httpCode === 403) {
        if (strpos($m, 'invalidprovidertoken') !== false) return '.p8 / kid / Team ID 任意一项错，或 JWT 签发时钟偏差过大（同步服务器时间 ntpdate -u ntp.aliyun.com）';
        if (strpos($m, 'expiredprovidertoken') !== false) return 'JWT 已过期（超过 60 分钟）—— 此脚本应已清理缓存，如反复出现请检查服务器时钟';
        if (strpos($m, 'forbidden')            !== false) return '.p8 证书已在 Apple Developer 后台被吊销，或该 Key 未启用 APNS 能力';
        return '403：Provider Token 校验失败（team_id / key_id / .p8 / 时钟）';
    }
    if ($httpCode === 404) return '该 device token 不存在于当前环境。常见原因：①生产证书打出来的 token 传到了 development endpoint，或反之（加 --env=development/production 切换）；②用户卸载 APP 后 token 作废';
    if ($httpCode === 405) return '请求方法被拒绝 —— APNS 只接受 POST。检查 ApnsService 是否 CURLOPT_POST=true';
    if ($httpCode === 410) return '该 token 已标记为失效（用户卸载/关闭通知）。建议：检查 devices.apns_active 为 0 或从管理端清除失效 token';
    if ($httpCode === 413) return 'payload 超过 4KB (普通通知) / 5KB (VOIP)。压缩 title/body 或减少自定义 payload 字段';
    if ($httpCode === 429) return 'APNS 限流：同一 token 请求过频。等待 60 秒后重试，或启用 AlertAggregator 告警合并窗口';
    if ($httpCode === 500) return '苹果服务端内部错误，通常几秒后恢复；可重试';
    if ($httpCode === 503) return '苹果 APNS 服务不可用（Service Unavailable）或该 topic 在当前节点不可达，稍后重试';
    if ($httpCode >= 500)   return 'APNS 服务端 5xx，稍后重试';
    return '';
}
