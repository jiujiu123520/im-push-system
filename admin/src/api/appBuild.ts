import { get, request } from '@/utils/request'

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

// HBuilderX 模板信息
export interface HBuilderXTemplate {
  id: string
  name: string
  description: string
  available: boolean
  /** 模板最后一次修改时间（unix 秒，可能为 null） */
  updated_at?: number | null
  /** 模板最后一次修改对应的 git commit 短哈希（可能为 null） */
  commit?: string | null
}

// 获取 HBuilderX 模板列表
export function getHBuilderXTemplatesApi() {
  return get<{ templates: HBuilderXTemplate[] }>('/admin/app-build/hbuilderx/templates')
}

// 生成 HBuilderX 项目包
export function generateHBuilderXProjectApi(data: {
  app_name: string
  package_name?: string
  default_key?: string
  server_url?: string
  ws_url?: string
  icon_base64?: string
  version?: string
  template?: string
}) {
  return request<Blob>({
    method: 'post',
    url: '/admin/app-build/hbuilderx/generate',
    data,
    responseType: 'blob'
  })
}

// 生成玻璃拟态源码包
export function generateComposeSourceApi(data: {
  app_name: string
  package_name?: string
  default_key?: string
  server_url: string
  ws_url: string
  icon_base64?: string
  version_name?: string
  version_code?: number
}) {
  return request<Blob>({
    method: 'post',
    url: '/admin/app-build/compose/generate',
    data,
    responseType: 'blob'
  })
}
