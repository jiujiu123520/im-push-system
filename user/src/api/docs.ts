import { get, post, put, del } from '@/utils/request'
import type { PageQuery, PageResult, ApiKey } from './types'

export interface ApiKeyCreateParams {
  name: string
  expires_days?: number
}

// 用户端：API 文档概览
export function getDocsIndexApi() {
  return get<{
    endpoints: { method: string; path: string; description: string }[]
    examples: Record<string, any>
  }>('/user-api/docs')
}

// 用户端：自己的 API Key 列表（开放 API 调用凭证）
export function getUserApiKeyListApi(params: PageQuery) {
  return get<PageResult<ApiKey>>('/user-api/docs/api-keys', params)
}

// 创建 API Key
export function createUserApiKeyApi(params: ApiKeyCreateParams) {
  return post<ApiKey>('/user-api/docs/api-keys', params)
}

// 切换 API Key 状态
export function updateUserApiKeyStatusApi(id: number, status: 0 | 1) {
  return put<{ message: string }>(`/user-api/docs/api-keys/${id}/status`, { status })
}

// 删除 API Key
export function deleteUserApiKeyApi(id: number) {
  return del<{ message: string }>(`/user-api/docs/api-keys/${id}`)
}
