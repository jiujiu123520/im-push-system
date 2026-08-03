<template>
  <div class="layout-wrapper">
    <Sidebar />
    <div class="layout-main" :class="{ 'is-collapsed': appStore.sidebarCollapsed }">
      <Navbar />
      <AppMain />
    </div>
    <transition name="fade">
      <div v-if="isMobile && !appStore.sidebarCollapsed" class="mobile-mask"
           @click="appStore.toggleSidebar"></div>
    </transition>
    <!-- 全局公告弹窗 -->
    <NoticeDialog />
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import Sidebar from './components/Sidebar/index.vue'
import Navbar from './components/Navbar/index.vue'
import AppMain from './components/AppMain/index.vue'
import NoticeDialog from '@/components/NoticeDialog/index.vue'
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()
const isMobile = ref(false)

function checkDevice() {
  isMobile.value = window.innerWidth < 768
  appStore.setDevice(isMobile.value ? 'mobile' : 'desktop')
}
onMounted(() => {
  checkDevice()
  window.addEventListener('resize', checkDevice)
})
onUnmounted(() => window.removeEventListener('resize', checkDevice))
</script>

<style lang="scss" scoped>
.layout-wrapper {
  display: flex; width: 100%; height: 100vh; overflow: hidden;
  background: var(--bg-page);
}
.layout-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.mobile-mask {
  position: fixed; inset: 0; background: rgba(15,23,42,0.5);
  backdrop-filter: blur(2px); z-index: 999;
}
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

@media (max-width: 768px) {
  :deep(.sidebar) {
    position: fixed; left: 0; top: 0; z-index: 1000;
    &.is-collapsed { transform: translateX(-100%); width: var(--sidebar-width); }
  }
}
</style>
