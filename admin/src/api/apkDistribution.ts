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
  download_count: number
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

/** Cookie 验证结果 */
export interface CookieValidateResult {
  valid: boolean
  message: string
}

/** 下载日志记录 */
export interface DownloadLogItem {
  ip_address: string
  user_agent: string
  user_agent_short: string
  referer: string
  downloaded_at: string
}

/** 下载统计数据 */
export interface DownloadStats {
  total: number
  recent: DownloadLogItem[]
}

/** 本地上传 APK 结果 */
export interface UploadApkResult {
  success: boolean
  message: string
  id: number
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

/** 验证蓝奏云 Cookie 是否有效 */
export function validateLanzouCookieApi(cookie: string) {
  return post<CookieValidateResult>('/admin/apk-distribution/validate-cookie', { cookie })
}

/** 本地上传 APK 文件 */
export function uploadApkApi(formData: FormData) {
  return post<UploadApkResult>('/admin/apk-distribution/upload', formData)
}

/** 获取下载统计数据 */
export function getDownloadStatsApi(id: number) {
  return get<DownloadStats>(`/admin/apk-distribution/${id}/stats`)
}
