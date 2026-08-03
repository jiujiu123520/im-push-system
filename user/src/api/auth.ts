import { get, post } from '@/utils/request'
import type {
  LoginParams, RegisterParams, SendCodeParams, ResetPasswordParams,
  ResetPasswordByQqParams, LoginData, UserInfo, SecurityConfig
} from './types'

// 注意：
// 认证接口（登录/注册/验证码）走后端公开路由 /auth/*
// 私有接口走 /user-api/* 前缀（经过 UserApiAuth 鉴权）

// 登录
export function loginApi(params: LoginParams) {
  return post<LoginData>('/auth/login', params)
}

// 注册
export function registerApi(params: RegisterParams) {
  return post<LoginData>('/auth/register', params)
}

// 发送验证码（短信/邮箱）
export function sendCodeApi(params: SendCodeParams) {
  return post<{ expires_in: number }>('/auth/send-code', params)
}

// 获取图形验证码
export function captchaImageUrl() {
  // 公开路由 /captcha/image
  return '/api/captcha/image?_t=' + Date.now()
}

// 通过安全码重置密码
export function resetPasswordApi(params: ResetPasswordParams) {
  return post<{ message: string }>('/auth/reset-password', params)
}

// 通过 QQ 号（+邮箱验证码）重置密码
export function resetPasswordByQqApi(params: ResetPasswordByQqParams) {
  return post<{ message: string }>('/auth/reset-password-by-qq', params)
}

// 安全配置（QQ绑定开关、改密方式）
export function getSecurityConfigApi() {
  return get<SecurityConfig>('/auth/security-config')
}

// 用户端私有：获取当前登录用户信息（/user-api/profile/info）
export function getUserInfoApi() {
  return get<UserInfo>('/user-api/profile')
}
