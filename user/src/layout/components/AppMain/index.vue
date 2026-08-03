<template>
  <el-scrollbar class="app-main">
    <router-view v-slot="{ Component, route }">
      <transition name="fade-transform" mode="out-in">
        <keep-alive :include="cachedNames">
          <component :is="Component" :key="route.fullPath" />
        </keep-alive>
      </transition>
    </router-view>
  </el-scrollbar>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const cachedNames = computed(() => {
  // 简单缓存策略：标记 cache: true 路由
  return (route.matched
    .flatMap((r: any) => [r.name, ...(r.children?.map((c: any) => c.name) || [])])
    .filter(Boolean) as string[])
})
</script>

<style lang="scss" scoped>
.app-main {
  flex: 1; min-height: 0;
  padding: $layout-content-padding;
  background:
    radial-gradient(at 20% 10%, rgba(34,197,94,0.08), transparent 45%),
    radial-gradient(at 80% 20%, rgba(14,165,233,0.08), transparent 50%),
    var(--bg-page);
}
.fade-transform-enter-active,
.fade-transform-leave-active {
  transition: all 0.25s ease;
}
.fade-transform-enter-from { opacity: 0; transform: translateY(8px); }
.fade-transform-leave-to   { opacity: 0; transform: translateY(-8px); }
</style>
