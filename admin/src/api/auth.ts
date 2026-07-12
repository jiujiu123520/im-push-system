import { post, get } from '@/utils/request'
import type { LoginParams, LoginResult, UserInfo } from './types'

// 登录
export function loginApi(data: LoginParams) {
  return post<LoginResult>('/admin/auth/login', data)
}

// 登出
export function logoutApi() {
  return post('/admin/auth/logout')
}

// 获取当前用户信息
export function getUserInfoApi() {
  return get<UserInfo>('/admin/auth/info')
}

// 获取图形验证码
export function getCaptchaApi() {
  return get<{ captchaId: string; img: string }>('/admin/auth/captcha')
}

// 修改密码
export function changePasswordApi(data: {
  oldPassword: string
  newPassword: string
}) {
  return post('/admin/auth/password', data)
}

// 刷新 token
export function refreshTokenApi() {
  return post<{ token: string }>('/admin/auth/refresh')
}
