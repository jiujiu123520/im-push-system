(function () {
  'use strict';

  var API_BASE = location.origin;
  var LS_DEVICE_ID = 'pwa_device_id';
  var LS_PUSH_KEY = 'pwa_push_key';
  var APP_VERSION = '1.0.0';

  var PAGE_SIZE = 50;        // 每页拉取条数（服务端上限 100）
  var CLAMP_LIMIT = 140;     // 正文超过该长度即折叠
  var TOMBSTONE_MAX = 500;   // 删除墓碑最多保留条数
  var SEARCH_DEBOUNCE = 180;
  var FOREGROUND_SYNC_GAP = 5000;  // 两次同步的最短间隔，避免手动刷新与回前台重复触发
  var lastSyncAt = 0;              // 上次发起同步的时间戳

  // IndexedDB config 键名（与 sw.js 共用同一套键）
  var CFG = {
    PUSH_KEY: 'push_key',
    DEVICE_ID: 'device_id',
    VAPID: 'vapid_public_key',
    LAST_ENDPOINT: 'last_endpoint',
    WAS_SUBSCRIBED: 'was_subscribed',
    DELETED_IDS: 'deleted_ids',
    CLEARED_BEFORE: 'cleared_before_id',
    MAX_SEEN: 'max_seen_id',
    NEXT_BEFORE: 'next_before_id',
    HAS_MORE: 'has_more'
  };

  var state = {
    messages: [],        // 本地归档，按 id 倒序
    deletedIds: {},      // 本地删除墓碑：id -> 1
    clearedBeforeId: 0,  // 清空水位线，id <= 该值的一律不展示
    maxSeenId: 0,        // 服务端返回过的最大 id
    nextBeforeId: 0,     // 分页游标：取 id < nextBeforeId 的消息
    hasMore: true,
    loading: false,
    keyword: '',
    expanded: {},        // 长文展开状态：id -> true
    vapidKey: '',
    wasSubscribed: false,
    userScrolled: false,
    pendingHighlight: ''
  };

  var swRegistration = null;
  var subscription = null;
  var searchTimer = null;

  // ---------- DOM ----------
  var keyInput = document.getElementById('key-input');
  var saveKeyBtn = document.getElementById('save-key-btn');
  var subscribeBtn = document.getElementById('subscribe-btn');
  var testBtn = document.getElementById('test-btn');
  var refreshBtn = document.getElementById('refresh-btn');
  var statusEl = document.getElementById('status');
  var messageList = document.getElementById('message-list');
  var listFooter = document.getElementById('list-footer');
  var loadSentinel = document.getElementById('load-sentinel');
  var searchInput = document.getElementById('search-input');
  var clearBtn = document.getElementById('clear-btn');
  var iosTip = document.getElementById('ios-tip');
  var subWarn = document.getElementById('sub-warn');

  // ---------- 基础工具 ----------

  function setStatus(msg) { statusEl.textContent = msg; }

  function showSubWarn(msg) {
    subWarn.textContent = msg;
    subWarn.style.display = 'block';
    setTimeout(function () { subWarn.style.display = 'none'; }, 10000);
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // 不解析时区，直接把服务端 "YYYY-MM-DD HH:mm:ss" 规整成 "MM-DD HH:mm"
  function formatTime(s) {
    if (!s) { return ''; }
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (!m) { return String(s); }
    var prefix = (m[1] === String(new Date().getFullYear())) ? '' : m[1] + '-';
    return prefix + m[2] + '-' + m[3] + ' ' + m[4] + ':' + m[5];
  }

  function generateUuid() {
    if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (crypto.getRandomValues(new Uint8Array(1))[0] & 15) >> (c === 'x' ? 0 : 3);
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  // ---------- 设备标识 / Key（localStorage 为唯一真源，同时镜像进 IndexedDB 供 SW 使用） ----------

  function getDeviceId() {
    var id = localStorage.getItem(LS_DEVICE_ID);
    if (!id) {
      id = 'pwa_' + generateUuid();
      localStorage.setItem(LS_DEVICE_ID, id);
    }
    return id;
  }

  function getPushKey() { return localStorage.getItem(LS_PUSH_KEY) || ''; }
  function setPushKey(k) { localStorage.setItem(LS_PUSH_KEY, k); }

  function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function detectPlatform() {
    // 优先用 Client Hints 品牌识别（User-Agent Switcher 只改 UA 字符串，不改 brands）
    if (navigator.userAgentData && navigator.userAgentData.brands && navigator.userAgentData.brands.length) {
      var brands = navigator.userAgentData.brands;
      for (var i = 0; i < brands.length; i++) {
        if (brands[i].brand === 'Microsoft Edge') { return 'edge'; }
        if (brands[i].brand === 'Google Chrome') { return 'chrome'; }
      }
    }
    var ua = navigator.userAgent;
    if (/Edg\//.test(ua)) { return 'edge'; }
    if (/Chrome\//.test(ua) && !/Edg\//.test(ua)) { return 'chrome'; }
    return 'ios';
  }

  // 读取 iOS 系统版本
  //
  // 注意：不能用 UA 里的 "CPU iPhone OS 18_7" —— Apple 为降低指纹追踪把这一段
  // 冻结在了旧版本，系统升到 iOS 26 后它依然是 18_7，永远读不到真实版本。
  // 真正跟随系统升级的是 "Version/26.6.1"，且 iOS 上 Safari 版本号与系统版本号
  // 一致（iOS 26.x ⇄ Safari 26.x），故优先取 Version，UA 段仅作兜底。
  function detectIOSVersion(ua) {
    var vm = ua.match(/Version\/(\d+(?:[._]\d+)*)/);
    if (vm) { return vm[1].replace(/_/g, '.'); }
    var om = ua.match(/CPU (?:iPhone )?OS (\d+(?:[._]\d+)*)/);
    if (om) { return om[1].replace(/_/g, '.'); }
    return '';
  }

  function getDeviceInfo() {
    var ua = navigator.userAgent || '';
    var platform = detectPlatform();
    var model = '';
    var osVersion = '';

    if (platform === 'ios') {
      if (/iPad/.test(ua)) { model = 'iPad'; }
      else if (/iPhone/.test(ua)) { model = 'iPhone'; }
      else if (/iPod/.test(ua)) { model = 'iPod'; }
      else { model = 'iOS 设备'; }
      var iosVer = detectIOSVersion(ua);
      if (iosVer) { osVersion = 'iOS ' + iosVer; }
    } else if (platform === 'edge') {
      model = 'Edge';
      var em = ua.match(/Edg\/([\d.]+)/);
      if (em) { osVersion = 'Edge ' + em[1]; }
    } else {
      model = 'Chrome';
      var cm = ua.match(/Chrome\/([\d.]+)/);
      if (cm) { osVersion = 'Chrome ' + cm[1]; }
    }

    return {
      platform: platform,
      deviceName: model + ' PWA',
      model: model,
      osVersion: osVersion
    };
  }

  // ---------- 本地状态读写 ----------

  function loadLocalState() {
    return PushDB.Config.getMany([
      CFG.DELETED_IDS, CFG.CLEARED_BEFORE, CFG.MAX_SEEN,
      CFG.NEXT_BEFORE, CFG.HAS_MORE, CFG.VAPID, CFG.WAS_SUBSCRIBED
    ]).then(function (cfg) {
      var set = {};
      var ids = cfg[CFG.DELETED_IDS];
      (Array.isArray(ids) ? ids : []).forEach(function (x) { set[Number(x)] = 1; });
      state.deletedIds = set;
      state.clearedBeforeId = Number(cfg[CFG.CLEARED_BEFORE] || 0);
      state.maxSeenId = Number(cfg[CFG.MAX_SEEN] || 0);
      state.nextBeforeId = Number(cfg[CFG.NEXT_BEFORE] || 0);
      state.hasMore = cfg[CFG.HAS_MORE] === undefined ? true : !!cfg[CFG.HAS_MORE];
      state.vapidKey = cfg[CFG.VAPID] || '';
      state.wasSubscribed = !!cfg[CFG.WAS_SUBSCRIBED];
      return PushDB.Messages.all();
    }).then(function (rows) {
      // 本地归档排序 + 过滤掉已删除/已清空的记录
      state.messages = (rows || [])
        .map(function (m) { m.id = Number(m.id); return m; })
        .filter(function (m) {
          return m.id > state.clearedBeforeId && !state.deletedIds[m.id];
        })
        .sort(function (a, b) { return b.id - a.id; });
    }).catch(function () {
      // IndexedDB 不可用（隐私模式等）时退化为纯内存运行
    });
  }

  function persistState() {
    return PushDB.Config.setMany({
      deleted_ids: Object.keys(state.deletedIds).map(Number),
      cleared_before_id: state.clearedBeforeId,
      max_seen_id: state.maxSeenId,
      next_before_id: state.nextBeforeId,
      has_more: state.hasMore
    }).catch(function () { /* 写失败不影响使用 */ });
  }

  // ---------- 渲染 ----------

  function visibleMessages() {
    var kw = state.keyword.trim().toLowerCase();
    return state.messages.filter(function (m) {
      if (m.id <= state.clearedBeforeId) { return false; }
      if (state.deletedIds[m.id]) { return false; }
      if (kw) {
        var hay = ((m.title || '') + '\n' + (m.content || '')).toLowerCase();
        if (hay.indexOf(kw) === -1) { return false; }
      }
      return true;
    });
  }

  function renderItem(m) {
    var id = m.id;
    var title = escapeHtml(m.title || '(无标题)');
    var content = escapeHtml(m.content || '');
    var time = escapeHtml(formatTime(m.created_at));
    var isLong = String(m.content || '').length > CLAMP_LIMIT;
    var expanded = !!state.expanded[id];

    var html = '<div class="message-item" data-id="' + id + '">' +
      '<div class="message-head">' +
        '<div class="message-title">' + title + '</div>' +
        '<button class="message-del" data-act="del" data-id="' + id + '" aria-label="删除">&#215;</button>' +
      '</div>' +
      '<div class="message-content' + (isLong && !expanded ? ' clamped' : '') + '">' + content + '</div>';

    if (isLong) {
      html += '<button class="link-btn message-toggle" data-act="toggle" data-id="' + id + '">' +
        (expanded ? '收起' : '展开') + '</button>';
    }

    html += '<div class="message-time">' + time + '</div></div>';
    return html;
  }

  function renderFooter(visibleCount) {
    var n = typeof visibleCount === 'number' ? visibleCount : visibleMessages().length;
    var total = state.messages.length;
    var html = '';

    if (state.keyword) {
      html = '<span class="foot-text">匹配 ' + n + ' 条 / 本地共 ' + total + ' 条</span>';
    } else if (state.loading) {
      html = '<span class="foot-text">加载中…</span>';
    } else if (state.hasMore && total) {
      html = '<button class="btn-ghost" data-act="load-more">加载更多</button>';
    } else if (total) {
      html = '<span class="foot-text">已加载全部 ' + total + ' 条</span>';
    }

    listFooter.innerHTML = html;
  }

  function render() {
    var list = visibleMessages();

    if (!list.length) {
      messageList.innerHTML = '<div class="empty">' +
        (state.keyword ? '没有匹配的消息' : '暂无消息') + '</div>';
    } else {
      var html = '';
      for (var i = 0; i < list.length; i++) { html += renderItem(list[i]); }
      messageList.innerHTML = html;
    }

    renderFooter(list.length);
    applyPendingHighlight();
  }

  // 列表是在顶部插入新消息的，整页高度会向下增长。若用户已经往下滚在看旧消息，
  // 直接重渲染会把他"顶走"。这里按内容高度差补回滚动偏移，保持视觉位置不变。
  // 打开页面与回到前台都会自动拉取，若不补偿会明显打断阅读。
  function renderKeepingScroll() {
    var prevHeight = document.body.scrollHeight;
    var prevY = window.scrollY;

    render();

    if (prevY > 0) {
      var delta = document.body.scrollHeight - prevHeight;
      if (delta !== 0) { window.scrollTo(0, prevY + delta); }
    }
  }

  // 通知里的 message_id 是业务字符串（如 msg_xxx / test_xxx），不是数据库自增 id，
  // 所以要先在本地归档里按 message_id 反查，找不到就留待下一轮 render（同步到之后自然会命中）
  function resolveHighlightId() {
    var wanted = state.pendingHighlight;
    if (!wanted) { return 0; }
    for (var i = 0; i < state.messages.length; i++) {
      var m = state.messages[i];
      if (String(m.message_id || '') === wanted) { return m.id; }
      if (String(m.id) === wanted) { return m.id; }
    }
    return 0;
  }

  function applyPendingHighlight() {
    if (!state.pendingHighlight) { return; }
    var targetId = resolveHighlightId();
    if (!targetId) { return; }

    var el = messageList.querySelector('.message-item[data-id="' + targetId + '"]');
    if (!el) { return; }

    state.pendingHighlight = '';
    el.classList.add('highlight');
    try { el.scrollIntoView({ block: 'center' }); } catch (e) { /* 老浏览器忽略 */ }
    setTimeout(function () { el.classList.remove('highlight'); }, 3000);
  }

  // ---------- 消息同步（服务端 → 本地归档） ----------

  function fetchMessages(beforeId) {
    var url = API_BASE + '/api/device/messages?push_key=' + encodeURIComponent(getPushKey()) +
      '&device_id=' + encodeURIComponent(getDeviceId()) +
      '&limit=' + PAGE_SIZE +
      (beforeId > 0 ? '&before_id=' + beforeId : '');

    return fetch(url, { cache: 'no-store' })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        // 兼容单层/双层包装：data.data.list 或 data.list
        var data = json.data;
        if (data && typeof data === 'object' && data.data && !('list' in data)) { data = data.data; }
        return { list: (data && data.list) || [], meta: data || {} };
      });
  }

  function mergeMessages(list) {
    if (!list || !list.length) { return Promise.resolve(0); }

    var index = {};
    state.messages.forEach(function (m) { index[m.id] = m; });

    var fresh = [];

    list.forEach(function (raw) {
      var id = Number(raw.id);
      if (!id) { return; }
      if (id > state.maxSeenId) { state.maxSeenId = id; }
      if (id <= state.clearedBeforeId) { return; }   // 已清空水位线以下，丢弃
      if (state.deletedIds[id]) { return; }          // 已被本地删除，保持删除态
      var m = raw;
      m.id = id;
      index[id] = m;
      fresh.push(m);
    });

    state.messages = Object.keys(index)
      .map(function (k) { return index[k]; })
      .sort(function (a, b) { return b.id - a.id; });

    return PushDB.Messages.putMany(fresh);
  }

  // 拉取最新一页（before_id=0 表示从最新开始）
  function syncLatest(silent) {
    if (state.loading || !getPushKey()) { return Promise.resolve(); }
    state.loading = true;
    lastSyncAt = Date.now();
    renderFooter();

    return fetchMessages(0).then(function (res) {
      return mergeMessages(res.list).then(function () {
        var localMinId = state.messages.length ? state.messages[state.messages.length - 1].id : 0;
        var pageMinId = res.list.length ? Number(res.list[res.list.length - 1].id) : 0;

        if (localMinId > 0 && pageMinId > 0 && localMinId < pageMinId) {
          // 本地已有更早的归档：游标顺延，沿用上次的 has_more 结论
          state.nextBeforeId = localMinId - 1;
        } else {
          state.nextBeforeId = Number(res.meta.next_before_id || 0) ||
            (pageMinId > 0 ? pageMinId - 1 : 0);
          state.hasMore = !!res.meta.has_more;
          if (!res.list.length) { state.hasMore = false; }
        }
        return persistState();
      });
    }).then(function () {
      state.loading = false;
      renderKeepingScroll();
      if (!silent) { setStatus('消息已刷新'); }
    }).catch(function () {
      state.loading = false;
      renderFooter();
      if (!silent) { setStatus('刷新失败，请检查网络'); }
    });
  }

  // 拉取最新一页（PAGE_SIZE 条）。触发时机只有两个：打开页面、回到前台。
  // 不做定时轮询：iOS 会冻结非前台页面的 JS，定时器在后台本就无效；
  // 在前台则持续唤醒网络，收益与耗电不成正比。
  function autoSync() {
    if (document.visibilityState !== 'visible') { return; }
    if (Date.now() - lastSyncAt < FOREGROUND_SYNC_GAP) { return; }
    syncLatest(true);
  }

  // 回到前台：刷新订阅活跃时间 + 拉一次最新消息
  function onForeground() {
    if (document.visibilityState !== 'visible') { return; }
    heartbeat();
    autoSync();
  }

  // 上拉加载更早的一页
  function loadMore() {
    if (state.loading || !state.hasMore || !getPushKey()) { return Promise.resolve(); }

    if (state.nextBeforeId > 0 && state.nextBeforeId <= state.clearedBeforeId) {
      state.hasMore = false;
      renderFooter();
      return Promise.resolve();
    }

    var cursor = state.nextBeforeId;
    state.loading = true;
    renderFooter();

    return fetchMessages(cursor).then(function (res) {
      return mergeMessages(res.list).then(function () {
        if (!res.list.length) {
          state.hasMore = false;
        } else {
          var next = Number(res.meta.next_before_id || 0) ||
            (Number(res.list[res.list.length - 1].id) - 1);
          // 游标必须严格前进，否则判定到底，避免重复请求同一页
          if (next <= 0 || next >= cursor) {
            state.hasMore = false;
          } else {
            state.nextBeforeId = next;
            state.hasMore = !!res.meta.has_more;
          }
        }
        return persistState();
      });
    }).then(function () {
      state.loading = false;
      render();
    }).catch(function () {
      state.loading = false;
      renderFooter();
      setStatus('加载更多失败');
    });
  }

  // ---------- 消息操作 ----------

  function onDelete(id) {
    if (!window.confirm('删除这条消息？')) { return; }

    state.deletedIds[id] = 1;
    state.messages = state.messages.filter(function (m) { return m.id !== id; });
    delete state.expanded[id];

    // 墓碑过多时裁剪最旧的，避免无限增长
    var ids = Object.keys(state.deletedIds).map(Number).sort(function (a, b) { return a - b; });
    if (ids.length > TOMBSTONE_MAX) {
      var kept = {};
      ids.slice(ids.length - TOMBSTONE_MAX).forEach(function (x) { kept[x] = 1; });
      state.deletedIds = kept;
    }

    PushDB.Messages.remove(id)
      .then(persistState)
      .then(function () { render(); })
      .catch(function () { render(); });
  }

  function onClear() {
    if (!state.messages.length) { setStatus('没有可清空的消息'); return; }
    if (!window.confirm('清空全部本地消息？该操作只影响本机，不会删除服务端记录。')) { return; }

    // 水位线取「本地最大 id」与「服务端见过的最大 id」的较大者，
    // 保证清空后再次刷新也不会把刚清掉的旧消息拉回来
    state.clearedBeforeId = Math.max(state.clearedBeforeId, state.maxSeenId);
    state.messages = [];
    state.expanded = {};
    state.hasMore = false;
    state.nextBeforeId = state.clearedBeforeId;

    PushDB.Messages.clear()
      .then(persistState)
      .then(function () { render(); setStatus('已清空本地消息'); })
      .catch(function () { render(); });
  }

  // ---------- 订阅关系（含失联自愈） ----------

  function ensureVapidKey() {
    if (state.vapidKey) { return Promise.resolve(state.vapidKey); }
    return fetch(API_BASE + '/api/web-push/public-key', { cache: 'no-store' })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        var pk = json && json.data ? json.data.public_key : '';
        if (!pk) { throw new Error('获取 VAPID 公钥失败'); }
        state.vapidKey = pk;
        return pk;
      });
  }

  // 上报订阅，并把 SW 自救所需的配置镜像进 IndexedDB
  function postSubscription(sub, key) {
    var info = getDeviceInfo();
    var deviceId = getDeviceId();

    var cfg = {
      push_key: key,
      device_id: deviceId,
      platform: info.platform,
      device_name: info.deviceName,
      device_model: info.model,
      os_version: info.osVersion,
      app_version: APP_VERSION,
      last_endpoint: sub.endpoint,
      was_subscribed: true
    };
    if (state.vapidKey) { cfg.vapid_public_key = state.vapidKey; }

    return fetch(API_BASE + '/api/web-push/subscribe', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        push_key: key,
        device_id: deviceId,
        subscription: sub.toJSON(),
        platform: info.platform,
        device_name: info.deviceName,
        device_model: info.model,
        os_version: info.osVersion,
        app_version: APP_VERSION
      })
    }).then(function (resp) {
      return PushDB.Config.setMany(cfg).catch(function () { }).then(function () { return resp; });
    });
  }

  function createSubscription(key) {
    return ensureVapidKey().then(function (publicKey) {
      return swRegistration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey)
      });
    }).then(function (sub) {
      subscription = sub;
      return postSubscription(sub, key);
    });
  }

  function doSubscribe() {
    var key = getPushKey();
    if (!key) { setStatus('请先输入并保存推送 Key'); return; }

    Promise.resolve()
      .then(function () { return Notification.requestPermission(); })
      .then(function (permission) {
        if (permission !== 'granted') {
          throw new Error('通知权限被拒绝，请在系统设置中允许通知');
        }
        return createSubscription(key);
      })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (json.code !== 0) { throw new Error(json.message || '订阅失败'); }
        state.wasSubscribed = true;
        subWarn.style.display = 'none';
        updateSubscribeState();
        setStatus('已启用推送');
        return syncLatest(true);
      })
      .catch(function (err) {
        setStatus('订阅失败: ' + err.message);
      });
  }

  function doUnsubscribe() {
    var p = subscription ? subscription.unsubscribe() : Promise.resolve();
    p.then(function () {
      subscription = null;
      state.wasSubscribed = false;
      updateSubscribeState();
      return PushDB.Config.set(CFG.WAS_SUBSCRIBED, false).catch(function () { });
    }).then(function () {
      return fetch(API_BASE + '/api/web-push/unsubscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ push_key: getPushKey(), device_id: getDeviceId() })
      });
    }).then(function () {
      setStatus('已取消订阅');
    }).catch(function (err) {
      setStatus('取消订阅失败: ' + err.message);
    });
  }

  // 每次打开页面自检：订阅丢了就重建，endpoint 变了就重新登记。
  // iOS 上 pushsubscriptionchange 触发并不总是可靠，这里是最终兜底。
  function healSubscription() {
    if (!state.wasSubscribed || !getPushKey() || !swRegistration) { return Promise.resolve(); }

    return swRegistration.pushManager.getSubscription().then(function (sub) {
      if (!sub) {
        return Promise.resolve()
          .then(function () { return Notification.requestPermission(); })
          .then(function (permission) {
            if (permission !== 'granted') { throw new Error('通知权限已关闭'); }
            return createSubscription(getPushKey());
          })
          .then(function () {
            updateSubscribeState();
            showSubWarn('检测到推送订阅已失效，已自动重新登记，消息不会再漏收。');
          });
      }

      subscription = sub;
      return PushDB.Config.get(CFG.LAST_ENDPOINT).then(function (last) {
        if (last && last !== sub.endpoint) {
          // endpoint 已轮换，服务端还指向旧地址 → 重新登记
          return postSubscription(sub, getPushKey()).then(function () {
            showSubWarn('推送订阅地址已更新，已重新登记。');
          });
        }
      });
    }).catch(function (err) {
      showSubWarn('推送订阅自检失败：' + err.message + '（可在下方点「启用推送」手动恢复）');
    });
  }

  function updateSubscribeState() {
    if (subscription) {
      subscribeBtn.textContent = '取消订阅';
      subscribeBtn.className = 'btn-danger';
      testBtn.disabled = false;
    } else {
      subscribeBtn.textContent = '启用推送';
      subscribeBtn.className = 'btn-primary';
      testBtn.disabled = true;
    }
  }

  // ---------- 心跳 / 测试推送 ----------

  function heartbeat() {
    var key = getPushKey();
    if (!subscription || !key) { return; }
    postSubscription(subscription, key).catch(function () { /* 静默失败 */ });
  }

  function onTest() {
    var key = getPushKey();
    if (!key) { setStatus('请先输入并保存推送 Key'); return; }
    setStatus('发送中...');

    fetch(API_BASE + '/api/web-push/send-test', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ push_key: key, device_id: getDeviceId() })
    })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (json.code === 0) {
          setStatus('测试推送已发送，请留意系统通知');
          setTimeout(function () { syncLatest(true); }, 1500);
        } else {
          setStatus('测试推送失败: ' + json.message);
        }
      })
      .catch(function (err) {
        setStatus('测试推送出错: ' + err.message);
      });
  }

  // ---------- 事件绑定 ----------

  function onSaveKey() {
    var key = keyInput.value.trim().replace(/^[`'"]+|[`'"]+$/g, '');
    if (!key) { setStatus('请输入推送 Key'); return; }

    var old = getPushKey();
    if (old === key) { setStatus('Key 未变化'); return; }

    setPushKey(key);
    keyInput.value = key;
    PushDB.Config.set(CFG.PUSH_KEY, key).catch(function () { });

    // 换 Key 等于换账号，旧 Key 的本地归档必须清掉，否则会串数据
    if (old) {
      state.messages = [];
      state.deletedIds = {};
      state.clearedBeforeId = 0;
      state.maxSeenId = 0;
      state.nextBeforeId = 0;
      state.hasMore = true;
      state.expanded = {};
      PushDB.Messages.clear().then(persistState).then(render).catch(render);
    }

    setStatus('Key 已保存');
    render();
    if (subscription) { doSubscribe(); } else { syncLatest(true); }
  }

  function bindEvents() {
    saveKeyBtn.addEventListener('click', onSaveKey);
    subscribeBtn.addEventListener('click', function () {
      if (subscription) { doUnsubscribe(); } else { doSubscribe(); }
    });
    testBtn.addEventListener('click', onTest);
    refreshBtn.addEventListener('click', function () { syncLatest(false); });
    clearBtn.addEventListener('click', onClear);

    // 列表内的删除 / 展开
    messageList.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-act]') : null;
      if (!btn) { return; }
      var act = btn.getAttribute('data-act');
      var id = Number(btn.getAttribute('data-id'));
      if (act === 'del') {
        onDelete(id);
      } else if (act === 'toggle') {
        if (state.expanded[id]) { delete state.expanded[id]; } else { state.expanded[id] = true; }
        render();
      }
    });

    // 底部「加载更多」
    listFooter.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-act="load-more"]') : null;
      if (btn) { loadMore(); }
    });

    // 搜索（本地全量归档，防抖）
    searchInput.addEventListener('input', function () {
      state.keyword = searchInput.value || '';
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { render(); }, SEARCH_DEBOUNCE);
    });

    // 上拉自动加载：仅在用户真正滚动过之后才触发，避免首屏把全部历史拉空
    window.addEventListener('scroll', function () { state.userScrolled = true; }, { passive: true });

    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        if (entries[0] && entries[0].isIntersecting && state.userScrolled) { loadMore(); }
      }, { rootMargin: '200px' });
      io.observe(loadSentinel);
    }

    // 回到前台：刷新活跃时间 + 拉一次最新消息
    document.addEventListener('visibilitychange', onForeground);
  }

  // ---------- 启动 ----------

  function init() {
    // shared.js 提供 PushDB；nginx 的 try_files 会把缺失文件回退成 index.html，
    // 这里显式判断一次，避免出问题时页面只剩一个空白壳子
    if (typeof PushDB === 'undefined') {
      setStatus('本地存储组件（shared.js）加载失败，请强制刷新页面后重试');
      return;
    }

    document.getElementById('device-id').textContent = getDeviceId();
    keyInput.value = getPushKey();

    if (isIOS() && !window.navigator.standalone) {
      iosTip.style.display = 'block';
    }

    // 通知点进来的深链：/webpush/?m=<message_id>
    var hm = location.search.match(/[?&]m=([^&]+)/);
    if (hm) { state.pendingHighlight = decodeURIComponent(hm[1]); }

    bindEvents();

    loadLocalState().then(function () {
      render();
      return syncLatest(true);
    }).then(function () {
      return initPush();
    });
  }

  function initPush() {
    if (!('Notification' in window) || !('PushManager' in window) || !('serviceWorker' in navigator)) {
      setStatus('当前浏览器不支持 Web Push');
      subscribeBtn.disabled = true;
      return Promise.resolve();
    }

    return navigator.serviceWorker.register('/webpush/sw.js')
      .then(function (reg) {
        swRegistration = reg;
        return reg.pushManager.getSubscription();
      })
      .then(function (sub) {
        subscription = sub;
        updateSubscribeState();
        return healSubscription();
      })
      .then(function () {
        if (subscription) { syncLatest(true); }
        heartbeat();
      })
      .catch(function (err) {
        setStatus('Service Worker 注册失败: ' + err.message);
      });
  }

  document.addEventListener('DOMContentLoaded', init);
})();
