import { get, post, del } from '@/utils/request'
import service from '@/utils/request'
import type {
  PageQuery,
  PageResult,
  PushLog,
  PushParams,
  DashboardStats,
  TrendItem,
  PlatformDist,
  TestPushResult
} from './types'

// 推送记录列表
export function getPushLogListApi(params: PageQuery) {
  return get<PageResult<PushLog>>('/admin/push-logs', params)
}

// 推送记录详情
export function getPushLogDetailApi(id: number) {
  return get<PushLog>(`/admin/push-logs/${id}`)
}

// 删除推送记录
export function deletePushLogApi(id: number) {
  return del(`/admin/push-logs/${id}`)
}

// 导出推送记录（返回二进制流，触发下载）
export function exportPushLogsApi(params: { format: 'csv' | 'json'; keyword?: string }) {
  return service({
    method: 'get',
    url: '/admin/push-logs/export',
    params,
    responseType: 'blob',
  })
}

// 导出消息记录
export function exportMessagesApi(params: { format: 'csv' | 'json'; keyword?: string }) {
  return service({
    method: 'get',
    url: '/admin/messages/export',
    params,
    responseType: 'blob',
  })
}

// 发起推送
export function sendPushApi(data: PushParams) {
  return post<{ messageId: string }>('/admin/push/send', data)
}

// 批量删除推送记录
export function batchDeletePushLogsApi(ids: number[]) {
  return del('/admin/push/logs/batch', { ids })
}

// 重新推送
export function retryPushApi(id: number) {
  return post(`/admin/push/logs/${id}/retry`)
}

// 取消推送
export function cancelPushApi(id: number) {
  return post(`/admin/push/logs/${id}/cancel`)
}

// ---- 仪表盘统计 ----
export function getDashboardStatsApi() {
  return get<DashboardStats>('/admin/dashboard/stats')
}

// 在线设备趋势（近 7 天）
export function getOnlineTrendApi() {
  return get<TrendItem[]>('/admin/dashboard/online-trend')
}

// 今日推送量趋势（按小时）
export function getTodayPushTrendApi() {
  return get<TrendItem[]>('/admin/dashboard/today-push')
}

// 设备平台分布
export function getPlatformDistApi() {
  return get<PlatformDist[]>('/admin/dashboard/platform-dist')
}

// 近 7 天推送量趋势
export function getPushTrendApi() {
  return get<TrendItem[]>('/admin/dashboard/push-trend')
}

// ---- 测试调试推送 ----

// 发送测试推送
export function sendTestPushApi(data: {
  target_type: 'device' | 'key'
  target_value: string
  title?: string
  content?: string
  priority?: 'high' | 'normal' | 'low'
}) {
  return post<TestPushResult>('/admin/test-push', data)
}

// 检查设备/Key 在线状态
export function checkOnlineApi(params: { type: 'device' | 'key'; value: string }) {
  return get<{ online: boolean; online_count: number; detail: any }>('/admin/test-push/check', params)
}
