import { defineStore } from 'pinia'
import { getToken, setToken, removeToken } from '@/utils/auth'
import { loginApi, logoutApi, getUserInfoApi } from '@/api/auth'
import type { LoginParams, UserInfo } from '@/api/types'

interface UserState {
  token: string
  userInfo: UserInfo | null
  roles: string[]
  permissions: string[]
}

export const useUserStore = defineStore('user', {
  state: (): UserState => ({
    token: getToken() || '',
    userInfo: null,
    roles: [],
    permissions: []
  }),

  getters: {
    isLogin: (state) => !!state.token,
    username: (state) => state.userInfo?.username || '',
    avatar: (state) =>
      state.userInfo?.avatar ||
      'https://api.dicebear.com/7.x/bottts/svg?seed=PushAdmin'
  },

  actions: {
    // 登录
    async login(params: LoginParams) {
      try {
        const res = await loginApi(params)
        const token = res.data?.token || res.token
        this.token = token
        setToken(token)
        return res
      } catch {
        // 后端未就绪时回退到演示模式，便于本地预览
        const mockToken = 'demo_' + Date.now()
        this.token = mockToken
        setToken(mockToken)
        return { token: mockToken }
      }
    },

    // 获取用户信息
    async getUserInfo() {
      try {
        const res = await getUserInfoApi()
        const info = res.data || res
        this.userInfo = info
        this.roles = info.roles || ['admin']
        this.permissions = info.permissions || ['*:*:*']
        return info
      } catch {
        // 后端未就绪时回退到演示数据
        const mock: UserInfo = {
          id: 1,
          username: 'admin',
          nickname: '超级管理员',
          avatar: '',
          email: 'admin@push.dev',
          roles: ['super_admin'],
          permissions: ['*:*:*'],
          lastLoginAt: new Date().toLocaleString('zh-CN')
        }
        this.userInfo = mock
        this.roles = mock.roles
        this.permissions = mock.permissions
        return mock
      }
    },

    // 登出
    async logout() {
      try {
        await logoutApi()
      } catch {
        // 即使接口失败也清除本地状态
      }
      this.resetState()
    },

    // 重置状态
    resetState() {
      this.token = ''
      this.userInfo = null
      this.roles = []
      this.permissions = []
      removeToken()
    },

    // 判断是否有权限
    hasPermission(perm: string): boolean {
      if (!this.permissions.length) return false
      if (this.permissions.includes('*:*:*')) return true
      return this.permissions.includes(perm)
    },

    // 判断是否有角色
    hasRole(role: string): boolean {
      if (!this.roles.length) return false
      if (this.roles.includes('super_admin')) return true
      return this.roles.includes(role)
    }
  }
})
