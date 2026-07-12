import { get, post, del } from '@/utils/request'
import type {
  PageQuery,
  PageResult,
  AppBuildRecord,
  AppBuildForm
} from './types'

// 打包记录列表
export function getAppBuildListApi(params: PageQuery) {
  return get<PageResult<AppBuildRecord>>('/admin/app-builds', params)
}

// 打包详情
export function getAppBuildDetailApi(id: number) {
  return get<AppBuildRecord>(`/admin/app-builds/${id}`)
}

// 发起打包
export function createAppBuildApi(data: AppBuildForm) {
  return post<{ buildId: string }>('/admin/app-builds', data)
}

// 重新打包
export function retryAppBuildApi(id: number) {
  return post(`/admin/app-builds/${id}/retry`)
}

// 取消打包
export function cancelAppBuildApi(id: number) {
  return post(`/admin/app-builds/${id}/cancel`)
}

// 删除打包记录
export function deleteAppBuildApi(id: number) {
  return del(`/admin/app-builds/${id}`)
}

// 获取打包日志
export function getBuildLogApi(id: number) {
  return get<string>(`/admin/app-builds/${id}/log`)
}

// 获取打包配置模板
export function getBuildTemplatesApi() {
  return get<{ name: string; platform: string; config: Record<string, any> }[]>(
    '/admin/app-builds/templates'
  )
}
