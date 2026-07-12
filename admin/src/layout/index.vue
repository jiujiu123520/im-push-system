<template>
  <div class="layout-wrapper">
    <!-- 侧边导航 -->
    <Sidebar />

    <!-- 右侧主体 -->
    <div class="layout-main" :class="{ 'is-collapsed': appStore.sidebarCollapsed }">
      <div class="layout-header">
        <Navbar />
        <TabsView />
      </div>

      <AppMain />
    </div>

    <!-- 移动端遮罩 -->
    <transition name="fade">
      <div
        v-if="isMobile && !appStore.sidebarCollapsed"
        class="mobile-mask"
        @click="appStore.toggleSidebar"
      ></div>
    </transition>
  </div>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import Sidebar from './components/Sidebar/index.vue'
import Navbar from './components/Navbar/index.vue'
import TabsView from './components/TabsView/index.vue'
import AppMain from './components/AppMain/index.vue'
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

onUnmounted(() => {
  window.removeEventListener('resize', checkDevice)
})
</script>

<style lang="scss" scoped>
.layout-wrapper {
  display: flex;
  width: 100%;
  height: 100vh;
  overflow: hidden;
  background: var(--bg-page);
}

.layout-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.layout-header {
  flex-shrink: 0;
  position: sticky;
  top: 0;
  z-index: $z-tabs;
}

.mobile-mask {
  position: fixed;
  inset: 0;
  background: $bg-overlay;
  backdrop-filter: blur(2px);
  z-index: 999;
}

@media (max-width: 768px) {
  .layout-wrapper {
    :deep(.sidebar) {
      position: fixed;
      left: 0;
      top: 0;
      z-index: 1000;
      transform: translateX(0);
      transition: transform 0.3s ease;

      &.is-collapsed {
        transform: translateX(-100%);
        width: var(--sidebar-width);
      }
    }
  }
}
</style>
