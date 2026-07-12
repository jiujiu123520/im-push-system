<template>
  <aside class="sidebar" :class="{ 'is-collapsed': collapsed }">
    <!-- Logo 区 -->
    <div class="sidebar-logo" @click="goDashboard">
      <div class="logo-mark">
        <svg viewBox="0 0 40 40" width="32" height="32" aria-hidden="true">
          <defs>
            <linearGradient id="logoGrad" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0%" stop-color="#9b5cff" />
              <stop offset="100%" stop-color="#5cb8ff" />
            </linearGradient>
          </defs>
          <rect x="4" y="4" width="32" height="32" rx="10" fill="url(#logoGrad)" />
          <path
            d="M14 20.5l4.5 4.5L27 16"
            fill="none"
            stroke="#fff"
            stroke-width="3"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </div>
      <transition name="slide-fade">
        <span v-show="!collapsed" class="logo-text">
          <b>Push</b><span class="logo-badge">Admin</span>
        </span>
      </transition>
    </div>

    <!-- 菜单 -->
    <el-scrollbar class="sidebar-scroll">
      <el-menu
        :default-active="activeMenu"
        :collapse="collapsed"
        :collapse-transition="false"
        :unique-opened="true"
        router
        class="sidebar-menu"
      >
        <sidebar-item
          v-for="route in menuRoutes"
          :key="route.path"
          :item="route"
          :base-path="route.path"
        />
      </el-menu>
    </el-scrollbar>

    <!-- 底部信息 -->
    <transition name="slide-fade">
      <div v-show="!collapsed" class="sidebar-footer">
        <div class="footer-card">
          <div class="footer-dot" :class="{ pulse: true }"></div>
          <div class="footer-text">
            <div class="footer-title">系统运行中</div>
            <div class="footer-sub">
              在线设备: {{ appStore.onlineDevices }} 台
            </div>
          </div>
        </div>
      </div>
    </transition>
  </aside>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { usePermissionStore } from '@/stores/permission'
import { getDashboardOverviewApi } from '@/api/dashboard'
import SidebarItem from './SidebarItem.vue'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const permissionStore = usePermissionStore()

const collapsed = computed(() => appStore.sidebarCollapsed)
const menuRoutes = computed(() => permissionStore.menuRoutes)

const activeMenu = computed(() => {
  const { meta, path } = route
  return (meta.activeMenu as string) || path
})

let refreshTimer: number | null = null

async function loadSystemStatus() {
  try {
    const res = await getDashboardOverviewApi()
    if (res.data) {
      appStore.setSystemStatus({
        onlineDevices: res.data.online_devices
      })
    }
  } catch (e) {
    // 静默失败，不影响侧边栏显示
  }
}

function startRefreshTimer() {
  stopRefreshTimer()
  refreshTimer = window.setInterval(() => {
    if (document.visibilityState === 'visible') {
      loadSystemStatus()
    }
  }, 30000)
}

function stopRefreshTimer() {
  if (refreshTimer !== null) {
    clearInterval(refreshTimer)
    refreshTimer = null
  }
}

function goDashboard() {
  router.push('/dashboard')
}

onMounted(() => {
  loadSystemStatus()
  startRefreshTimer()
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      loadSystemStatus()
    }
  })
})

onBeforeUnmount(() => {
  stopRefreshTimer()
})
</script>

<style lang="scss" scoped>
.sidebar {
  position: relative;
  width: var(--sidebar-width);
  height: 100vh;
  background: var(--bg-sidebar);
  border-right: 1px solid var(--border-light);
  display: flex;
  flex-direction: column;
  transition: width $transition-base;
  z-index: $z-sidebar;

  &.is-collapsed {
    width: var(--sidebar-collapsed);
    :deep(.sidebar-menu) {
      width: 100%;
    }
  }
}

.sidebar-logo {
  height: var(--navbar-height);
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 0 18px;
  cursor: pointer;
  border-bottom: 1px solid var(--border-light);
  flex-shrink: 0;

  .logo-mark {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    filter: drop-shadow(0 4px 12px rgba(109, 92, 255, 0.3));
  }

  .logo-text {
    font-size: 18px;
    font-weight: 700;
    color: var(--text-primary);
    white-space: nowrap;
    display: flex;
    align-items: baseline;
    gap: 6px;

    .logo-badge {
      font-size: 11px;
      font-weight: 600;
      padding: 2px 6px;
      border-radius: 4px;
      background: $gradient-primary;
      color: #fff;
      letter-spacing: 0.5px;
    }
  }
}

.sidebar-scroll {
  flex: 1;
  overflow-x: hidden;
  @include scrollbar(6px);
}

.sidebar-menu {
  border-right: none !important;
  padding: 12px 12px;
  --el-menu-bg-color: transparent;
  --el-menu-hover-bg-color: rgba(109, 92, 255, 0.08);
  --el-menu-text-color: var(--text-regular);
  --el-menu-active-color: var(--color-primary);
  --el-menu-item-height: 46px;

  :deep(.el-menu-item),
  :deep(.el-sub-menu__title) {
    border-radius: 10px;
    margin: 4px 0;
    font-weight: 500;
    transition: all 0.2s ease;

    .el-icon {
      font-size: 18px;
    }

    &:hover {
      background: rgba(109, 92, 255, 0.08) !important;
    }
  }

  :deep(.el-menu-item.is-active) {
    background: $gradient-primary !important;
    color: #fff !important;
    box-shadow: $shadow-primary;
    font-weight: 600;

    .el-icon {
      color: #fff !important;
    }
  }
}

.sidebar-footer {
  padding: 12px 16px 16px;
  flex-shrink: 0;

  .footer-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border-radius: 12px;
    background: linear-gradient(135deg, rgba(109, 92, 255, 0.08), rgba(92, 184, 255, 0.06));
    border: 1px solid rgba(109, 92, 255, 0.12);
  }

  .footer-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: $color-success;
    box-shadow: 0 0 0 4px rgba(24, 194, 156, 0.18);
    animation: pulse-soft 2s ease-in-out infinite;
    flex-shrink: 0;
  }

  .footer-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
  }
  .footer-sub {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 2px;
  }
}
</style>
