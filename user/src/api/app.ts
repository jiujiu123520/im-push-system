import { get, post } from '@/utils/request'
import type { AppInfo, HBuilderXGenerateParams } from './types'

// 用户端 APP 下载信息
export function getAppInfoApi() {
  return get<AppInfo>('/user-api/app/info')
}

// APP 下载二维码
export function getAppDownloadQrApi() {
  return get<{ apk_url: string; ipa_url: string; version: string }>('/user-api/app/download-qr')
}

// 用户端 HBuilderX 项目生成
export function generateHBuilderXApi(params: HBuilderXGenerateParams) {
  return post<any>('/user-api/app/hbuilderx/generate', params)
}
