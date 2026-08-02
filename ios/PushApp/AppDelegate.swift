import UIKit
import UserNotifications

/**
 * AppDelegate - 处理 APNS 注册与推送回调
 *
 * iOS 推送的核心流程：
 *   1. App 启动时请求通知权限
 *   2. 注册 APNS，系统回调 didRegisterForRemoteNotificationsWithDeviceToken
 *   3. 拿到 device token 后上报给后端
 *   4. 收到推送时（前台/后台/被杀），系统回调 didReceiveRemoteNotification
 *
 * iOS 限制说明：
 *   - App 在前台时，收到推送不会自动弹出通知栏，需手动触发 UNNotificationContent
 *   - App 在后台/被杀时，系统自动展示通知，点击后唤醒 App 并回调
 *   - WebSocket 在后台最多存活几秒就被系统挂起，所以必须依赖 APNS
 */
class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {

    func application(
        _ application: UIApplication,
        didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil
    ) -> Bool {

        // 1. 设置 UNUserNotificationCenter 代理（前台收到推送时回调）
        UNUserNotificationCenter.current().delegate = self

        // 2. 请求通知权限
        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound, .badge]) { granted, error in
            DispatchQueue.main.async {
                if granted {
                    print("[APNS] 通知权限已授予，开始注册 APNS")
                    application.registerForRemoteNotifications()
                } else {
                    print("[APNS] 通知权限被拒绝，用户将无法收到后台推送")
                }
            }
        }

        return true
    }

    // MARK: - APNS 注册成功，拿到 device token

    func application(
        _ application: UIApplication,
        didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data
    ) {
        // 将 Data 转换为十六进制字符串（后端需要的格式）
        let tokenString = deviceToken.map { String(format: "%02x", $0) }.joined()
        print("[APNS] 注册成功，device token: \(tokenString.prefix(32))...")

        // 上报 token 到后端
        Task {
            await PushManager.shared.registerApnsToken(tokenString)
        }
    }

    // MARK: - APNS 注册失败

    func application(
        _ application: UIApplication,
        didFailToRegisterForRemoteNotificationsWithError error: Error
    ) {
        print("[APNS] 注册失败: \(error.localizedDescription)")

        // 模拟器无法注册 APNS，这是正常的
        #if targetEnvironment(simulator)
        print("[APNS] 当前为模拟器环境，APNS 不可用，请使用真机测试")
        #endif
    }

    // MARK: - 收到远程推送（后台/被杀时点击通知唤醒 App）

    func application(
        _ application: UIApplication,
        didReceiveRemoteNotification userInfo: [AnyHashable: Any],
        fetchCompletionHandler completionHandler: @escaping (UIBackgroundFetchResult) -> Void
    ) {
        print("[APNS] 收到远程推送: \(userInfo)")

        // 处理推送内容（存入本地消息列表）
        PushManager.shared.handleApnsPayload(userInfo)

        completionHandler(.newData)
    }

    // MARK: - UNUserNotificationCenterDelegate

    /// App 在前台时收到推送的回调
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        let userInfo = notification.request.content.userInfo
        print("[APNS] 前台收到推送: \(userInfo)")

        // 处理推送内容
        PushManager.shared.handleApnsPayload(userInfo)

        // 前台也展示通知（横幅 + 声音）
        completionHandler([.banner, .sound, .badge])
    }

    /// 用户点击通知栏的推送后回调
    func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let userInfo = response.notification.request.content.userInfo
        print("[APNS] 用户点击通知: \(userInfo)")

        PushManager.shared.handleApnsPayload(userInfo)

        completionHandler()
    }
}
