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

// 获取 GitHub Actions 构建配置状态
export function getAppBuildConfigStatusApi() {
  return get<{
    available: boolean
    token_configured: boolean
    owner: string
    repo: string
    workflow_file: string
    api_proxy: string
    repo_url: string
    actions_url: string
    secrets_url: string
    token_create_url: string
    required_secrets: Array<{ name: string; description: string; required: boolean }>
    required_env: Array<{ name: string; description: string }>
  }>('/admin/app-build/config-status')
}

// 手动触发 GitHub Actions 构建(不经过 Redis 队列)
export function manualTriggerBuildApi(data: {
  app_name: string
  package_name?: string
  default_key?: string
  server_url?: string
  ws_url?: string
  icon_base64?: string
}) {
  return post<{
    build_id: string
    dispatched: boolean
    message: string
    actions_url: string
    query_url: string
  }>('/admin/app-build/manual-trigger', data)
}

// 获取 GitHub Actions workflow 运行列表
export function getWorkflowRunsApi(params: { per_page?: number; page?: number }) {
  return get<{
    total: number
    runs: Array<{
      id: number
      name: string
      build_id: string
      status: string
      conclusion: string
      display_title: string
      html_url: string
      created_at: string
      updated_at: string
      run_started_at: string
      actor: string
      event: string
      head_branch: string
    }>
  }>('/admin/app-build/runs', params)
}

// 打包详情
export function getAppBuildDetailApi(id: string) {
  return get<AppBuildRecord>(`/admin/app-build/status/${id}`)
}

// 发起打包
export function createAppBuildApi(data: AppBuildForm) {
  return post<{ build_id: string }>('/admin/app-build', data)
}

// 删除打包记录
export function deleteAppBuildApi(buildId: string) {
  return del(`/admin/app-build/${buildId}`)
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

// 获取 GitHub Actions 配置
export function getGithubConfigApi() {
  return get<{
    token: string
    owner: string
    repo: string
    workflow_file: string
    ref: string
    api_proxy: string
    timeout: number
  }>('/admin/app-build/config')
}

// 保存 GitHub Actions 配置
export function saveGithubConfigApi(data: {
  token: string
  owner: string
  repo: string
  workflow_file?: string
  ref?: string
  api_proxy?: string
  timeout?: number
}) {
  return post<{
    message: string
    env_updated: boolean
  }>('/admin/app-build/config', data)
}

// 验证 GitHub Token 和仓库配置
export function validateGithubConfigApi(data: {
  token: string
  owner: string
  repo: string
}) {
  return post<{
    valid: boolean
    message: string
    repo_exists: boolean
    can_push: boolean
    has_workflow_scope: boolean
    has_repo_scope: boolean
    scopes: string[]
    user: string
  }>('/admin/app-build/config/validate', data)
}

// 全面检测配置状态
export function checkGithubConfigApi() {
  return get<{
    status: string
    checks: Record<string, {
      status: string
      message: string
      total?: number
      existing?: string[]
      missing?: string[]
      required?: string[]
    }>
    summary: string
  }>('/admin/app-build/config/check')
}

// 一键配置：生成 keystore + SSH 密钥 + 配置 GitHub Secrets
export function autoSetupGithubApi(data?: {
  ssh_port?: string
  ssh_user?: string
}) {
  return post<{
    success: boolean
    steps: Array<{
      step: string
      status: string
      message?: string
      failed?: Record<string, string>
    }>
    message: string
    ssh_pub_key?: string
    setup_info?: {
      keystore_path: string
      ssh_pub_path: string
      server_host: string
      ssh_port: string
      ssh_user: string
    }
    next_step?: string
  }>('/admin/app-build/config/auto-setup', data || {})
}
