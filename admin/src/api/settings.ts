import { get, post, put } from '@/utils/request'
import type { Settings } from './types'

// 获取系统设置
export function getSettingsApi() {
  return get<Settings>('/admin/settings')
}

// 更新系统设置
export function updateSettingsApi(data: Partial<Settings>) {
  return put('/admin/settings', data)
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
    uptime: number
    cpu: number
    memory: { used: number; total: number }
    disk: { used: number; total: number }
  }>('/admin/settings/system-info')
}

// 版本检测 - 对比本地与云端版本
export function checkVersionApi() {
  return get<{
    local: { commit: string; short: string; date: string }
    remote: { commit: string; short: string; branch: string; date: string }
    status: 'up-to-date' | 'behind' | 'ahead' | 'diverged' | 'unknown'
    ahead_count: number
    behind_count: number
    changelog: string[]
  }>('/admin/settings/check-version')
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
