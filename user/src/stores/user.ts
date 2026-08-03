import { defineStore } from 'pinia'
import { getToken, setToken, removeToken } from '@/utils/auth'
import { loginApi, getUserInfoApi, registerApi } from '@/api/auth'
import type { LoginParams, RegisterParams, UserInfo } from '@/api/types'
import router, { resetRouter } from '@/router'

interface UserState {
  token: string
  userInfo: UserInfo | null
}

export const useUserStore = defineStore('user', {
  state: (): UserState => ({
    token: getToken() || '',
    userInfo: null
  }),

  getters: {
    isLogin: (state) => !!state.token,
    username: (state) => state.userInfo?.username || '',
    nickname: (state) => state.userInfo?.nickname || state.userInfo?.username || '',
    avatar: (state) =>
      state.userInfo?.avatar ||
      'https://api.dicebear.com/7.x/bottts/svg?seed=' + (state.userInfo?.username || 'PushUser'),
    qq: (state) => state.userInfo?.qq || null,
    email: (state) => state.userInfo?.email || null,
    phone: (state) => state.userInfo?.phone || null,
    userId: (state) => state.userInfo?.id || 0
  },

  actions: {
    // 登录
    async login(params: LoginParams) {
      const res = await loginApi(params)
      const token = res.data?.token
      if (!token) {
        throw new Error('登录失败：服务器未返回 Token')
      }
      this.token = token
      setToken(token)
      return res
    },

    // 注册（注册成功通常也会返回 token，可直接登录态）
    async register(params: RegisterParams) {
      const res = await registerApi(params)
      const token = res.data?.token
      if (token) {
        this.token = token
        setToken(token)
      }
      return res
    },

    // 获取用户信息
    async getUserInfo() {
      const res = await getUserInfoApi()
      const info = res.data
      if (!info) {
        throw new Error('获取用户信息失败')
      }
      this.userInfo = info
      return info
    },

    // 刷新用户信息（用于改绑、改密码后）
    async refreshUserInfo() {
      try {
        await this.getUserInfo()
      } catch {}
    },

    // 登出
    async logout() {
      this.resetState()
      resetRouter()
      router.push('/login')
    },

    // 重置状态
    resetState() {
      this.token = ''
      this.userInfo = null
      removeToken()
      resetRouter()
    }
  }
})
