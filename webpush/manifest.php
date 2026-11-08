<?php
/**
 * 动态 PWA manifest
 * 带 ?key=<push_key> 请求时，start_url 也带上 Key：
 * iOS「添加到主屏幕」按 manifest 的 start_url 生成图标入口，
 * 这样从主屏图标打开也直接是本 Key 的专属推送页面（免输 KEY）。
 * 不带 key 时输出与静态 manifest.webmanifest 相同的内容。
 */
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$key = isset($_GET['key']) ? trim((string)$_GET['key']) : '';
// 只允许字母数字，防止注入异常字符
$key = preg_replace('/[^A-Za-z0-9]/', '', $key);

$startUrl = '/webpush/';
if ($key !== '') {
    $startUrl = '/webpush/?key=' . $key;
}

$manifest = [
    'name'            => '推送接收',
    'short_name'      => '推送',
    'description'     => 'PWA Web Push 推送接收',
    'start_url'       => $startUrl,
    'scope'           => '/webpush/',
    'display'         => 'standalone',
    'orientation'     => 'portrait',
    'background_color' => '#f2f3f7',
    'theme_color'     => '#4a90d9',
    'icons'           => [
        ['src' => '/webpush/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
    ],
];

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
