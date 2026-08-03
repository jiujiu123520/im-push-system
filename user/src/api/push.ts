import { post } from '@/utils/request'
import type { PushSendParams } from './types'

// 用户端发送推送
export function sendPushApi(params: PushSendParams) {
  return post<{ log_id: number; message?: string }>('/user-api/push/send', params)
}
