// HBuilderX 版本的消息推送应用
(function() {
    'use strict';

    // 状态管理
    let state = {
        key: '',
        serverUrl: '',
        wsUrl: '',
        ws: null,
        messages: [],
        todayCount: 0,
        totalCount: 0,
        deviceId: '',
        connected: false
    };

    // DOM 元素缓存
    let $ = function(id) {
        return document.getElementById(id);
    };

    // 页面切换
    function showPage(pageId) {
        var pages = document.querySelectorAll('.page');
        for (var i = 0; i < pages.length; i++) {
            pages[i].classList.remove('active');
        }
        $(pageId).classList.add('active');
    }

    // 初始化设备ID
    function initDeviceId() {
        var deviceId = localStorage.getItem('push_device_id');
        if (!deviceId) {
            deviceId = 'web-' + Math.random().toString(36).substring(2, 10) + Date.now().toString(36);
            localStorage.setItem('push_device_id', deviceId);
        }
        state.deviceId = deviceId;
        $('device-id').textContent = deviceId.substring(0, 8);
    }

    // 初始化配置
    function initConfig() {
        var savedKey = localStorage.getItem('push_key');
        var savedServer = localStorage.getItem('push_server');
        var savedWs = localStorage.getItem('push_ws');

        if (savedKey) {
            $('key-input').value = savedKey;
        } else {
            $('key-input').value = window.APP_CONFIG.default_key;
        }

        if (savedServer) {
            $('server-input').value = savedServer;
        } else {
            $('server-input').value = window.APP_CONFIG.server_url;
        }

        $('app-version').textContent = window.APP_CONFIG.version_name;
    }

    // 加载消息历史
    function loadMessages() {
        try {
            var saved = localStorage.getItem('push_messages');
            if (saved) {
                state.messages = JSON.parse(saved);
                renderMessages();
                updateStats();
            }
        } catch (e) {
            console.error('加载消息失败', e);
        }
    }

    // 保存消息
    function saveMessages() {
        try {
            localStorage.setItem('push_messages', JSON.stringify(state.messages.slice(0, 100)));
        } catch (e) {
            console.error('保存消息失败', e);
        }
    }

    // 渲染消息列表
    function renderMessages() {
        var listEl = $('message-list');
        if (state.messages.length === 0) {
            listEl.innerHTML = '<div class="empty-state"><p>暂无消息</p><span>推送的消息将显示在这里</span></div>';
            return;
        }

        var html = '';
        for (var i = 0; i < state.messages.length; i++) {
            var msg = state.messages[i];
            var time = formatTime(msg.time);
            html += '<div class="message-item">';
            html += '<div class="message-title">' + escapeHtml(msg.title || '消息推送') + '</div>';
            html += '<div class="message-content">' + escapeHtml(msg.content || '') + '</div>';
            html += '<div class="message-time">' + time + '</div>';
            html += '</div>';
        }
        listEl.innerHTML = html;
    }

    // 添加消息
    function addMessage(title, content) {
        state.messages.unshift({
            title: title,
            content: content,
            time: Date.now()
        });

        if (state.messages.length > 100) {
            state.messages = state.messages.slice(0, 100);
        }

        state.totalCount++;

        var today = new Date().toDateString();
        var lastToday = localStorage.getItem('push_today_date');
        if (lastToday !== today) {
            localStorage.setItem('push_today_date', today);
            state.todayCount = 1;
        } else {
            state.todayCount++;
            var savedToday = parseInt(localStorage.getItem('push_today_count') || '0');
            state.todayCount = savedToday + 1;
        }
        localStorage.setItem('push_today_count', state.todayCount.toString());

        renderMessages();
        updateStats();
        saveMessages();

        // 显示通知（如果支持）
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification(title, { body: content });
        }
    }

    // 更新统计
    function updateStats() {
        $('today-count').textContent = state.todayCount;
        $('total-count').textContent = state.totalCount;
    }

    // 格式化时间
    function formatTime(timestamp) {
        var date = new Date(timestamp);
        var now = new Date();
        var diff = now.getTime() - timestamp;

        if (diff < 60000) {
            return '刚刚';
        } else if (diff < 3600000) {
            return Math.floor(diff / 60000) + '分钟前';
        } else if (now.toDateString() === date.toDateString()) {
            return date.getHours().toString().padStart(2, '0') + ':' + date.getMinutes().toString().padStart(2, '0');
        } else {
            return (date.getMonth() + 1) + '-' + date.getDate() + ' ' + date.getHours().toString().padStart(2, '0') + ':' + date.getMinutes().toString().padStart(2, '0');
        }
    }

    // HTML 转义
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // WebSocket 连接
    function connectWebSocket() {
        if (state.ws && state.ws.readyState === WebSocket.OPEN) {
            return;
        }

        var wsUrl = state.wsUrl + '/ws/client?key=' + encodeURIComponent(state.key) + '&device_id=' + encodeURIComponent(state.deviceId);

        try {
            state.ws = new WebSocket(wsUrl);

            state.ws.onopen = function() {
                state.connected = true;
                updateConnectionStatus();
                console.log('WebSocket 已连接');
            };

            state.ws.onmessage = function(event) {
                try {
                    var data = JSON.parse(event.data);
                    if (data.type === 'ping') {
                        state.ws.send(JSON.stringify({ type: 'pong' }));
                    } else if (data.type === 'message') {
                        addMessage(data.title || '消息推送', data.content || '');
                    }
                } catch (e) {
                    console.error('消息解析失败', e);
                }
            };

            state.ws.onclose = function() {
                state.connected = false;
                updateConnectionStatus();
                console.log('WebSocket 已断开，3秒后重连...');
                setTimeout(connectWebSocket, 3000);
            };

            state.ws.onerror = function(error) {
                console.error('WebSocket 错误', error);
            };
        } catch (e) {
            console.error('WebSocket 连接失败', e);
            setTimeout(connectWebSocket, 3000);
        }
    }

    // 更新连接状态显示
    function updateConnectionStatus() {
        var statusEl = $('connection-status');
        if (state.connected) {
            statusEl.className = 'status-dot connected';
            statusEl.title = '已连接';
        } else {
            statusEl.className = 'status-dot disconnected';
            statusEl.title = '未连接';
        }
    }

    // 登录
    function handleLogin() {
        var key = $('key-input').value.trim();
        var serverUrl = $('server-input').value.trim();

        if (!key) {
            alert('请输入推送 Key');
            return;
        }
        if (!serverUrl) {
            alert('请输入服务器地址');
            return;
        }

        state.key = key;
        state.serverUrl = serverUrl;

        // 推导 WebSocket 地址
        if (serverUrl.startsWith('https://')) {
            state.wsUrl = 'wss://' + serverUrl.substring(8);
        } else if (serverUrl.startsWith('http://')) {
            state.wsUrl = 'ws://' + serverUrl.substring(7);
        } else {
            state.wsUrl = 'ws://' + serverUrl;
        }

        // 保存配置
        localStorage.setItem('push_key', key);
        localStorage.setItem('push_server', serverUrl);
        localStorage.setItem('push_ws', state.wsUrl);

        // 更新设置页面
        $('setting-key').value = key;
        $('setting-server').value = serverUrl;
        $('setting-ws').value = state.wsUrl;

        // 请求通知权限
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        // 连接 WebSocket
        connectWebSocket();

        // 切换到主页面
        showPage('main-page');
    }

    // 退出登录
    function handleLogout() {
        if (state.ws) {
            state.ws.close();
            state.ws = null;
        }
        state.connected = false;
        showPage('login-page');
    }

    // 清空消息
    function handleClearMessages() {
        if (confirm('确定要清空所有消息吗？')) {
            state.messages = [];
            saveMessages();
            renderMessages();
        }
    }

    // 初始化事件监听
    function initEvents() {
        $('login-btn').addEventListener('click', handleLogin);
        $('settings-btn').addEventListener('click', function() {
            showPage('settings-page');
        });
        $('back-btn').addEventListener('click', function() {
            showPage('main-page');
        });
        $('logout-btn').addEventListener('click', handleLogout);
        $('clear-btn').addEventListener('click', handleClearMessages);
    }

    // 检查是否已登录
    function checkAutoLogin() {
        var savedKey = localStorage.getItem('push_key');
        var savedServer = localStorage.getItem('push_server');
        var savedWs = localStorage.getItem('push_ws');

        if (savedKey && savedServer) {
            state.key = savedKey;
            state.serverUrl = savedServer;
            state.wsUrl = savedWs || window.APP_CONFIG.ws_url;

            $('setting-key').value = savedKey;
            $('setting-server').value = savedServer;
            $('setting-ws').value = state.wsUrl;

            connectWebSocket();
            showPage('main-page');
            return true;
        }
        return false;
    }

    // 初始化应用
    function init() {
        initDeviceId();
        initConfig();
        loadMessages();
        initEvents();

        if (!checkAutoLogin()) {
            showPage('login-page');
        }
    }

    // 页面加载完成后初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
