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
  feijipan_url: string
  feijipan_share_id: string
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
  feijii_app_token: string
  feijii_uuid: string
  feijii_dev_code: string
  custom_script: string
  base_url: string
}

export interface PageResult<T> {
  list: T[]
  total: number
  page: number
  page_size: number
}

/** 凭证验证结果 */
export interface CredentialsValidateResult {
  valid: boolean
  message: string
  user_info: any
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

/** 小飞机网盘文件 */
export interface FeijiiFile {
  fileId: string
  fileName: string
  fileSize: number
  updTime: string
  fileIcon: string
}

/** 小飞机网盘文件夹 */
export interface FeijiiFolder {
  folderId: number
  folderName: string
  updTime: string
  addTime: string
}

/** 小飞机网盘文件列表结果 */
export interface FeijiiFilesResult {
  success: boolean
  message: string
  files: FeijiiFile[]
  folders: FeijiiFolder[]
  total: number
  currentFolderId: number
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

/** 获取小飞机网盘文件列表 */
export function getFeijiiFilesApi(params?: { folderId?: number; offset?: number; limit?: number }) {
  return get<FeijiiFilesResult>('/admin/apk-distribution/feijii-files', params)
}

/** 为指定文件创建小飞机分享链接 */
export function uploadToFeijiiApi(id: number, fileId: string) {
  return post(`/admin/apk-distribution/${id}/feijipan`, { fileId })
}

export function uploadCustomApi(id: number) {
  return post(`/admin/apk-distribution/${id}/custom`)
}

export function deleteDistributionApi(id: number) {
  return del(`/admin/apk-distribution/${id}`)
}

/** 验证小飞机网盘凭证是否有效 */
export function validateFeijiiCredentialsApi(data: {
  app_token: string
  uuid: string
  dev_code: string
}) {
  return post<CredentialsValidateResult>('/admin/apk-distribution/validate-credentials', data)
}

/** 本地上传 APK 文件（大文件 200MB，请求超时放宽到 10 分钟） */
export function uploadApkApi(formData: FormData) {
  return post<UploadApkResult>(
    '/admin/apk-distribution/upload',
    formData as unknown as Record<string, any>,
    { timeout: 10 * 60 * 1000 }
  )
}

/** 获取下载统计数据 */
export function getDownloadStatsApi(id: number) {
  return get<DownloadStats>(`/admin/apk-distribution/${id}/stats`)
}
