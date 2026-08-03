<template>
  <div class="navbar glass-navbar">
    <div class="nav-left">
      <el-icon class="toggle-btn" @click="appStore.toggleSidebar">
        <Fold v-if="!appStore.sidebarCollapsed" /><Expand v-else />
      </el-icon>
      <el-breadcrumb separator="/">
        <el-breadcrumb-item v-for="(m, idx) in breadcrumbs" :key="idx" :to="idx < breadcrumbs.length - 1 ? m.path : undefined">
          {{ m.title }}
        </el-breadcrumb-item>
      </el-breadcrumb>
    </div>

    <div class="nav-right">
      <!-- 通知图标（站内公告） -->
      <el-badge :value="unreadCount" :hidden="unreadCount === 0" class="notice-badge">
        <el-icon class="icon-btn" @click="goNotices"><Bell /></el-icon>
      </el-badge>
      <!-- 用户菜单 -->
      <el-dropdown trigger="click" @command="handleCommand">
        <div class="user-menu">
          <el-avatar :src="userStore.avatar" :size="34" />
          <div v-if="!isMobile" class="user-info">
            <div class="name">{{ userStore.nickname || userStore.username }}</div>
            <div class="role">普通用户</div>
          </div>
          <el-icon><ArrowDown /></el-icon>
        </div>
        <template #dropdown>
          <el-dropdown-menu>
            <el-dropdown-item command="profile">
              <el-icon><User /></el-icon> 个人中心
            </el-dropdown-item>
            <el-dropdown-item command="notices">
              <el-icon><Bell /></el-icon> 系统公告
            </el-dropdown-item>
            <el-dropdown-item divided command="logout">
              <el-icon><SwitchButton /></el-icon> 退出登录
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Fold, Expand, Bell, ArrowDown, User, SwitchButton } from '@element-plus/icons-vue'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { getNoticeListApi, markAllNoticeReadApi } from '@/api/notice'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()
const isMobile = computed(() => appStore.device === 'mobile')
const unreadCount = ref(0)

const breadcrumbs = computed(() => {
  const matched = route.matched.filter((r: any) => r.meta?.title)
  return matched.map((r: any) => ({ title: r.meta.title, path: r.path }))
})

async function loadUnread() {
  try {
    // 简单估算未读：取前 50 条公告，未读数量待后端扩展；这里用 localStorage 近似
    const read: number[] = JSON.parse(localStorage.getItem('user_notice_read') || '[]')
    const res = await getNoticeListApi({ page: 1, pageSize: 50 })
    const items: any[] = res.data?.items || []
    unreadCount.value = items.filter((n) => !read.includes(n.id)).length
  } catch {}
}
onMounted(loadUnread)

function goNotices() { router.push('/notices') }

async function handleCommand(cmd: string) {
  if (cmd === 'profile') router.push('/profile')
  else if (cmd === 'notices') router.push('/notices')
  else if (cmd === 'logout') {
    try {
      await ElMessageBox.confirm('确定要退出登录吗？', '提示', {
        confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning'
      })
      await userStore.logout()
      ElMessage.success('已退出登录')
    } catch {}
  }
}
</script>

<style lang="scss" scoped>
.navbar {
  height: var(--navbar-height);
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 $space-5; flex-shrink: 0;
  z-index: var(--z-navbar);
}
.nav-left { display: flex; align-items: center; gap: $space-4; min-width: 0; }
.toggle-btn {
  font-size: $font-size-xl; padding: $space-2;
  color: var(--text-regular); border-radius: $radius-sm;
  cursor: pointer;
  &:hover { background: var(--color-primary-light-9); color: var(--color-primary); }
}
.nav-right { display: flex; align-items: center; gap: $space-4; }
.icon-btn {
  font-size: $font-size-lg; padding: $space-2;
  color: var(--text-regular); border-radius: $radius-sm;
  cursor: pointer;
  &:hover { background: var(--color-primary-light-9); color: var(--color-primary); }
}
.notice-badge { display: inline-flex; }
.user-menu {
  display: flex; align-items: center; gap: $space-2;
  padding: 4px 8px; border-radius: $radius-md;
  cursor: pointer;
  &:hover { background: var(--border-lighter); }
}
.user-info { line-height: 1.2;
  .name { font-size: $font-size-sm; font-weight: 600; color: var(--text-primary); }
  .role { font-size: $font-size-xs; color: var(--text-secondary); margin-top: 2px; }
}
</style>
