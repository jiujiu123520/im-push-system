<template>
  <section class="app-main">
    <router-view v-slot="{ Component, route }">
      <transition name="route-fade" mode="out-in" appear>
        <keep-alive :include="cachedViews">
          <component :is="Component" :key="route.fullPath" />
        </keep-alive>
      </transition>
    </router-view>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useAppStore } from '@/stores/app'

const appStore = useAppStore()
const cachedViews = computed(() => appStore.cachedViews)
</script>

<style lang="scss" scoped>
.app-main {
  flex: 1;
  min-height: 0;
  overflow: auto;
  background: var(--bg-page);
  position: relative;
  @include scrollbar(8px);
}

// 路由过渡已由全局 transition.scss 提供 .route-fade
</style>
