(function () {
  'use strict';

  var API_BASE = location.origin;
  var DEVICE_ID_KEY = 'pwa_device_id';
  var PUSH_KEY_KEY = 'pwa_push_key';

  // ---------- 稳定设备 ID（localStorage 持久化，非随机） ----------
  function getDeviceId() {
    var id = localStorage.getItem(DEVICE_ID_KEY);
    if (!id) {
      id = 'pwa_' + generateUuid();
      localStorage.setItem(DEVICE_ID_KEY, id);
    }
    return id;
  }

  function generateUuid() {
    if (window.crypto && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (crypto.getRandomValues(new Uint8Array(1))[0] & 15) >> (c === 'x' ? 0 : 3);
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  function getPushKey() { return localStorage.getItem(PUSH_KEY_KEY) || ''; }
  function setPushKey(k) { localStorage.setItem(PUSH_KEY_KEY, k); }

  // ---------- DOM ----------
  var keyInput = document.getElementById('key-input');
  var saveKeyBtn = document.getElementById('save-key-btn');
  var subscribeBtn = document.getElementById('subscribe-btn');
  var testBtn = document.getElementById('test-btn');
  var refreshBtn = document.getElementById('refresh-btn');
  var statusEl = document.getElementById('status');
  var messageList = document.getElementById('message-list');
  var iosTip = document.getElementById('ios-tip');

  var swRegistration = null;
  var subscription = null;

  function setStatus(msg) { statusEl.textContent = msg; }

  function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  function detectPlatform() {
    var ua = navigator.userAgent;
    if (/Edg\//.test(ua)) return 'edge';
    if (/Chrome\//.test(ua) && !/Edg\//.test(ua)) return 'chrome';
    return 'ios';
  }

  function init() {
    document.getElementById('device-id').textContent = getDeviceId();
    keyInput.value = getPushKey();

    if (isIOS() && !window.navigator.standalone) {
      iosTip.style.display = 'block';
    }

    if (!('Notification' in window) || !('PushManager' in window) || !('serviceWorker' in navigator)) {
      setStatus('当前浏览器不支持 Web Push');
      subscribeBtn.disabled = true;
      return;
    }

    navigator.serviceWorker.register('/webpush/sw.js')
      .then(function (reg) {
        swRegistration = reg;
        return reg.pushManager.getSubscription();
      })
      .then(function (sub) {
        subscription = sub;
        updateSubscribeState();
      })
      .catch(function (err) {
        setStatus('Service Worker 注册失败: ' + err.message);
      });

    saveKeyBtn.addEventListener('click', onSaveKey);
    subscribeBtn.addEventListener('click', onSubscribe);
    testBtn.addEventListener('click', onTest);
    refreshBtn.addEventListener('click', refreshMessages);
  }

  function updateSubscribeState() {
    if (subscription) {
      subscribeBtn.textContent = '取消订阅';
      subscribeBtn.className = 'btn-danger';
      testBtn.disabled = false;
      refreshMessages();
    } else {
      subscribeBtn.textContent = '启用推送';
      subscribeBtn.className = 'btn-primary';
      testBtn.disabled = true;
    }
  }

  function onSaveKey() {
    var key = keyInput.value.trim();
    if (!key) { setStatus('请输入推送 Key'); return; }
    setPushKey(key);
    setStatus('Key 已保存');
    if (subscription) { doSubscribe(); }
  }

  function onSubscribe() {
    if (subscription) { doUnsubscribe(); return; }
    doSubscribe();
  }

  function doSubscribe() {
    var key = getPushKey();
    if (!key) { setStatus('请先输入并保存推送 Key'); return; }

    Promise.resolve()
      .then(function () { return Notification.requestPermission(); })
      .then(function (permission) {
        if (permission !== 'granted') {
          throw new Error('通知权限被拒绝，请在浏览器设置中允许通知');
        }
        return fetch(API_BASE + '/api/web-push/public-key');
      })
      .then(function (resp) { return resp.json(); })
      .then(function (pkJson) {
        var publicKey = pkJson && pkJson.data ? pkJson.data.public_key : '';
        if (!publicKey) { throw new Error('获取 VAPID 公钥失败'); }
        return swRegistration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicKey)
        });
      })
      .then(function (sub) {
        subscription = sub;
        return fetch(API_BASE + '/api/web-push/subscribe', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            push_key: key,
            device_id: getDeviceId(),
            subscription: sub.toJSON(),
            platform: detectPlatform()
          })
        });
      })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (json.code !== 0) { throw new Error(json.message || '订阅失败'); }
        updateSubscribeState();
        setStatus('已启用推送');
      })
      .catch(function (err) {
        setStatus('订阅失败: ' + err.message);
      });
  }

  function doUnsubscribe() {
    var p = subscription ? subscription.unsubscribe() : Promise.resolve();
    p.then(function () {
      subscription = null;
      updateSubscribeState();
      var key = getPushKey();
      return fetch(API_BASE + '/api/web-push/unsubscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ push_key: key, device_id: getDeviceId() })
      });
    }).then(function () {
      setStatus('已取消订阅');
    }).catch(function (err) {
      setStatus('取消订阅失败: ' + err.message);
    });
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
          setTimeout(refreshMessages, 1500);
        } else {
          setStatus('测试推送失败: ' + json.message);
        }
      })
      .catch(function (err) {
        setStatus('测试推送出错: ' + err.message);
      });
  }

  function refreshMessages() {
    var key = getPushKey();
    if (!key) { return; }
    var url = API_BASE + '/api/device/messages?push_key=' + encodeURIComponent(key) +
      '&device_id=' + encodeURIComponent(getDeviceId()) + '&limit=50';

    fetch(url)
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        // 兼容单层/双层包装：data.data.list 或 data.list
        var data = json.data;
        if (data && typeof data === 'object' && data.data && !('list' in data)) {
          data = data.data;
        }
        var list = (data && data.list) || [];
        renderMessages(list);
      })
      .catch(function () { /* 静默失败 */ });
  }

  function renderMessages(list) {
    if (!list || !list.length) {
      messageList.innerHTML = '<div class="empty">暂无消息</div>';
      return;
    }
    var html = '';
    list.forEach(function (msg) {
      var title = escapeHtml(msg.title || '(无标题)');
      var content = escapeHtml(msg.content || '');
      var time = escapeHtml(msg.created_at || '');
      html += '<div class="message-item">' +
        '<div class="message-title">' + title + '</div>' +
        '<div class="message-content">' + content + '</div>' +
        '<div class="message-time">' + time + '</div>' +
        '</div>';
    });
    messageList.innerHTML = html;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  document.addEventListener('DOMContentLoaded', init);
})();
