import { get, post, del, request } from '@/utils/request'
import type {
  PageQuery,
  PageResult,
  AppBuildRecord,
  AppBuildForm
} from './types'

// 打包记录列表
export function getAppBuildListApi(params: PageQuery) {
  return get<PageResult<AppBuildRecord>>('/admin/app-build/list', params)
}

// 打包详情
export function getAppBuildDetailApi(id: string) {
  return get<AppBuildRecord>(`/admin/app-build/status/${id}`)
}

// 发起打包
export function createAppBuildApi(data: AppBuildForm) {
  return post<{ build_id: string }>('/admin/app-build', data)
}

// 重新打包
export function retryAppBuildApi(id: number) {
  return post(`/admin/app-build/${id}/retry`)
}

// 取消打包
export function cancelAppBuildApi(id: number) {
  return post(`/admin/app-build/${id}/cancel`)
}

// 删除打包记录
export function deleteAppBuildApi(id: number) {
  return del(`/admin/app-build/${id}`)
}

// 获取打包日志
export function getBuildLogApi(id: string) {
  return get<string>(`/admin/app-build/log/${id}`)
}

// 下载打包日志
export function downloadBuildLogApi(buildId: string) {
  return request<Blob>({
    method: 'get',
    url: `/admin/app-build/log/${buildId}/download`,
    responseType: 'blob'
  })
}

// 获取打包配置模板
export function getBuildTemplatesApi() {
  return get<{ name: string; platform: string; config: Record<string, any> }[]>(
    '/admin/app-build/templates'
  )
}

// 生成随机配置（包名、APP名称）
export function getRandomConfigApi() {
  return get<{ package_name: string; app_name: string; random_key: string }>(
    '/admin/app-build/random-config'
  )
}

// 生成图标（首字+渐变色）
export function generateIconApi(text: string) {
  return get<{ icon_base64: string; text: string; gradient: { start: string; end: string } }>(
    '/admin/app-build/generate-icon',
    { text }
  )
}

// 下载 APK
export function downloadApkApi(buildId: string) {
  return request<Blob>({
    method: 'get',
    url: `/admin/app-build/download/${buildId}`,
    responseType: 'blob'
  })
}
