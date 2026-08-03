import { get } from '@/utils/request'
import type { DashboardOverview } from './types'

// 用户端仪表盘概览
export function getDashboardOverviewApi() {
  return get<DashboardOverview>('/user-api/dashboard/overview')
}
