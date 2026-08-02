import SwiftUI

/**
 * ContentView - 主界面
 *
 * 包含两个 Tab：
 *   1. 消息列表：展示收到的推送消息（WebSocket + APNS 合并）
 *   2. 设置：配置服务器地址、推送 Key、查看设备信息
 *
 * 首次启动未配置时，自动跳转到设置页引导用户完成配置
 */
struct ContentView: View {

    @StateObject private var pushManager = PushManager.shared
    @State private var selectedTab = 0
    @State private var showSettingsSheet = false

    var body: some View {
        TabView(selection: $selectedTab) {
            // Tab 1: 消息列表
            MessageListView()
                .tabItem {
                    Label("消息", systemImage: "bell.fill")
                }
                .tag(0)

            // Tab 2: 设置
            SettingsView()
                .tabItem {
                    Label("设置", systemImage: "gearshape.fill")
                }
                .tag(1)
        }
        .environmentObject(pushManager)
        .onAppear {
            // 首次启动未配置时，跳转到设置页
            if !PreferencesManager.shared.isConfigured {
                selectedTab = 1
            }
            // App 启动时连接 WebSocket
            pushManager.connectWebSocket()
        }
    }
}

// MARK: - 消息列表视图

struct MessageListView: View {

    @EnvironmentObject var pushManager: PushManager

    var body: some View {
        NavigationStack {
            Group {
                if pushManager.messages.isEmpty {
                    // 空状态
                    VStack(spacing: 16) {
                        Image(systemName: "bell.slash")
                            .font(.system(size: 56))
                            .foregroundStyle(.secondary)
                        Text("暂无消息")
                            .font(.headline)
                            .foregroundStyle(.secondary)
                        Text("配置好服务器和推送 Key 后，即可接收推送消息")
                            .font(.caption)
                            .foregroundStyle(.tertiary)
                            .multilineTextAlignment(.center)
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                } else {
                    // 消息列表
                    List {
                        ForEach(pushManager.messages) { message in
                            MessageRow(message: message)
                        }
                        .onDelete(perform: deleteMessage)
                    }
                    .listStyle(.insetGrouped)
                }
            }
            .navigationTitle("推送消息")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarTrailing) {
                    HStack {
                        // 连接状态指示
                        ConnectionStatusBadge(state: pushManager.connectionState)

                        if !pushManager.messages.isEmpty {
                            Button(role: .destructive) {
                                pushManager.clearMessages()
                            } label: {
                                Image(systemName: "trash")
                            }
                        }
                    }
                }
            }
        }
    }

    private func deleteMessage(at offsets: IndexSet) {
        pushManager.messages.remove(atOffsets: offsets)
        PreferencesManager.shared.saveMessages(pushManager.messages)
    }
}

// MARK: - 连接状态徽章

struct ConnectionStatusBadge: View {
    let state: ConnectionState

    var color: Color {
        switch state {
        case .connected:    return .green
        case .connecting:   return .orange
        case .reconnecting: return .orange
        case .disconnected: return .red
        }
    }

    var body: some View {
        HStack(spacing: 4) {
            Circle()
                .fill(color)
                .frame(width: 8, height: 8)
            Text(state.rawValue)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
    }
}

// MARK: - 单条消息行

struct MessageRow: View {
    let message: PushMessage

    var sourceColor: Color {
        switch message.source {
        case .websocket: return .blue
        case .apns:      return .purple
        }
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text(message.title.isEmpty ? "（无标题）" : message.title)
                    .font(.headline)
                    .lineLimit(1)

                Spacer()

                // 消息来源标签
                Text(message.source.rawValue)
                    .font(.system(size: 9, weight: .medium))
                    .padding(.horizontal, 6)
                    .padding(.vertical, 2)
                    .background(sourceColor.opacity(0.15))
                    .foregroundStyle(sourceColor)
                    .clipShape(Capsule())
            }

            Text(message.content)
                .font(.body)
                .foregroundStyle(.secondary)
                .lineLimit(3)

            Text(message.timeString)
                .font(.caption2)
                .foregroundStyle(.tertiary)
        }
        .padding(.vertical, 4)
    }
}

// MARK: - 设置视图

struct SettingsView: View {

    @EnvironmentObject var pushManager: PushManager

    @State private var serverUrl: String = ""
    @State private var pushKey: String = ""
    @State private var deviceId: String = ""
    @State private var apnsToken: String = ""
    @State private var showSavedAlert = false

    private let preferences = PreferencesManager.shared

    var body: some View {
        NavigationStack {
            Form {
                // MARK: - 服务器配置
                Section {
                    TextField("服务器地址", text: $serverUrl)
                        .keyboardType(.URL)
                        .autocapitalization(.none)
                        .textContentType(.URL)
                        .placeholder("https://push.example.com")

                    SecureField("推送 Key", text: $pushKey)
                        .autocapitalization(.none)

                    Button("保存并连接") {
                        saveConfig()
                    }
                    .frame(maxWidth: .infinity, alignment: .center)
                    .buttonStyle(.borderedProminent)
                    .disabled(serverUrl.isEmpty || pushKey.isEmpty)
                } header: {
                    Text("服务器配置")
                } footer: {
                    Text("服务器地址为推送系统的完整 URL（含 https://），推送 Key 在后台管理系统中创建。")
                }

                // MARK: - 设备信息
                Section("设备信息") {
                    InfoRow(label: "设备 ID", value: deviceId)

                    if !apnsToken.isEmpty {
                        InfoRow(label: "APNS Token", value: String(apnsToken.prefix(32)) + "...")
                    } else {
                        InfoRow(label: "APNS Token", value: "未注册")
                    }

                    InfoRow(label: "连接状态", value: pushManager.connectionState.rawValue)
                }

                // MARK: - APNS 说明
                Section {
                    VStack(alignment: .leading, spacing: 8) {
                        Label("iOS 推送说明", systemImage: "info.circle.fill")
                            .font(.subheadline.bold())

                        Text("• 前台时：通过 WebSocket 实时接收消息")
                        Text("• 后台/被杀时：通过 APNS 接收推送通知")
                        Text("• App 重新打开时：自动重连 WebSocket 并补全离线消息")
                    }
                    .font(.caption)
                    .foregroundStyle(.secondary)
                }
            }
            .navigationTitle("设置")
            .navigationBarTitleDisplayMode(.inline)
            .onAppear {
                loadConfig()
            }
            .alert("已保存", isPresented: $showSavedAlert) {
                Button("好的") { }
            } message: {
                Text("配置已保存，正在连接服务器...")
            }
        }
    }

    private func loadConfig() {
        serverUrl = preferences.serverUrl
        pushKey   = preferences.pushKey
        deviceId  = preferences.deviceId
        apnsToken = pushManager.apnsToken
    }

    private func saveConfig() {
        preferences.serverUrl = serverUrl.trimmingCharacters(in: .whitespacesAndNewlines)
        preferences.pushKey   = pushKey.trimmingCharacters(in: .whitespacesAndNewlines)

        showSavedAlert = true

        // 重新连接 WebSocket
        pushManager.disconnectWebSocket()
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) {
            pushManager.connectWebSocket()
        }
    }
}

// MARK: - 信息行组件

struct InfoRow: View {
    let label: String
    let value: String

    var body: some View {
        HStack {
            Text(label)
                .foregroundStyle(.secondary)
            Spacer()
            Text(value)
                .font(.system(.body, design: .monospaced))
                .lineLimit(1)
                .truncationMode(.middle)
        }
    }
}

// MARK: - TextField Placeholder 扩展

extension View {
    func placeholder(_ text: String, when shouldShow: Bool = true) -> some View {
        ZStack(alignment: .leading) {
            if shouldShow {
                Text(text)
                    .foregroundStyle(.tertiary)
            }
            self
        }
    }
}

// MARK: - 预览

#Preview {
    ContentView()
}
