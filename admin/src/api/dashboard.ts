import { get } from '@/utils/request'

// 仪表盘概览数据
export interface DashboardOverview {
  online_devices: number
  today_push: number
  yesterday_push: number
  active_keys: number
  total_keys: number
  total_users: number
  today_new_users: number
  today_new_devices: number
}

export function getDashboardOverviewApi() {
  return get<DashboardOverview>('/admin/dashboard/overview')
}

// 在线设备趋势
export interface TrendData {
  dates: string[]
  values: number[]
}

export function getOnlineTrendApi(days: number = 7) {
  return get<TrendData>('/admin/dashboard/online-trend', { days })
}

// 今日推送量（按小时）
export interface HourlyData {
  hours: string[]
  values: number[]
}

export function getTodayPushApi() {
  return get<HourlyData>('/admin/dashboard/today-push')
}

// Key 状态分布
export interface PieDataItem {
  name: string
  value: number
}

export function getKeyDistributionApi() {
  return get<{ data: PieDataItem[] }>('/admin/dashboard/key-distribution')
}

// 设备平台分布
export function getDevicePlatformApi() {
  return get<{ data: PieDataItem[] }>('/admin/dashboard/device-platform')
}

// 最新推送记录
export interface RecentPushItem {
  id: number
  title: string
  target_type: string
  target_value: string
  success_count: number
  fail_count: number
  created_at: string
}

export function getRecentPushApi(limit: number = 5) {
  return get<{ list: RecentPushItem[] }>('/admin/dashboard/recent-push', { limit })
}
