import { get, post, put, del } from '@/utils/request'
import type { PageQuery, PageResult, PushKey } from './types'

export interface KeyStoreParams {
  name: string
  max_devices?: number
  notify_enabled?: 0 | 1
  notify_email?: string
  notify_interval?: number
}

export interface KeyUpdateParams {
  name?: string
  max_devices?: number
  notify_enabled?: 0 | 1
  notify_email?: string
  notify_interval?: number
}

// 用户端 Push Key 列表
export function getKeyListApi(params: PageQuery) {
  return get<PageResult<PushKey>>('/user-api/keys', params)
}

// 创建 Push Key
export function createKeyApi(params: KeyStoreParams) {
  return post<PushKey>('/user-api/keys', params)
}

// 更新 Push Key
export function updateKeyApi(id: number, params: KeyUpdateParams) {
  return put<PushKey>(`/user-api/keys/${id}`, params)
}

// 切换 Push Key 状态
export function updateKeyStatusApi(id: number, status: 0 | 1) {
  return put<{ message: string }>(`/user-api/keys/${id}/status`, { status })
}

// 删除 Push Key
export function deleteKeyApi(id: number) {
  return del<{ message: string }>(`/user-api/keys/${id}`)
}
