// Web Push Service Worker
// 接收 push 事件并展示系统通知（iOS Safari 主屏幕 PWA / Edge / Chrome）
importScripts('/webpush/shared.js');

self.addEventListener('install', () => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: '新消息', body: event.data ? event.data.text() : '' };
  }

  const title = data.title || '推送通知';
  const options = {
    body: data.body || '',
    icon: '/webpush/icon.svg',
    badge: '/webpush/icon.svg',
    data: data.data || {},
    vibrate: [200, 100, 200],
    requireInteraction: false,
  };

  // 用 message_id 作为 tag，避免同一条消息重复弹通知
  if (data.data && data.data.message_id) {
    options.tag = String(data.data.message_id);
  }

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
  const data = event.notification.data || {};
  const messageId = data.message_id ? String(data.message_id) : '';
  // 带 message_id 打开，页面会滚动并高亮到对应消息
  const url = messageId ? '/webpush/?m=' + encodeURIComponent(messageId) : '/webpush/';

  event.notification.close();

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (!('focus' in client)) { continue; }
        if (client.navigate) {
          try {
            const nav = client.navigate(url);
            if (nav && typeof nav.then === 'function') {
              return nav.then((c) => (c && c.focus ? c.focus() : client.focus())).catch(() => client.focus());
            }
          } catch (e) { /* 退回到只聚焦 */ }
        }
        return client.focus();
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(url);
      }
    })
  );
});

// 推送订阅轮换（endpoint 失效 / 被系统回收）时自动重新订阅并上报，
// 否则服务端会持续向已失效的 endpoint 推送，表现为「静默失联」。
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil(recoverSubscription(event));
});

async function recoverSubscription(event) {
  try {
    const cfg = await self.PushDB.Config.getMany([
      'push_key', 'device_id', 'vapid_public_key', 'platform',
      'device_name', 'device_model', 'os_version', 'app_version'
    ]);

    if (!cfg.push_key || !cfg.device_id || !cfg.vapid_public_key) { return; }

    const old = event.oldSubscription || null;
    if (old) {
      try { await old.unsubscribe(); } catch (e) { /* 旧订阅可能已自动失效 */ }
    }

    const sub = await self.registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(cfg.vapid_public_key)
    });

    const resp = await fetch('/api/web-push/subscribe', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        push_key: cfg.push_key,
        device_id: cfg.device_id,
        subscription: sub.toJSON(),
        platform: cfg.platform || 'ios',
        device_name: cfg.device_name || 'PWA 设备',
        device_model: cfg.device_model || '',
        os_version: cfg.os_version || '',
        app_version: cfg.app_version || '1.0.0'
      })
    });

    if (!resp.ok) { throw new Error('上报失败 HTTP ' + resp.status); }

    await self.PushDB.Config.set('last_endpoint', sub.endpoint);
  } catch (e) {
    // 静默失败：下次打开页面时由 app.js 的 healSubscription() 兜底恢复
  }
}
