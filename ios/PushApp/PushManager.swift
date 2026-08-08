import Foundation
import UIKit
import UserNotifications

/**
 * PushManager - 推送管理器（单例）
 *
 * 职责：
 *   1. 管理 APNS device token 的上报
 *   2. 处理收到的 APNS 推送内容
 *   3. 管理 WebSocket 连接（前台时使用）
 *   4. 协调 WebSocket 和 APNS 双通道
 *
 * 双通道策略：
 *   - 前台时：WebSocket 在线，消息实时投递（低延迟）
 *   - 后台/被杀时：WebSocket 断开，后端自动走 APNS 投递
 *   - App 重新打开时：WebSocket 重连 + 拉取离线消息
 *
 * 线程安全（P1 修复）：
 *   - 所有 @Published 属性都在 @MainActor 上，外部调用会自动 hop 到主线程
 *   - WebSocketClient 的回调已统一 dispatch 到 main，在此不会二次 hop
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

    private init() {
        // 启动时加载本地消息
        messages = preferences.loadMessages()

        // 启动时立即检查是否有已保存的 APNS token 需要上报
        // （App 被杀后重启，系统会重新触发 didRegisterForRemoteNotificationsWithDeviceToken，
        //  但如果权限被拒绝或系统没触发，也不能漏掉 — 这里用保存值兜底）
        Task {
            await self.ensureApnsTokenReported()
        }
    }

    // MARK: - APNS Token 上报

    /// 确保 APNS token 已上报（启动时兜底）
    private func ensureApnsTokenReported() async {
        let saved = preferences.lastReportedApnsToken
        if !saved.isEmpty && saved != apnsToken {
            apnsToken = saved
        }
    }

    /// 注册 APNS 成功后，将 device token 上报给后端
    func registerApnsToken(_ token: String) async {
        apnsToken = token

        // P1 修复：使用持久化的去重标记，确保 App 重启后也不会漏上报
        guard token != preferences.lastReportedApnsToken else { return }

        let pushKey = preferences.pushKey
        let deviceId = preferences.deviceId
        let serverUrl = preferences.serverUrl

        guard !pushKey.isEmpty, !deviceId.isEmpty, !serverUrl.isEmpty else {
            print("[PushManager] 未配置 pushKey/deviceId/serverUrl，跳过 APNS token 上报")
            return
        }

        // 使用持久化值代替内存变量，修复重启后去重失效问题
        preferences.lastReportedApnsToken = token

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
                // 上报失败：回退去重标记，下次还能重试
                preferences.lastReportedApnsToken = ""
            }
        } catch {
            print("[PushManager] APNS token 上报异常: \(error.localizedDescription)")
            // 异常：回退去重标记，下次还能重试
            preferences.lastReportedApnsToken = ""
        }
    }

    // MARK: - 处理 APNS 推送内容

    /// 收到 APNS 推送时调用（前台/后台/被杀均会触发）
    func handleApnsPayload(_ userInfo: [AnyHashable: Any]) {
        let aps = userInfo["aps"] as? [String: Any] ?? [:]
        let alert = aps["alert"] as? [String: Any] ?? [:]

        let title = (alert["title"] as? String) ?? ""
        let content = (alert["body"] as? String) ?? ""
        // 优先读后端透传的 message_id，其次读后端可能用的 msg_id / data.id
        let messageId = (userInfo["message_id"] as? String)
            ?? (userInfo["msg_id"] as? String)
            ?? (userInfo["id"] as? String)
            ?? UUID().uuidString
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
        let badge = aps["badge"] as? Int ?? messages.count
        if #available(iOS 16.0, *) {
            UNUserNotificationCenter.current().setBadgeCount(badge) { _ in }
        } else {
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

        // 已连接或正在连接则不重复创建
        if connectionState == .connected || connectionState == .connecting {
            return
        }

        connectionState = .connecting

        // 关闭旧连接
        webSocketClient?.disconnect()
        webSocketClient = nil

        // 创建新连接
        // PushWebSocketClient 内已统一 dispatch 到 main，这里不需要再包 Task { @MainActor in }
        let client = PushWebSocketClient(
            serverUrl: serverUrl,
            pushKey: pushKey,
            deviceId: deviceId,
            onMessage: { [weak self] message in
                self?.handleWebSocketMessage(message)
            },
            onStateChange: { [weak self] state in
                self?.connectionState = state
            }
        )
        webSocketClient = client
        client.connect()
    }

    /// 断开 WebSocket（App 进入后台时调用）
    func disconnectWebSocket() {
        webSocketClient?.disconnect()
        webSocketClient = nil
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
        if #available(iOS 16.0, *) {
            UNUserNotificationCenter.current().setBadgeCount(0) { _ in }
        } else {
            UIApplication.shared.applicationIconBadgeNumber = 0
        }
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
