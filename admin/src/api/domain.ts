import { get, post, put, del } from '@/utils/request'

// 目标类型
export type TargetType = 'frontend' | 'backend' | 'ws' | 'all'

// 域名记录
export interface DomainRecord {
  id: number
  domain: string
  listen_port: number
  type: string
  target_type: TargetType
  target_host: string
  ssl_enabled: number
  ssl_status: 'none' | 'pending' | 'issued' | 'failed' | 'expired'
  ssl_expire_at: string | null
  ssl_auto_renew: number
  ssl_last_renew_at: string | null
  ssl_cert_path: string
  ssl_key_path: string
  ssl_error: string
  nginx_deployed: number
  is_primary: number
  status: number
  remark: string
  created_at: string
  updated_at: string
  days_left?: number
}

// SSL 环境检查
export interface SslEnvironment {
  acme_sh: boolean
  nginx: boolean
  curl: boolean
  openssl: boolean
  ssl_dir: boolean
  webroot_dir: boolean
  sudoers: boolean
  ready: boolean
}

// 获取域名列表
export function getDomainListApi() {
  return get<{ list: DomainRecord[]; total: number }>('/admin/domains')
}

// 获取域名详情
export function getDomainApi(id: number) {
  return get<DomainRecord>(`/admin/domains/${id}`)
}

// 添加域名
export function createDomainApi(data: {
  domain: string
  target_type?: TargetType
  listen_port?: number
  target_host?: string
  remark?: string
}) {
  return post<{ message: string; id: number; is_primary: number }>('/admin/domains', data)
}

// 更新域名
export function updateDomainApi(
  id: number,
  data: {
    domain?: string
    target_type?: TargetType
    listen_port?: number
    target_host?: string
    remark?: string
    status?: number
    ssl_auto_renew?: number
  }
) {
  return put<{ message: string }>(`/admin/domains/${id}`, data)
}

// 删除域名
export function deleteDomainApi(id: number) {
  return del<{ message: string }>(`/admin/domains/${id}`)
}

// 设为主域名
export function setPrimaryDomainApi(id: number) {
  return post<{ message: string }>(`/admin/domains/${id}/set-primary`)
}

// 申请 SSL 证书
export function applySslApi(id: number) {
  return post<{
    message: string
    expire_at: string
    cert_path: string
    key_path: string
  }>(`/admin/domains/${id}/ssl-apply`)
}

// 续费 SSL 证书
export function renewSslApi(id: number) {
  return post<{ message: string; expire_at: string }>(`/admin/domains/${id}/ssl-renew`)
}

// 部署 Nginx 配置
export function deployNginxApi(id: number) {
  return post<{ message: string; output: string }>(`/admin/domains/${id}/ssl-deploy`)
}

// 同步所有域名 Nginx 配置
export function syncNginxApi() {
  return post<{ message: string; output: string }>('/admin/domains/sync-nginx')
}

// 批量续费所有即将过期证书
export function renewAllApi() {
  return post<{ message: string; renewed: string[]; failed: Array<{ domain: string; error: string }> }>(
    '/admin/domains/renew-all'
  )
}

// 切换自动续费
export function toggleAutoRenewApi(id: number, ssl_auto_renew: boolean) {
  return post<{ message: string; ssl_auto_renew: number }>(`/admin/domains/${id}/toggle-auto-renew`, {
    ssl_auto_renew: ssl_auto_renew ? 1 : 0
  })
}

// 检查 SSL 环境
export function getSslEnvironmentApi() {
  return get<SslEnvironment>('/admin/domains/environment')
}

// 安装 acme.sh
export function installAcmeApi() {
  return post<{ message: string; output: string }>('/admin/domains/install-acme')
}
