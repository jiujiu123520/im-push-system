<?php
declare(strict_types=1);

namespace App\Controller;

use App\Middleware\AdminAuth;
use App\Service\Database;
use App\Service\Response;

/**
 * Push Key 管理控制器（需管理员鉴权）
 *
 * 路由：
 *   GET    /admin/keys          列表（分页10条，支持搜索）
 *   POST   /admin/keys          创建
 *   PUT    /admin/keys/{id}     更新（含通知邮箱配置）
 *   DELETE /admin/keys/{id}     删除
 *   PUT    /admin/keys/{id}/status  切换状态
 */
class PushKeyController
{
    private const PER_PAGE = 10;

    public function index(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $page    = (int)($context['get']['page'] ?? 1);
        $keyword = (string)($context['get']['keyword'] ?? '');

        $page    = max(1, $page);
        $offset  = ($page - 1) * self::PER_PAGE;

        $where  = '';
        $sqlParams = [];
        if ($keyword !== '') {
            $where  = ' WHERE pk.name LIKE ? OR pk.key_value LIKE ? OR u.username LIKE ?';
            $sqlParams = ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"];
        }

        $list = Database::fetchAll(
            "SELECT pk.id, pk.key_value, pk.name, pk.user_id, pk.max_devices, pk.status, pk.created_at, pk.updated_at,
                    u.username
             FROM push_keys pk
             LEFT JOIN users u ON u.id = pk.user_id
             {$where} ORDER BY pk.id DESC LIMIT " . self::PER_PAGE . " OFFSET " . $offset,
            $sqlParams
        );

        // 尝试追加通知字段（如果表中存在）
        $list = $this->appendNotifyFields($list);

        // 追加订阅统计：总订阅数 + 当前在线订阅数（从 Redis 读取）
        try {
            $redis = \App\Service\Redis::getInstance();
            foreach ($list as &$row) {
                $keyValue = (string)($row['key_value'] ?? '');
                if ($keyValue !== '') {
                    // 订阅总数（key:subscribe:{} 集合的元素数，持久订阅关系）
                    $subscribedTotal = (int)$redis->sCard("key:subscribe:{$keyValue}");
                    $row['subscribed_total'] = $subscribedTotal;

                    // 在线订阅数：遍历订阅设备，检查每个 device_id 是否有在线 fd（ws:device:{} 集合）
                    $onlineCount = 0;
                    if ($subscribedTotal > 0) {
                        $deviceIds = $redis->sMembers("key:subscribe:{$keyValue}");
                        if (is_array($deviceIds)) {
                            foreach ($deviceIds as $deviceId) {
                                if ((int)$redis->sCard('ws:device:' . $deviceId) > 0) {
                                    $onlineCount++;
                                }
                            }
                        }
                    }
                    $row['online_count'] = $onlineCount;
                } else {
                    $row['subscribed_total'] = 0;
                    $row['online_count'] = 0;
                }
            }
            unset($row);
        } catch (\Throwable $e) {
            // Redis 异常时兜底给 0
            foreach ($list as &$row) {
                $row['subscribed_total'] = 0;
                $row['online_count'] = 0;
            }
            unset($row);
        }

        $total = (int)(Database::fetch(
            "SELECT COUNT(*) AS total FROM push_keys pk LEFT JOIN users u ON u.id = pk.user_id{$where}",
            $sqlParams
        )['total'] ?? 0);

        return [
            'list'        => $list,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => self::PER_PAGE,
            'total_pages' => $total > 0 ? (int)ceil($total / self::PER_PAGE) : 0,
        ];
    }

    public function show(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = $this->fetchKeyById($id);

        if ($key === null) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        return $key;
    }

    /**
     * 获取某个 Key 的订阅设备列表（含在线状态和设备详情）
     * 路由：GET /admin/keys/{id}/subscribers
     *
     * 候选设备来自三方并集：
     *   1) Redis key:subscribe:{keyValue} 集合            —— 正常的订阅关系
     *   2) devices 表 WHERE push_key_id = id 的所有记录 —— 防止 Redis 键丢失/key_value 不匹配导致漏显示
     *   3) Redis device:key 哈希中 value = keyValue 的键 —— 设备端声明的归属（双向映射交叉校验）
     * 并对每个设备标注它出现在哪些来源，方便用户识别"僵尸订阅/订阅丢失"。
     */
    public function subscribers(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = Database::fetch(
            'SELECT id, key_value, name FROM push_keys WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($key === false) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $keyValue = (string)($key['key_value'] ?? '');
        $pushKeyId = (int)($key['id'] ?? 0);
        $redis = \App\Service\Redis::getInstance();

        // ====== 0) 直接验证 Redis 状态（兜底诊断） ======
        // 用 sCard 直接验证 key:subscribe 集合的真实元素数，避免 sMembers 返回空导致误判
        $redisSetCount = 0;
        if ($keyValue !== '') {
            $redisSetCount = (int)$redis->sCard("key:subscribe:{$keyValue}");
        }
        // 用 hLen 验证 device:key 哈希的总数
        $deviceKeyTotal = 0;
        if ($redis->exists('device:key')) {
            $deviceKeyTotal = (int)$redis->hLen('device:key');
        }

        // ====== 1) 从 Redis key:subscribe 集合取订阅的 device_id ======
        $redisIds = [];
        if ($keyValue !== '') {
            $tmp = $redis->sMembers("key:subscribe:{$keyValue}");
            if (is_array($tmp)) {
                foreach ($tmp as $v) {
                    $v = (string)$v;
                    if ($v !== '') {
                        $redisIds[$v] = true;
                    }
                }
            }
            // 兜底：如果 sMembers 返回空但 sCard > 0，尝试用 sScan 遍历（处理大集合或连接池差异）
            if (empty($redisIds) && $redisSetCount > 0) {
                $iterator = null;
                $scanned = [];
                do {
                    $result = $redis->sScan("key:subscribe:{$keyValue}", $iterator, null, 500);
                    if (is_array($result)) {
                        $iterator = $result[0];
                        $members = $result[1] ?? [];
                        if (is_array($members)) {
                            foreach ($members as $v) {
                                $v = (string)$v;
                                if ($v !== '') $scanned[$v] = true;
                            }
                        }
                    } else {
                        break;
                    }
                } while ($iterator > 0);
                if (!empty($scanned)) {
                    $redisIds = $scanned;
                    error_log("[PushKey] subscribers sScan 兜底命中 " . count($redisIds) . " 台设备 (sCard={$redisSetCount})");
                }
            }
        }

        // ====== 2) 从 devices 表 WHERE push_key_id = id 的所有登记记录 ======
        $dbDeviceRows = Database::fetchAll(
            "SELECT id, device_id, device_name, device_model, os_version, platform, app_version,
                    ip, status, last_connect_at, last_active_at, user_id, push_key_id
             FROM devices WHERE push_key_id = ?",
            [$pushKeyId]
        );
        $deviceMap = [];
        $dbIds = [];
        foreach ($dbDeviceRows as $row) {
            $did = (string)$row['device_id'];
            if ($did === '') {
                continue;
            }
            $deviceMap[$did] = $row;
            $dbIds[$did] = true;
        }

        // ====== 3) 从 device:key 哈希中筛 value === keyValue 的设备（反向校验） ======
        $deviceKeyHashIds = [];
        if ($keyValue !== '') {
            // 兼容大小写不敏感的 key_value 差异（Redis 大小写敏感但客户端可能发送不一致）
            $kvLower = strtolower($keyValue);
            $allDeviceKey = $redis->hGetAll('device:key');
            if (is_array($allDeviceKey)) {
                foreach ($allDeviceKey as $did => $v) {
                    $did = (string)$did;
                    $v = (string)$v;
                    if ($did !== '' && $v !== '' && strtolower($v) === $kvLower) {
                        $deviceKeyHashIds[$did] = true;
                    }
                }
            }
        }

        // ====== 4) 从当前 ws:conn:* 连接详情中反查 push_key_id = ? 的在线会话设备
        //         （最硬的一手兜底：WebSocket 进程的 registerDevice 只要执行过，
        //          ws:conn:* 就一定有记录，哪怕三方映射都乱套了也能识别。）
        $wsConnIds = [];
        $wsConnDeviceInfo = [];
        try {
            $onlineFds = $redis->sMembers('ws:online');
            if (is_array($onlineFds)) {
                foreach ($onlineFds as $fd) {
                    $fd = (string)$fd;
                    if ($fd === '') continue;
                    $connInfo = $redis->hGetAll("ws:conn:{$fd}");
                    if (!is_array($connInfo)) continue;
                    $connPushKeyId = (int)($connInfo['push_key_id'] ?? 0);
                    $connKeyValue = (string)($connInfo['key_value'] ?? '');
                    if ($connPushKeyId <= 0) {
                        // 退化匹配：老数据没有 push_key_id 字段时按 key_value 匹配
                        if ($keyValue !== '' && $connKeyValue !== ''
                            && strtolower($connKeyValue) === strtolower($keyValue)) {
                            $connPushKeyId = $pushKeyId;
                        }
                    }
                    if ($connPushKeyId !== $pushKeyId) continue;
                    $did = (string)($connInfo['device_id'] ?? '');
                    if ($did === '') continue;
                    $wsConnIds[$did] = true;
                    if (!isset($wsConnDeviceInfo[$did])) {
                        $wsConnDeviceInfo[$did] = [
                            'ip'               => (string)($connInfo['ip'] ?? ''),
                            'last_connect_at'  => isset($connInfo['connect_at']) ? date('Y-m-d H:i:s', (int)$connInfo['connect_at']) : null,
                            'fd_count'         => 1,
                        ];
                    } else {
                        $wsConnDeviceInfo[$did]['fd_count'] += 1;
                        // 保留连接时间最早 / IP 最后一次非空
                        if (!empty($connInfo['ip']) && $wsConnDeviceInfo[$did]['ip'] === '') {
                            $wsConnDeviceInfo[$did]['ip'] = (string)$connInfo['ip'];
                        }
                        if (isset($connInfo['connect_at'])) {
                            $connTs = (int)$connInfo['connect_at'];
                            $earliestTs = $wsConnDeviceInfo[$did]['last_connect_at']
                                ? (strtotime($wsConnDeviceInfo[$did]['last_connect_at']) ?: PHP_INT_MAX)
                                : PHP_INT_MAX;
                            if ($connTs < $earliestTs) {
                                $wsConnDeviceInfo[$did]['last_connect_at'] = date('Y-m-d H:i:s', $connTs);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[PushKey] subscribers 扫描 ws:conn:* 异常: ' . $e->getMessage());
        }

        // ====== 合并四方 device_id ======
        $allDeviceIds = array_unique(array_merge(
            array_keys($redisIds),
            array_keys($dbIds),
            array_keys($deviceKeyHashIds),
            array_keys($wsConnIds)
        ));

        if (empty($allDeviceIds)) {
            return [
                'key_info'       => $key,
                'list'           => [],
                'total'          => 0,
                'online_count'   => 0,
                // 调试统计：帮助快速定位"为什么全是 0"
                '_debug'         => [
                    'key_value'                  => $keyValue,
                    'push_key_id'                => $pushKeyId,
                    'redis_subscribe_count'      => count($redisIds),
                    'redis_subscribe_scard'      => $redisSetCount,
                    'db_device_count'            => count($dbIds),
                    'device_key_hash_count'      => count($deviceKeyHashIds),
                    'device_key_hash_total'      => $deviceKeyTotal,
                    'ws_online_conn_match_count' => count($wsConnIds),
                    'ws_online_total_fd'         => is_array($redis->sMembers('ws:online')) ? count($redis->sMembers('ws:online')) : 0,
                    'note'                       => $redisSetCount > 0 && count($redisIds) === 0
                        ? "⚠️ Redis sCard={$redisSetCount} 但 sMembers 返回空，集合可能已损坏或连接异常"
                        : '',
                ],
            ];
        }

        // 逐个组装返回
        $list = [];
        $onlineCount = 0;
        foreach ($allDeviceIds as $deviceId) {
            $deviceId = (string)$deviceId;
            if ($deviceId === '') {
                continue;
            }

            $wsConnInfo = $wsConnDeviceInfo[$deviceId] ?? null;
            $fdCount = (int)($wsConnInfo['fd_count'] ?? 0);
            // 兜底：ws:device:{} 集合（如果 wsConnInfo 没捕捉到，但集合里确实有 fd，那也认为在线）
            if ($fdCount <= 0) {
                $fdCount = (int)$redis->sCard('ws:device:' . $deviceId);
            }
            $isOnline = $fdCount > 0;
            if ($isOnline) {
                $onlineCount++;
            }

            $inRedis        = isset($redisIds[$deviceId]);
            $inDb           = isset($dbIds[$deviceId]);
            $inDeviceKey    = isset($deviceKeyHashIds[$deviceId]);
            $inWsConn       = isset($wsConnIds[$deviceId]);

            // 来源 tag：哪些映射来源认定它属于这个 Key
            $sources = [];
            if ($inRedis)        $sources[] = 'key:subscribe';
            if ($inDeviceKey)    $sources[] = 'device:key';
            if ($inDb)           $sources[] = 'devices表';
            if ($inWsConn)       $sources[] = '在线会话';
            if (empty($sources)) $sources[] = 'unknown';

            $dbRow = $deviceMap[$deviceId] ?? null;

            // 如果 DB 没记录但 ws:conn 在线会话有记录，就把 IP / 连接时间填回来
            $fallbackIp = (string)($wsConnInfo['ip'] ?? '');
            $fallbackConnectAt = $wsConnInfo['last_connect_at'] ?? null;

            // exists_in_db = 1 是"存在于 devices 表"，0 是"只有 Redis 映射，设备记录被删了"=僵尸订阅
            // source_status：
            //   normal                 = DB + Redis 集合 + device:key 三方齐全
            //   mapping_missing        = 有 DB/在线会话，但 Redis 订阅集合缺失（推送发不到，最常见需要修复）
            //   online_mapping_missing = 在线会话存在（一定有设备），但集合 + device:key 都没有
            //   zombie                 = 只有 Redis 映射，DB/会话都没记录
            //   partial                = 其它不完整组合
            if ($inDb && $inRedis && $inDeviceKey) {
                $sourceStatus = 'normal';
            } elseif (($inDb || $inWsConn) && !$inRedis) {
                // 有设备登记 / 有在线连接，但 key:subscribe 没它 → 推送肯定漏发
                $sourceStatus = 'mapping_missing';
            } elseif (!$inDb && ($inRedis || $inDeviceKey) && !$inWsConn) {
                $sourceStatus = 'zombie';
            } else {
                $sourceStatus = 'partial';
            }

            $ip = (string)($dbRow['ip'] ?? '');
            if ($ip === '' && $fallbackIp !== '') $ip = $fallbackIp;
            $lastConnectAt = $dbRow['last_connect_at'] ?? $fallbackConnectAt;

            $list[] = [
                'device_id'        => $deviceId,
                'fd_count'         => $fdCount,
                'online'           => $isOnline ? 1 : 0,
                'exists_in_db'     => $inDb ? 1 : 0,
                'in_redis_sub'     => $inRedis ? 1 : 0,
                'in_device_key'    => $inDeviceKey ? 1 : 0,
                'in_ws_conn'       => $inWsConn ? 1 : 0,
                'sources'          => $sources,
                'source_status'    => $sourceStatus,
                'device_name'      => (string)($dbRow['device_name'] ?? ''),
                'device_model'     => (string)($dbRow['device_model'] ?? ''),
                'platform'         => (string)($dbRow['platform'] ?? ''),
                'os_version'       => (string)($dbRow['os_version'] ?? ''),
                'app_version'      => (string)($dbRow['app_version'] ?? ''),
                'ip'               => $ip,
                'status'           => (int)($dbRow['status'] ?? ($isOnline ? 1 : 0)),
                'last_connect_at'  => $lastConnectAt,
                'last_active_at'   => $dbRow['last_active_at'] ?? $lastConnectAt,
                'user_id'          => (int)($dbRow['user_id'] ?? 0),
                'db_device_id'     => (int)($dbRow['id'] ?? 0),
            ];
        }

        // 排序：在线 > 订阅丢失(mapping_missing，要优先修) > 存在DB > 僵尸订阅 > 离线
        $statusRank = [
            'mapping_missing' => 0,
            'normal'          => 1,
            'partial'         => 2,
            'zombie'          => 3,
        ];
        usort($list, function ($a, $b) use ($statusRank) {
            if ($a['online'] !== $b['online']) return $b['online'] <=> $a['online'];
            $ar = $statusRank[$a['source_status']] ?? 9;
            $br = $statusRank[$b['source_status']] ?? 9;
            if ($ar !== $br) return $ar <=> $br;
            return $b['exists_in_db'] <=> $a['exists_in_db'];
        });

        return [
            'key_info'     => $key,
            'list'         => $list,
            'total'        => count($list),
            'online_count' => $onlineCount,
            '_debug'       => [
                'key_value'                  => $keyValue,
                'push_key_id'                => $pushKeyId,
                'redis_subscribe_count'      => count($redisIds),
                'redis_subscribe_scard'      => $redisSetCount,
                'db_device_count'            => count($dbIds),
                'device_key_hash_count'      => count($deviceKeyHashIds),
                'device_key_hash_total'      => $deviceKeyTotal,
                'ws_online_conn_match_count' => count($wsConnIds),
                'ws_online_total_fd'         => is_array($redis->sMembers('ws:online')) ? count($redis->sMembers('ws:online')) : 0,
            ],
        ];
    }

    /**
     * 从某个 Key 中移除一个订阅设备（同时断开在线连接 + 清理订阅关系）
     * 路由：DELETE /admin/keys/{id}/subscribers/{device_id}
     *
     * 支持各种不完整的映射状态：
     *   - 只有 key:subscribe 集合
     *   - 只有 device:key 哈希（含大小写不匹配）
     *   - 只有 devices 表记录（无 Redis 映射，mapping_missing 的反例）
     * 删除时全部清理。
     */
    public function removeSubscriber(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        $deviceId = (string)($params['device_id'] ?? '');
        if ($id <= 0 || $deviceId === '') {
            Response::fail($context['response'], '参数错误', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = Database::fetch(
            'SELECT id, key_value, name FROM push_keys WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($key === false) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        $keyValue = (string)($key['key_value'] ?? '');

        $redis = \App\Service\Redis::getInstance();

        // ===== 检查：key:subscribe / device:key(含大小写不匹配) / devices 表 任一存在即允许删除 =====
        $inSubscribe = $keyValue !== '' ? (int)$redis->sIsMember("key:subscribe:{$keyValue}", $deviceId) > 0 : false;
        $kvLower = $keyValue !== '' ? strtolower($keyValue) : '';
        $deviceKeyValue = (string)$redis->hGet('device:key', $deviceId);
        $inDeviceKey = ($deviceKeyValue !== '' && $deviceKeyValue !== null)
            && ($kvLower !== '' && strtolower($deviceKeyValue) === $kvLower);

        $dbCount = (int)(Database::fetch(
            'SELECT COUNT(*) AS c FROM devices WHERE push_key_id = ? AND device_id = ? LIMIT 1',
            [$id, $deviceId]
        )['c'] ?? 0);
        $inDb = $dbCount > 0;

        if (!$inSubscribe && !$inDeviceKey && !$inDb) {
            Response::fail($context['response'], '此设备未订阅该 Key（三个来源均无记录）', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $removedSubscribe = 0;
        $removedDeviceKey = 0;
        $removedDb = 0;

        // 1. 清理 key:subscribe（含大小写不匹配的 key_value——如果 device:key 里存的是其它大小写版本，也要把那个集合里的删掉）
        if ($keyValue !== '') {
            $removedSubscribe += (int)$redis->sRem("key:subscribe:{$keyValue}", $deviceId);
        }
        if ($deviceKeyValue !== '' && $deviceKeyValue !== $keyValue) {
            $removedSubscribe += (int)$redis->sRem("key:subscribe:{$deviceKeyValue}", $deviceId);
        }

        // 2. 清理 device:key 哈希
        if ($deviceKeyValue !== '') {
            $removedDeviceKey += (int)$redis->hDel('device:key', $deviceId);
        }

        // 3. 如果设备在线，踢下线
        $fds = $redis->sMembers('ws:device:' . $deviceId);
        $fdCount = is_array($fds) ? count($fds) : 0;
        if ($fdCount > 0) {
            $cmd = [
                'action'     => 'disconnect',
                'device_id'  => $deviceId,
                'fds'        => array_map('intval', $fds),
                'reason'     => 'removed from key subscribers',
                'created_at' => time(),
            ];
            $redis->lPush('ws:queue:cmd', json_encode($cmd, JSON_UNESCAPED_UNICODE));

            foreach ($fds as $fd) {
                $redis->sRem("ws:device:{$deviceId}", (string)$fd);
                $redis->hDel('ws:fd:device', (string)$fd);
                $redis->sRem('ws:online', (string)$fd);
                $redis->del("ws:conn:{$fd}");
            }
        }

        // 4. 清理 devices 表中的该设备记录（用户点"删除订阅设备"就是要彻底删除该订阅关系，保留设备行没意义）
        try {
            $del = Database::execute(
                'DELETE FROM devices WHERE push_key_id = ? AND device_id = ? LIMIT 1',
                [$id, $deviceId]
            );
            $removedDb = is_numeric($del) ? (int)$del : 0;
        } catch (\Throwable $e) {
            $removedDb = 0;
        }

        return [
            'removed'              => true,
            'disconnected'         => $fdCount,
            'redis_subscribe_rm'   => $removedSubscribe,
            'redis_device_key_rm'  => $removedDeviceKey,
            'db_rows_removed'      => $removedDb,
            'message'              => "设备 {$deviceId} 已移除（断开连接 {$fdCount} 个，集合 {$removedSubscribe}，哈希 {$removedDeviceKey}，表 {$removedDb} 行）",
        ];
    }

    /**
     * 修复单个设备的订阅映射
     * 路由：PUT /admin/keys/{id}/subscribers/{device_id}/repair
     *
     * 针对 mapping_missing（devices 表有，但 Redis key:subscribe/device:key 缺失）：
     *   把设备补回 key:subscribe:{keyValue} 集合 + 写入 device:key 哈希
     * 针对 zombie（只有 Redis 映射，devices 表没记录）：
     *   保持现状（提示用户删除即可），不会凭空造 DB 行。
     */
    public function repairSubscriber(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        $deviceId = (string)($params['device_id'] ?? '');
        if ($id <= 0 || $deviceId === '') {
            Response::fail($context['response'], '参数错误', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = Database::fetch(
            'SELECT id, key_value, name FROM push_keys WHERE id = ? LIMIT 1',
            [$id]
        );
        if ($key === false) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }
        $keyValue = (string)($key['key_value'] ?? '');
        if ($keyValue === '') {
            Response::fail($context['response'], 'Key 的 key_value 为空，无法修复', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $dbRow = Database::fetch(
            'SELECT device_id FROM devices WHERE push_key_id = ? AND device_id = ? LIMIT 1',
            [$id, $deviceId]
        );
        if ($dbRow === false) {
            Response::fail($context['response'], 'devices 表中不存在此设备（僵尸订阅不能修复，只能删除）', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $redis = \App\Service\Redis::getInstance();
        $addedSet  = (int)$redis->sAdd("key:subscribe:{$keyValue}", $deviceId);
        $oldHashVal = (string)$redis->hGet('device:key', $deviceId);
        $redis->hSet('device:key', $deviceId, $keyValue);
        $updatedHash = ($oldHashVal === $keyValue) ? 0 : 1;

        return [
            'repaired'           => true,
            'added_to_sub_set'   => $addedSet,
            'updated_device_key' => $updatedHash,
            'message'            => "订阅关系已修复：加入集合 {$addedSet} 项，更新 device:key {$updatedHash} 项",
        ];
    }

    public function create(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $body = $this->parseBody($context);
        $name = (string)($body['name'] ?? $body['title'] ?? '');

        if ($name === '') {
            Response::fail($context['response'], '名称不能为空', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $keyValue = $this->generateKeyValue();

        // 兼容 camelCase 和 snake_case 字段名；未提供时默认 10（与表 DEFAULT 一致）
        $maxDevices = (int)($body['max_devices'] ?? $body['daily_limit'] ?? $body['dailyLimit'] ?? $body['maxDevices'] ?? 10);
        $notifyEmail = (string)($body['notify_email'] ?? $body['notifyEmail'] ?? '');
        $notifyEnabled = (int)($body['notify_enabled'] ?? $body['notifyEnabled'] ?? 0);
        $notifyInterval = (int)($body['notify_interval'] ?? $body['notifyInterval'] ?? 300);

        // 归属用户：管理员创建的 Key 默认为全局 Key（user_id=0）；
        // 若 body 中显式指定 user_id（管理员为指定用户创建），则使用该值。
        $userId = (int)($body['user_id'] ?? $body['userId'] ?? 0);
        if ($userId > 0) {
            // 校验用户是否存在
            $existsUser = Database::fetch('SELECT id FROM users WHERE id = ? LIMIT 1', [$userId]);
            if ($existsUser === false) {
                Response::fail($context['response'], '指定的归属用户不存在', Response::CODE_BAD_REQUEST, 400);
                return false;
            }
        } else {
            $userId = 0;
        }

        // 先插入基本字段（确保兼容旧表结构）
        $id = Database::insert(
            'INSERT INTO push_keys (key_value, name, max_devices, user_id, status)
             VALUES (?, ?, ?, ?, 1)',
            [$keyValue, $name, $maxDevices, $userId]
        );

        // 尝试更新通知字段（如果表中有这些列）
        try {
            Database::execute(
                'UPDATE push_keys SET notify_email = ?, notify_enabled = ?, notify_interval = ? WHERE id = ?',
                [$notifyEmail, $notifyEnabled, $notifyInterval, $id]
            );
        } catch (\Throwable $e) {
            // 表中可能没有通知字段，忽略错误
        }

        // 直接返回新创建的记录
        return $this->fetchKeyById((int)$id) ?: ['id' => $id, 'key_value' => $keyValue, 'name' => $name];
    }

    public function update(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = Database::fetch('SELECT id FROM push_keys WHERE id = ? LIMIT 1', [$id]);
        if ($key === false) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        $body = $this->parseBody($context);
        $data = [];

        // 基本字段更新
        $basicData = [];
        if (isset($body['name'])) {
            $basicData['name'] = (string)$body['name'];
        } elseif (isset($body['title'])) {
            $basicData['name'] = (string)$body['title'];
        }
        if (isset($body['max_devices'])) {
            $basicData['max_devices'] = (int)$body['max_devices'];
        } elseif (isset($body['daily_limit'])) {
            $basicData['max_devices'] = (int)$body['daily_limit'];
        } elseif (isset($body['dailyLimit'])) {
            $basicData['max_devices'] = (int)$body['dailyLimit'];
        } elseif (isset($body['maxDevices'])) {
            $basicData['max_devices'] = (int)$body['maxDevices'];
        }
        if (isset($body['status'])) {
            $basicData['status'] = (int)$body['status'];
        }
        // 归属用户改绑：支持 user_id / userId 字段，0=全局 Key
        if (array_key_exists('user_id', $body) || array_key_exists('userId', $body)) {
            $newUserId = (int)($body['user_id'] ?? $body['userId'] ?? 0);
            if ($newUserId > 0) {
                $existsUser = Database::fetch('SELECT id FROM users WHERE id = ? LIMIT 1', [$newUserId]);
                if ($existsUser === false) {
                    Response::fail($context['response'], '指定的归属用户不存在', Response::CODE_BAD_REQUEST, 400);
                    return false;
                }
            } else {
                $newUserId = 0;
            }
            $basicData['user_id'] = $newUserId;
        }

        if (!empty($basicData)) {
            $basicData['updated_at'] = date('Y-m-d H:i:s');
            $columns = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($basicData)));
            $values = array_values($basicData);
            $values[] = $id;
            Database::execute("UPDATE push_keys SET {$columns} WHERE id = ?", $values);
        }

        // 通知字段更新（单独处理，兼容表中无这些列的情况）
        $notifyData = [];
        if (isset($body['notify_email'])) {
            $notifyData['notify_email'] = (string)$body['notify_email'];
        } elseif (isset($body['notifyEmail'])) {
            $notifyData['notify_email'] = (string)$body['notifyEmail'];
        }
        if (isset($body['notify_enabled'])) {
            $notifyData['notify_enabled'] = (int)$body['notify_enabled'];
        } elseif (isset($body['notifyEnabled'])) {
            $notifyData['notify_enabled'] = (int)$body['notifyEnabled'];
        }
        if (isset($body['notify_interval'])) {
            $notifyData['notify_interval'] = (int)$body['notify_interval'];
        } elseif (isset($body['notifyInterval'])) {
            $notifyData['notify_interval'] = (int)$body['notifyInterval'];
        }

        if (!empty($notifyData)) {
            $notifyData['updated_at'] = date('Y-m-d H:i:s');
            $columns = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($notifyData)));
            $values = array_values($notifyData);
            $values[] = $id;
            try {
                Database::execute("UPDATE push_keys SET {$columns} WHERE id = ?", $values);
            } catch (\Throwable $e) {
                // 表中可能没有通知字段，忽略
            }
        }

        // 直接返回更新后的记录
        return $this->fetchKeyById($id) ?: ['id' => $id];
    }

    public function delete(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $key = Database::fetch('SELECT id FROM push_keys WHERE id = ? LIMIT 1', [$id]);
        if ($key === false) {
            Response::fail($context['response'], 'Key 不存在', Response::CODE_NOT_FOUND, 404);
            return false;
        }

        Database::execute('DELETE FROM push_keys WHERE id = ?', [$id]);

        return ['deleted' => true];
    }

    public function updateStatus(array $context, array $params)
    {
        $admin = AdminAuth::authenticate($context);
        if ($admin === null) {
            return false;
        }

        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            Response::fail($context['response'], '无效的 ID', Response::CODE_BAD_REQUEST, 400);
            return false;
        }

        $body = $this->parseBody($context);
        $status = (int)($body['status'] ?? 0);

        Database::execute('UPDATE push_keys SET status = ?, updated_at = NOW() WHERE id = ?', [$status, $id]);

        return ['id' => $id, 'status' => $status];
    }

    private function generateKeyValue(): string
    {
        $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $key = '';
        for ($i = 0; $i < 32; $i++) {
            $key .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $key;
    }

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

    /**
     * 查询单条 Key 记录（兼容表中无通知字段的情况）
     */
    private function fetchKeyById(int $id): ?array
    {
        $row = Database::fetch(
            'SELECT pk.id, pk.key_value, pk.name, pk.user_id, pk.max_devices, pk.status, pk.created_at, pk.updated_at,
                    u.username
             FROM push_keys pk
             LEFT JOIN users u ON u.id = pk.user_id
             WHERE pk.id = ? LIMIT 1',
            [$id]
        );

        if ($row === false) {
            return null;
        }

        // 尝试追加通知字段
        $rows = $this->appendNotifyFields([$row]);
        return $rows[0] ?? $row;
    }

    /**
     * 为列表追加通知字段（notify_email, notify_enabled, notify_interval）
     * 如果表中不存在这些列，则填充默认值
     */
    private function appendNotifyFields(array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }

        $ids = array_column($rows, 'id');
        if (empty($ids)) {
            return $rows;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            $notifyRows = Database::fetchAll(
                "SELECT id, notify_email, notify_enabled, notify_interval
                 FROM push_keys WHERE id IN ({$placeholders})",
                $ids
            );

            if (!empty($notifyRows)) {
                $notifyMap = [];
                foreach ($notifyRows as $nr) {
                    $notifyMap[$nr['id']] = $nr;
                }
                foreach ($rows as &$row) {
                    $nr = $notifyMap[$row['id']] ?? null;
                    $row['notify_email']     = $nr['notify_email'] ?? '';
                    $row['notify_enabled']   = $nr['notify_enabled'] ?? 0;
                    $row['notify_interval']  = $nr['notify_interval'] ?? 300;
                }
                unset($row);
            } else {
                foreach ($rows as &$row) {
                    $row['notify_email']     = '';
                    $row['notify_enabled']   = 0;
                    $row['notify_interval']  = 300;
                }
                unset($row);
            }
        } catch (\Throwable $e) {
            // 表中可能没有通知字段，填充默认值
            foreach ($rows as &$row) {
                $row['notify_email']     = '';
                $row['notify_enabled']   = 0;
                $row['notify_interval']  = 300;
            }
            unset($row);
        }

        return $rows;
    }
}