import { get, post, put, del } from '@/utils/request'

export interface ApkDistributionRecord {
  id: number
  build_id: string
  app_name: string
  package_name: string
  version_name: string
  apk_path: string
  apk_size: number
  apk_size_text: string
  md5: string
  download_token: string
  self_hosted_url: string
  lanzou_url: string
  lanzou_password: string
  custom_url: string
  upload_status: string
  upload_message: string
  admin_id: number
  created_at: string
  updated_at: string
}

export interface ApkDistributionConfig {
  enabled: boolean
  lanzou_cookie: string
  custom_script: string
  base_url: string
}

export interface PageResult<T> {
  list: T[]
  total: number
  page: number
  page_size: number
}

export function getDistributionListApi(params: { page?: number; keyword?: string }) {
  return get<PageResult<ApkDistributionRecord>>('/admin/apk-distribution', params)
}

export function getDistributionDetailApi(id: number) {
  return get<ApkDistributionRecord>(`/admin/apk-distribution/${id}`)
}

export function getDistributionConfigApi() {
  return get<ApkDistributionConfig>('/admin/apk-distribution/config')
}

export function saveDistributionConfigApi(data: ApkDistributionConfig) {
  return put('/admin/apk-distribution/config', data)
}

export function uploadToLanzouApi(id: number) {
  return post(`/admin/apk-distribution/${id}/lanzou`)
}

export function uploadCustomApi(id: number) {
  return post(`/admin/apk-distribution/${id}/custom`)
}

export function deleteDistributionApi(id: number) {
  return del(`/admin/apk-distribution/${id}`)
}
