<?php
declare(strict_types=1);

namespace App\Service;

/**
 * HBuilderX uni-app 项目生成服务
 *
 * 根据用户输入的 app_name / package_id / api_base_url / ws_url / icon_base64，
 * 从项目内置的模板目录（build/hbuilderx/ 或 build/hbuilderx-old/）生成可下载的 ZIP。
 *
 * 模板目录结构：
 *   - build/hbuilderx/      → 新版模板（改进 UI：搜索、美化设置、提示适配）
 *   - build/hbuilderx-old/  → 旧版模板（兼容旧版 APP 源码）
 */
class HBuilderXService
{
    /**
     * 项目根目录（build/ 所在层级）
     */
    private function projectRoot(): string
    {
        return dirname(BASE_PATH);
    }

    /**
     * 返回模板目录路径
     *
     * @param string $template 模板类型：new（新版）/ old（旧版），默认 new
     * @return string 模板目录绝对路径（不保证存在）
     */
    public function getTemplateDir(string $template = 'new'): string
    {
        $root = $this->projectRoot();
        if ($template === 'old') {
            return $root . '/build/hbuilderx-old';
        }
        return $root . '/build/hbuilderx';
    }

    /**
     * 获取可用模板列表
     */
    public function getAvailableTemplates(): array
    {
        $newDir = $this->getTemplateDir('new');
        $oldDir = $this->getTemplateDir('old');

        return [
            [
                'id'          => 'new',
                'name'        => '新版模板',
                'description' => '推荐使用，改进 UI：消息模糊搜索、设置组件美化、提示适配、无限滚动优化',
                'available'   => is_dir($newDir) && $this->dirHasFiles($newDir),
            ],
            [
                'id'          => 'old',
                'name'        => '旧版模板',
                'description' => '兼容旧版 APP 源码，适合已使用旧版模板的用户',
                'available'   => is_dir($oldDir) && $this->dirHasFiles($oldDir),
            ],
        ];
    }

    /**
     * 生成临时打包目录并返回 ZIP 文件路径
     *
     * @param array $params [user_id, app_name, package_id, api_base_url, ws_url, icon_base64, template]
     * @return string ZIP 文件绝对路径
     */
    public function generateZip(array $params): string
    {
        $userId      = (int)($params['user_id'] ?? 0);
        $appName     = trim((string)($params['app_name'] ?? 'Push 推送'));
        $pkgId       = trim((string)($params['package_id'] ?? 'com.example.push'));
        $apiBase     = rtrim((string)($params['api_base_url'] ?? ''), '/');
        $wsUrl       = rtrim((string)($params['ws_url'] ?? ''), '/');
        $versionName = trim((string)($params['version_name'] ?? '1.0.0'));
        $versionCode = (int)($params['version_code'] ?? 1);

        // 如果只填了 api_base_url 没填 ws_url，自动从 api_base_url 推导
        // https://push.example.com → wss://push.example.com/ws
        // http://push.example.com  → ws://push.example.com/ws
        if ($wsUrl === '' && $apiBase !== '') {
            $wsUrl = preg_replace('#^https?://#i', '', $apiBase);
            if (strpos($apiBase, 'https://') === 0) {
                $wsUrl = 'wss://' . $wsUrl . '/ws';
            } else {
                $wsUrl = 'ws://' . $wsUrl . '/ws';
            }
        }
        $defaultKey = trim((string)($params['default_key'] ?? ''));
        $iconB64   = trim((string)($params['icon_base64'] ?? ''));
        $template  = trim((string)($params['template'] ?? 'new'));
        if (!in_array($template, ['new', 'old'], true)) $template = 'new';

        $tmpDir = sys_get_temp_dir() . '/hbx-' . $pkgId . '-' . $userId . '-' . time();
        if (is_dir($tmpDir)) {
            $this->rmDir($tmpDir);
        }
        @mkdir($tmpDir, 0755, true);

        $templateDir = $this->getTemplateDir($template);
        $hasTemplate = is_dir($templateDir) && $this->dirHasFiles($templateDir);

        if ($hasTemplate) {
            // 有真实模板：复制模板文件，只注入动态配置
            $this->copyDir($templateDir, $tmpDir);
            $this->injectManifest($tmpDir, $appName, $pkgId, $versionName, $versionCode);
            $this->writeConfig($tmpDir, $appName, $defaultKey, $apiBase, $wsUrl, $versionName);
        } else {
            // 无模板：走最小脚手架兜底
            $this->scaffoldMinimalTemplate($tmpDir);
            $this->writeManifest($tmpDir, $appName, $pkgId, $versionName, $versionCode);
            $this->writePagesJson($tmpDir);
            $this->writeMainJs($tmpDir);
            $this->writeAppVue($tmpDir, $appName);
            $this->writeConfig($tmpDir, $appName, $defaultKey, $apiBase, $wsUrl, $versionName);
            $this->writeIndexHtml($tmpDir, $appName);
            $this->writePageScaffolds($tmpDir);
        }

        if ($iconB64 !== '') {
            $this->writeIcon($tmpDir, $iconB64);
        }

        // 生成 README
        $this->writeReadme($tmpDir, $appName, $template);

        // ZIP 打包
        $zipPath = $tmpDir . '.zip';
        if (file_exists($zipPath)) @unlink($zipPath);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('无法创建 ZIP 文件');
        }
        $this->zipDir($zip, $tmpDir, basename($tmpDir));
        $zip->close();

        // 清理临时目录
        register_shutdown_function(function () use ($tmpDir) {
            $this->rmDir($tmpDir);
        });

        return $zipPath;
    }

    /**
     * 向已有模板的 manifest.json 注入动态参数（app_name, package_id）
     * 保留模板原有的权限、图标、模块等配置
     */
    private function injectManifest(string $dir, string $appName, string $pkgId, string $versionName, int $versionCode): void
    {
        $path = $dir . '/manifest.json';
        if (!file_exists($path)) {
            $this->writeManifest($dir, $appName, $pkgId, $versionName, $versionCode);
            return;
        }

        $json = file_get_contents($path);
        $arr = json_decode($json, true);
        if (!is_array($arr)) {
            $this->writeManifest($dir, $appName, $pkgId, $versionName, $versionCode);
            return;
        }

        // 只修改动态字段，保留模板原有配置
        $arr['name'] = $appName;
        $arr['appid'] = '__UNI__' . substr(md5($pkgId . '|' . $appName), 0, 7);
        $arr['description'] = $appName . ' - 即时消息推送客户端';
        $arr['versionName'] = $versionName;
        $arr['versionCode'] = (string)$versionCode;

        // 注入 package_id
        if (isset($arr['app-plus']['distribute']['android'])) {
            $arr['app-plus']['distribute']['android']['package'] = $pkgId;
        }
        if (isset($arr['app-plus']['distribute']['ios'])) {
            $arr['app-plus']['distribute']['ios']['bundleid'] = $pkgId;
        }

        file_put_contents($path, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * 重写根目录 config.js（App.vue 从 ./config.js 读取）
     */
    private function writeConfig(string $dir, string $appName, string $defaultKey, string $apiBase, string $wsUrl, string $versionName): void
    {
        $appName  = str_replace("'", "\\'", $appName ?: 'PushApp');
        $defaultKey = str_replace("'", "\\'", $defaultKey);
        $apiBase  = str_replace("'", "\\'", $apiBase);
        $wsUrl    = str_replace("'", "\\'", $wsUrl);
        $versionName = str_replace("'", "\\'", $versionName ?: '1.0.0');
        $buildTime = date('Y-m-d H:i:s');
        $code = <<<"JS"
// 服务器配置 — 由 PushApp Backend 自动注入
// 不要手动修改，由后台 APP 构建时生成
export const APP_CONFIG = {
    app_name: '{$appName}',
    default_key: '{$defaultKey}',
    server_url: '{$apiBase}',
    ws_url: '{$wsUrl}',
    version_name: '{$versionName}',
    build_time: '{$buildTime}',
    generator: 'PushApp Backend'
};
JS;
        file_put_contents($dir . '/config.js', $code);
    }

    /**
     * 生成 README 打包说明
     */
    private function writeReadme(string $dir, string $appName, string $template): void
    {
        $templateName = $template === 'old' ? '旧版' : '新版';
        $readme = <<<"TXT"
{$appName} - HBuilderX 源码包（{$templateName}模板）
========================================

使用说明：
1. 下载并安装 HBuilderX（https://www.dcloud.io/hbuilderx.html）
2. 打开 HBuilderX → 文件 → 导入 → 从本地导入 → 选择本目录
3. 打开 manifest.json 配置应用信息（图标、版本等已预填）
4. 发行 → 原生App-云打包（选择 Android/iOS）
5. 等待打包完成后下载 APK/IPA

配置信息：
- 服务器地址和 WebSocket 地址已写入 static/config.js
- 如需修改，编辑 static/config.js 中的 APP_CONFIG

注意：
- 需要 HBuilderX 3.6.0+
- 云打包需要 DCloud 账号
- iOS 打包需要 Apple Developer 账号
TXT;
        file_put_contents($dir . '/README.txt', $readme);
    }

    // ---------- 兜底方法（无模板时使用） ----------

    private function writeManifest(string $dir, string $appName, string $pkgId, string $versionName, int $versionCode): void
    {
        $path = $dir . '/manifest.json';
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
        $arr = [
            'name'         => $appName,
            'appid'        => '__UNI__' . substr(md5($pkgId . '|' . $appName), 0, 7),
            'description'  => $appName . ' - 即时消息推送客户端',
            'versionName'  => $versionName,
            'versionCode'  => (string)$versionCode,
            'transformPx'  => false,
            'app-plus'     => [
                'usingComponents' => true,
                'nvueStyleCompiler' => 'uni-app',
                'compilerVersion' => 3,
                'splashscreen' => ['alwaysShowBeforeRender' => true, 'waiting' => true, 'autoclose' => true, 'delay' => 0],
                'modules' => [
                    'Push' => ['description' => '推送消息'],
                ],
                'distribute' => [
                    'android' => ['permissions' => ['<uses-permission android:name="android.permission.INTERNET"/>'], 'package' => $pkgId],
                    'ios'     => ['dSYMs' => false, 'bundleid' => $pkgId],
                ],
            ],
            'quickapp'    => [],
            'mp-weixin'   => ['appid' => '', 'setting' => ['urlCheck' => false]],
            'h5'          => ['router' => ['base' => './'], 'title' => $appName],
        ];
        file_put_contents($path, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writePagesJson(string $dir): void
    {
        $path = $dir . '/pages.json';
        $arr = [
            'pages' => [
                ['path' => 'pages/index/index',  'style' => ['navigationBarTitleText' => '消息推送', 'navigationBarBackgroundColor' => '#667eea', 'navigationBarTextStyle' => 'white']],
                ['path' => 'pages/home/index',   'style' => ['navigationBarTitleText' => '消息推送', 'navigationBarBackgroundColor' => '#667eea', 'navigationBarTextStyle' => 'white', 'enablePullDownRefresh' => true]],
            ],
            'globalStyle' => [
                'navigationBarTextStyle' => 'black',
                'navigationBarTitleText' => '消息推送',
                'navigationBarBackgroundColor' => '#F8F8F8',
                'backgroundColor' => '#F8F8F8',
            ],
        ];
        file_put_contents($path, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writeMainJs(string $dir): void
    {
        $code = <<<'JS'
import { createSSRApp } from 'vue'
import App from './App.vue'

export function createApp() {
    const app = createSSRApp(App)
    return { app }
}
JS;
        file_put_contents($dir . '/main.js', $code);
    }

    private function writeAppVue(string $dir, string $appName): void
    {
        $code = <<<"VUE"
<template><App /></template>
<script>
import App from './App.vue'
export default { components: { App } }
</script>
VUE;
        file_put_contents($dir . '/App.vue', $code);
    }

    private function writeIndexHtml(string $dir, string $appName): void
    {
        $html = <<<"HTML"
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no" />
<title>{$appName}</title>
</head>
<body>
  <div id="app"><!--app-html--></div>
</body>
</html>
HTML;
        file_put_contents($dir . '/index.html', $html);
    }

    private function writePageScaffolds(string $dir): void
    {
        // 兜底：生成最小登录页
        $loginDir = $dir . '/pages/index';
        if (!is_dir($loginDir)) @mkdir($loginDir, 0755, true);
        $login = <<<"VUE"
<template>
  <view class="container">
    <view class="login-page">
      <view class="login-container">
        <view class="logo-section">
          <text class="logo-text">📨</text>
          <text class="app-title">消息推送</text>
        </view>
        <view class="login-form">
          <view class="form-group">
            <text class="form-label">推送 Key</text>
            <input class="form-input" v-model="form.key" placeholder="请输入推送 Key" />
          </view>
          <view class="form-group">
            <text class="form-label">服务器地址</text>
            <input class="form-input" v-model="form.serverUrl" placeholder="http://example.com" />
          </view>
          <button class="btn-primary" @click="handleLogin">进入应用</button>
        </view>
      </view>
    </view>
  </view>
</template>
<script>
import { APP_CONFIG } from '@/config.js'
export default {
  data() { return { form: { key: '', serverUrl: '' } } },
  onLoad() {
    const savedKey = uni.getStorageSync('push_key')
    const savedServer = uni.getStorageSync('push_server')
    if (savedKey && savedServer) { uni.redirectTo({ url: '/pages/home/index' }); return }
    this.form.key = APP_CONFIG.default_key
    this.form.serverUrl = APP_CONFIG.server_url
  },
  methods: {
    handleLogin() {
      if (!this.form.key.trim()) { uni.showToast({ title: '请输入推送 Key', icon: 'none', duration: 2500 }); return }
      if (!this.form.serverUrl.trim()) { uni.showToast({ title: '请输入服务器地址', icon: 'none', duration: 2500 }); return }
      uni.setStorageSync('push_key', this.form.key.trim())
      uni.setStorageSync('push_server', this.form.serverUrl.trim())
      uni.redirectTo({ url: '/pages/home/index' })
    }
  }
}
</script>
VUE;
        file_put_contents($loginDir . '/index.vue', $login);
    }

    private function writeIcon(string $dir, string $iconB64): void
    {
        if (str_starts_with($iconB64, 'data:image')) {
            $iconB64 = preg_replace('#^data:image/[a-zA-Z0-9+.-]+;base64,#', '', $iconB64);
        }
        $data = base64_decode((string)$iconB64, true);
        if ($data === false || strlen($data) < 100) {
            throw new \InvalidArgumentException('icon_base64 解码失败');
        }
        $static = $dir . '/static';
        if (!is_dir($static)) @mkdir($static, 0755, true);
        file_put_contents($static . '/logo.png', $data);
    }

    // ---------- 工具 ----------

    private function scaffoldMinimalTemplate(string $dir): void
    {
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @mkdir($dir . '/uni_modules', 0755, true);
        file_put_contents(
            $dir . '/package.json',
            json_encode(['name' => 'push-console-user', 'version' => '1.0.0', 'private' => true], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function dirHasFiles(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        $files = @scandir($dir);
        if (!is_array($files)) return false;
        return count(array_diff($files, ['.', '..'])) > 0;
    }

    private function copyDir(string $src, string $dst): void
    {
        if (!is_dir($src)) return;
        if (!is_dir($dst)) @mkdir($dst, 0755, true);
        $items = scandir($src);
        if (!is_array($items)) return;
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $s = $src . '/' . $it;
            $d = $dst . '/' . $it;
            if (is_dir($s)) {
                $this->copyDir($s, $d);
            } else {
                copy($s, $d);
            }
        }
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        if (!is_array($items)) return;
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $p = $dir . '/' . $it;
            is_dir($p) ? $this->rmDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function zipDir(\ZipArchive $zip, string $dir, string $relative): void
    {
        $items = scandir($dir);
        if (!is_array($items)) return;
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $abs = $dir . '/' . $it;
            $rel = $relative . '/' . $it;
            if (is_dir($abs)) {
                $zip->addEmptyDir($rel);
                $this->zipDir($zip, $abs, $rel);
            } else {
                $zip->addFile($abs, $rel);
            }
        }
    }
}
