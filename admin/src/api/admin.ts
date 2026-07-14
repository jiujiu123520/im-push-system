import { get, post, put, del } from '@/utils/request'
import type { PageQuery, PageResult, AdminRecord, AdminForm } from './types'

// 管理员列表
export function getAdminListApi(params: PageQuery) {
  return get<PageResult<AdminRecord>>('/admin/admins', params)
}

// 管理员详情
export function getAdminDetailApi(id: number) {
  return get<AdminRecord>(`/admin/admins/${id}`)
}

// 新增管理员
export function createAdminApi(data: AdminForm) {
  return post('/admin/admins', data)
}

// 更新管理员
export function updateAdminApi(id: number, data: AdminForm) {
  return put(`/admin/admins/${id}`, data)
}

// 删除管理员
export function deleteAdminApi(id: number) {
  return del(`/admin/admins/${id}`)
}

// 切换管理员状态
export function toggleAdminStatusApi(id: number, status: number) {
  return put(`/admin/admins/${id}/status`, { status })
}

// 重置管理员密码
export function resetAdminPasswordApi(id: number, password: string) {
  return put(`/admin/users/${id}/password`, { password })
}

// 获取所有角色（用于分配）
export function getRoleOptionsApi() {
  return get<{ label: string; value: string }[]>('/admin/admins/roles')
}

// 管理员登录日志列表
export function getLoginLogsApi(params: PageQuery) {
  return get<PageResult<any>>('/admin/login-logs', params)
}
