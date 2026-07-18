<template>
  <!-- 隐藏的路由不渲染 -->
  <template v-if="!item.meta?.hidden">
    <!-- 单一子菜单或无子菜单 -->
    <template
      v-if="visibleChildren.length === 1 && !visibleChildren[0].children"
    >
      <el-menu-item :index="resolvePath(visibleChildren[0].path)">
        <el-icon v-if="iconComponent">
          <component :is="iconComponent" />
        </el-icon>
        <template #title>
          <span>{{ visibleChildren[0].meta?.title || item.meta?.title }}</span>
        </template>
      </el-menu-item>
    </template>

    <!-- 无可见子项，渲染自身 -->
    <template v-else-if="visibleChildren.length === 0">
      <el-menu-item :index="resolvePath(item.path)">
        <el-icon v-if="iconComponent">
          <component :is="iconComponent" />
        </el-icon>
        <template #title>
          <span>{{ item.meta?.title }}</span>
        </template>
      </el-menu-item>
    </template>

    <!-- 多子菜单 - 折叠分组 -->
    <el-sub-menu v-else :index="resolvePath(item.path)">
      <template #title>
        <el-icon v-if="iconComponent">
          <component :is="iconComponent" />
        </el-icon>
        <span>{{ item.meta?.title }}</span>
      </template>
      <sidebar-item
        v-for="child in visibleChildren"
        :key="child.path"
        :item="child"
        :base-path="resolvePath(item.path)"
      />
    </el-sub-menu>
  </template>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { RouteRecordRaw } from 'vue-router'
import * as Icons from '@element-plus/icons-vue'

const props = defineProps<{
  item: RouteRecordRaw
  basePath: string
}>()

// 过滤可见的子路由
const visibleChildren = computed(() => {
  const children = props.item.children || []
  return children.filter((c) => !c.meta?.hidden)
})

// 图标组件（从导入的 Icons 中获取，避免与自动导入组件名冲突）
const iconComponent = computed(() => {
  const name = props.item.meta?.icon as string | undefined
  if (!name) return null
  const iconMap = Icons as Record<string, any>
  if (!iconMap[name]) return null
  return iconMap[name]
})

// 解析路径
function resolvePath(routePath: string): string {
  if (/^https?:\/\//.test(routePath)) return routePath
  // 如果已经是绝对路径（以 / 开头），直接返回规范化后的路径
  if (routePath.startsWith('/')) {
    return routePath.replace(/\/+/g, '/')
  }
  const base = props.basePath.endsWith('/')
    ? props.basePath.slice(0, -1)
    : props.basePath
  const path = routePath === '' ? '/' : `/${routePath}`
  // 处理根路径子项为空字符串的情况
  if (path === '/' && base) return base
  return (base + path).replace(/\/+/g, '/')
}
</script>
