import SwiftUI

@main
struct PushAppApp: App {

    // 使用 UIApplicationDelegateAdaptor 桥接 AppDelegate（APNS 注册需要）
    @UIApplicationDelegateAdaptor(AppDelegate.self) var appDelegate

    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            ContentView()
                .onChange(of: scenePhase) { _, newPhase in
                    handleScenePhase(newPhase)
                }
        }
    }

    /// 处理 App 前后台切换
    ///
    /// iOS 后台 WebSocket 限制：
    ///   - App 进入后台后，系统几秒内会挂起所有网络连接（包括 WebSocket）
    ///   - 挂起后 WebSocket 无法收发消息，再保持连接只是浪费资源
    ///   - 所以主动断开，回到前台时重连
    ///   - 后台期间的消息由 APNS 投递（后端 PushDispatcher 自动切换通道）
    private func handleScenePhase(_ phase: ScenePhase) {
        switch phase {
        case .active:
            // App 回到前台：重连 WebSocket + 拉取离线消息
            print("[App] 进入前台，重连 WebSocket")
            PushManager.shared.connectWebSocket()

        case .background:
            // App 进入后台：主动断开 WebSocket（系统几秒后会挂起，不如主动断）
            print("[App] 进入后台，断开 WebSocket（后台由 APNS 接管推送）")
            PushManager.shared.disconnectWebSocket()

        case .inactive:
            // 过渡状态（如来电、控制中心），不做处理
            break

        @unknown default:
            break
        }
    }
}
