<?php
declare(strict_types=1);

namespace App\Service;

/**
 * uni-app 玻璃拟态全新 UI 源码生成服务
 *
 * 复制项目内置的 build/hbuilderx-glass/ 模板，
 * 注入服务器配置后打包为 HBuilderX 可导入的 ZIP。
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
        $tplDir = $root . '/build/hbuilderx-glass';
        $hasTpl = is_dir($tplDir);

        $this->log('getAvailableTypes', [
            'root' => $root,
            'tplDir' => $tplDir,
            'exists' => $hasTpl,
            'files' => $hasTpl ? $this->countFiles($tplDir) : 0,
        ]);

        return [
            [
                'id'          => 'glass',
                'name'        => '玻璃拟态全新 UI',
                'description' => 'uni-app + Vue 3，HBuilderX 云打包 / 本地打包均可，深色玻璃拟态主题 + 多页面完整功能',
                'available'   => $hasTpl,
                'features'    => [
                    '玻璃拟态深色主题',
                    '6 个完整页面（登录/Key/主页/消息/个人中心/设置）',
                    'WebSocket 实时推送 + 指数退避重连',
                    'WakeLock 原生保活',
                    '8 大品牌权限引导（小米/OPPO/vivo/华为/荣耀…）',
                    '消息搜索 / 筛选 / 已读状态',
                ],
                'builder'     => 'HBuilderX',
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
        $tplSrcDir   = $projectRoot . '/build/hbuilderx-glass';

        $this->log('generateZip START', [
            'projectRoot' => $projectRoot,
            'tplSrcDir' => $tplSrcDir,
            'exists' => is_dir($tplSrcDir),
            'userId' => $userId,
            'pkg' => $pkgName,
        ]);

        if (!is_dir($tplSrcDir)) {
            $fallbacks = [
                dirname($projectRoot) . '/build/hbuilderx-glass',
                BASE_PATH . '/../build/hbuilderx-glass',
            ];
            foreach ($fallbacks as $fb) {
                $this->log('trying fallback', ['path' => $fb, 'exists' => is_dir($fb)]);
                if (is_dir($fb)) { $tplSrcDir = $fb; break; }
            }
        }

        if (!is_dir($tplSrcDir)) {
            throw new \RuntimeException(
                'uni-app 玻璃拟态模板不存在。已尝试路径：' . $projectRoot . '/build/hbuilderx-glass。' .
                '服务器上是否已 git pull 最新代码？'
            );
        }

        $this->log('template confirmed', [
            'tplSrcDir' => $tplSrcDir,
            'files' => $this->countFiles($tplSrcDir),
        ]);

        $tempBase = sys_get_temp_dir() . '/push_glass_build';
        if (!is_dir($tempBase)) @mkdir($tempBase, 0755, true);
        $tempDir = $tempBase . '/glass_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4));
        if (!mkdir($tempDir, 0755, true)) {
            throw new \RuntimeException('创建临时目录失败：' . $tempDir);
        }

        try {
            $this->copyDir($tplSrcDir, $tempDir);
            $this->log('after copyDir', ['files' => $this->countFiles($tempDir)]);

            // 1. 注入 config.js（服务器地址 / WS / Key）
            $this->injectConfigJs($tempDir, $appName, $defaultKey, $serverUrl, $wsUrl, $versionName);

            // 2. 注入 manifest.json（APP 名 / 版本 / 包名）
            $this->injectManifest($tempDir, $appName, $pkgName, $versionName, $versionCode);

            // 3. 自定义图标 → 替换 static/logo.png
            if ($iconB64 !== '') {
                $this->writeIcon($tempDir, $iconB64);
            }

            // 4. 生成 README（HBuilderX 打包说明）
            $this->writeReadme($tempDir, $appName, $serverUrl, $wsUrl, $defaultKey);

            $this->log('before zip', ['total files' => $this->countFiles($tempDir)]);

            $zipPath = $tempDir . '.zip';
            if (file_exists($zipPath)) @unlink($zipPath);

            $zip = new \ZipArchive();
            $res = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            if ($res !== true) {
                throw new \RuntimeException('无法创建 ZIP 文件，error=' . $res);
            }

            $this->zipDir($zip, $tempDir, 'hbuilderx-glass');
            $zip->close();

            $zipSize = filesize($zipPath);
            $this->log('zip created', ['zipPath' => $zipPath, 'zipSize' => $zipSize]);

            if ($zipSize < 1024) {
                throw new \RuntimeException('ZIP 文件异常小（' . $zipSize . ' bytes）');
            }

            return $zipPath;
        } catch (\Throwable $e) {
            $this->log('ERROR', ['msg' => $e->getMessage()]);
            $this->rmDir($tempDir);
            throw $e;
        }
    }

    private function injectConfigJs(string $dir, string $appName, string $defaultKey, string $serverUrl, string $wsUrl, string $versionName): void
    {
        $file = $dir . '/config.js';
        $content = <<<"JS"
// 服务器配置 — 由 PushApp Backend 自动注入
export const APP_CONFIG = {
    app_name: {$this->jsString($appName)},
    default_key: {$this->jsString($defaultKey)},
    server_url: {$this->jsString($serverUrl)},
    ws_url: {$this->jsString($wsUrl)},
    version_name: {$this->jsString($versionName)},
    build_time: {$this->jsString(date('Y-m-d H:i:s'))},
    generator: 'PushApp Backend (uni-app glass)'
};
JS;
        file_put_contents($file, $content);
        $this->log('injectConfigJs done', ['size' => strlen($content)]);
    }

    private function injectManifest(string $dir, string $appName, string $pkgName, string $versionName, int $versionCode): void
    {
        $file = $dir . '/manifest.json';
        if (!is_file($file)) { $this->log('injectManifest SKIP'); return; }
        $c = (string)file_get_contents($file);
        $c = preg_replace('/"name"\s*:\s*"[^"]*"/', '"name": ' . json_encode($appName, JSON_UNESCAPED_UNICODE), $c);
        $c = preg_replace('/"versionName"\s*:\s*"[^"]*"/', '"versionName": ' . json_encode($versionName, JSON_UNESCAPED_UNICODE), $c);
        $c = preg_replace('/"versionCode"\s*:\s*"\d*"/', '"versionCode": ' . json_encode((string)$versionCode), $c);
        file_put_contents($file, $c);
        $this->log('injectManifest done', ['app_name' => $appName, 'version' => $versionName]);
    }

    private function writeIcon(string $dir, string $iconB64): void
    {
        $iconB64 = preg_replace('/^data:image\/[a-z]+;base64,/i', '', $iconB64);
        if ($iconB64 === '') return;
        $png = base64_decode($iconB64);
        if ($png === false) return;
        $target = $dir . '/static/logo.png';
        file_put_contents($target, $png);
        @copy($target, $dir . '/static/logo.jpg');
    }

    private function writeReadme(string $dir, string $appName, string $serverUrl, string $wsUrl, string $defaultKey): void
    {
        $content = <<<"MD"
# {$appName} - uni-app 玻璃拟态全新 UI

## 快速打包（HBuilderX）

### 1. 解压 ZIP 得到 `hbuilderx-glass/` 目录

### 2. 用 HBuilderX 打开
- 菜单：**文件 → 打开目录** → 选择解压后的目录
- 等待 HBuilderX 识别 uni-app 项目（识别后左侧会出现 pages、manifest.json）

### 3. 修改 APP 信息
- 打开 `manifest.json`，在可视化界面修改：
  - **App 名称**
  - **AppID**（选"重新获取"或填入自己的 dcloud AppID）
  - **版本号 / 版本名称**
  - **应用图标**（替换 static/logo.png，建议 1024×1024）

### 4. 打包 APK
- **云打包（推荐）**：菜单 → 发行 → 原生 App-云打包
  - 平台：Android
  - 证书：使用 DCloud 公用证书（测试用）或自有证书（正式发布）
  - 完成后会得到 APK 下载链接
- **本地打包**：需要 Android Studio 环境，菜单 → 发行 → 原生 App-本地打包 → 生成本地打包 App 资源 → 导入 Android Studio 编译

### 5. 服务器配置说明
已注入到 `config.js`：
- **HTTP API**：{$serverUrl}
- **WebSocket**：{$wsUrl}
- **默认 Key**：{$defaultKey}

APP 首次打开会自动读取这些默认值，用户也可以在"设置 → 服务器配置"里修改。

### 6. 功能清单
- ✅ 玻璃拟态深色主题（渐变背景 + 半透明卡片 + 模糊）
- ✅ 6 个页面：登录 / Key 输入 / 主页 / 消息列表 / 个人中心 / 设置
- ✅ WebSocket 实时推送（30s 心跳 + 指数退避重连）
- ✅ WakeLock 原生保活（防 CPU 休眠）
- ✅ 8 大品牌权限引导（小米/OPPO/vivo/华为/荣耀/三星 等）
- ✅ 消息搜索 + 筛选 + 已读状态 + 本地持久化
- ✅ uni-app 原生通知（Android）

### 7. 目录结构
```
hbuilderx-glass/
├── App.vue            # 应用入口
├── main.js            # Vue 3 入口
├── config.js          # 服务器配置（后端注入）
├── manifest.json      # uni-app 项目配置
├── pages.json         # 页面路由 + TabBar
├── uni.scss           # SCSS 变量
├── css/glass.css      # 玻璃拟态主题
├── js/
│   ├── ws.js          # WebSocket 封装
│   ├── storage.js     # 本地存储
│   ├── api.js         # HTTP API
│   ├── notify.js      # 原生通知
│   ├── keepalive.js   # WakeLock 保活
│   └── permissions.js # 权限引导
├── pages/             # 6 个页面
└── static/            # logo.png
```

---

由 PushApp 后台自动生成（uni-app 玻璃拟态模板）
MD;
        file_put_contents($dir . '/README.md', $content);
    }

    private function jsString(string $s): string
    {
        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $s) . '"';
    }

    private function countFiles(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) { if ($file->isFile()) $count++; }
        return $count;
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
        $this->log('zipDir done', ['files_added' => $fileCount]);
    }
}
