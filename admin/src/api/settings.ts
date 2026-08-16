import { get, post, put, del } from '@/utils/request'
import type { Settings, PageQuery, PageResult } from './types'

// 获取系统设置
export function getSettingsApi() {
  return get<Settings>('/admin/settings')
}

// 更新系统设置
export function updateSettingsApi(data: Partial<Settings>) {
  return put('/admin/settings', data)
}

// 检测端口可用性
export function checkPortApi(port: number) {
  return get<{
    port: number
    available: boolean
    in_use: boolean
    process: string
    is_privileged: boolean
    well_known: string | null
    recommend: boolean
  }>('/admin/settings/check-port', { port })
}

// 获取邮件配置（用于设备掉线通知）
export function getMailConfigApi() {
  return get<{
    enabled: boolean
    host: string
    port: string
    username: string
    password: string
    encryption: string
    sender_name: string
  }>('/admin/settings/mail')
}

// 保存邮件配置
export function saveMailConfigApi(data: {
  enabled: boolean
  host: string
  port: string
  username: string
  password: string
  encryption: string
  sender_name: string
}) {
  return post('/admin/settings/mail', data)
}

// 测试邮件配置
export function testMailConfigApi(data: {
  to: string
  host: string
  port: string
  username: string
  password: string
  encryption: string
  sender_name: string
}) {
  return post<{ message: string }>('/admin/settings/mail/test', data)
}

// 测试存储配置
export function testStorageApi(data: {
  type: string
  bucket: string
  region: string
  endpoint: string
}) {
  return get<{ success: boolean; message: string }>(
    '/admin/settings/test-storage',
    data
  )
}

// 获取系统日志
export function getSystemLogsApi(params: {
  page: number
  pageSize: number
  level?: string
}) {
  return get('/admin/settings/logs', params)
}

// 清除缓存
export function clearCacheApi() {
  return get<{ success: boolean }>('/admin/settings/clear-cache')
}

// 获取系统信息
export function getSystemInfoApi() {
  return get<{
    version: string
    php_version: string
    swoole_version: string
    redis_status: string
    mysql_status: string
    uptime: number
    cpu: number
    memory: { used: number; total: number }
    disk: { used: number; total: number }
  }>('/admin/settings/system-info')
}

// 版本检测 - 对比本地与云端版本
export function checkVersionApi(params?: { ghProxy?: boolean }) {
  return get<{
    local: { commit: string; short: string; date: string }
    remote: { commit: string; short: string; branch: string; date: string }
    status: 'up-to-date' | 'behind' | 'ahead' | 'diverged' | 'unknown'
    ahead_count: number
    behind_count: number
    changelog: string[]
  }>('/admin/settings/check-version', params)
}

// 一键更新 - 触发服务器端更新流程
export function systemUpdateApi(params?: {
  proxy?: string
  ghProxy?: boolean
  skipBuild?: boolean
  skipMigration?: boolean
}) {
  return post<{
    task_id: string
    message: string
  }>('/admin/settings/system-update', params)
}

// 查询更新进度
export function getUpdateProgressApi(taskId: string) {
  return get<{
    task_id: string
    status: 'pending' | 'running' | 'success' | 'failed'
    step: string
    progress: number
    message: string
    logs: string[]
  }>(`/admin/settings/update-progress/${taskId}`)
}

// ==================== iOS APNS 推送配置 ====================

// APNS 配置
export interface ApnsConfig {
  enabled: boolean
  team_id: string
  key_id: string
  auth_key: string
  bundle_id: string
  environment: 'production' | 'development'
}

// 获取 APNS 配置
export function getApnsConfigApi() {
  return get<ApnsConfig>('/admin/settings/apns')
}

// 保存 APNS 配置
export function saveApnsConfigApi(data: ApnsConfig) {
  return post<{ message: string }>('/admin/settings/apns', data)
}

// 测试 APNS 推送
export function testApnsPushApi(data: {
  device_token: string
  title?: string
  body?: string
}) {
  return post<{ message: string; apns_id: string }>('/admin/settings/apns/test', data)
}

// APNS 健康度统计
export interface ApnsHealthStats {
  success_total: number
  fail_total: number
  success_today: number
  fail_today: number
  success_rate: number
  last_success_at: string
  last_fail_at: string
  last_circuit_break: string
  last_reset: string
  circuit_broken: boolean
  fail_count: number
}

// 获取 APNS 健康度统计
export function getApnsHealthApi() {
  return get<ApnsHealthStats>('/admin/settings/apns/health')
}

// 重置 APNS 熔断状态
export function resetApnsCircuitApi() {
  return post<{ message: string }>('/admin/settings/apns/reset-circuit')
}

// ==================== 路径配置（实时生效） ====================

export interface SettingsPaths {
  admin_path: string
  user_path: string
  admin_api_prefix: string
  user_api_prefix: string
}

// 获取路径配置
export function getPathsConfigApi() {
  return get<SettingsPaths>('/admin/settings/paths')
}

// 保存路径配置
export function savePathsConfigApi(data: SettingsPaths) {
  return post<{ message: string; need_reload_nginx?: boolean; nginx_message?: string }>('/admin/settings/paths', data)
}

// ==================== 安全配置扩展 ====================

export interface SettingsSecurityExt {
  qq_bind_enabled: number
  password_reset_mode: 'qq' | 'qq_email' | 'email_only'
  session_expire_hours: number
  password_reuse_limit: number
}

// 获取安全扩展配置
export function getSecurityExtConfigApi() {
  return get<SettingsSecurityExt>('/admin/settings/security-ext')
}

// 保存安全扩展配置
export function saveSecurityExtConfigApi(data: SettingsSecurityExt) {
  return post<{ message: string }>('/admin/settings/security-ext', data)
}

// ==================== 用户 APP 配置 ====================

export interface SettingsUserApp {
  apk_version: string
  apk_url: string
  apk_size: string
  apk_md5: string
  apk_force_update: number
  ipa_version: string
  ipa_url: string
  user_register_enabled: number
  user_default_key_limit: number
  user_default_device_limit: number
}

// 获取用户 APP 配置
export function getUserAppConfigApi() {
  return get<SettingsUserApp>('/admin/settings/user-app')
}

// 保存用户 APP 配置
export function saveUserAppConfigApi(data: Partial<SettingsUserApp>) {
  return post<{ message: string }>('/admin/settings/user-app', data)
}

// ==================== 用户公告 CRUD ====================

export interface UserNoticeRecord {
  id: number
  title: string
  content?: string
  type: number // 1=普通 2=紧急 3=维护 4=新功能
  level: number // 1=普通 2=重要 3=紧急
  show_dialog: number
  show_home: number
  is_sticky: number
  sort: number
  status: number // 0=草稿 1=已发布
  start_at?: string
  end_at?: string
  publish_at?: string
  created_by?: number
  updated_by?: number
  created_at: string
  updated_at: string
  read_count?: number
}

export interface UserNoticeForm {
  id?: number
  title: string
  content?: string
  type?: number
  level?: number
  show_dialog?: number
  show_home?: number
  is_sticky?: number
  sort?: number
  status?: number
  start_at?: string
  end_at?: string
}

// 获取公告列表
export function getUserNoticeListApi(params: PageQuery) {
  return get<PageResult<UserNoticeRecord>>('/admin/user-notices', params)
}

// 获取公告详情
export function getUserNoticeDetailApi(id: number) {
  return get<UserNoticeRecord>(`/admin/user-notices/${id}`)
}

// 创建公告
export function createUserNoticeApi(data: UserNoticeForm) {
  return post<{ id: number; message: string }>('/admin/user-notices', data)
}

// 更新公告
export function updateUserNoticeApi(id: number, data: UserNoticeForm) {
  return put<{ message: string }>(`/admin/user-notices/${id}`, data)
}

// 删除公告
export function deleteUserNoticeApi(id: number) {
  return del<{ message: string }>(`/admin/user-notices/${id}`)
}

// 发布公告
export function publishUserNoticeApi(id: number) {
  return post<{ message: string }>(`/admin/user-notices/${id}/publish`)
}

// 撤回公告
export function withdrawUserNoticeApi(id: number) {
  return post<{ message: string }>(`/admin/user-notices/${id}/withdraw`)
}

// 置顶/取消置顶
export function toggleUserNoticeStickyApi(id: number, is_sticky: number) {
  return put<{ message: string }>(`/admin/user-notices/${id}/sticky`, { is_sticky })
}
