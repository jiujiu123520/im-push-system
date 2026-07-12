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
