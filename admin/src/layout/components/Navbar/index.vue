<template>
  <header class="navbar">
    <div class="navbar-left">
      <!-- 折叠按钮 -->
      <div class="collapse-btn" @click="appStore.toggleSidebar">
        <el-icon :class="{ 'is-rotate': appStore.sidebarCollapsed }">
          <FoldIcon v-if="!appStore.sidebarCollapsed" />
          <ExpandIcon v-else />
        </el-icon>
      </div>

      <!-- 面包屑 -->
      <el-breadcrumb :separator-icon="ArrowRightIcon" class="breadcrumb">
        <transition-group name="breadcrumb">
          <el-breadcrumb-item
            v-for="item in breadcrumbs"
            :key="item.path"
            :to="item.redirect ? undefined : item.path"
          >
            {{ item.title }}
          </el-breadcrumb-item>
        </transition-group>
      </el-breadcrumb>
    </div>

    <div class="navbar-right">
      <!-- 搜索 -->
      <el-tooltip content="全局搜索" placement="bottom">
        <div class="nav-action" @click="handleSearch">
          <el-icon><SearchIcon /></el-icon>
        </div>
      </el-tooltip>

      <!-- 刷新 -->
      <el-tooltip content="刷新页面" placement="bottom">
        <div class="nav-action" :class="{ 'is-loading': refreshing }" @click="handleRefresh">
          <el-icon><RefreshIcon /></el-icon>
        </div>
      </el-tooltip>

      <!-- 全屏 -->
      <el-tooltip :content="isFullscreen ? '退出全屏' : '全屏'" placement="bottom">
        <div class="nav-action" @click="toggleFullscreen">
          <el-icon><FullScreenIcon v-if="!isFullscreen" /><AimIcon v-else /></el-icon>
        </div>
      </el-tooltip>

      <!-- 主题切换 -->
      <el-tooltip :content="appStore.theme === 'dark' ? '亮色模式' : '暗色模式'" placement="bottom">
        <div class="nav-action theme-toggle" @click="appStore.toggleTheme">
          <el-icon class="theme-icon">
            <SunnyIcon v-if="appStore.theme === 'dark'" />
            <MoonIcon v-else />
          </el-icon>
        </div>
      </el-tooltip>

      <!-- 通知 -->
      <el-badge :value="3" class="nav-badge">
        <el-tooltip content="消息通知" placement="bottom">
          <div class="nav-action">
            <el-icon><BellIcon /></el-icon>
          </div>
        </el-tooltip>
      </el-badge>

      <el-divider direction="vertical" />

      <!-- 用户菜单 -->
      <el-dropdown trigger="click" @command="handleCommand">
        <div class="user-info">
          <el-avatar :size="34" :src="userStore.avatar" class="user-avatar">
            {{ userStore.username.charAt(0).toUpperCase() }}
          </el-avatar>
          <div class="user-meta">
            <div class="user-name">{{ userStore.userInfo?.nickname || userStore.username }}</div>
            <div class="user-role">{{ roleLabel }}</div>
          </div>
          <el-icon class="dropdown-arrow"><ArrowDownIcon /></el-icon>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="profile">
              <el-icon><UserIcon /></el-icon>个人中心
            </el-dropdown-item>
            <el-dropdown-item command="password">
              <el-icon><LockIcon /></el-icon>修改密码
            </el-dropdown-item>
            <el-dropdown-item divided command="logout">
              <el-icon><SwitchButtonIcon /></el-icon>退出登录
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>
  </header>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessageBox, ElMessage } from 'element-plus'
import {
  ArrowRight as ArrowRightIcon,
  ArrowDown as ArrowDownIcon,
  Fold as FoldIcon,
  Expand as ExpandIcon,
  Search as SearchIcon,
  Refresh as RefreshIcon,
  FullScreen as FullScreenIcon,
  Aim as AimIcon,
  Sunny as SunnyIcon,
  Moon as MoonIcon,
  Bell as BellIcon,
  User as UserIcon,
  Lock as LockIcon,
  SwitchButton as SwitchButtonIcon
} from '@element-plus/icons-vue'
import { useFullscreen } from '@vueuse/core'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()
const { isFullscreen, toggle: toggleFullscreen } = useFullscreen()

const refreshing = ref(false)

interface BreadcrumbItem {
  path: string
  title: string
  redirect?: string
}

// 面包屑
const breadcrumbs = computed<BreadcrumbItem[]>(() => {
  const matched = route.matched.filter((item) => item.meta && item.meta.title)
  // 若首项不是 dashboard，则前置一项
  const first = matched[0]
  if (first && first.path !== '/dashboard') {
    return [
      { path: '/dashboard', title: '首页' },
      ...matched.map((m) => ({
        path: m.path,
        title: m.meta.title as string,
        redirect: (m as any).redirect
      }))
    ]
  }
  return matched.map((m) => ({
    path: m.path,
    title: m.meta.title as string,
    redirect: (m as any).redirect
  }))
})

const roleLabel = computed(() => {
  const roles = userStore.roles
  if (roles.includes('super_admin')) return '超级管理员'
  if (roles.includes('admin')) return '管理员'
  return '运营'
})

function handleSearch() {
  ElMessage.info('全局搜索功能即将开放')
}

function handleRefresh() {
  refreshing.value = true
  // 通过重定向实现刷新
  const { fullPath } = route
  router.replace({ path: '/redirect' + fullPath }).finally(() => {
    setTimeout(() => (refreshing.value = false), 600)
  })
}

async function handleCommand(cmd: string) {
  if (cmd === 'logout') {
    try {
      await ElMessageBox.confirm('确定要退出登录吗？', '提示', {
        confirmButtonText: '退出',
        cancelButtonText: '取消',
        type: 'warning'
      })
      await userStore.logout()
      ElMessage.success('已退出登录')
      router.push('/login')
    } catch {
      // 取消
    }
  } else if (cmd === 'profile') {
    ElMessage.info('个人中心即将开放')
  } else if (cmd === 'password') {
    ElMessage.info('修改密码功能即将开放')
  }
}
</script>

<style lang="scss" scoped>
.navbar {
  height: var(--navbar-height);
  background: var(--bg-navbar);
  backdrop-filter: blur(14px) saturate(180%);
  -webkit-backdrop-filter: blur(14px) saturate(180%);
  border-bottom: 1px solid var(--border-light);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  position: sticky;
  top: 0;
  z-index: $z-navbar;
}

.navbar-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.collapse-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: var(--text-regular);
  transition: all 0.2s ease;
  font-size: 18px;

  &:hover {
    background: rgba(109, 92, 255, 0.08);
    color: var(--color-primary);
  }

  .el-icon {
    transition: transform 0.3s ease;
    &.is-rotate {
      transform: rotate(180deg);
    }
  }
}

.breadcrumb {
  :deep(.el-breadcrumb__inner) {
    color: var(--text-secondary);
    font-weight: 500;
    &.is-link:hover {
      color: var(--color-primary);
    }
  }
  :deep(.el-breadcrumb__item:last-child .el-breadcrumb__inner) {
    color: var(--text-primary);
    font-weight: 600;
  }
}

.navbar-right {
  display: flex;
  align-items: center;
  gap: 6px;
}

.nav-action {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: var(--text-regular);
  font-size: 18px;
  transition: all 0.25s ease;
  position: relative;

  &:hover {
    background: rgba(109, 92, 255, 0.1);
    color: var(--color-primary);
    transform: translateY(-1px);
  }

  &.is-loading .el-icon {
    animation: rotate-slow 0.8s linear infinite;
  }
}

.theme-toggle {
  .theme-icon {
    transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  &:hover .theme-icon {
    transform: rotate(45deg) scale(1.15);
  }
}

.nav-badge {
  margin-right: 4px;
}

:deep(.el-divider--vertical) {
  height: 24px;
  margin: 0 8px;
  border-color: var(--border-base);
}

.user-info {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 5px 10px 5px 5px;
  border-radius: 999px;
  cursor: pointer;
  transition: background 0.2s ease;

  &:hover {
    background: rgba(109, 92, 255, 0.08);
  }

  .user-avatar {
    background: $gradient-primary;
    color: #fff;
    font-weight: 600;
    flex-shrink: 0;
  }

  .user-meta {
    line-height: 1.2;
  }

  .user-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-primary);
  }

  .user-role {
    font-size: 11px;
    color: var(--text-secondary);
    margin-top: 2px;
  }

  .dropdown-arrow {
    font-size: 12px;
    color: var(--text-secondary);
    transition: transform 0.2s ease;
  }

  &:hover .dropdown-arrow {
    transform: rotate(180deg);
  }
}

// 面包屑过渡
.breadcrumb-enter-active,
.breadcrumb-leave-active {
  transition: all 0.4s ease;
}
.breadcrumb-enter-from,
.breadcrumb-leave-to {
  opacity: 0;
  transform: translateX(12px);
}

@media (max-width: 768px) {
  .user-meta {
    display: none;
  }
  .breadcrumb {
    display: none;
  }
}
</style>
