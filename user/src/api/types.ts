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
  items: T[]
  total: number
  page: number
  pageSize: number
  per_page?: number
  total_pages?: number
}

// ==================== 认证相关 ====================
export interface LoginParams {
  username: string
  password: string
  captcha?: string
}

export interface RegisterParams {
  username: string
  password: string
  phone?: string
  email?: string
  code_type?: 'sms' | 'email' | ''
  code?: string
  captcha?: string
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
  device_count: number
  online_count: number
  key_count: number
  today_push_count: number
  yesterday_push_count: number
  total_push_count: number
  push_trend_7d?: { date: string; count: number }[]
  key_top?: { key_name: string; count: number }[]
  platform_distribution?: { platform: string; count: number }[]
}

// ==================== 推送 ====================
export interface PushSendParams {
  key_id: number
  title: string
  content: string
  platform?: 'all' | 'android' | 'ios'
  target_type?: 'broadcast' | 'key' | 'device'
  device_id?: number
  payload?: Record<string, any>
}

// ==================== 推送记录 ====================
export interface PushLog {
  id: number
  key_id: number
  key_name?: string
  title: string
  content: string
  platform: string
  target_type: string
  total: number
  success: number
  failed: number
  status: 'pending' | 'sending' | 'completed' | 'failed' | 'partial'
  retry_count: number
  created_at: string
  finished_at?: string
}

export interface PushLogDetail extends PushLog {
  devices?: PushLogDevice[]
  message?: string
}

export interface PushLogDevice {
  id: number
  device_id: number
  device_name?: string
  platform?: string
  status: 'success' | 'failed' | 'pending'
  error_reason?: string
  sent_at?: string
}

// ==================== 设备 ====================
export interface Device {
  id: number
  device_id: string
  name?: string
  platform: 'android' | 'ios' | 'unknown'
  model?: string
  app_version?: string
  os_version?: string
  status: 0 | 1
  is_online: 0 | 1
  last_online_at?: string
  subscribed_keys?: number[]
  created_at: string
}

// ==================== Push Key ====================
export interface PushKey {
  id: number
  name: string
  push_key: string
  description?: string
  subscriber_count?: number
  status: 0 | 1
  created_at: string
  updated_at?: string
}

// ==================== API Key（开放 API 调用凭证） ====================
export interface ApiKey {
  id: number
  name: string
  api_key: string
  api_secret?: string
  status: 0 | 1
  call_count?: number
  last_called_at?: string
  expires_at?: string
  created_at: string
}

// ==================== APP 信息 ====================
export interface AppInfo {
  apk_name?: string
  apk_version?: string
  apk_download_url?: string
  apk_size?: string
  apk_updated_at?: string
  ipa_name?: string
  ipa_version?: string
  hbuilderx_enabled?: boolean
  qq_bind_enabled?: boolean
}

export interface HBuilderXGenerateParams {
  app_name: string
  package_name: string
  app_description?: string
  app_icon?: string
  version_name?: string
  version_code?: number
}

// ==================== 公告 ====================
export interface Notice {
  id: number
  title: string
  content?: string
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
}

export interface BindQqParams {
  qq: string
  captcha?: string
}
