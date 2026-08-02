import Foundation
import UIKit

/**
 * PushManager - 推送管理器（单例）
 *
 * 职责：
 *   1. 管理 APNS device token 的上报
 *   2. 处理收到的 APNS 推送内容
 *   3. 管理 WebSocket 连接（前台时使用，与 Android 一致）
 *   4. 协调 WebSocket 和 APNS 双通道
 *
 * 双通道策略：
 *   - 前台时：WebSocket 在线，消息实时投递（低延迟）
 *   - 后台/被杀时：WebSocket 断开，后端自动走 APNS 投递
 *   - App 重新打开时：WebSocket 重连 + 拉取离线消息（补全断线期间的消息）
 */
@MainActor
class PushManager: ObservableObject {

    static let shared = PushManager()

    // MARK: - Published Properties

    /// WebSocket 连接状态
    @Published var connectionState: ConnectionState = .disconnected

    /// 消息列表（WebSocket + APNS 收到的消息合并）
    @Published var messages: [PushMessage] = []

    /// APNS token（注册成功后赋值）
    @Published var apnsToken: String = ""

    // MARK: - Private Properties

    private let preferences = PreferencesManager.shared
    private var webSocketClient: PushWebSocketClient?

    // 防止重复上报 APNS token
    private var lastReportedToken: String = ""

    private init() {
        // 启动时加载本地消息
        messages = preferences.loadMessages()
    }

    // MARK: - APNS Token 上报

    /// 注册 APNS 成功后，将 device token 上报给后端
    func registerApnsToken(_ token: String) async {
        apnsToken = token

        // 防止重复上报
        guard token != lastReportedToken else { return }
        lastReportedToken = token

        let pushKey = preferences.pushKey
        let deviceId = preferences.deviceId
        let serverUrl = preferences.serverUrl

        guard !pushKey.isEmpty, !deviceId.isEmpty, !serverUrl.isEmpty else {
            print("[PushManager] 未配置 pushKey/deviceId/serverUrl，跳过 APNS token 上报")
            return
        }

        guard let url = URL(string: "\(serverUrl)/api/device/register-token") else {
            print("[PushManager] 无效的服务器地址: \(serverUrl)")
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.timeoutInterval = 15

        let body: [String: Any] = [
            "push_key": pushKey,
            "device_id": deviceId,
            "apns_token": token,
            "bundle_id": Bundle.main.bundleIdentifier ?? ""
        ]

        do {
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
            let (_, response) = try await URLSession.shared.data(for: request)

            if let httpResponse = response as? HTTPURLResponse, httpResponse.statusCode == 200 {
                print("[PushManager] APNS token 上报成功")
            } else {
                let code = (response as? HTTPURLResponse)?.statusCode ?? -1
                print("[PushManager] APNS token 上报失败，HTTP \(code)")
            }
        } catch {
            print("[PushManager] APNS token 上报异常: \(error.localizedDescription)")
        }
    }

    // MARK: - 处理 APNS 推送内容

    /// 收到 APNS 推送时调用（前台/后台/被杀均会触发）
    func handleApnsPayload(_ userInfo: [AnyHashable: Any]) {
        // APNS payload 结构：
        // {
        //   "aps": { "alert": { "title": "...", "body": "..." }, "sound": "default" },
        //   "message_id": "msg_xxx",    // 后端透传的消息 ID，用于去重
        //   "data": { ... }              // 自定义数据
        // }

        let aps = userInfo["aps"] as? [String: Any] ?? [:]
        let alert = aps["alert"] as? [String: Any] ?? [:]

        let title = (alert["title"] as? String) ?? ""
        let content = (alert["body"] as? String) ?? ""
        let messageId = (userInfo["message_id"] as? String) ?? UUID().uuidString
        let timestamp = Int64(Date().timeIntervalSince1970)

        let message = PushMessage(
            id: messageId,
            title: title,
            content: content,
            timestamp: timestamp,
            source: .apns
        )

        // 去重：如果 WebSocket 已收到同 ID 消息，不再重复添加
        if messages.contains(where: { $0.id == messageId }) {
            print("[PushManager] 消息已存在（WebSocket 已收到），跳过: \(messageId)")
            return
        }

        messages.insert(message, at: 0)
        preferences.saveMessages(messages)

        // 更新角标
        let badge = aps["badge"] as? Int ?? 0
        if badge > 0 {
            UIApplication.shared.applicationIconBadgeNumber = badge
        }

        print("[PushManager] APNS 消息已存储: \(title)")
    }

    // MARK: - WebSocket 连接管理

    /// 启动 WebSocket 连接（App 进入前台时调用）
    func connectWebSocket() {
        let pushKey = preferences.pushKey
        let deviceId = preferences.deviceId
        let serverUrl = preferences.serverUrl

        guard !pushKey.isEmpty, !deviceId.isEmpty, !serverUrl.isEmpty else {
            print("[PushManager] 未配置推送参数，跳过 WebSocket 连接")
            return
        }

        // 已连接则不重复连接
        if connectionState == .connected || connectionState == .connecting {
            return
        }

        connectionState = .connecting

        // 关闭旧连接
        webSocketClient?.disconnect()

        // 创建新连接
        webSocketClient = PushWebSocketClient(
            serverUrl: serverUrl,
            pushKey: pushKey,
            deviceId: deviceId,
            onMessage: { [weak self] message in
                Task { @MainActor in
                    self?.handleWebSocketMessage(message)
                }
            },
            onStateChange: { [weak self] state in
                Task { @MainActor in
                    self?.connectionState = state
                }
            }
        )
        webSocketClient?.connect()
    }

    /// 断开 WebSocket（App 进入后台时调用）
    func disconnectWebSocket() {
        webSocketClient?.disconnect()
        connectionState = .disconnected
    }

    // MARK: - 处理 WebSocket 消息

    private func handleWebSocketMessage(_ message: PushMessage) {
        // 去重
        if messages.contains(where: { $0.id == message.id }) {
            return
        }

        messages.insert(message, at: 0)
        preferences.saveMessages(messages)
    }

    // MARK: - 清空消息

    func clearMessages() {
        messages.removeAll()
        preferences.saveMessages(messages)
        UIApplication.shared.applicationIconBadgeNumber = 0
    }
}

// MARK: - 连接状态枚举

enum ConnectionState: String {
    case disconnected = "已断开"
    case connecting = "连接中"
    case connected = "已连接"
    case reconnecting = "重连中"
}

// MARK: - 推送消息模型

struct PushMessage: Identifiable, Codable {
    let id: String
    let title: String
    let content: String
    let timestamp: Int64
    let source: MessageSource

    var timeString: String {
        let date = Date(timeIntervalSince1970: TimeInterval(timestamp))
        let formatter = DateFormatter()
        formatter.dateFormat = "MM-dd HH:mm:ss"
        return formatter.string(from: date)
    }
}

enum MessageSource: String, Codable {
    case websocket = "WebSocket"
    case apns = "APNS"
}
