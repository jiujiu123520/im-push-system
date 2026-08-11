<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Jetpack Compose Android 源码生成服务
 *
 * 复制项目内置的 app/ 目录（Compose 源码） + 根级 Gradle 配置，
 * 注入服务器配置后打包为可下载 ZIP。
 * 用户导入 Android Studio → Gradle Sync → Build APK。
 */
class ComposeService
{
    /**
     * 项目根目录
     */
    private function projectRoot(): string
    {
        return dirname(BASE_PATH);
    }

    /**
     * 可用源码类型
     */
    public function getAvailableTypes(): array
    {
        $root = $this->projectRoot();
        $appDir = $root . '/app';
        $hasCompose = is_dir($appDir) && is_file($appDir . '/build.gradle.kts');

        return [
            [
                'id'          => 'compose',
                'name'        => 'Compose 全新 UI（推荐）',
                'description' => 'Jetpack Compose + Material3 + 玻璃拟态设计 + DataStore + OkHttp WebSocket',
                'available'   => $hasCompose,
                'features'    => [
                    '玻璃拟态深色主题',
                    '权限引导（8 大品牌）',
                    '前台 Service + WakeLock 保活',
                    'DataStore 设置持久化',
                    '自动重连 + 指数退避',
                    '消息分页 + 搜索 + 已读状态',
                ],
            ],
        ];
    }

    /**
     * 生成临时打包目录并返回 ZIP 文件路径
     *
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

        if (!is_dir($appSrcDir)) {
            throw new \RuntimeException('Compose 源码目录不存在：app/');
        }

        // 创建临时构建目录（放在项目 .deploy 下避免权限问题）
        $tempBase = $projectRoot . '/.deploy/compose_build';
        if (!is_dir($tempBase)) {
            @mkdir($tempBase, 0755, true);
        }
        $tempDir = $tempBase . '/compose_' . $userId . '_' . time();
        if (is_dir($tempDir)) {
            $this->rmDir($tempDir);
        }
        if (!mkdir($tempDir, 0755, true)) {
            throw new \RuntimeException('创建临时目录失败：' . $tempDir);
        }

        try {
            // 1. 复制根级 Gradle 配置文件
            foreach (['build.gradle.kts', 'settings.gradle.kts', 'gradle.properties'] as $file) {
                $src = $projectRoot . '/' . $file;
                if (is_file($src)) {
                    copy($src, $tempDir . '/' . $file);
                }
            }

            // 2. 复制 gradle wrapper（如有）
            $wrapperDir = $projectRoot . '/gradle/wrapper';
            if (is_dir($wrapperDir)) {
                $dstWrapper = $tempDir . '/gradle/wrapper';
                @mkdir($dstWrapper, 0755, true);
                foreach (glob($wrapperDir . '/*') as $f) {
                    copy($f, $dstWrapper . '/' . basename($f));
                }
            }
            foreach (['gradlew', 'gradlew.bat'] as $gwrap) {
                $src = $projectRoot . '/' . $gwrap;
                if (is_file($src)) copy($src, $tempDir . '/' . $gwrap);
            }

            // 3. 复制 app/ 目录
            $this->copyDir($appSrcDir, $tempDir . '/app');

            // 4. 清理构建产物（build/、.gradle/）
            $this->rmDir($tempDir . '/app/build');
            $this->rmDir($tempDir . '/app/.gradle');

            // 5. 注入 build_config.json（运行时配置）
            $this->writeBuildConfig($tempDir, $appName, $defaultKey, $serverUrl, $wsUrl);

            // 6. 注入 build.gradle.kts（applicationId, version, namespace）
            $this->injectGradleConfig($tempDir, $pkgName, $versionName, $versionCode);

            // 7. 更新 strings.xml app_name
            $this->injectAppName($tempDir, $appName);

            // 8. 如果包名变了，重命名源码目录 + 更新 package 声明
            $this->renamePackage($tempDir, $pkgName);

            // 9. 自定义图标
            if ($iconB64 !== '') {
                $this->writeIcon($tempDir, $iconB64);
            }

            // 10. 生成 README
            $this->writeReadme($tempDir, $appName, $serverUrl, $wsUrl, $defaultKey);

            // 11. ZIP 打包
            $zipPath = $tempDir . '.zip';
            if (file_exists($zipPath)) @unlink($zipPath);
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('无法创建 ZIP 文件');
            }
            $this->zipDir($zip, $tempDir, basename($tempDir));
            $zip->close();

            return $zipPath;
        } catch (\Throwable $e) {
            // 出错清理临时目录
            $this->rmDir($tempDir);
            throw $e;
        } finally {
            register_shutdown_function(function () use ($tempDir) {
                $this->rmDir($tempDir);
            });
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
            'app_name'     => $appName,
            'default_key'  => $defaultKey,
            'server_url'   => $serverUrl,
            'server_ws_url' => $wsUrl,
            'build_time'   => date('Y-m-d H:i:s'),
            'generator'    => 'PushApp Backend',
        ];

        file_put_contents(
            $assetsDir . '/build_config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function injectGradleConfig(string $dir, string $pkgName, string $versionName, int $versionCode): void
    {
        // app/build.gradle.kts
        $gradleFile = $dir . '/app/build.gradle.kts';
        if (!is_file($gradleFile)) return;

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
            // 没有 app_name 就追加
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

        // 移动目录
        if (!is_dir(dirname($newPath))) {
            @mkdir(dirname($newPath), 0755, true);
        }
        rename($oldPath, $newPath);

        // 清理空父目录
        $parent = dirname($oldPath);
        while ($parent !== $javaRoot && is_dir($parent) && @rmdir($parent)) {
            $parent = dirname($parent);
        }

        // 更新所有 .kt 文件中的 package 声明和 import
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($newPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'kt') continue;
            $content = (string)file_get_contents($file->getPathname());
            $content = str_replace($oldPkg, $newPkg, $content);
            file_put_contents($file->getPathname(), $content);
        }

        // 更新 AndroidManifest
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
- ✅ 前台 Service + WakeLock 保活
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
    // 工具方法（与 HBuilderXService 保持一致）
    // =================================================================

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
        foreach ($iterator as $file) {
            $filePath = $file->getPathname();
            $local = $relative . '/' . substr($filePath, strlen($dir) + 1);
            if ($file->isDir()) {
                $zip->addEmptyDir($local);
            } else {
                $zip->addFile($filePath, $local);
            }
        }
    }
}
