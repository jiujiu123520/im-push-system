import { get } from '@/utils/request'
import type { PageQuery, PageResult, PushLog, PushLogDetail } from './types'

// 用户端推送记录列表
export function getPushLogListApi(params: PageQuery) {
  return get<PageResult<PushLog>>('/user-api/push-logs', params)
}

// 用户端推送记录详情
export function getPushLogDetailApi(id: number) {
  return get<PushLogDetail>(`/user-api/push-logs/${id}`)
}
