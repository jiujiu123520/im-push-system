import { get, post, put, del } from '@/utils/request'
import type { PageQuery, PageResult, KeyRecord, KeyForm } from './types'

// Key 列表
export function getKeyListApi(params: PageQuery) {
  return get<PageResult<KeyRecord>>('/admin/keys', params)
}

// Key 详情
export function getKeyDetailApi(id: number) {
  return get<KeyRecord>(`/admin/keys/${id}`)
}

// 新增 Key
export function createKeyApi(data: KeyForm) {
  return post('/admin/keys', data)
}

// 更新 Key
export function updateKeyApi(id: number, data: KeyForm) {
  return put(`/admin/keys/${id}`, data)
}

// 删除 Key
export function deleteKeyApi(id: number) {
  return del(`/admin/keys/${id}`)
}

// 切换 Key 状态
export function toggleKeyStatusApi(id: number, status: number) {
  return put(`/admin/keys/${id}/status`, { status })
}

// 重置 AppSecret
export function resetKeySecretApi(id: number) {
  return put<{ appSecret: string }>(`/admin/keys/${id}/secret`)
}

// 获取 Key 推送统计
export function getKeyStatsApi(id: number) {
  return get<{ today: number; total: number; week: number }>(
    `/admin/keys/${id}/stats`
  )
}
