/**
 * PWA 共享工具 —— 页面（<script src>）与 Service Worker（importScripts）共用
 *
 * 全局导出：
 *   PushDB                     IndexedDB 封装
 *   PushDB.Messages            { all, count, putMany, remove, clear }
 *   PushDB.Config              { get, getMany, set, setMany, remove }
 *   urlBase64ToUint8Array()    VAPID 公钥 base64url → Uint8Array
 *
 * 存储结构（DB: push_pwa, version 1）：
 *   messages  keyPath 'id'   消息归档，id 与服务端 messages.id 一致
 *   config    keyPath 'k'    键值配置（订阅信息 / 清空水位线 / 删除墓碑 等）
 */
(function (global) {
  'use strict';

  var DB_NAME = 'push_pwa';
  var DB_VERSION = 1;
  var S_MESSAGES = 'messages';
  var S_CONFIG = 'config';

  var _dbPromise = null;

  function openDB() {
    if (_dbPromise) { return _dbPromise; }
    _dbPromise = new Promise(function (resolve, reject) {
      if (!global.indexedDB) { reject(new Error('当前环境不支持 IndexedDB')); return; }
      var req = global.indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function (ev) {
        var db = ev.target.result;
        if (!db.objectStoreNames.contains(S_MESSAGES)) {
          db.createObjectStore(S_MESSAGES, { keyPath: 'id' });
        }
        if (!db.objectStoreNames.contains(S_CONFIG)) {
          db.createObjectStore(S_CONFIG, { keyPath: 'k' });
        }
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error || new Error('打开 IndexedDB 失败')); };
      req.onblocked = function () { reject(new Error('IndexedDB 被其它页面占用')); };
    });
    return _dbPromise;
  }

  function request(req) {
    return new Promise(function (resolve, reject) {
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error); };
    });
  }

  // 在事务内执行 fn(store)，事务提交后再 resolve（保证写入落盘）
  function run(storeName, mode, fn) {
    return openDB().then(function (db) {
      return new Promise(function (resolve, reject) {
        var t = db.transaction(storeName, mode);
        var out;
        try {
          out = fn(t.objectStore(storeName));
        } catch (e) {
          reject(e);
          return;
        }
        t.oncomplete = function () { resolve(out); };
        t.onerror = function () { reject(t.error); };
        t.onabort = function () { reject(t.error); };
      });
    });
  }

  var Messages = {
    all: function () {
      return openDB().then(function (db) {
        return request(db.transaction(S_MESSAGES, 'readonly').objectStore(S_MESSAGES).getAll());
      });
    },
    count: function () {
      return openDB().then(function (db) {
        return request(db.transaction(S_MESSAGES, 'readonly').objectStore(S_MESSAGES).count());
      });
    },
    putMany: function (list) {
      if (!list || !list.length) { return Promise.resolve(0); }
      return run(S_MESSAGES, 'readwrite', function (store) {
        for (var i = 0; i < list.length; i++) { store.put(list[i]); }
        return list.length;
      });
    },
    remove: function (id) {
      return run(S_MESSAGES, 'readwrite', function (store) { store.delete(id); return 1; });
    },
    clear: function () {
      return run(S_MESSAGES, 'readwrite', function (store) { store.clear(); return 1; });
    }
  };

  var Config = {
    get: function (k) {
      return openDB()
        .then(function (db) {
          return request(db.transaction(S_CONFIG, 'readonly').objectStore(S_CONFIG).get(k));
        })
        .then(function (row) { return row ? row.v : undefined; });
    },
    getMany: function (keys) {
      var out = {};
      return openDB()
        .then(function (db) {
          return Promise.all(keys.map(function (k) {
            return request(db.transaction(S_CONFIG, 'readonly').objectStore(S_CONFIG).get(k))
              .then(function (row) { out[k] = row ? row.v : undefined; });
          }));
        })
        .then(function () { return out; });
    },
    set: function (k, v) {
      return run(S_CONFIG, 'readwrite', function (store) { store.put({ k: k, v: v }); return 1; });
    },
    setMany: function (obj) {
      return run(S_CONFIG, 'readwrite', function (store) {
        Object.keys(obj).forEach(function (k) { store.put({ k: k, v: obj[k] }); });
        return 1;
      });
    },
    remove: function (k) {
      return run(S_CONFIG, 'readwrite', function (store) { store.delete(k); return 1; });
    }
  };

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var rawData = global.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) { outputArray[i] = rawData.charCodeAt(i); }
    return outputArray;
  }

  global.PushDB = { Messages: Messages, Config: Config, open: openDB };
  global.urlBase64ToUint8Array = urlBase64ToUint8Array;
})(typeof self !== 'undefined' ? self : window);
