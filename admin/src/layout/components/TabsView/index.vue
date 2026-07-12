<template>
  <div class="tabs-view">
    <div class="tabs-scroll-wrapper">
      <el-scrollbar>
        <div class="tabs-inner">
          <div
            v-for="tab in appStore.visitedViews"
            :key="tab.path"
            class="tab-item"
            :class="{ 'is-active': isActive(tab) }"
            @click="goTo(tab)"
            @contextmenu.prevent="openMenu($event, tab)"
          >
            <span class="tab-dot" v-if="tab.affix"></span>
            <span class="tab-title">{{ tab.title }}</span>
            <el-icon
              v-if="!tab.affix"
              class="tab-close"
              @click.stop="closeTab(tab)"
            >
              <CloseIcon />
            </el-icon>
          </div>
        </div>
      </el-scrollbar>
    </div>

    <!-- 操作按钮 -->
    <el-dropdown trigger="click" @command="handleCommand" class="tabs-actions">
      <div class="actions-btn">
        <el-icon><ArrowDownIcon /></el-icon>
      </div>
      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item command="refresh">
            <el-icon><RefreshIcon /></el-icon>刷新当前
          </el-dropdown-item>
          <el-dropdown-item command="closeOthers">
            <el-icon><CircleCloseIcon /></el-icon>关闭其他
          </el-dropdown-item>
          <el-dropdown-item command="closeLeft">
            <el-icon><BackIcon /></el-icon>关闭左侧
          </el-dropdown-item>
          <el-dropdown-item command="closeRight">
            <el-icon><RightIcon /></el-icon>关闭右侧
          </el-dropdown-item>
          <el-dropdown-item command="closeAll" divided>
            <el-icon><DeleteIcon /></el-icon>关闭全部
          </el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>

    <!-- 右键菜单 -->
    <transition name="zoom">
      <ul
        v-show="menuVisible"
        :style="menuStyle"
        class="context-menu"
        @click.stop
      >
        <li @click="refreshCurrent">
          <el-icon><RefreshIcon /></el-icon>刷新
        </li>
        <li v-if="!currentTab?.affix" @click="closeCurrent">
          <el-icon><CloseIcon /></el-icon>关闭
        </li>
        <li @click="closeOthers">
          <el-icon><CircleCloseIcon /></el-icon>关闭其他
        </li>
        <li @click="closeAll">
          <el-icon><DeleteIcon /></el-icon>关闭全部
        </li>
      </ul>
    </transition>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import {
  Close as CloseIcon,
  ArrowDown as ArrowDownIcon,
  Refresh as RefreshIcon,
  CircleClose as CircleCloseIcon,
  Back as BackIcon,
  Right as RightIcon,
  Delete as DeleteIcon
} from '@element-plus/icons-vue'
import { useAppStore, type AppView } from '@/stores/app'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()

const menuVisible = ref(false)
const menuStyle = ref<{ left: string; top: string }>({ left: '0px', top: '0px' })
const currentTab = ref<AppView | null>(null)

// 初始化固定标签
function initAffixTabs() {
  // Dashboard 作为固定标签
  appStore.addVisitedView({
    name: 'Dashboard',
    path: '/dashboard',
    title: '仪表盘',
    affix: true
  })
}

// 添加当前路由到标签
function addTab() {
  const { name, path, meta } = route
  if (!name || !meta?.title) return
  appStore.addVisitedView({
    name: name as string,
    path,
    title: meta.title as string,
    affix: meta.affix,
    query: route.query
  })
}

watch(
  () => route.path,
  () => {
    addTab()
  },
  { immediate: true }
)

onMounted(() => {
  initAffixTabs()
  document.addEventListener('click', closeMenu)
})

onUnmounted(() => {
  document.removeEventListener('click', closeMenu)
})

function isActive(tab: AppView): boolean {
  return tab.path === route.path
}

function goTo(tab: AppView) {
  if (!isActive(tab)) {
    router.push(tab.path)
  }
}

function closeTab(tab: AppView) {
  if (tab.affix) {
    ElMessage.info('固定标签不可关闭')
    return
  }
  // 若关闭的是当前激活标签，需跳转
  const wasActive = isActive(tab)
  appStore.removeVisitedView(tab)
  if (wasActive) {
    const last = appStore.visitedViews[appStore.visitedViews.length - 1]
    if (last) {
      router.push(last.path)
    } else {
      router.push('/dashboard')
    }
  }
}

// 右键菜单
function openMenu(e: MouseEvent, tab: AppView) {
  currentTab.value = tab
  const { clientX, clientY } = e
  menuStyle.value = {
    left: clientX + 'px',
    top: clientY + 'px'
  }
  menuVisible.value = true
}

function closeMenu() {
  menuVisible.value = false
}

function refreshCurrent() {
  menuVisible.value = false
  if (!currentTab.value) return
  router.replace('/redirect' + currentTab.value.path)
}

function closeCurrent() {
  menuVisible.value = false
  if (currentTab.value) closeTab(currentTab.value)
}

function closeOthers() {
  menuVisible.value = false
  if (!currentTab.value) return
  appStore.removeOtherViews(currentTab.value)
  if (!isActive(currentTab.value)) {
    router.push(currentTab.value.path)
  }
}

function closeAll() {
  menuVisible.value = false
  appStore.removeAllViews()
  const first = appStore.visitedViews[0]
  router.push(first ? first.path : '/dashboard')
}

function handleCommand(cmd: string) {
  currentTab.value = {
    name: route.name as string,
    path: route.path,
    title: (route.meta?.title as string) || ''
  }
  switch (cmd) {
    case 'refresh':
      refreshCurrent()
      break
    case 'closeOthers':
      closeOthers()
      break
    case 'closeLeft':
      menuVisible.value = false
      if (!currentTab.value) return
      appStore.removeLeftViews(currentTab.value)
      break
    case 'closeRight':
      menuVisible.value = false
      if (!currentTab.value) return
      appStore.removeRightViews(currentTab.value)
      if (!isActive(currentTab.value)) {
        router.push(currentTab.value.path)
      }
      break
    case 'closeAll':
      closeAll()
      break
  }
}
</script>

<style lang="scss" scoped>
.tabs-view {
  height: var(--tabs-height);
  background: var(--bg-tabs);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border-bottom: 1px solid var(--border-light);
  display: flex;
  align-items: center;
  padding: 0 12px;
  position: relative;
  flex-shrink: 0;
  z-index: $z-tabs;
}

.tabs-scroll-wrapper {
  flex: 1;
  overflow: hidden;
  @include scrollbar(4px);
}

.tabs-inner {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 0;
  white-space: nowrap;
}

.tab-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 30px;
  padding: 0 12px;
  border-radius: 8px;
  background: transparent;
  border: 1px solid transparent;
  color: var(--text-regular);
  font-size: 13px;
  cursor: pointer;
  transition: all 0.2s ease;
  user-select: none;

  &:hover {
    color: var(--color-primary);
    background: rgba(109, 92, 255, 0.06);
  }

  &.is-active {
    background: var(--bg-card);
    border-color: rgba(109, 92, 255, 0.25);
    color: var(--color-primary);
    font-weight: 600;
    box-shadow: 0 2px 8px rgba(109, 92, 255, 0.12);
  }

  .tab-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: $gradient-primary;
  }

  .tab-title {
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .tab-close {
    font-size: 12px;
    border-radius: 50%;
    padding: 2px;
    transition: all 0.2s ease;

    &:hover {
      background: rgba(255, 90, 110, 0.15);
      color: $color-danger;
    }
  }
}

.tabs-actions {
  flex-shrink: 0;
  margin-left: 8px;
}

.actions-btn {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  color: var(--text-secondary);
  transition: all 0.2s ease;

  &:hover {
    background: rgba(109, 92, 255, 0.1);
    color: var(--color-primary);
  }
}

.context-menu {
  position: fixed;
  background: var(--bg-card);
  border: 1px solid var(--border-base);
  border-radius: 10px;
  box-shadow: $shadow-lg;
  padding: 6px;
  min-width: 140px;
  z-index: $z-dropdown;
  list-style: none;

  li {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    color: var(--text-regular);
    transition: all 0.15s ease;

    &:hover {
      background: rgba(109, 92, 255, 0.08);
      color: var(--color-primary);
    }
  }
}
</style>
