// PushApp Mock Server — 本地调试用
// 同时提供 HTTP + WebSocket，让 preview 页真·连、真·发、真·收
// node ws-mock-server.js   → 启动后:
//   HTTP  : http://127.0.0.1:8898
//   WS    : ws://127.0.0.1:8898/ws

const http = require('http');
const { WebSocketServer } = require('ws');

const PORT = 8898;
const VALID_KEY = 'PK_a1b2c3d4e5f6g7h8'; // 和 preview 默认填的一致

// { device_id: { ws, key } }
const devices = new Map();

function broadcastByKey(key, payload) {
  let sent = 0;
  for (const [device, info] of devices) {
    if (info.key === key && info.ws.readyState === 1) {
      info.ws.send(JSON.stringify(payload));
      sent++;
    }
  }
  return sent;
}

function json(res, code, data) {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
  res.writeHead(code === 0 ? 200 : 400);
  res.end(JSON.stringify(data));
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://127.0.0.1');

  if (req.method === 'OPTIONS') {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    res.writeHead(204); res.end(); return;
  }

  const chunks = [];
  req.on('data', c => chunks.push(c));
  req.on('end', () => {
    const body = Buffer.concat(chunks).toString();
    let parsed = {};
    try { parsed = body ? JSON.parse(body) : {}; } catch(e) {}

    console.log(`[HTTP] ${req.method} ${url.pathname} ${url.search}`);

    if (url.pathname === '/api/user/test-push' && req.method === 'POST') {
      const key = parsed.key;
      if (!key) return json(res, 1, { code: 1, message: 'key required' });
      if (key !== VALID_KEY) return json(res, 1, { code: 1, message: 'invalid key (use PK_a1b2c3d4e5f6g7h8)' });

      const sent = broadcastByKey(key, {
        type: 'push',
        id: 'mock_' + Date.now(),
        title: '🧪 测试推送',
        content: '这是一条从 mock 服务器发来的真实 WebSocket 推送 — ' + new Date().toLocaleTimeString(),
        priority: 'high',
        timestamp: Date.now()
      });
      return json(res, 0, { code: 0, message: sent > 0 ? `已推送到 ${sent} 台设备` : '无在线设备，消息将在下次连接时投递' });
    }

    if (url.pathname === '/api/user/test-push' && req.method === 'GET') {
      const key = url.searchParams.get('key');
      if (key !== VALID_KEY) return json(res, 1, { code: 1, message: 'invalid key' });
      return json(res, 0, { code: 0, message: 'ok', server: 'mock-push-server', devices_online: devices.size });
    }

    if (url.pathname === '/api/user/messages') {
      return json(res, 0, { code: 0, data: { list: [], page: 1, total: 0 } });
    }

    json(res, 0, { code: 0, message: 'pong', path: url.pathname });
  });
});

const wss = new WebSocketServer({ server, path: '/ws' });

wss.on('connection', (ws, req) => {
  const qs = new URL(req.url, 'http://x.x').searchParams;
  const urlKey = qs.get('key');
  const urlDevice = qs.get('device') || 'unknown';

  let deviceId = urlDevice;
  let authKey = urlKey;
  let authed = false;

  console.log(`[WS] connect device=${deviceId} key=${urlKey || '(pending)'}`);

  ws.send(JSON.stringify({ type: 'hello', server: 'mock-push-server', time: Date.now() }));

  ws.on('message', (raw) => {
    let env;
    try { env = JSON.parse(raw.toString()); } catch(e) { return; }

    const t = env.type;

    if (t === 'auth') {
      authKey = env.key;
      deviceId = env.device_id || deviceId;
      if (authKey === VALID_KEY) {
        authed = true;
        devices.set(deviceId, { ws, key: authKey });
        console.log(`[WS] ✅ auth ok device=${deviceId} key=${authKey}`);
        ws.send(JSON.stringify({ type: 'auth_ok', device_id: deviceId, heartbeat_interval: env.heartbeat_interval || 30 }));
        // 连接成功后 1.5s 自动推一条欢迎消息
        setTimeout(() => {
          if (ws.readyState === 1) {
            ws.send(JSON.stringify({
              type: 'push',
              id: 'welcome_' + Date.now(),
              title: '👋 连接成功',
              content: '你已通过 Push Key 连接到 Push 服务。点击「测试推送」试试收发消息！',
              priority: 'default',
              timestamp: Date.now()
            }));
          }
        }, 1500);
      } else {
        ws.send(JSON.stringify({ type: 'auth_fail', message: 'invalid key' }));
        console.log(`[WS] ❌ auth fail key=${authKey}`);
      }
      return;
    }

    if (t === 'ping') {
      ws.send(JSON.stringify({ type: 'pong', ts: env.ts }));
      return;
    }

    if (t === 'pong') return;

    if (t === 'push') {
      // 客户端反向发 push（特殊：用于 preview 里自造消息）
      broadcastByKey(authKey, env);
      return;
    }

    console.log(`[WS] unhandled type=${t}`, env);
  });

  ws.on('close', () => {
    devices.delete(deviceId);
    console.log(`[WS] disconnect device=${deviceId}`);
  });

  ws.on('error', (e) => console.error('[WS] error', e.message));
});

server.listen(PORT, () => {
  console.log(`\n🚀 PushApp Mock Server running:`);
  console.log(`   HTTP : http://127.0.0.1:${PORT}`);
  console.log(`   WS   : ws://127.0.0.1:${PORT}/ws`);
  console.log(`   Key  : ${VALID_KEY}`);
  console.log(`\n   Open preview: http://127.0.0.1:8899/preview-final.html`);
  console.log(`   In preview, set server URL → http://127.0.0.1:${PORT}`);
  console.log(`\n`);
});
