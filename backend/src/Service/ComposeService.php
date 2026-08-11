<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Jetpack Compose Android 源码生成服务
 *
 * 复制项目内置的 app/ 目录（Compose 源码），
 * 注入服务器配置后打包为可下载 ZIP。
 */
class ComposeService
{
    private function projectRoot(): string
    {
        return dirname(BASE_PATH);
    }

    private function log(string $msg, array $ctx = []): void
    {
        $line = '[ComposeService] ' . $msg . ($ctx ? ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) : '');
        @error_log($line . PHP_EOL, 3, BASE_PATH . '/runtime/logs/compose.log');
    }

    public function getAvailableTypes(): array
    {
        $root = $this->projectRoot();
        $appDir = $root . '/app';
        $hasCompose = is_dir($appDir);
        if ($hasCompose) {
            $this->log('getAvailableTypes', [
                'root' => $root,
                'appDir' => $appDir,
                'appDir_exists' => is_dir($appDir),
                'app_files' => $this->countFiles($appDir),
            ]);
        } else {
            $this->log('getAvailableTypes NO TEMPLATE', [
                'root' => $root,
                'appDir' => $appDir,
                'candidates' => glob($root . '/*', GLOB_ONLYDIR),
            ]);
        }

        return [
            [
                'id'          => 'compose',
                'name'        => 'Compose 全新 UI（推荐）',
                'description' => 'Jetpack Compose + Material3 + 玻璃拟态设计 + DataStore + OkHttp WebSocket',
                'available'   => $hasCompose,
                'features'    => [
                    '玻璃拟态深色主题',
                    '权限引导（8 大品牌）',
                    '前台 Service 保活',
                    'DataStore 设置持久化',
                    '自动重连 + 指数退避',
                    '消息分页 + 搜索 + 已读状态',
                ],
            ],
        ];
    }

    /**
     * @param array $params [user_id, app_name, package_name, default_key, server_url, ws_url, version_name, version_code, icon_base64]
     * @return string ZIP 文件绝对路径
     */
    public function generateZip(array $params): string
    {
        $userId      = (int)($params['user_id'] ?? 0);
        $appName     = trim((string)($params['app_name'] ?? 'PushApp'));
        $pkgName     = trim((string)($params['package_name'] ?? 'com.push.app'));
        $defaultKey  = trim((string)($params['default_key'] ?? 'default_key'));
        $serverUrl   = rtrim((string)($params['server_url'] ?? ''), '/');
        $wsUrl       = rtrim((string)($params['ws_url'] ?? ''), '/');
        $versionName = trim((string)($params['version_name'] ?? '1.0.0'));
        $versionCode = (int)($params['version_code'] ?? 1);
        $iconB64     = trim((string)($params['icon_base64'] ?? ''));

        $projectRoot = $this->projectRoot();
        $appSrcDir   = $projectRoot . '/app';

        $this->log('generateZip START', [
            'projectRoot' => $projectRoot,
            'appSrcDir' => $appSrcDir,
            'appSrcDir_exists' => is_dir($appSrcDir),
            'userId' => $userId,
            'pkg' => $pkgName,
        ]);

        if (!is_dir($appSrcDir)) {
            // 尝试 fallback 路径
            $fallbacks = [
                dirname($projectRoot) . '/app',
                BASE_PATH . '/../app',
            ];
            foreach ($fallbacks as $fb) {
                $this->log("trying fallback", ['path' => $fb, 'exists' => is_dir($fb)]);
                if (is_dir($fb)) {
                    $appSrcDir = $fb;
                    break;
                }
            }
        }

        if (!is_dir($appSrcDir)) {
            throw new \RuntimeException(
                'Compose 源码目录不存在。已尝试路径：' . $projectRoot . '/app。' .
                '服务器上是否已 git pull 最新代码？'
            );
        }

        $this->log('template confirmed', [
            'appSrcDir' => $appSrcDir,
            'files' => $this->countFiles($appSrcDir),
            'sample' => array_slice($this->listFiles($appSrcDir), 0, 10),
        ]);

        // 创建临时构建目录
        $tempBase = sys_get_temp_dir() . '/push_compose_build';
        if (!is_dir($tempBase)) {
            @mkdir($tempBase, 0755, true);
        }
        $tempDir = $tempBase . '/compose_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4));
        if (!mkdir($tempDir, 0755, true)) {
            throw new \RuntimeException('创建临时目录失败：' . $tempDir);
        }

        $this->log('temp dir', ['tempDir' => $tempDir]);

        try {
            // 1. 复制 app/ 目录
            $this->copyDir($appSrcDir, $tempDir . '/app');
            $this->log('after copyDir', ['app files in temp' => $this->countFiles($tempDir . '/app')]);

            // 2. 清理构建产物
            $this->rmDir($tempDir . '/app/build');
            $this->rmDir($tempDir . '/app/.gradle');
            $this->rmDir($tempDir . '/app/local.properties');
            $this->rmDir($tempDir . '/app/gradle.properties');
            $this->rmDir($tempDir . '/app/gradlew');
            $this->rmDir($tempDir . '/app/gradlew.bat');
            $this->rmDir($tempDir . '/app/proguard-rules.pro');

            // 3. 注入 build_config.json
            $this->writeBuildConfig($tempDir, $appName, $defaultKey, $serverUrl, $wsUrl);

            // 4. 注入 build.gradle.kts
            $this->injectGradleConfig($tempDir, $pkgName, $versionName, $versionCode);

            // 5. 更新 strings.xml
            $this->injectAppName($tempDir, $appName);

            // 6. 重命名包
            $this->renamePackage($tempDir, $pkgName);

            // 7. 自定义图标
            if ($iconB64 !== '') {
                $this->writeIcon($tempDir, $iconB64);
            }

            // 8. 生成 README
            $this->writeReadme($tempDir, $appName, $serverUrl, $wsUrl, $defaultKey);

            $this->log('before zip', ['total files' => $this->countFiles($tempDir)]);

            // 9. ZIP 打包
            $zipPath = $tempDir . '.zip';
            if (file_exists($zipPath)) @unlink($zipPath);

            $zip = new \ZipArchive();
            $res = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($res !== true) {
                throw new \RuntimeException('无法创建 ZIP 文件，error=' . $res);
            }

            $this->zipDir($zip, $tempDir, basename($tempDir));
            $zip->close();

            $zipSize = filesize($zipPath);
            $this->log('zip created', ['zipPath' => $zipPath, 'zipSize' => $zipSize]);

            if ($zipSize < 1024) {
                throw new \RuntimeException('ZIP 文件异常小（' . $zipSize . ' bytes），模板目录可能为空');
            }

            return $zipPath;
        } catch (\Throwable $e) {
            $this->log('ERROR', ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->rmDir($tempDir);
            throw $e;
        }
    }

    // =================================================================
    // 配置注入方法
    // =================================================================

    private function writeBuildConfig(string $dir, string $appName, string $defaultKey, string $serverUrl, string $wsUrl): void
    {
        $assetsDir = $dir . '/app/src/main/assets';
        if (!is_dir($assetsDir)) @mkdir($assetsDir, 0755, true);

        $config = [
            'app_name'       => $appName,
            'default_key'    => $defaultKey,
            'server_url'     => $serverUrl,
            'server_ws_url'  => $wsUrl,
            'build_time'     => date('Y-m-d H:i:s'),
            'generator'      => 'PushApp Backend',
        ];

        file_put_contents(
            $assetsDir . '/build_config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function injectGradleConfig(string $dir, string $pkgName, string $versionName, int $versionCode): void
    {
        $gradleFile = $dir . '/app/build.gradle.kts';
        if (!is_file($gradleFile)) {
            $this->log('injectGradleConfig SKIP file not found', ['path' => $gradleFile]);
            return;
        }

        $content = (string)file_get_contents($gradleFile);
        $content = preg_replace('/namespace\s*=\s*"[^"]*"/', 'namespace = "' . $pkgName . '"', $content);
        $content = preg_replace('/applicationId\s*=\s*"[^"]*"/', 'applicationId = "' . $pkgName . '"', $content);
        $content = preg_replace('/versionCode\s*=\s*\d+/', 'versionCode = ' . $versionCode, $content);
        $content = preg_replace('/versionName\s*=\s*"[^"]*"/', 'versionName = "' . $versionName . '"', $content);
        file_put_contents($gradleFile, $content);
    }

    private function injectAppName(string $dir, string $appName): void
    {
        $stringsFile = $dir . '/app/src/main/res/values/strings.xml';
        if (!is_file($stringsFile)) return;

        $content = (string)file_get_contents($stringsFile);
        if (preg_match('/<string name="app_name">.*?<\/string>/', $content)) {
            $content = preg_replace(
                '/<string name="app_name">.*?<\/string>/',
                '<string name="app_name">' . htmlspecialchars($appName, ENT_XML1) . '</string>',
                $content
            );
        } else {
            $content = preg_replace(
                '/<resources>/',
                "<resources>\n    <string name=\"app_name\">" . htmlspecialchars($appName, ENT_XML1) . '</string>',
                $content
            );
        }
        file_put_contents($stringsFile, $content);
    }

    private function renamePackage(string $dir, string $newPkg): void
    {
        $javaRoot = $dir . '/app/src/main/java';
        $oldPkg = 'com.push.app';
        $oldPath = $javaRoot . '/com/push/app';
        $newPath = $javaRoot . '/' . str_replace('.', '/', $newPkg);

        if (!is_dir($oldPath)) return;
        if ($oldPath === $newPath) return;

        if (!is_dir(dirname($newPath))) {
            @mkdir(dirname($newPath), 0755, true);
        }
        rename($oldPath, $newPath);

        $parent = dirname($oldPath);
        while ($parent !== $javaRoot && is_dir($parent) && @rmdir($parent)) {
            $parent = dirname($parent);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($newPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'kt') continue;
            $content = (string)file_get_contents($file->getPathname());
            $content = str_replace($oldPkg, $newPkg, $content);
            file_put_contents($file->getPathname(), $content);
        }

        $manifest = $dir . '/app/src/main/AndroidManifest.xml';
        if (is_file($manifest)) {
            $c = (string)file_get_contents($manifest);
            $c = str_replace($oldPkg, $newPkg, $c);
            file_put_contents($manifest, $c);
        }
    }

    private function writeIcon(string $dir, string $iconB64): void
    {
        $iconB64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $iconB64);
        if ($iconB64 === '') return;

        $png = base64_decode($iconB64);
        if ($png === false) return;

        $mipmapDir = $dir . '/app/src/main/res/mipmap-xxxhdpi';
        if (!is_dir($mipmapDir)) @mkdir($mipmapDir, 0755, true);

        file_put_contents($mipmapDir . '/ic_launcher.png', $png);
        file_put_contents($mipmapDir . '/ic_launcher_round.png', $png);
    }

    private function writeReadme(string $dir, string $appName, string $serverUrl, string $wsUrl, string $defaultKey): void
    {
        $content = <<<"MD"
# {$appName} - Jetpack Compose 源码

## 构建说明

### 1. 环境要求
- Android Studio Hedgehog (2023.1.1) 或更新
- JDK 17
- Android SDK 34
- Gradle 8.7

### 2. 导入项目
1. 打开 Android Studio → **File → Open**
2. 选择本项目根目录（包含 `settings.gradle.kts` 的目录）
3. 等待 Gradle Sync 完成（首次可能需要几分钟下载依赖）

### 3. 运行调试
- 连接 Android 设备或启动模拟器
- 点击 Android Studio 的 ▶️ Run 按钮
- 或命令行：`./gradlew assembleDebug`

### 4. 导出 Release APK
```bash
# 生成 debug 签名的 release APK
./gradlew assembleRelease
# 输出路径：app/build/outputs/apk/release/app-release.apk
```

### 5. 配置说明
服务器配置已注入到 `app/src/main/assets/build_config.json`：
- **HTTP 地址**：{$serverUrl}
- **WebSocket 地址**：{$wsUrl}
- **默认 Key**：{$defaultKey}

用户首次打开 APP 时会自动读取这些默认值，也可以在设置页手动修改。

### 6. 功能清单
- ✅ 玻璃拟态深色主题
- ✅ WebSocket 实时推送
- ✅ 前台 Service 保活
- ✅ 自动重连 + 指数退避
- ✅ 8 大品牌权限引导
- ✅ 消息分页 / 搜索 / 已读状态
- ✅ DataStore 设置持久化

### 7. 包名与签名
- 当前包名：见 `app/build.gradle.kts` 的 `applicationId`
- 如需正式发布，请在 Android Studio 中配置签名（Build → Generate Signed Bundle / APK）

---

由 PushApp 后台自动生成
MD;
        file_put_contents($dir . '/README.md', $content);
    }

    // =================================================================
    // 工具方法
    // =================================================================

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) $count++;
        }
        return $count;
    }

    private function listFiles(string $dir): array
    {
        if (!is_dir($dir)) return [];
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace($dir . '/', '', $file->getPathname());
            }
        }
        return $files;
    }

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($src)) return;
        if (!is_dir($dst)) @mkdir($dst, 0755, true);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $target = $dst . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) @mkdir($target, 0755, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function zipDir(\ZipArchive $zip, string $dir, string $relative): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        $fileCount = 0;
        foreach ($iterator as $file) {
            $filePath = $file->getPathname();
            $local = $relative . '/' . substr($filePath, strlen($dir) + 1);
            if ($file->isDir()) {
                $zip->addEmptyDir($local);
            } else {
                $zip->addFile($filePath, $local);
                $fileCount++;
            }
        }
        $this->log('zipDir done', ['files_added' => $fileCount, 'relative' => $relative]);
    }
}
