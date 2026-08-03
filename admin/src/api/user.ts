import { get, post, put, del } from '@/utils/request'
import type { PageQuery, PageResult, UserRecord, UserForm } from './types'

// 用户列表
export function getUserListApi(params: PageQuery) {
  return get<PageResult<UserRecord>>('/admin/users', params)
}

// 用户详情
export function getUserDetailApi(id: number) {
  return get<UserRecord>(`/admin/users/${id}`)
}

// 新增用户
export function createUserApi(data: UserForm) {
  return post('/admin/users', data)
}

// 更新用户
export function updateUserApi(id: number, data: UserForm) {
  return put(`/admin/users/${id}`, data)
}

// 删除用户
export function deleteUserApi(id: number) {
  return del(`/admin/users/${id}`)
}

// 切换用户状态
export function toggleUserStatusApi(id: number, status: number) {
  return put(`/admin/users/${id}/status`, { status })
}

// 重置用户密码
export function resetUserPasswordApi(id: number, password: string) {
  return put(`/admin/users/${id}/password`, { password })
}

// 批量删除用户
export function batchDeleteUsersApi(ids: number[]) {
  return del('/admin/users/batch', { ids })
}

// 导出用户
export function exportUsersApi(params: PageQuery) {
  return get('/admin/users/export', params)
}

// ==================== QQ 绑定相关操作 ====================

// 为用户绑定 QQ（管理员强制绑定/改绑）
export function bindUserQqApi(id: number, qq: string) {
  return put<{ message: string }>(`/admin/users/${id}/qq`, { qq })
}

// 解绑用户 QQ
export function unbindUserQqApi(id: number) {
  return del<{ message: string }>(`/admin/users/${id}/qq`)
}

// 通过 QQ 号查询用户
export function findUserByQqApi(qq: string) {
  return get<UserRecord | null>('/admin/users/find-by-qq', { qq })
}

// 通过 QQ + 验证码重置用户密码（管理员改密方式之一）
export function resetUserPasswordByQqApi(params: {
  account: string
  qq: string
  new_password: string
  email_code?: string
}) {
  return post<{ message: string }>('/admin/users/reset-password-by-qq', params)
}
