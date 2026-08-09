// 通用分页请求参数
export interface PageQuery {
  page: number
  pageSize: number
  per_page?: number
  keyword?: string
  [key: string]: any
}

// 通用分页响应
export interface PageResult<T> {
  list: T[]
  total: number
  page: number
  per_page: number
  total_pages?: number
}

// ==================== 认证相关 ====================
// 后端 AuthController::login 兼容 username / account 双字段、
// captcha_token / captchaToken / captcha 多种写法；此处统一按 admin 端的风格，
// 使用 username + captcha_token + captcha_input，请求发送时再由 auth.ts -> loginApi
// 做一层映射（account = username），与后端路由设计保持一致。
export interface LoginParams {
  username: string
  password: string
  captcha_token?: string
  captcha_input?: string
  // 兼容旧字段（老代码可能仍在传 captcha，保持不报错）
  captcha?: string
}

export interface RegisterParams {
  username: string
  password: string
  phone?: string
  email?: string
  // 验证码三字段（与后端 AuthController::register 读取的字段对齐）：
  //   code_type  = 'captcha'  → 图形验证码：code_target=token(AES加密), code_input=图形码
  //   code_type  = 'sms'|'email' → 短信/邮箱验证码：code_target=手机号/邮箱, code_input=收到的验证码
  //   code_type  = '' → 无额外验证码
  code_type?: 'captcha' | 'sms' | 'email' | ''
  code_target?: string
  code_input?: string
}

export interface SendCodeParams {
  type: 'sms' | 'email'
  target: string
  usage?: 'register' | 'reset' | 'login'
  captcha?: string
}

export interface ResetPasswordParams {
  security_code?: string
  new_password: string
  account?: string
  captcha?: string
}

export interface ResetPasswordByQqParams {
  qq: string
  account?: string
  email?: string
  email_code?: string
  new_password: string
}

export interface LoginData {
  token: string
  security_code?: string
  user_id?: number
  username?: string
}

export interface UserInfo {
  id: number
  username: string
  nickname?: string
  avatar?: string
  phone?: string | null
  email?: string | null
  qq?: string | null
  status: number
  created_at?: string
}

export interface SecurityConfig {
  qq_bind_enabled: boolean
  password_reset_mode: 'qq_only' | 'email_only' | 'both'
  require_email_for_reset: boolean
  [key: string]: any
}

// ==================== 仪表盘 ====================
export interface DashboardOverview {
  total_devices: number
  online_devices: number
  total_keys: number
  active_keys: number
  today_push: number
  yesterday_push: number
  today_new_devices: number
  trend_7d?: { date: string; count: number }[]
}

// ==================== 推送 ====================
export interface PushSendParams {
  target_type: 'device' | 'key' | 'broadcast'
  target_value: string
  title: string
  content: string
  payload?: Record<string, any>
  priority?: 'high' | 'normal' | 'low'
}

// ==================== 推送记录 ====================
export interface PushLog {
  id: number
  api_key_id: number
  target_type: string
  target_value: string
  title: string
  content: string
  success_count: number
  fail_count: number
  fail_reason: string
  status: number  // 0=failed, 1=success, 2=partial, 4=stored_offline
  elapsed_ms: number
  created_at: string
  detail?: any
  fail_detail?: any[]
  push_detail?: any[]
}

// PushLogDetail extends PushLog
export interface PushLogDetail extends PushLog {
  // additional fields from detail
}

// PushLogDevice: match backend push_detail items
export interface PushLogDevice {
  device_id: string
  status: string
  reason?: string
}

// ==================== 设备 ====================
export interface Device {
  id: number
  device_id: string
  device_name: string
  device_model: string
  platform: string
  os_version: string
  ip: string
  ua: string
  status: number  // 1=enabled, 2=disabled
  online: number  // 0 or 1
  model: string
  last_connect_at: string
  push_key_value?: string
  push_key_name?: string
  created_at: string
  updated_at: string
}

// ==================== Push Key ====================
export interface PushKey {
  id: number
  key_value: string
  name: string
  status: number  // 0 or 1
  max_devices: number
  subscribed_total: number
  online_count: number
  notify_enabled?: number  // 0 or 1，掉线邮件通知开关
  notify_email?: string   // 掉线通知邮箱
  notify_interval?: number // 通知冷却间隔秒数
  created_at: string
  updated_at: string
}

// ==================== API Key（开放 API 调用凭证） ====================
export interface ApiKey {
  id: number
  key_value: string
  name: string
  description: string
  status: number  // 0 or 1
  expire_at: string | null
  created_at: string
  updated_at: string
}

// ==================== APP 信息 ====================
export interface AppInfo {
  download?: {
    apk_download_url?: string
    ipa_download_url?: string
    apk_version?: string
    ipa_version?: string
    update_log?: string
    force_update?: number
    user_hbx_enabled?: number
  }
  api_config?: {
    api_base?: string
    ws_base?: string
  }
}

// HBuilderXGenerateParams: match backend AppController::hbuilderxGenerate
export interface HBuilderXGenerateParams {
  app_name: string
  package_id: string
  api_base_url?: string
  ws_url?: string
  icon_base64?: string
}

// ==================== 公告 ====================
export interface Notice {
  id: number
  title: string
  content?: string
  summary?: string  // 列表接口返回的截断内容（LEFT(content,300)）
  type: 1 | 2 | 3 | 4
  level: 1 | 2 | 3
  show_dialog: 0 | 1
  show_home: 0 | 1
  is_sticky: 0 | 1
  sort: number
  status: 0 | 1
  start_at?: string
  end_at?: string
  publish_at?: string
  created_at: string
}

export interface NoticeReadRecord {
  id: number
  notice_id: number
  read_at: string
}

// ==================== 个人中心 ====================
export interface ProfileUpdateParams {
  nickname?: string
  avatar?: string
  old_password?: string
  new_password?: string
  email?: string
  email_code?: string
  phone?: string
  phone_code?: string
}

export interface ChangePasswordParams {
  old_password: string
  new_password: string
  confirm_password: string
}

export interface BindQqParams {
  qq: string
  captcha?: string
}
