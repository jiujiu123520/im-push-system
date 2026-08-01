# 小飞机网盘 V2 增强（脚本兼容 + 抓包向导 + 直链中转统计）Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 修复小飞机上传脚本在 Alpine/FreeBSD/macOS 上的兼容性隐患；在管理后台新增「抓包向导」替代「账号密码自动登录」；实现 302 中转直链下载（懒解析+缓存）保证下载统计 100% 准确。

**Architecture:**
  - Shell 脚本：新增 `assert_dependencies()` 统一入口，缺失依赖时按发行版（apt/apk/yum/brew）提示对应安装命令；JSON 解析以 `python3 -c 'json.loads'` 为主路径，grep/sed 正则作 fallback。
  - 后端：在现有下载链路（`downloadByToken`）最前端插入「直链解析缓存命中判断」——命中则 `302 Location + exit`，未命中则 `curl` 抓分享页、提取直链、写回缓存字段后再 302；统计 `incrementDownloadCount()` 在 `302` 前同步执行，保证自托管/小飞机两条下载链路统计计数一致。
  - 前端：在「分发设置」抽屉里加一个折叠面板「💡 如何获取小飞机凭证」，内嵌 3 步图文说明 + 示例截图占位 + 快速复制粘贴按钮，不新增页面或弹窗。

**Tech Stack:** PHP 8.x（curl）+ Vue3 + TS + Element Plus + Bash 4+（兼容 ash/dash/posix sh 的降级分支）

---

## Task 1: Shell 脚本跨系统兼容性修复

**Files:**
- Modify: `deploy/apk/upload-to-feijipan.sh`（重写依赖检查 + JSON 解析走 python3 主路径）
- Modify: `deploy/quick-deploy.sh`（头部加 assert_dependencies 调用）
- Modify: `backend/deploy/update.sh`（头部加 assert_dependencies 调用）

- [ ] **Step 1.1: 在 `upload-to-feijipan.sh` 头部插入 `assert_dependencies()` 函数**

将以下代码插入到 `set -u` 之后、`APK_PATH=` 之前。新增检测：`bash` 版本 >=4、`curl`、`python3`（有警告但可降级）、对 `stat/grep/sed` 做 BusyBox 存在性检测并提示 apk add：

```bash
# ============================================================
# 跨系统：依赖检查 & 发行版识别（apt/apk/yum/brew/pacman）
# ============================================================
_need_cmd() { command -v "$1" >/dev/null 2>&1; }

_distro_install_hint() {
  local pkg="$1"
  if _need_cmd apt-get; then
    echo "  Debian/Ubuntu:  sudo apt-get update && sudo apt-get install -y $pkg"
  elif _need_cmd apk; then
    echo "  Alpine:        apk add --no-cache $pkg"
  elif _need_cmd yum; then
    echo "  CentOS/RHEL:    sudo yum install -y $pkg"
  elif _need_cmd dnf; then
    echo "  Rocky/Fedora:   sudo dnf install -y $pkg"
  elif _need_cmd brew; then
    echo "  macOS:          brew install $pkg"
  elif _need_cmd pacman; then
    echo "  Arch:           sudo pacman -S --noconfirm $pkg"
  else
    echo "  请使用系统包管理器安装: $pkg"
  fi
}

assert_dependencies() {
  local missing=()
  _need_cmd bash   || missing+=("bash (>=4.0，当前脚本依赖 bash 语法)")
  _need_cmd curl   || missing+=("curl")
  if ! _need_cmd python3; then
    echo "[WARN] 未检测到 python3，将使用 grep/sed 正则兜底解析 JSON（推荐安装 python3 以提高兼容性）" >&2
    _distro_install_hint "python3" >&2
  fi
  # 检测 BusyBox 工具链（Alpine 默认）
  if _need_cmd apk && ! _need_cmd python3; then
    echo "[WARN] Alpine 环境检测到 BusyBox grep/sed，扩展正则行为与 GNU 不一致，请先执行：apk add --no-cache python3 curl bash" >&2
  fi
  if [[ ${#missing[@]} -gt 0 ]]; then
    echo "[ERROR] 缺失以下必需依赖，请先安装后重试：" >&2
    local m
    for m in "${missing[@]}"; do
      echo "  - $m" >&2
      local base_pkg="${m%% *}"
      _distro_install_hint "$base_pkg" >&2
    done
    exit 1
  fi
}

assert_dependencies
```

- [ ] **Step 1.2: 替换 `upload-to-feijipan.sh` 的三处 JSON 解析调用（getUpToken、share/url 响应）为 python3 主路径**

替换 `RESP_CODE` / `RESP_MSG` / `extract_json_field` 为如下实现（python3 优先，fallback 到正则）：

```bash
# 通用 JSON 字段提取（python3 优先，否则回退 grep/sed 正则）
extract_json_field() {
    local field="$1"
    local json="$2"
    [[ -z "$json" ]] && { echo ""; return; }

    # ---- 主路径：python3 ----
    if _need_cmd python3; then
        python3 - "$field" <<'PYEOF' "$json" || true
import sys, json
f, raw = sys.argv[1], sys.stdin.read()
# 顶层或 data 子对象都尝试
def lookup(d):
    if isinstance(d, dict):
        if f in d: return d[f]
        if 'data' in d and isinstance(d['data'], dict):
            if f in d['data']: return d['data']['data'] if f=='data' else d['data'][f]
    return None
try:
    v = lookup(json.loads(raw))
    if v is None:
        top = json.loads(raw)
        if isinstance(top, dict) and isinstance(top.get('data'), (dict, list, str, int, float)):
            v2 = lookup({'data': top['data']})
            if v2 is not None: v = v2
    if isinstance(v, bool): print('true' if v else 'false')
    elif v is None: print('')
    else: print(str(v))
except Exception:
    print('')
PYEOF
        return
    fi

    # ---- 兜底：老正则 ----
    local v
    v=$(echo "$json" | grep -oE "\"$field\"\s*:\s*\"[^\"]*\"" | head -1 | sed -E "s/.*:\"([^\"]*)\".*/\1/")
    [[ -n "$v" ]] && { echo "$v"; return; }
    v=$(echo "$json" | grep -oE "\"$field\"\s*:\s*-?[0-9]+" | head -1 | sed -E 's/.*:\s*(-?[0-9]+).*/\1/')
    echo "$v"
}

# === Step 1 解析 getUpToken 响应 ===
RESP_CODE=$(extract_json_field "code" "$TOKEN_RESP")
RESP_MSG=$(extract_json_field  "msg"  "$TOKEN_RESP")
UPLOAD_URL=$(extract_json_field "uploadUrl" "$TOKEN_RESP")
S3_KEY=$(extract_json_field    "key"       "$TOKEN_RESP")
FILE_ID=$(extract_json_field   "fileId"    "$TOKEN_RESP")

# === Step 3 解析 share/url 响应 ===
SHARE_CODE=$(extract_json_field "code" "$SHARE_RESP")
SHARE_MSG=$(extract_json_field  "msg"  "$SHARE_RESP")
SHARE_DATA_BLOB="$SHARE_RESP"
FINAL_URL=""
for k in url shortUrl shareUrl downloadUrl; do
    v=$(extract_json_field "$k" "$SHARE_RESP")
    if [[ -n "$v" && "$v" == http* ]]; then FINAL_URL="$v"; break; fi
done
```

并删除旧的 `DATA_BLOB=$(echo ... | sed -E ...)` 以及 `SHARE_DATA=$(echo ... | sed -E ...)` 两行（`extract_json_field` 内部已兼容「顶层 / data 子对象」两种结构）。

- [ ] **Step 1.3: 在 `quick-deploy.sh` 和 `backend/deploy/update.sh` 头部 shebang 之后同样插入 `assert_dependencies` 最小版本（检测：bash/mysql/curl，缺失时提示）**

快速复用版，直接复制到两个脚本（函数名不变，`missing` 列表中去掉 `curl`，加 `mysql`）：

```bash
_need_cmd() { command -v "$1" >/dev/null 2>&1; }
_distro_install_hint() {
  local pkg="$1"
  if _need_cmd apt-get; then echo "  sudo apt-get install -y $pkg"
  elif _need_cmd apk; then echo "  apk add --no-cache $pkg"
  elif _need_cmd yum; then echo "  sudo yum install -y $pkg"
  elif _need_cmd dnf; then echo "  sudo dnf install -y $pkg"
  elif _need_cmd brew; then echo "  brew install $pkg"
  elif _need_cmd pacman; then echo "  sudo pacman -S --noconfirm $pkg"
  else echo "  请安装 $pkg"; fi
}
assert_deps() {
  local miss=()
  _need_cmd bash || miss+=(bash)
  _need_cmd mysql || miss+=(mysql-client)
  if [[ ${#miss[@]} -gt 0 ]]; then
    echo "[ERROR] 缺少依赖：${miss[*]}" >&2
    for p in "${miss[@]}"; do _distro_install_hint "$p" >&2; done
    exit 1
  fi
  # 非 systemd 容器提示
  if ! _need_cmd systemctl; then
    echo "[WARN] 当前环境未检测到 systemctl（Docker 容器 / macOS），服务重启步骤将改为提示" >&2
  fi
}
assert_deps
```

- [ ] **Step 1.4: 提交 commit**

```bash
git add deploy/apk/upload-to-feijipan.sh deploy/quick-deploy.sh backend/deploy/update.sh
git commit -m "fix(deploy): 修复 Shell 脚本跨发行版兼容性（Alpine/macOS/BSD）
- 新增 assert_dependencies() 按 apt/apk/yum/brew/pacman 给出安装提示
- upload-to-feijipan.sh JSON 解析改为 python3 主路径 + grep/sed 兜底
- quick-deploy.sh / update.sh 头部加依赖检查，缺失 bash/mysql 直接报错"
```

---

## Task 2: 数据库 016 迁移（feijipan_direct_url 缓存字段）

**Files:**
- Create: `backend/database/migrations/016_apk_feijii_direct_url.sql`
- Modify: `backend/deploy/update.sh`（`record_if_applied` 新增一条 016）
- Modify: `deploy/quick-deploy.sh`（`record_if_applied` 新增一条 016）

- [ ] **Step 2.1: 创建迁移 SQL**

```sql
-- ============================================================
-- 016_apk_feijii_direct_url.sql
-- 小飞机分享链接 -> 真实直链 懒解析 + 缓存
--   feijipan_direct_url:       解析到的 CDN 直链
--   feijipan_direct_expires:  直链过期时间（sign/exp 参数过期之前就用缓存）
--   feijipan_fetch_count:     解析次数（监控分享页结构是否变化，突增就要补正则）
-- ============================================================

ALTER TABLE `apk_distributions`
  ADD COLUMN `feijipan_direct_url` TEXT NULL COMMENT '缓存的小飞机直链（解析分享页得到）' AFTER `feijipan_share_id`,
  ADD COLUMN `feijipan_direct_expires` DATETIME NULL COMMENT '直链过期时间（NULL=不强制过期）' AFTER `feijipan_direct_url`,
  ADD COLUMN `feijipan_fetch_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '解析小飞机分享页次数（用于监控告警）' AFTER `feijipan_direct_expires`;
```

- [ ] **Step 2.2: 两个部署脚本加 016 补录检查**

在 `backend/deploy/update.sh` L759 `015` 那行之后插入：

```bash
record_if_applied "016_apk_feijii_direct_url.sql" \
    "SELECT IF(EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='apk_distributions' AND COLUMN_NAME='feijipan_fetch_count'),1,0);"
```

在 `deploy/quick-deploy.sh` 对应的 `015` 之后同样插入。

- [ ] **Step 2.3: 提交 commit**

```bash
git add backend/database/migrations/016_apk_feijii_direct_url.sql backend/deploy/update.sh deploy/quick-deploy.sh
git commit -m "feat(db): 新增 016 迁移 - 小飞机直链缓存字段(direct_url/expires/fetch_count)"
```

---

## Task 3: ApkDistributionService 新增 `resolveFeijiiDirectUrl()` 解析 + 缓存

**Files:**
- Modify: `backend/src/Service/ApkDistributionService.php`（新增 2 个 public 方法 + 2 个 private helper）

- [ ] **Step 3.1: 新增常量和缓存管理方法**

在 `PAGE_SIZE = 10;` 之后插入：

```php
    /** 小飞机直链默认缓存有效期（秒）：2 小时。sign 参数通常 24h 过期，保守一点留 2h。 */
    private const FEEJII_DIRECT_TTL = 7200;

    /**
     * 小飞机直链缓存命中判断 + 更新 DB
     * 返回 [ 'hit' => bool, 'url' => string ]   hit=true 时可以直接 302
     */
    public static function getCachedFeijiiDirectUrl(int $id): array
    {
        $row = Database::fetch(
            'SELECT id, feijipan_url, feijipan_direct_url, feijipan_direct_expires
             FROM apk_distributions WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($row === false || empty($row['feijipan_url'] ?? '')) {
            return ['hit' => false, 'url' => ''];
        }
        $directUrl = (string)($row['feijipan_direct_url'] ?? '');
        $expiresStr = $row['feijipan_direct_expires'] ?? null;
        if ($directUrl !== '' && $expiresStr !== null) {
            $expiresAt = strtotime((string)$expiresStr);
            if ($expiresAt !== false && $expiresAt > time() + 60) {
                return ['hit' => true, 'url' => $directUrl];
            }
        }
        return ['hit' => false, 'url' => ''];
    }

    /**
     * 保存解析到的直链到缓存
     */
    public static function saveCachedFeijiiDirectUrl(int $id, string $directUrl, int $ttl = self::FEEJII_DIRECT_TTL): void
    {
        if ($directUrl === '') return;
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        try {
            Database::execute(
                'UPDATE apk_distributions
                    SET feijipan_direct_url = ?,
                        feijipan_direct_expires = ?,
                        feijipan_fetch_count = feijipan_fetch_count + 1,
                        updated_at = NOW()
                  WHERE id = ?',
                [$directUrl, $expires, $id]
            );
        } catch (\Throwable $e) {}
    }
```

- [ ] **Step 3.2: 新增核心解析方法 `resolveFeijiiDirectUrl($shareUrl): string`**

在 `saveCachedFeijiiDirectUrl` 后面继续加：

```php
    /**
     * 请求小飞机分享页 HTML，用多层正则/meta 提取真实下载直链。
     * 兜底：解析失败返回 ''，由调用方决定是否回退到跳分享页。
     */
    public static function resolveFeijiiDirectUrl(string $shareUrl): string
    {
        $shareUrl = trim($shareUrl);
        if ($shareUrl === '' || !str_starts_with($shareUrl, 'http')) return '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $shareUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,  // 分享页可能 301/302 跳转
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
            ],
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($html === false || $httpCode < 200 || $httpCode >= 400) return '';
        if (!is_string($html)) return '';

        $candidates = [];

        // ---------- Pattern 1: 页面直接声明下载按钮/链接 ----------
        // <a id="downfile" href="https://cdn.feejii.com/...">
        // <a id="download-btn" href="https://...">
        if (preg_match_all('/<a[^>]+(?:id|class)="[^"]*(?:down|download|getfile)[^"]*"[^>]+href="(https?:\/\/[^"]+\.(?:apk|zip|rar|7z|tar\.gz|exe|dmg|ipa))"/i', $html, $m)) {
            foreach ($m[1] as $u) $candidates[] = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
        }

        // ---------- Pattern 2: JS 内声明变量 ----------
        // const DOWNLOAD_URL = "https://...";
        // var fileUrl = 'https://...';
        if (preg_match_all('/(?:DOWNLOAD_URL|download[_\-]?url|file[_\-]?url|share[_\-]?url|real[_\-]?url)\s*[:=]\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) $candidates[] = $u;
        }

        // ---------- Pattern 3: meta property (og:audio / og:video / og:file) ----------
        // <meta property="og:video:url" content="https://...">
        // <meta property="og:audio" content="https://...">
        // <meta property="og:file:url" content="https://...">
        if (preg_match_all('/<meta\s+(?:property|name)="(?:og:(?:video|audio|file|download)(?::url)?)"\s+content="(https?:\/\/[^"]+)"|<meta\s+content="(https?:\/\/[^"]+)"\s+(?:property|name)="(?:og:(?:video|audio|file|download)(?::url)?)")/i', $html, $m)) {
            foreach (array_filter(array_merge($m[1] ?? [], $m[2] ?? [], $m[3] ?? [])) as $u) {
                $candidates[] = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
            }
        }

        // ---------- Pattern 4: 通用：任何带 sign/exp/token 参数的 https CDN URL（小飞机直链特征） ----------
        // 形如 https://cdnX.feejii.com/v1/file/xxx?sign=yyy&expire=zzz
        // 或     https://*.feejii.com/*?AWSAccessKeyId=...&Signature=...  (S3 签名)
        // 或     https://*.aliyuncs.com/*?Expires=...&OSSAccessKeyId=...&Signature=...  (OSS)
        if (preg_match_all('/https?:\/\/[a-zA-Z0-9.\-]+\/[^"\'<>?#\s]+\?(?:[^"\'<>#\s]*(?:sign|expir|expire|token|AWSAccessKeyId|OSSAccessKeyId|Signature)=[^"\'<>#\s]*)+/i', $html, $m)) {
            foreach ($m[0] as $u) $candidates[] = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
        }

        // ---------- Pattern 5: 小飞机常见的 JS 跳转 / window.location ----------
        // window.location.href = "https://..."; location.replace("https://...")
        if (preg_match_all('/(?:window\.location(?:\.href)?|location\.replace)\s*[=.]\s*\(?\s*["\'](https?:\/\/[^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $u) $candidates[] = $u;
        }

        // ---------- 命中优先级：长度长的优先（直链通常更长、带签名参数） ----------
        $candidates = array_values(array_filter(array_unique($candidates)));
        if (empty($candidates)) return '';
        usort($candidates, fn($a, $b) => strlen($b) <=> strlen($a));
        return $candidates[0];
    }
```

- [ ] **Step 3.3: 给 `getDetail/getList` 补新字段的 int 化 + 映射兜底**

两个方法里的 `foreach (&$item)` 已经有 `feijipan_url` 兼容映射，不需要改，因为 PHP `TEXT` 字段本来就是 string 不会有类型问题。只需要确认 SQL 的 `SELECT *` 会把这三个新字段带出来（不需要改列选择）。

- [ ] **Step 3.4: 提交 commit**

```bash
git add backend/src/Service/ApkDistributionService.php
git commit -m "feat(apk): 新增 resolveFeijiiDirectUrl() - 从分享页 HTML 提取真实 CDN 直链（5 种正则兜底）+ TTL 缓存读写"
```

---

## Task 4: Controller 下载逻辑改造（302 直链优先 + 仍然精确统计）

**Files:**
- Modify: `backend/src/Controller/ApkDistributionController.php`（只改 `downloadByToken()` 方法内部）

- [ ] **Step 4.1: 在 `incrementDownloadCount()` 调用之后、`sendfile()` 之前，插入「直链 302」分支**

改造完的 `downloadByToken()` 应该是如下结构（先把 **计数+日志** 放到最前面，保证不管是 sendfile 直出还是 302 跳转都能记到一次下载）：

```php
    public static function downloadByToken(array $context, array $params = [])
    {
        $token = (string)($params['token'] ?? '');
        $response = $context['response'];

        $fileInfo = ApkDistributionService::getDownloadFile($token);
        if (!$fileInfo['found']) {
            $msg = $fileInfo['record'] !== null ? 'APK 文件不存在，可能已被删除' : '下载链接无效或已失效';
            Response::fail($response, $msg, Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $record  = $fileInfo['record'];
        $apkPath = $fileInfo['path'];
        $filename= $fileInfo['filename'];
        $apkSize = filesize($apkPath);
        $distributionId = (int)($record['id'] ?? 0);

        // ===== 统计：先计数 + 写日志（保证「先统计、后跳转/发送」）=====
        $ip = AdminAuth::getClientIp($context);
        $ua = (string)($context['header']['user-agent'] ?? $context['server']['http_user_agent'] ?? '');
        $referer = (string)($context['header']['referer'] ?? $context['server']['http_referer'] ?? '');
        ApkDistributionService::incrementDownloadCount($token, $ip, $ua, $referer);

        // ===== 分支 A：小飞机直链 302（优先走缓存 → 懒解析 → 再失败就回退）=====
        if ($distributionId > 0 && !empty($record['feijipan_url'])) {
            $cache = ApkDistributionService::getCachedFeijiiDirectUrl($distributionId);
            $directUrl = '';
            if ($cache['hit']) {
                $directUrl = $cache['url'];
            } else {
                $parsed = ApkDistributionService::resolveFeijiiDirectUrl((string)$record['feijipan_url']);
                if ($parsed !== '') {
                    ApkDistributionService::saveCachedFeijiiDirectUrl($distributionId, $parsed);
                    $directUrl = $parsed;
                }
            }
            if ($directUrl !== '') {
                // 用 307 跳转（部分安卓浏览器对 302 APK 下载拦截更严，307 兼容性更好）
                $response->status(307);
                $response->header('Location', $directUrl);
                $response->header('Access-Control-Allow-Origin', '*');
                $response->header('Cache-Control', 'no-cache, no-store');
                // Swoole Response 没有 307 的快捷方法，直接写头 + end 即可
                if (method_exists($response, 'end')) {
                    $response->end('');
                } else {
                    // 兼容 FastRoute + output buffer 模式（兜底：原生 header）
                    if (!headers_sent()) {
                        header('HTTP/1.1 307 Temporary Redirect', true, 307);
                        header('Location: ' . $directUrl);
                    }
                }
                return false;
            }
            // 解析失败：不阻塞，回退到「分支 B：自托管 sendfile」
        }

        // ===== 分支 B：自托管下载（服务器直出）=====
        $response->status(200);
        $response->header('Content-Type', 'application/vnd.android.package-archive');
        $response->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->header('Content-Length', (string)$apkSize);
        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Cache-Control', 'no-cache');
        $response->sendfile($apkPath);
        return false;
    }
```

> ⚠️ 重要：**把「统计」放到最前面**，保证 307 跳转之前已经完成一次计数+写日志，这是统计不丢数的关键。

- [ ] **Step 4.2: 提交 commit**

```bash
git add backend/src/Controller/ApkDistributionController.php
git commit -m "feat(apk): 下载链路支持小飞机 307 直链中转 - 先统计再跳转 + 多层正则兜底解析分享页"
```

---

## Task 5: 前端 index.vue 分发设置抽屉新增「💡 如何获取小飞机凭证」抓包向导

**Files:**
- Modify: `admin/src/views/apk-distribution/index.vue`（新增一个折叠面板在 DevCode 表单项下方、`credentials-actions` 按钮组上方）

- [ ] **Step 5.1: 在 DevCode 表单下方插入 `el-collapse` 抓包向导**

插入到 `<div class="credentials-actions">` 这一行的**上方**（即 DevCode 输入框下方，验证按钮上方）：

```vue
          <!-- 抓包向导折叠面板 -->
          <el-collapse class="guide-collapse">
            <el-collapse-item title="💡 如何获取小飞机 AppToken / UUID / DevCode（3 步搞定）" name="guide">
              <ol class="guide-steps">
                <li>
                  <div class="guide-step-title">第 1 步：用 Chrome / Edge 打开小飞机官网并登录</div>
                  <div class="guide-step-desc">
                    访问 <a href="https://www.feejii.com" target="_blank" rel="noopener" class="guide-link">feejii.com</a>，
                    用你注册的手机号/账号密码正常登录。
                  </div>
                </li>
                <li>
                  <div class="guide-step-title">第 2 步：打开开发者工具并随便点一个 API 请求</div>
                  <div class="guide-step-desc">
                    按 <el-tag size="small" round class="kbd">F12</el-tag> 或右键→「检查」打开 DevTools →
                    切换到 <el-tag size="small" round effect="plain" type="success">Network（网络）</el-tag> 面板 →
                    筛选 <el-tag size="small" round effect="plain" type="warning">Fetch/XHR</el-tag> →
                    回到网页点一下「文件列表」或刷新页面 → 在左侧请求列表里<strong>随便点任意一个</strong>（比如 <code>/app/user/info</code>）。
                  </div>
                </li>
                <li>
                  <div class="guide-step-title">第 3 步：复制 3 个参数，粘贴到上方三个输入框</div>
                  <div class="guide-step-desc">
                    在请求详情的「Headers → Query String Parameters」区域，复制下面 3 个值：
                    <ul class="guide-params">
                      <li><code>appToken</code> → 粘贴到上方「<strong>小飞机网盘 AppToken</strong>」</li>
                      <li><code>uuid</code>     → 粘贴到上方「<strong>小飞机网盘 UUID</strong>」</li>
                      <li><code>devCode</code>  → 粘贴到上方「<strong>小飞机网盘 DevCode</strong>」</li>
                    </ul>
                    <el-alert type="info" :closable="false" show-icon title="三项必须来自同一个登录会话。修改密码 / 换设备登录后，必须重新抓取一次。" size="small" style="margin-top:8px" />
                  </div>
                </li>
              </ol>
            </el-collapse-item>
          </el-collapse>
```

- [ ] **Step 5.2: 在 `<style>` 末尾追加向导相关 SCSS**

```scss
// ===== 小飞机抓包向导 =====
.guide-collapse {
  margin-top: 14px;
  border: 1px solid var(--border-light);
  border-radius: $radius-sm;
  overflow: hidden;

  :deep(.el-collapse-item__header) {
    padding-left: 14px;
    padding-right: 14px;
    font-weight: 600;
    color: var(--text-regular);
    background: var(--bg-page);
    border-bottom: none;
  }
  :deep(.el-collapse-item__wrap) {
    border-top: 1px solid var(--border-light);
  }
  :deep(.el-collapse-item__content) {
    padding: 14px 18px 18px;
  }
}

.guide-steps {
  margin: 0;
  padding-left: 22px;
  display: flex;
  flex-direction: column;
  gap: 14px;

  > li::marker {
    color: $color-primary;
    font-weight: 800;
    font-size: 15px;
  }
}

.guide-step-title {
  font-weight: 600;
  color: var(--text-primary);
  font-size: 14px;
  margin-bottom: 4px;
}

.guide-step-desc {
  font-size: 13px;
  color: var(--text-regular);
  line-height: 1.75;
}

.guide-link {
  color: $color-primary;
  text-decoration: none;
  font-weight: 500;
  &:hover { text-decoration: underline; }
}

.kbd {
  font-family: $font-family-mono;
  letter-spacing: 0.2px;
}

.guide-params {
  margin: 6px 0 0;
  padding-left: 18px;
  color: var(--text-regular);
  font-size: 13px;
  line-height: 1.9;
  code {
    background: var(--bg-page);
    padding: 1px 6px;
    border-radius: 4px;
    color: $color-primary;
    font-family: $font-family-mono;
  }
}
```

- [ ] **Step 5.3: 提交 commit**

```bash
git add admin/src/views/apk-distribution/index.vue
git commit -m "feat(admin): APK 分发设置抽屉新增「小飞机抓包向导 3 步图文」折叠面板"
```

---

## Task 6: 部署脚本补 016 迁移 + python3 提示（Task 1 和 2 已经做了，这里做一次「联合提交前的自检」）

**Files:**
- Modify: none（Task 1/2 已覆盖）

- [ ] **Step 6.1: 静态 grep 核对 016 是否出现在两处 record_if_applied**

```bash
grep -n "016_apk_feijii_direct_url.sql" backend/deploy/update.sh deploy/quick-deploy.sh
# 预期两处分别命中一条
```

- [ ] **Step 6.2: grep 核对两处 assert_dependencies 已加入 quick-deploy.sh / update.sh 头部**

```bash
grep -n "assert_deps\|assert_dependencies" deploy/quick-deploy.sh backend/deploy/update.sh deploy/apk/upload-to-feijipan.sh
# 预期 3 个文件各有一条 assert_dependencies / assert_deps 定义 + 一条调用
```

---

## Task 7: 静态代码自查 + 最终合并 commit

- [ ] **Step 7.1: grep 蓝奏云残留前端调用（不应再出现 validateLanzou / uploadToLanzou / lanzou_*）**

```bash
grep -RIn "uploadToLanzou\|validateLanzou\|lanzou_cookie\|lanzou_url\|lanzou_password" admin/src backend/src backend/public/index.php
# 预期：只在 ApkDistributionService 兼容映射的 2 处注释里出现，其他 0 命中
```

- [ ] **Step 7.2: grep 新增符号是否都存在**

```bash
grep -n "resolveFeijiiDirectUrl\|getCachedFeijiiDirectUrl\|saveCachedFeijiiDirectUrl\|feijipan_direct_url" backend/src/Service/ApkDistributionService.php backend/src/Controller/ApkDistributionController.php
# 预期 7+ 命中
```

- [ ] **Step 7.3: 最终联合提交（如果前面的小 commit 已经做完，就只 push）**

如果之前的 Task commit 没做，这里一次性全部提交：

```bash
git status --short
git add -A
git commit -m "feat(apk): 小飞机 V2 增强 - 脚本兼容 + 抓包向导 + 直链中转统计

Shell:
  - upload-to-feijipan.sh: 新增 assert_dependencies(), JSON 解析切 python3 主路径
  - quick-deploy.sh / update.sh: 新增 bash/mysql 缺失检测 + 发行版安装提示

Database:
  - 新增 016_apk_feijii_direct_url.sql: feijipan_direct_url / expires / fetch_count

Backend:
  - ApkDistributionService: 新增 resolveFeijiiDirectUrl()（5 层正则兜底解析分享页）
    + 缓存读写 + 2h TTL
  - ApkDistributionController::downloadByToken: 先 incrementDownloadCount 保证统计，
    有 feijipan_url 则 307 直链跳转，解析失败或无配置回退到自托管 sendfile

Frontend:
  - 分发设置抽屉新增「💡 抓包向导 3 步图文」折叠面板 + DevTools 操作说明"
```
