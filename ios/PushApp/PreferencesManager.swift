import Foundation
import UIKit

/**
 * PreferencesManager - 本地偏好设置管理（单例）
 *
 * 使用 UserDefaults 持久化：
 *   - 服务器地址（如 https://push.example.com）
 *   - 推送 Key
 *   - 设备 ID（首次启动用 IDFV，无 IDFV 时 fallback UUID）
 *   - 上次上报的 APNS token（用于防止重复上报）
 *   - 历史消息列表
 *
 * 说明：
 *   - device_id 使用 UIDevice.identifierForVendor（IDFV），
 *     同一厂商下的 APP 在卸载重装后 IDFV 仍保持不变，
 *     避免用户卸载重装后被后端识别为"新设备"
 *   - UserDefaults 存一个 fallback UUID，保证极端情况（无 IDFV）也有稳定标识
 *   - 消息列表持久化到 UserDefaults（轻量级方案，200 条上限，消息量大时可改用 SQLite/CoreData）
 */
class PreferencesManager {

    static let shared = PreferencesManager()

    private let defaults = UserDefaults.standard

    // MARK: - Keys

    private let kServerUrl  = "server_url"
    private let kPushKey    = "push_key"
    private let kDeviceId   = "device_id"
    private let kMessages   = "cached_messages"
    private let kLastReportedToken = "last_reported_apns_token"

    private init() {}

    // MARK: - 服务器地址

    /// 服务器 HTTP(S) 地址（如 https://push.example.com）
    /// WebSocketClient 会自动把 http/https 转成 ws/wss，所以这里统一存 HTTP(S)。
    /// 自动归一化：ws:// → http://, wss:// → https://, 无前缀 → http://
    var serverUrl: String {
        get { defaults.string(forKey: kServerUrl) ?? "" }
        set {
            let normalized = Self.normalizeHttpUrl(newValue)
            defaults.set(normalized, forKey: kServerUrl)
        }
    }

    /// 归一化为 HTTP(S) 协议：ws→http, wss→https, 补缺失的 http:// 前缀
    static func normalizeHttpUrl(_ url: String) -> String {
        let trimmed = url.trimmingCharacters(in: .whitespaces).replacingOccurrences(of: "/+$", with: "", options: .regularExpression)
        guard !trimmed.isEmpty else { return trimmed }
        if trimmed.lowercased().hasPrefix("wss://") {
            return "https://" + trimmed.dropFirst(6)
        }
        if trimmed.lowercased().hasPrefix("ws://") {
            return "http://" + trimmed.dropFirst(5)
        }
        if trimmed.lowercased().hasPrefix("http://") || trimmed.lowercased().hasPrefix("https://") {
            return trimmed
        }
        return "http://" + trimmed
    }

    // MARK: - 推送 Key

    var pushKey: String {
        get { defaults.string(forKey: kPushKey) ?? "" }
        set { defaults.set(newValue, forKey: kPushKey) }
    }

    // MARK: - 设备 ID
    // P0 修复：改用 IDFV，卸载重装后 ID 仍稳定（Android 端对应 ANDROID_ID）

    var deviceId: String {
        // 1. 如果 UserDefaults 已有值，直接返回
        if let id = defaults.string(forKey: kDeviceId), !id.isEmpty {
            return id
        }

        // 2. 尝试用 IDFV（Identifier for Vendor），iOS 厂商级稳定标识
        if let idfv = UIDevice.current.identifierForVendor?.uuidString, !idfv.isEmpty {
            defaults.set(idfv, forKey: kDeviceId)
            return idfv
        }

        // 3. fallback：UUID（极端情况，如 App 首次启动、系统还没分配 IDFV）
        let uuid = UUID().uuidString
        defaults.set(uuid, forKey: kDeviceId)
        return uuid
    }

    // MARK: - 上次上报的 APNS Token（持久化去重）

    var lastReportedApnsToken: String {
        get { defaults.string(forKey: kLastReportedToken) ?? "" }
        set { defaults.set(newValue, forKey: kLastReportedToken) }
    }

    // MARK: - 消息持久化

    /// 加载本地缓存的消息
    func loadMessages() -> [PushMessage] {
        guard let data = defaults.data(forKey: kMessages) else {
            return []
        }
        let decoder = JSONDecoder()
        return (try? decoder.decode([PushMessage].self, from: data)) ?? []
    }

    /// 保存消息到本地
    func saveMessages(_ messages: [PushMessage]) {
        // 最多保留 200 条，避免无限增长
        let trimmed = Array(messages.prefix(200))
        let encoder = JSONEncoder()
        if let data = try? encoder.encode(trimmed) {
            defaults.set(data, forKey: kMessages)
        }
    }

    /// 清空所有消息
    func clearMessages() {
        defaults.removeObject(forKey: kMessages)
    }

    // MARK: - 配置完整性检查

    /// 检查是否已完成基础配置
    var isConfigured: Bool {
        !serverUrl.isEmpty && !pushKey.isEmpty
    }
}
