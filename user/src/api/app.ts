import { get, post } from '@/utils/request'
import type { AppInfo, HBuilderXGenerateParams } from './types'

// 模板信息
export interface HBuilderXTemplate {
  id: string
  name: string
  description: string
  available: boolean
}

// 用户端 APP 下载信息
export function getAppInfoApi() {
  return get<AppInfo>('/user-api/app/info')
}

// APP 下载二维码
export function getAppDownloadQrApi() {
  return get<{ apk_url: string; ipa_url: string; version: string }>('/user-api/app/download-qr')
}

// 获取可用 HBuilderX 模板列表
export function getHBuilderXTemplatesApi() {
  return get<{ templates: HBuilderXTemplate[] }>('/user-api/app/hbuilderx/templates')
}

// 用户端 HBuilderX 项目生成
export function generateHBuilderXApi(params: HBuilderXGenerateParams) {
  return post<any>('/user-api/app/hbuilderx/generate', params)
}
