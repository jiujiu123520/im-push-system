import { post } from '@/utils/request'
import type { PushSendParams } from './types'

// 用户端发送推送
export function sendPushApi(params: PushSendParams) {
  return post<{
    status: number  // 0=失败 1=成功 2=部分成功
    success_count: number
    fail_count: number
    fail_reason?: string
    elapsed_ms?: number
    stored_offline?: boolean
    message?: string
  }>('/user-api/push/send', params)
}
