<template>
  <div class="sidebar" :class="{ 'is-collapsed': appStore.sidebarCollapsed }">
    <!-- logo -->
    <div class="sidebar-logo" @click="router.push('/dashboard')">
      <div class="logo-orb">
        <span>IM</span>
      </div>
      <transition name="fade">
        <div v-if="!appStore.sidebarCollapsed" class="logo-text">
          <div class="title">Push 用户中心</div>
          <div class="subtitle">Console</div>
        </div>
      </transition>
    </div>

    <!-- 菜单 -->
    <el-scrollbar class="sidebar-scroll">
      <el-menu
        :default-active="activeMenu"
        :collapse="appStore.sidebarCollapsed"
        :collapse-transition="false"
        background-color="transparent"
        text-color="var(--text-regular)"
        active-text-color="var(--color-primary)"
        router
        unique-opened
      >
        <template v-for="route in menuRoutes" :key="route.path">
          <el-sub-menu v-if="route.children?.length && !onlyOneChild(route)" :index="resolvePath(route)">
            <template #title>
              <el-icon v-if="route.meta?.icon"><component :is="route.meta.icon" /></el-icon>
              <span>{{ route.meta?.title }}</span>
            </template>
            <el-menu-item
              v-for="child in route.children"
              :key="resolveChild(route, child)"
              :index="resolveChild(route, child)"
            >
              <el-icon v-if="child.meta?.icon"><component :is="child.meta.icon" /></el-icon>
              <template #title>{{ child.meta?.title }}</template>
            </el-menu-item>
          </el-sub-menu>
          <el-menu-item
            v-else-if="route.children?.length === 1"
            :index="resolveChild(route, route.children[0])"
          >
            <el-icon v-if="route.meta?.icon || route.children[0].meta?.icon">
              <component :is="route.children[0].meta?.icon || route.meta?.icon" />
            </el-icon>
            <template #title>{{ route.children[0].meta?.title }}</template>
          </el-menu-item>
        </template>
      </el-menu>
    </el-scrollbar>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { asyncRoutes, constantRoutes } from '@/router'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()

const menuRoutes = computed(() => {
  // 用户端菜单：仪表盘(constantRoutes) + 异步路由
  const dash = constantRoutes.filter((r) => r.path === '/')
  return [...dash, ...asyncRoutes.filter((r) => !r.meta?.hidden && r.path !== '/:pathMatch(.*)*')]
})
const activeMenu = computed(() => route.path)

function onlyOneChild(r: any) {
  return r.children?.length === 1 && !r.children[0].children
}
function resolvePath(r: any) {
  if (r.path.startsWith('/')) return r.path
  return '/' + r.path
}
function resolveChild(parent: any, child: any) {
  const p = parent.path.endsWith('/') ? parent.path : parent.path + '/'
  const c = child.path.replace(/^\//, '')
  if (child.path.startsWith('/')) return child.path
  if (parent.path === '/' && c === '') return '/' + parent.path + ''
  if (parent.path === '/') return '/' + c
  return p + c
}
</script>

<style lang="scss" scoped>
.sidebar {
  width: var(--sidebar-width);
  flex-shrink: 0;
  background: var(--bg-sidebar);
  border-right: 1px solid var(--border-light);
  display: flex; flex-direction: column;
  transition: width 0.25s ease;
  z-index: var(--z-sidebar);
  &.is-collapsed { width: var(--sidebar-collapsed); }
}
.sidebar-logo {
  height: var(--navbar-height);
  display: flex; align-items: center; gap: $space-3;
  padding: 0 $space-5; border-bottom: 1px solid var(--border-light);
  cursor: pointer; user-select: none;
}
.logo-orb {
  width: 36px; height: 36px; flex-shrink: 0;
  border-radius: 10px;
  background: $gradient-primary;
  color: #fff; display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 13px; letter-spacing: 0.5px;
  box-shadow: $shadow-primary;
}
.logo-text { overflow: hidden; white-space: nowrap;
  .title    { font-size: $font-size-md; font-weight: 600; color: var(--text-primary); line-height: 1.2; }
  .subtitle { font-size: $font-size-xs; color: var(--text-secondary); margin-top: 2px; }
}
.sidebar-scroll { flex: 1; padding: $space-3 0; }
:deep(.el-menu) { border-right: none; }
:deep(.el-menu-item), :deep(.el-sub-menu__title) {
  height: 44px; line-height: 44px; margin: 2px 8px;
  border-radius: $radius-sm;
  &:hover, &.is-active { color: var(--color-primary); }
}
:deep(.el-menu-item.is-active) { background: var(--color-primary-light-9); }
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
