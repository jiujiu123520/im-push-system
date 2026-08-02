<?php
declare(strict_types=1);

namespace App\Service;

/**
 * HBuilderX uni-app 项目生成服务
 *
 * 根据用户输入的 app_name / package_id / api_base_url / ws_url / icon_base64，
 * 从后端内置的模板（backend/storage/hbuilderx-template/）生成可下载的 ZIP。
 *
 * 如果模板目录不存在，则生成一个最小可运行模板（pages.json/manifest.json/main/App.vue/）。
 */
class HBuilderXService
{
    /**
     * 返回模板目录路径（不存在时创建）
     */
    public function getTemplateDir(): string
    {
        $dir = BASE_PATH . '/storage/hbuilderx-template';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * 生成临时打包目录并返回 ZIP 文件路径
     *
     * @param array $params [user_id, app_name, package_id, api_base_url, ws_url, icon_base64]
     * @return string ZIP 文件绝对路径
     */
    public function generateZip(array $params): string
    {
        $userId    = (int)($params['user_id'] ?? 0);
        $appName   = trim((string)($params['app_name'] ?? 'Push 推送'));
        $pkgId     = trim((string)($params['package_id'] ?? 'com.example.push'));
        $apiBase   = rtrim((string)($params['api_base_url'] ?? ''), '/') . '/';
        $wsUrl     = rtrim((string)($params['ws_url'] ?? ''), '/') . '/';
        $iconB64   = trim((string)($params['icon_base64'] ?? ''));

        $tmpDir = sys_get_temp_dir() . '/hbx-' . $pkgId . '-' . $userId . '-' . time();
        if (is_dir($tmpDir)) {
            $this->rmDir($tmpDir);
        }
        @mkdir($tmpDir, 0755, true);

        $templateDir = $this->getTemplateDir();
        $hasTemplate = $this->dirHasFiles($templateDir);
        if ($hasTemplate) {
            $this->copyDir($templateDir, $tmpDir);
        } else {
            $this->scaffoldMinimalTemplate($tmpDir);
        }

        // 把 manifest.json / pages.json / main.js / App.vue / static/config.js 等写入
        $this->writeManifest($tmpDir, $appName, $pkgId);
        $this->writePagesJson($tmpDir);
        $this->writeMainJs($tmpDir);
        $this->writeAppVue($tmpDir, $appName);
        $this->writeConfig($tmpDir, $apiBase, $wsUrl);
        $this->writeIndexHtml($tmpDir, $appName);
        $this->writePageScaffolds($tmpDir);

        if ($iconB64 !== '') {
            $this->writeIcon($tmpDir, $iconB64);
        }

        // ZIP
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

    private function writeManifest(string $dir, string $appName, string $pkgId): void
    {
        $path = $dir . '/manifest.json';
        if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
        $arr = [
            'name'         => $appName,
            'appid'        => '__UNI__' . substr(md5($pkgId . '|' . $appName), 0, 7),
            'description'  => $appName . ' - 即时消息推送客户端',
            'versionName'  => '1.0.0',
            'versionCode'  => '100',
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
                    'android' => ['permissions' => ['<uses-permission android:name="android.permission.INTERNET"/>']],
                    'ios'     => ['dSYMs' => false],
                    'android' => ['package' => $pkgId],
                    'ios'     => ['bundleid' => $pkgId],
                ],
            ],
            'quickapp'    => [],
            'mp-weixin'   => ['appid' => '', 'setting' => ['urlCheck' => false]],
            'h5'          => ['router' => ['base' => './'], 'title' => $appName],
        ];
        // merge android/ios 避免重复键
        $arr['app-plus']['distribute']['android']['package'] = $pkgId;
        $arr['app-plus']['distribute']['ios']['bundleid']     = $pkgId;
        file_put_contents($path, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writePagesJson(string $dir): void
    {
        $path = $dir . '/pages.json';
        $arr = [
            'pages' => [
                ['path' => 'pages/login/index',       'style' => ['navigationBarTitleText' => '登录']],
                ['path' => 'pages/register/index',    'style' => ['navigationBarTitleText' => '注册']],
                ['path' => 'pages/dashboard/index',   'style' => ['navigationBarTitleText' => '首页']],
                ['path' => 'pages/push/index',        'style' => ['navigationBarTitleText' => '推送消息']],
                ['path' => 'pages/push-logs/index',   'style' => ['navigationBarTitleText' => '推送记录']],
                ['path' => 'pages/devices/index',     'style' => ['navigationBarTitleText' => '设备管理']],
                ['path' => 'pages/keys/index',        'style' => ['navigationBarTitleText' => 'Key 管理']],
                ['path' => 'pages/api-keys/index',    'style' => ['navigationBarTitleText' => 'API 文档']],
                ['path' => 'pages/app/index',         'style' => ['navigationBarTitleText' => 'APP 下载']],
                ['path' => 'pages/profile/index',     'style' => ['navigationBarTitleText' => '个人中心']],
                ['path' => 'pages/notices/index',     'style' => ['navigationBarTitleText' => '系统公告']],
                ['path' => 'pages/reset-password/index','style' => ['navigationBarTitleText' => '重置密码']],
            ],
            'globalStyle' => [
                'navigationBarTextStyle' => 'black',
                'navigationBarTitleText' => '推送控制台',
                'navigationBarBackgroundColor' => '#ffffff',
                'backgroundColor'         => '#f5f6fb',
            ],
        ];
        file_put_contents($path, json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function writeMainJs(string $dir): void
    {
        $code = <<<'JS'
import App from './App'
import config from './static/config.js'

// #ifndef VUE3
import Vue from 'vue'
Vue.config.productionTip = false
Vue.prototype.$config = config
App.mpType = 'app'
const app = new Vue({ ...App })
app.$mount()
// #endif

// #ifdef VUE3
import { createSSRApp } from 'vue'
export function createApp() {
  const app = createSSRApp(App)
  app.config.globalProperties.$config = config
  return { app }
}
// #endif
JS;
        file_put_contents($dir . '/main.js', $code);
    }

    private function writeAppVue(string $dir, string $appName): void
    {
        $code = <<<"VUE"
<script>
export default {
  onLaunch() {
    console.log('{$appName} 启动')
  },
  onShow() {},
  onHide() {},
}
</script>
<style>
page {
  background: #f5f6fb;
  font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "Microsoft YaHei", sans-serif;
}
</style>
VUE;
        file_put_contents($dir . '/App.vue', $code);
    }

    private function writeConfig(string $dir, string $apiBase, string $wsUrl): void
    {
        $static = $dir . '/static';
        if (!is_dir($static)) @mkdir($static, 0755, true);
        $code = <<<"JS"
export default {
  apiBaseUrl: '{$apiBase}',
  wsUrl:      '{$wsUrl}',
}
JS;
        file_put_contents($static . '/config.js', $code);
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
        $pages = [
            'login' => $this->pagePlaceholder('登录', '请输入账号和密码'),
            'register' => $this->pagePlaceholder('注册', '填写账号、密码、验证码完成注册'),
            'dashboard' => $this->pagePlaceholder('首页', '概览数据与快捷入口'),
            'push' => $this->pagePlaceholder('推送消息', '选择目标与内容进行推送'),
            'push-logs' => $this->pagePlaceholder('推送记录', '查看历史推送记录'),
            'devices' => $this->pagePlaceholder('设备管理', '管理绑定的设备'),
            'keys' => $this->pagePlaceholder('Key 管理', '创建与管理推送 Key'),
            'api-keys' => $this->pagePlaceholder('API 文档', '开放 API 使用说明与 Key 管理'),
            'app' => $this->pagePlaceholder('APP 下载', '扫码下载最新客户端'),
            'profile' => $this->pagePlaceholder('个人中心', '修改资料、密码与 QQ 绑定'),
            'notices' => $this->pagePlaceholder('系统公告', '查看官方公告'),
            'reset-password' => $this->pagePlaceholder('重置密码', '通过安全码/QQ/邮箱找回密码'),
        ];
        foreach ($pages as $name => $content) {
            $pDir = $dir . '/pages/' . $name;
            if (!is_dir($pDir)) @mkdir($pDir, 0755, true);
            file_put_contents($pDir . '/index.vue', $content);
        }
    }

    private function pagePlaceholder(string $title, string $desc): string
    {
        return <<<"VUE"
<template>
  <view class="page">
    <view class="title">{$title}</view>
    <view class="desc">{$desc}</view>
    <view class="tip">请根据项目实际需求，在此页面基础上完成交互与业务逻辑对接。</view>
  </view>
</template>
<script>
export default { data() { return {} } }
</script>
<style scoped>
.page { padding: 40rpx; }
.title { font-size: 40rpx; font-weight: 600; color: #1f2937; margin-bottom: 20rpx; }
.desc  { font-size: 28rpx; color: #4b5563; margin-bottom: 20rpx; }
.tip   { font-size: 24rpx; color: #9ca3af; }
</style>
VUE;
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
        // 目录结构（pages/static 会由 write* 方法创建）；这里只写 README
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
