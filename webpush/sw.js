// Web Push Service Worker
// 接收 push 事件并展示系统通知（iOS Safari 主屏幕 PWA / Edge / Chrome）

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
  event.notification.close();
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client) {
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow('/webpush/');
      }
    })
  );
});
