import { defineStore } from 'pinia'
import { getToken, setToken, removeToken } from '@/utils/auth'
import { loginApi, logoutApi, getUserInfoApi } from '@/api/auth'
import type { LoginParams, UserInfo } from '@/api/types'
import { resetRouter } from '@/router'

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
      const res = await loginApi(params)
      const token = res.data?.token
      if (!token) {
        throw new Error('登录失败：服务器未返回 Token')
      }
      this.token = token
      setToken(token)
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
      // 后端返回 role 字符串（super_admin/admin），转换为 roles 数组
      // 兜底：如果后端 role 为空/null/未知值，至少降级为 'admin'，保证菜单不空白
      const roleRaw = info.role as string | null | undefined
      let normalizedRole: string
      if (!roleRaw || typeof roleRaw !== 'string' || roleRaw.trim() === '') {
        console.warn('[userStore] /admin/info 返回的 role 为空，兜底降级为 admin')
        normalizedRole = 'admin'
      } else {
        normalizedRole = roleRaw.trim()
        // 兼容历史数据库里可能出现的非标准值：'0'/'1'/'2' / 'root' / 'administrator'
        const aliasMap: Record<string, string> = {
          '0': 'admin',
          '1': 'super_admin',
          '2': 'admin',
          root: 'super_admin',
          administrator: 'super_admin'
        }
        if (aliasMap[normalizedRole]) normalizedRole = aliasMap[normalizedRole]
        // 最后一次保险：只允许标准角色，其它一律兜底 admin
        if (!['admin', 'super_admin'].includes(normalizedRole)) {
          console.warn('[userStore] 未知 role=' + normalizedRole + '，兜底降级为 admin')
          normalizedRole = 'admin'
        }
      }
      this.roles = [normalizedRole]
      // 当前后端未提供细化权限，所有 admin 角色都拥有全部功能权限
      // TODO: 后端实现细化权限后，由 /admin/info 返回 permissions 数组
      this.permissions = ['*:*:*']
      return info
    },

    // 登出
    async logout() {
      try {
        await logoutApi()
      } catch {
        // 即使接口失败也清除本地状态
      }
      this.resetState()
      resetRouter()
    },

    // 重置状态
    resetState() {
      this.token = ''
      this.userInfo = null
      this.roles = []
      this.permissions = []
      removeToken()
      resetRouter()
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
