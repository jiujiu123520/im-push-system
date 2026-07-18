import { post, get, put } from '@/utils/request'
import type {
  LoginParams,
  LoginResult,
  UserInfo,
  RegisterParams,
  RegisterResult,
  SendCodeParams,
  ResetPasswordParams
} from './types'

// 登录（后端路由：POST /admin/login）
export function loginApi(data: LoginParams) {
  return post<LoginResult>('/admin/login', data)
}

// 登出（后端路由：POST /admin/logout）
export function logoutApi() {
  return post('/admin/logout')
}

// 获取当前登录管理员信息（后端路由：GET /admin/info）
export function getUserInfoApi() {
  return get<UserInfo>('/admin/info')
}

// 获取图形验证码（后端路由：GET /captcha/image，返回 {token, image}）
export function getCaptchaApi() {
  return get<{ token: string; image: string }>('/captcha/image')
}

// 修改密码（后端路由：PUT /admin/change-password）
export function changePasswordApi(data: { oldPassword: string; newPassword: string }) {
  return put('/admin/change-password', {
    old_password: data.oldPassword,
    new_password: data.newPassword
  })
}

// 刷新 token（后端暂未实现，保留接口）
export function refreshTokenApi() {
  return post<{ token: string }>('/admin/refresh')
}

// 用户注册（后端路由：POST /auth/register）
export function registerApi(data: RegisterParams) {
  return post<RegisterResult>('/auth/register', data)
}

// 发送验证码（后端路由：POST /auth/send-code）
export function sendCodeApi(data: SendCodeParams) {
  return post<{ sent: boolean; message: string }>('/auth/send-code', data)
}

// 通过安全码重置密码（后端路由：POST /auth/reset-password）
export function resetPasswordApi(data: ResetPasswordParams) {
  return post<{ message: string }>('/auth/reset-password', data)
}
