// ============================================================
// API 类型定义 · 即时消息推送系统
// ============================================================

// 通用分页请求
export interface PageQuery {
  page: number
  pageSize: number
  keyword?: string
  status?: number | string
  startDate?: string
  endDate?: string
  [key: string]: any
}

// 通用分页响应
export interface PageResult<T> {
  list: T[]
  total: number
  page: number
  pageSize: number
}

// 通用枚举
export enum StatusEnum {
  Disabled = 0,
  Enabled = 1
}

// ---- 认证相关 ----
export interface LoginParams {
  username: string
  password: string
  captcha_token: string
  captcha_input: string
}

export interface LoginResult {
  token: string
  admin?: {
    id: number
    username: string
    role: string
    status: number
  }
}

export interface UserInfo {
  id: number
  username: string
  nickname?: string
  avatar?: string
  role: string
  status: number
  created_at?: string
  updated_at?: string
}

// ---- 用户管理 ----
export interface UserRecord {
  id: number
  userId: string
  username: string
  nickname?: string
  avatar?: string
  email?: string
  phone?: string
  status: number
  deviceCount: number
  keyCount: number
  lastActiveAt?: string
  createdAt: string
}

export interface UserForm {
  id?: number
  username: string
  nickname?: string
  email?: string
  phone?: string
  password?: string
  status?: number
}

// ---- Key 管理 ----
export interface KeyRecord {
  id: number
  keyId: string
  userId: string
  title: string
  appKey: string
  appSecret: string
  platform: string
  status: number
  dailyLimit: number
  todayCount: number
  totalPush: number
  expiresAt?: string
  createdAt: string
  notifyEmail: string
  notifyEnabled: number
  notifyInterval: number
}

export interface KeyForm {
  id?: number
  userId: string
  title: string
  platform: string
  dailyLimit?: number
  expiresAt?: string
  status?: number
  notifyEmail?: string
  notifyEnabled?: number
  notifyInterval?: number
}

// ---- 设备管理 ----
export interface DeviceRecord {
  id: number
  deviceId: string
  userId: string
  platform: 'android' | 'ios' | 'web' | 'harmony'
  model?: string
  osVersion?: string
  appVersion?: string
  status: number
  online: boolean
  lastActiveAt?: string
  tags?: string[]
  createdAt: string
}

export interface DeviceForm {
  id?: number
  userId: string
  deviceId: string
  platform: string
  tags?: string[]
}

// ---- 推送记录 ----
export interface PushLog {
  id: number
  messageId: string
  userId: string
  title: string
  content: string
  platform: string
  targetType: 'all' | 'tag' | 'alias' | 'deviceId'
  target: string
  successCount: number
  failCount: number
  status: 'pending' | 'sending' | 'success' | 'partial' | 'failed'
  pushType: 'notification' | 'message' | 'silent'
  createdAt: string
}

export interface PushParams {
  title: string
  content: string
  platform: string
  targetType: 'all' | 'tag' | 'alias' | 'deviceId'
  target?: string
  pushType?: 'notification' | 'message' | 'silent'
  extras?: Record<string, any>
}

// ---- 黑名单 ----
export interface BlacklistRecord {
  id: number
  userId: string
  deviceId?: string
  type: 'user' | 'device' | 'ip'
  value: string
  reason: string
  expireAt?: string
  createdAt: string
}

export interface BlacklistForm {
  id?: number
  type: 'user' | 'device' | 'ip'
  value: string
  reason: string
  expireAt?: string
}

// ---- 管理员 ----
export interface AdminRecord {
  id: number
  username: string
  nickname: string
  avatar?: string
  email?: string
  phone?: string
  roles: string[]
  status: number
  lastLoginAt?: string
  createdAt: string
}

export interface AdminForm {
  id?: number
  username: string
  nickname: string
  email?: string
  phone?: string
  password?: string
  roles: string[]
  status?: number
}

// ---- APP 打包 ----
export interface AppBuildRecord {
  build_id: string
  app_name: string
  default_key: string
  server_url: string
  ws_url: string
  icon_path: string
  package_name?: string
  status: 'pending' | 'processing' | 'success' | 'failed'
  apk_path?: string
  result_message?: string
  created_at: string
  updated_at?: string
  started_at?: string
  finished_at?: string
}

export interface AppBuildForm {
  app_name: string
  default_key?: string
  server_url?: string
  ws_url?: string
  icon_path?: string
  package_name?: string
}

// ---- 开放 API ----
export interface ApiKeyRecord {
  id: number
  name: string
  accessKey: string
  secretKey: string
  permissions: string[]
  ipWhitelist: string[]
  rateLimit: number
  status: number
  lastUsedAt?: string
  expiresAt?: string
  createdAt: string
}

export interface ApiKeyForm {
  id?: number
  name: string
  permissions: string[]
  ipWhitelist?: string[]
  rateLimit?: number
  expiresAt?: string
  status?: number
}

// ---- 系统设置 ----
export interface Settings {
  siteName: string
  siteLogo?: string
  siteDescription?: string
  push: {
    defaultRetry: number
    timeout: number
    concurrent: number
  }
  security: {
    passwordExpire: number
    loginLimit: number
    captchaEnabled: boolean
    twoFactorEnabled: boolean
  }
  storage: {
    type: 'local' | 'oss' | 'cos' | 's3'
    bucket?: string
    region?: string
    endpoint?: string
  }
  notification: {
    mailEnabled: boolean
    mailHost?: string
    mailPort?: number
    mailFrom?: string
  }
}

// ---- 仪表盘统计 ----
export interface DashboardStats {
  onlineDevices: number
  totalDevices: number
  todayPush: number
  totalPush: number
  totalUsers: number
  totalKeys: number
  successRate: number
  activeKeys: number
}

export interface TrendItem {
  date: string
  value: number
}

export interface PlatformDist {
  name: string
  value: number
}

// ---- 测试调试推送 ----
export interface TestPushResult {
  online_count: number
  success_count: number
  fail_count: number
  detail: Array<{
    device_id?: string
    fd?: number
    status: 'success' | 'fail' | 'offline'
    reason?: string
  }>
  elapsed_ms: number
  message: string
  debug: {
    target_type: string
    target_value: string
    server_time: string
    device_online?: boolean
    online_fd_count?: number
    subscribed_devices?: number
    online_devices?: number
  }
}
