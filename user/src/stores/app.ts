import { defineStore } from 'pinia'

export interface AppState {
  sidebar: {
    opened: boolean
    withoutAnimation: boolean
  }
  device: 'desktop' | 'mobile'
  noticeDialogVisible: boolean
}

export const useAppStore = defineStore('app', {
  state: (): AppState => ({
    sidebar: {
      opened: localStorage.getItem('user_sidebar_status') !== '0',
      withoutAnimation: false
    },
    device: 'desktop',
    noticeDialogVisible: false
  }),

  getters: {
    sidebarOpened: (state) => state.sidebar.opened,
    sidebarCollapsed: (state) => !state.sidebar.opened
  },

  actions: {
    toggleSidebar() {
      this.sidebar.opened = !this.sidebar.opened
      this.sidebar.withoutAnimation = false
      localStorage.setItem('user_sidebar_status', this.sidebar.opened ? '1' : '0')
    },
    closeSidebar(withoutAnimation: boolean) {
      this.sidebar.opened = false
      this.sidebar.withoutAnimation = withoutAnimation
      localStorage.setItem('user_sidebar_status', '0')
    },
    setDevice(device: 'desktop' | 'mobile') {
      this.device = device
    },
    setNoticeDialogVisible(v: boolean) {
      this.noticeDialogVisible = v
    }
  }
})
