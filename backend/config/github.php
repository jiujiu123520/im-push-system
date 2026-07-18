<?php
declare(strict_types=1);

/**
 * GitHub Actions 构建配置
 *
 * 用于触发 workflow_dispatch 构建 APK。
 * 国内服务器通过 gh.jasonzeng.dev 代理访问 GitHub API。
 */
return [
    // GitHub Personal Access Token(需要 repo 和 workflow 权限)
    'token' => \App\Service\Config::env('GITHUB_TOKEN', ''),

    // 仓库所有者(用户名或组织名)
    'owner' => \App\Service\Config::env('GITHUB_OWNER', 'jiujiu123520'),

    // 仓库名
    'repo' => \App\Service\Config::env('GITHUB_REPO', 'im-push-system'),

    // Workflow 文件名(不带路径)
    'workflow_file' => \App\Service\Config::env('GITHUB_WORKFLOW_FILE', 'build-apk.yml'),

    // 触发 workflow 的分支(默认 main,如使用 master 分支需修改)
    'ref' => \App\Service\Config::env('GITHUB_REF', 'main'),

    // GitHub API 代理(国内服务器使用 gh.jasonzeng.dev,留空则直连)
    'api_proxy' => \App\Service\Config::env('GITHUB_API_PROXY', 'https://gh.jasonzeng.dev/'),

    // API 请求超时(秒)
    'timeout' => (int)\App\Service\Config::env('GITHUB_API_TIMEOUT', 30),
];
