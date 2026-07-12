import { defineStore } from 'pinia'

type ThemeMode = 'light' | 'dark'

interface AppState {
  sidebarCollapsed: boolean
  theme: ThemeMode
  // 标签页
  visitedViews: AppView[]
  cachedViews: string[]
  device: 'desktop' | 'mobile'
  // 系统状态
  onlineDevices: number
  systemStatusLoaded: boolean
}

interface AppView {
  name: string
  path: string
  title: string
  affix?: boolean
  query?: Record<string, any>
}

const SIDEBAR_KEY = 'push_sidebar_collapsed'
const THEME_KEY = 'push_theme'

export const useAppStore = defineStore('app', {
  state: (): AppState => ({
    sidebarCollapsed: localStorage.getItem(SIDEBAR_KEY) === '1',
    theme: (localStorage.getItem(THEME_KEY) as ThemeMode) || 'light',
    visitedViews: [],
    cachedViews: [],
    device: 'desktop',
    onlineDevices: 0,
    systemStatusLoaded: false
  }),

  actions: {
    // 更新系统状态
    setSystemStatus(status: { onlineDevices: number }) {
      this.onlineDevices = status.onlineDevices
      this.systemStatusLoaded = true
    },

    // 切换侧边栏折叠
    toggleSidebar() {
      this.sidebarCollapsed = !this.sidebarCollapsed
      localStorage.setItem(SIDEBAR_KEY, this.sidebarCollapsed ? '1' : '0')
    },

    // 设置设备类型
    setDevice(device: 'desktop' | 'mobile') {
      this.device = device
      if (device === 'mobile') {
        this.sidebarCollapsed = true
      }
    },

    // 初始化主题
    initTheme() {
      this.applyTheme(this.theme)
    },

    // 切换主题
    toggleTheme() {
      this.theme = this.theme === 'light' ? 'dark' : 'light'
      this.applyTheme(this.theme)
      localStorage.setItem(THEME_KEY, this.theme)
    },

    // 应用主题到 html
    applyTheme(theme: ThemeMode) {
      const html = document.documentElement
      if (theme === 'dark') {
        html.classList.add('dark')
      } else {
        html.classList.remove('dark')
      }
    },

    // 添加已访问视图
    addVisitedView(view: AppView) {
      if (this.visitedViews.some((v) => v.path === view.path)) return
      this.visitedViews.push(view)
      if (view.name) {
        this.cachedViews.push(view.name)
      }
    },

    // 删除已访问视图
    removeVisitedView(view: AppView) {
      const idx = this.visitedViews.findIndex((v) => v.path === view.path)
      if (idx > -1) {
        this.visitedViews.splice(idx, 1)
      }
      const cIdx = this.cachedViews.indexOf(view.name)
      if (cIdx > -1) {
        this.cachedViews.splice(cIdx, 1)
      }
    },

    // 删除其他
    removeOtherViews(view: AppView) {
      this.visitedViews = this.visitedViews.filter(
        (v) => v.affix || v.path === view.path
      )
      this.cachedViews = this.visitedViews.map((v) => v.name)
    },

    // 删除全部
    removeAllViews() {
      this.visitedViews = this.visitedViews.filter((v) => v.affix)
      this.cachedViews = this.visitedViews.map((v) => v.name)
    },

    // 关闭左侧
    removeLeftViews(view: AppView) {
      const idx = this.visitedViews.findIndex((v) => v.path === view.path)
      this.visitedViews = this.visitedViews.filter(
        (v, i) => v.affix || i >= idx
      )
      this.cachedViews = this.visitedViews.map((v) => v.name)
    },

    // 关闭右侧
    removeRightViews(view: AppView) {
      const idx = this.visitedViews.findIndex((v) => v.path === view.path)
      this.visitedViews = this.visitedViews.filter(
        (v, i) => v.affix || i <= idx
      )
      this.cachedViews = this.visitedViews.map((v) => v.name)
    }
  }
})

export type { AppView }
