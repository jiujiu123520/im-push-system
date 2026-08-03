import { get, put, post } from '@/utils/request'
import type {
  UserInfo, ProfileUpdateParams, ChangePasswordParams, BindQqParams
} from './types'

// 个人信息
export function getProfileApi() {
  return get<UserInfo>('/user-api/profile')
}

// 更新个人信息
export function updateProfileApi(params: ProfileUpdateParams) {
  return put<UserInfo>('/user-api/profile', params)
}

// 修改密码
export function changePasswordApi(params: ChangePasswordParams) {
  return put<{ message: string }>('/user-api/profile/password', params)
}

// 绑定 QQ
export function bindQqApi(params: BindQqParams) {
  return post<{ message: string; qq: string }>('/user-api/profile/bind-qq', params)
}

// 解绑 QQ（需要管理员操作，用户端如需调用会返回失败提示）
export function unbindQqApi() {
  return post<{ message: string }>('/user-api/profile/unbind-qq')
}

// 退出所有登录
export function logoutAllApi() {
  return post<{ logged_out: boolean }>('/user-api/profile/logout-all')
}
