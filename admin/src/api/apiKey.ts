import { get, post, put, del } from '@/utils/request'
import type { PageQuery, PageResult, ApiKeyRecord, ApiKeyForm } from './types'

// API Key 列表
export function getApiKeyListApi(params: PageQuery) {
  return get<PageResult<ApiKeyRecord>>('/admin/api-keys', params)
}

// API Key 详情
export function getApiKeyDetailApi(id: number) {
  return get<ApiKeyRecord>(`/admin/api-keys/${id}`)
}

// 新增 API Key
export function createApiKeyApi(data: ApiKeyForm) {
  return post<{ accessKey: string; secretKey: string }>('/admin/api-keys', data)
}

// 更新 API Key
export function updateApiKeyApi(id: number, data: ApiKeyForm) {
  return put(`/admin/api-keys/${id}`, data)
}

// 删除 API Key
export function deleteApiKeyApi(id: number) {
  return del(`/admin/api-keys/${id}`)
}

// 切换状态
export function toggleApiKeyStatusApi(id: number, status: number) {
  return put(`/admin/api-keys/${id}/status`, { status })
}

// 重置 SecretKey
export function resetApiSecretApi(id: number) {
  return put<{ secretKey: string }>(`/admin/api-keys/${id}/secret`)
}

// 获取可用权限列表
export function getApiPermissionsApi() {
  return get<{ label: string; value: string }[]>('/admin/api-keys/permissions')
}

// 获取调用日志
export function getApiCallLogsApi(id: number, params: PageQuery) {
  return get<PageResult<{ time: string; ip: string; endpoint: string; status: number }>>(
    `/admin/api-keys/${id}/logs`,
    params
  )
}
