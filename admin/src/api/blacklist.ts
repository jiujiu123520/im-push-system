import { get, post, put, del } from '@/utils/request'
import type {
  PageQuery,
  PageResult,
  BlacklistRecord,
  BlacklistForm
} from './types'

// 黑名单列表
export function getBlacklistApi(params: PageQuery) {
  return get<PageResult<BlacklistRecord>>('/admin/blacklist', params)
}

// 新增黑名单
export function createBlacklistApi(data: BlacklistForm) {
  return post('/admin/blacklist', data)
}

// 更新黑名单
export function updateBlacklistApi(id: number, data: BlacklistForm) {
  return put(`/admin/blacklist/${id}`, data)
}

// 删除黑名单
export function deleteBlacklistApi(id: number) {
  return del(`/admin/blacklist/${id}`)
}

// 批量删除
export function batchDeleteBlacklistApi(ids: number[]) {
  return del('/admin/blacklist/batch', { ids })
}

// 检查是否在黑名单
export function checkBlacklistApi(type: string, value: string) {
  return get<{ blocked: boolean }>('/admin/blacklist/check', { type, value })
}

// 导入黑名单
export function importBlacklistApi(data: { type: string; items: string[] }) {
  return post('/admin/blacklist/import', data)
}
