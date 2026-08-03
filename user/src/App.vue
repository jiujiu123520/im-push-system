<template>
  <el-config-provider :locale="zhCn" :size="'default'">
    <router-view />
  </el-config-provider>
</template>

<script setup lang="ts">
import zhCn from 'element-plus/es/locale/lang/zh-cn'
</script>

<style>
html,
body,
#app {
  height: 100%;
  margin: 0;
  padding: 0;
}
</style>
