import Foundation

/**
 * PreferencesManager - 本地偏好设置管理（单例）
 *
 * 使用 UserDefaults 持久化：
 *   - 服务器地址（如 https://push.example.com）
 *   - 推送 Key
 *   - 设备 ID（首次启动自动生成 UUID）
 *   - 历史消息列表
 *
 * 说明：
 *   - 设备 ID 一旦生成就固定不变，用于 WebSocket 鉴权和 APNS token 上报
 *   - 消息列表持久化到 UserDefaults（轻量级方案，消息量大时可改用 SQLite/CoreData）
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

    var serverUrl: String {
        get { defaults.string(forKey: kServerUrl) ?? "" }
        set { defaults.set(newValue, forKey: kServerUrl) }
    }

    // MARK: - 推送 Key

    var pushKey: String {
        get { defaults.string(forKey: kPushKey) ?? "" }
        set { defaults.set(newValue, forKey: kPushKey) }
    }

    // MARK: - 设备 ID

    var deviceId: String {
        if let id = defaults.string(forKey: kDeviceId), !id.isEmpty {
            return id
        }
        // 首次访问时生成唯一设备 ID
        let newId = UUID().uuidString
        defaults.set(newId, forKey: kDeviceId)
        return newId
    }

    // MARK: - 上次上报的 APNS Token（用于去重）

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
