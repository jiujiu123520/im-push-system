import Foundation

/**
 * PushWebSocketClient - WebSocket 客户端
 *
 * 与 Android 端 PushWebSocket.kt 保持一致的协议：
 *   1. 连接建立后发送 auth 消息鉴权
 *   2. 定时发送心跳 ping
 *   3. 收到 push 消息时回调
 *   4. 断线自动重连（指数退避）
 *
 * iOS 注意：
 *   - URLSessionWebSocketTask 是 iOS 原生 WebSocket 实现，无需第三方库
 *   - 后台时系统会挂起 WebSocket，这是 iOS 的限制，无法绕过
 *   - App 回到前台时自动重连
 *
 * 线程安全说明（P0 修复）：
 *   - URLSession delegate / receive completion 在独立队列触发，不在主线程
 *   - @Published 属性（ConnectionState）必须在主线程修改，否则 iOS 16+ 会崩溃
 *   - 所有 onStateChange 和 onMessage 回调统一 dispatch 到 main
 */
class PushWebSocketClient: NSObject {

    private let serverUrl: String
    private let pushKey: String
    private let deviceId: String
    private let onMessage: (PushMessage) -> Void
    private let onStateChange: (ConnectionState) -> Void

    private var webSocketTask: URLSessionWebSocketTask?
    private var urlSession: URLSession!

    // 心跳定时器
    private var heartbeatTimer: Timer?
    private let heartbeatInterval: TimeInterval = 30 // 30秒，与 Android 一致

    // 重连相关
    private var reconnectAttempts = 0
    private let maxReconnectDelay: TimeInterval = 60
    private var isManualDisconnect = false

    // 心跳未响应计数
    private var missedPongs = 0
    private let maxMissedPongs = 3

    init(
        serverUrl: String,
        pushKey: String,
        deviceId: String,
        onMessage: @escaping (PushMessage) -> Void,
        onStateChange: @escaping (ConnectionState) -> Void
    ) {
        self.serverUrl = serverUrl
        self.pushKey = pushKey
        self.deviceId = deviceId
        self.onMessage = onMessage
        self.onStateChange = onStateChange
        super.init()

        let config = URLSessionConfiguration.default
        config.waitsForConnectivity = true
        self.urlSession = URLSession(configuration: config, delegate: self, delegateQueue: nil)
    }

    // MARK: - 主线程派发工具（P0 修复：所有回调强制切到 main）

    private func dispatchStateChange(_ state: ConnectionState) {
        if Thread.isMainThread {
            onStateChange(state)
        } else {
            DispatchQueue.main.async { [weak self] in
                self?.onStateChange(state)
            }
        }
    }

    private func dispatchMessage(_ message: PushMessage) {
        if Thread.isMainThread {
            onMessage(message)
        } else {
            DispatchQueue.main.async { [weak self] in
                self?.onMessage(message)
            }
        }
    }

    // MARK: - 连接

    func connect() {
        isManualDisconnect = false

        // 将 HTTP(S) 协议转换为 WS(S) 协议
        var wsUrl = serverUrl
        if wsUrl.lowercased().hasPrefix("https://") {
            wsUrl = "wss://" + String(wsUrl.dropFirst("https://".count))
        } else if wsUrl.lowercased().hasPrefix("http://") {
            wsUrl = "ws://" + String(wsUrl.dropFirst("http://".count))
        } else if !wsUrl.lowercased().hasPrefix("ws://") && !wsUrl.lowercased().hasPrefix("wss://") {
            wsUrl = "ws://" + wsUrl
        }

        guard let url = URL(string: "\(wsUrl)/ws") else {
            print("[WebSocket] 无效的 URL: \(wsUrl)/ws")
            dispatchStateChange(.disconnected)
            return
        }

        var request = URLRequest(url: url)
        request.timeoutInterval = 15

        webSocketTask = urlSession.webSocketTask(with: request)
        webSocketTask?.resume()

        dispatchStateChange(.connecting)
        print("[WebSocket] 正在连接 \(url)")

        // 开始接收消息
        receiveMessage()
    }

    func disconnect() {
        isManualDisconnect = true
        heartbeatTimer?.invalidate()
        heartbeatTimer = nil
        webSocketTask?.cancel(with: .goingAway, reason: nil)
        webSocketTask = nil
        dispatchStateChange(.disconnected)
    }

    // MARK: - 接收消息

    private func receiveMessage() {
        webSocketTask?.receive { [weak self] result in
            guard let self = self else { return }

            switch result {
            case .success(let message):
                // 后台主动断开后不继续处理残留消息
                guard !self.isManualDisconnect else { return }

                switch message {
                case .string(let text):
                    self.handleMessage(text)
                case .data(let data):
                    if let text = String(data: data, encoding: .utf8) {
                        self.handleMessage(text)
                    }
                @unknown default:
                    break
                }
                // 继续接收下一条消息
                if !self.isManualDisconnect {
                    self.receiveMessage()
                }

            case .failure(let error):
                print("[WebSocket] 接收消息失败: \(error.localizedDescription)")
                if !self.isManualDisconnect {
                    self.handleDisconnect()
                }
            }
        }
    }

    // MARK: - 消息处理

    private func handleMessage(_ text: String) {
        guard let data = text.data(using: .utf8),
              let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
            return
        }

        let type = (json["type"] as? String) ?? ""

        switch type {
        case "auth_result":
            handleAuthResult(json)
        case "pong":
            // 心跳响应 —— 必须 dispatch 到主线程，
            // 因为 Timer 闭包（missedPongs += 1）也在主线程访问这个属性，
            // 在 URLSession delegateQueue 后台线程写会产生 data race 导致崩溃
            DispatchQueue.main.async { [weak self] in
                self?.missedPongs = 0
            }
        case "ping":
            // 服务端心跳，回复 pong
            send(text: "{\"type\":\"pong\"}")
        case "push":
            handlePushMessage(json)
        default:
            print("[WebSocket] 未知消息类型: \(type)")
        }
    }

    private func handleAuthResult(_ json: [String: Any]) {
        let success = (json["success"] as? Bool) ?? false
        let code = (json["code"] as? Int) ?? -1

        if success && code == 0 {
            print("[WebSocket] 鉴权成功")
            dispatchStateChange(.connected)
            // 必须 dispatch 到主线程，因为 reconnectAttempts 和 missedPongs 在 Timer 闭包里（主线程）也会访问
            DispatchQueue.main.async { [weak self] in
                self?.reconnectAttempts = 0
                self?.startHeartbeat()
            }
        } else {
            let message = (json["message"] as? String) ?? "未知错误"
            print("[WebSocket] 鉴权失败: \(message) (code=\(code))")
            dispatchStateChange(.disconnected)
            // 鉴权失败不重连，避免无效连接
        }
    }

    private func handlePushMessage(_ json: [String: Any]) {
        let id = (json["message_id"] as? String) ?? (json["id"] as? String) ?? UUID().uuidString
        let title = (json["title"] as? String) ?? ""
        let content = (json["content"] as? String) ?? ""
        let timestamp: Int64 = {
            if let d = json["timestamp"] as? Double { return Int64(d) }
            if let i = json["timestamp"] as? Int { return Int64(i) }
            return Int64(Date().timeIntervalSince1970)
        }()

        let message = PushMessage(
            id: id,
            title: title,
            content: content,
            timestamp: timestamp,
            source: .websocket
        )

        dispatchMessage(message)
    }

    // MARK: - 心跳

    private func startHeartbeat() {
        heartbeatTimer?.invalidate()
        missedPongs = 0

        // Timer 必须在主线程创建（iOS 基础要求）
        DispatchQueue.main.async {
            self.heartbeatTimer = Timer.scheduledTimer(withTimeInterval: self.heartbeatInterval, repeats: true) { [weak self] _ in
                guard let self = self else { return }
                self.missedPongs += 1
                self.send(text: "{\"type\":\"ping\"}")

                if self.missedPongs >= self.maxMissedPongs {
                    print("[WebSocket] 连续 \(self.maxMissedPongs) 次心跳无响应，触发重连")
                    self.handleDisconnect()
                }
            }
        }
    }

    // MARK: - 发送消息

    private func send(text: String) {
        guard let task = webSocketTask else { return }
        task.send(.string(text)) { error in
            if let error = error {
                print("[WebSocket] 发送消息失败: \(error.localizedDescription)")
            }
        }
    }

    /// 发送鉴权消息
    private func sendAuth() {
        let authMessage: [String: Any] = [
            "type": "auth",
            "key": pushKey,
            "device_id": deviceId,
            "heartbeat_interval": Int(heartbeatInterval),
            "platform": "ios",
            "app_version": Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "1.0.0",
            "model": UIDevice.current.model,
            "os_version": UIDevice.current.systemVersion
        ]

        guard let data = try? JSONSerialization.data(withJSONObject: authMessage),
              let text = String(data: data, encoding: .utf8) else {
            return
        }

        // P0 修复：直接发送，去掉无意义的 0.5 秒 global queue 延迟
        send(text: text)
    }

    // MARK: - 重连

    private func handleDisconnect() {
        heartbeatTimer?.invalidate()
        heartbeatTimer = nil
        webSocketTask?.cancel()
        webSocketTask = nil

        guard !isManualDisconnect else { return }

        dispatchStateChange(.reconnecting)

        // 重连相关的状态修改必须在主线程，避免和 Timer 闭包的 data race
        DispatchQueue.main.async { [weak self] in
            guard let self = self else { return }

            self.reconnectAttempts += 1
            let baseDelay = min(pow(2.0, Double(self.reconnectAttempts)), self.maxReconnectDelay)
            let jitter = Double.random(in: 0...0.2) * baseDelay
            let delay = baseDelay + jitter

            print("[WebSocket] \(String(format: "%.1f", delay))秒后重连（第\(self.reconnectAttempts)次）")

            DispatchQueue.global().asyncAfter(deadline: .now() + delay) { [weak self] in
                self?.connect()
            }
        }
    }
}

// MARK: - URLSessionWebSocketDelegate

extension PushWebSocketClient: URLSessionWebSocketDelegate {

    func urlSession(
        _ session: URLSession,
        webSocketTask: URLSessionWebSocketTask,
        didOpenWithProtocol protocol: String?
    ) {
        print("[WebSocket] 连接已建立")
        // 连接建立后发送鉴权消息
        sendAuth()
    }

    func urlSession(
        _ session: URLSession,
        webSocketTask: URLSessionWebSocketTask,
        didCloseWith closeCode: URLSessionWebSocketTask.CloseCode,
        reason: Data?
    ) {
        print("[WebSocket] 连接已关闭，code: \(closeCode.rawValue)")
        if !isManualDisconnect {
            handleDisconnect()
        }
    }
}
